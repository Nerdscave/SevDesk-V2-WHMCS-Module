<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use DateTimeImmutable;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/**
 * Creates a manually confirmed negative revenue voucher for one refund.
 *
 * The service intentionally does not enshrine anything and does not convert a
 * Voucher into a CreditNote. Its stable refund marker makes a lost POST response
 * reconcilable before another write is considered.
 *
 * @phpstan-type CorrectionResult array{
 *     status: 'succeeded'|'skipped'|'blocked'|'failed'|'ambiguous',
 *     code: string,
 *     message: string,
 *     remoteId?: string,
 *     dedupeReference?: string,
 *     context?: array<string, scalar|null>
 * }
 */
final class CorrectionService
{
    /** @var Closure(string): (int|string|null) */
    private readonly Closure $findExistingByReference;

    /** @var Closure(string, string): (void|bool) */
    private readonly Closure $persistReference;

    /**
     * Collaborator signatures:
     *
     * - $findExistingByReference(string $dedupeReference): int|string|null
     * - $persistReference(string $dedupeReference, string $sevdeskVoucherId): void|bool
     *
     * The caller must also own the active job-item dedupe key while create() is
     * running. These callbacks persist the terminal refund-reference mapping.
     */
    public function __construct(
        private readonly SevdeskClient $client,
        callable $findExistingByReference,
        callable $persistReference,
        private readonly bool $requireReceiptGuidance = true,
    ) {
        $this->findExistingByReference = Closure::fromCallable($findExistingByReference);
        $this->persistReference = Closure::fromCallable($persistReference);
    }

    /**
     * @param array{
     *     kind: string,
     *     whmcsRefundTransactionId: string,
     *     invoiceId: int,
     *     invoiceNumber: string,
     *     originalVoucherId: int|string,
     *     contactId: int|string,
     *     refundAmount: int|float|string,
     *     currency: string,
     *     voucherDate: string
     * } $request voucherDate must use YYYY-MM-DD. refundAmount and each LineItem
     *     amount are positive; this service applies the negative sign.
     * @param non-empty-list<LineItem> $positions Explicit refund allocation. A
     *     multi-rate refund must provide one or more positions per rate.
     * @param null|callable(string, array<string, scalar|null>): (void|bool) $checkpoint
     * @param bool $readOnlyRecovery When true, an exact marker match may be
     *     persisted locally, but no new remote voucher may be created.
     * @return CorrectionResult
     */
    public function create(
        array $request,
        TaxDecision $taxDecision,
        array $positions,
        bool $confirmed,
        ?callable $checkpoint = null,
        bool $readOnlyRecovery = false,
    ): array {
        if (!$confirmed) {
            return self::result(
                'blocked',
                'confirmation_required',
                'A correction voucher requires explicit individual confirmation.',
            );
        }

        try {
            $correction = $this->normaliseRequest($request);
        } catch (\InvalidArgumentException $exception) {
            return self::result('blocked', 'invalid_correction_request', $exception->getMessage());
        }

        if ($correction['kind'] !== 'refund') {
            return self::result(
                'blocked',
                'unsupported_correction_kind',
                'Chargebacks and other negative transactions are not correction-voucher candidates.',
            );
        }

        $positionFailure = $this->validatePositions($positions, $correction, $taxDecision);
        if ($positionFailure !== null) {
            return $positionFailure;
        }

        $reference = self::dedupeReference($correction['whmcsRefundTransactionId']);
        $refundMarker = self::refundMarker($correction['whmcsRefundTransactionId']);

        try {
            $existing = ($this->findExistingByReference)($reference);
        } catch (Throwable) {
            return self::result(
                'failed',
                'correction_mapping_lookup_failed',
                'The existing correction reference could not be checked.',
                dedupeReference: $reference,
            );
        }
        $existingId = self::numericId($existing);
        if ($existing !== null && trim((string) $existing) !== '' && $existingId === null) {
            return self::result(
                'ambiguous',
                'invalid_existing_correction_mapping',
                'The stored correction reference is not a valid sevdesk ID. Resolve it before creating anything.',
                dedupeReference: $reference,
            );
        }
        if ($existingId !== null) {
            return self::result(
                'skipped',
                'correction_already_mapped',
                'This WHMCS refund transaction already has a correction voucher.',
                $existingId,
                $reference,
            );
        }

        try {
            $remoteMarkers = $this->findRemoteMarkers($refundMarker);
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'correction_reconciliation_failed', $reference);
        }

        if ($remoteMarkers !== []) {
            if (
                count($remoteMarkers) !== 1
                || !$this->remoteCandidateMatches(
                    $remoteMarkers[0],
                    $correction,
                    $taxDecision,
                    $refundMarker,
                )
            ) {
                return self::result(
                    'ambiguous',
                    'correction_marker_conflict',
                    'A sevdesk voucher uses this refund marker but cannot be reconciled uniquely. '
                        . 'Do not retry automatically.',
                    dedupeReference: $reference,
                    context: ['matchCount' => count($remoteMarkers)],
                );
            }

            $remoteId = self::numericId($remoteMarkers[0]['id'] ?? null);
            if ($remoteId === null) {
                return self::result(
                    'ambiguous',
                    'correction_reconciliation_invalid_id',
                    'The matching correction voucher has no usable sevdesk ID.',
                    dedupeReference: $reference,
                );
            }

            $persistFailure = $this->persist($reference, $remoteId, true);
            if ($persistFailure !== null) {
                return $persistFailure;
            }

            return self::result(
                'skipped',
                'correction_reconciled',
                'An existing correction voucher was found and linked; no new voucher was created.',
                $remoteId,
                $reference,
            );
        }

        if ($readOnlyRecovery) {
            return self::result(
                'ambiguous',
                'correction_reconciliation_no_match',
                'No correction voucher was found by its refund marker. '
                    . 'Recovery remains read-only; no new voucher was created.',
                dedupeReference: $reference,
                context: ['matchCount' => 0],
            );
        }

        try {
            $originalVoucher = $this->readOne(
                '/Voucher/' . rawurlencode($correction['originalVoucherId']),
                'voucher',
            );
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'original_voucher_read_failed', $reference);
        }

        if (!$this->originalVoucherMatches($originalVoucher, $correction, $taxDecision)) {
            return self::result(
                'blocked',
                'original_voucher_mismatch',
                'The mapped original voucher does not match invoice, contact, currency and tax decision.',
                dedupeReference: $reference,
            );
        }

        // Close the local race immediately before the non-idempotent write. The
        // active job dedupe key closes the corresponding worker race.
        try {
            $existingBeforeWrite = ($this->findExistingByReference)($reference);
        } catch (Throwable) {
            return self::result(
                'failed',
                'correction_mapping_recheck_failed',
                'The correction reference could not be rechecked before voucher creation.',
                dedupeReference: $reference,
            );
        }
        $existingBeforeWriteId = self::numericId($existingBeforeWrite);
        if (
            $existingBeforeWrite !== null
            && trim((string) $existingBeforeWrite) !== ''
            && $existingBeforeWriteId === null
        ) {
            return self::result(
                'ambiguous',
                'invalid_existing_correction_mapping',
                'The stored correction reference changed to an invalid sevdesk ID. No API write was attempted.',
                dedupeReference: $reference,
            );
        }
        if ($existingBeforeWriteId !== null) {
            return self::result(
                'skipped',
                'correction_already_mapped',
                'Another worker already recorded this correction voucher.',
                $existingBeforeWriteId,
                $reference,
            );
        }

        $checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);
        if (
            !$this->emitCheckpoint($checkpoint, 'correction_voucher_write_requested', [
            'invoiceId' => $correction['invoiceId'],
            'originalVoucherId' => $correction['originalVoucherId'],
            'dedupeReference' => $reference,
            ])
        ) {
            return self::result(
                'failed',
                'checkpoint_persist_failed',
                'The correction write checkpoint could not be stored; no API write was attempted.',
                dedupeReference: $reference,
            );
        }

        try {
            $response = $this->client->post(
                '/Voucher/Factory/saveVoucher',
                $this->buildPayload($correction, $taxDecision, $positions, $refundMarker),
                true,
                [201],
            );
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'correction_voucher_write_failed', $reference);
        }

        $remoteVoucher = self::unwrapVoucher($response);
        $remoteId = self::numericId($remoteVoucher['id'] ?? null);
        if ($remoteId === null) {
            return self::result(
                'ambiguous',
                'correction_voucher_id_missing',
                'sevdesk accepted the correction request but returned no voucher ID. Reconcile before retrying.',
                dedupeReference: $reference,
            );
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'correction_voucher_created', [
            'invoiceId' => $correction['invoiceId'],
            'remoteId' => $remoteId,
            'dedupeReference' => $reference,
            ])
        ) {
            return self::result(
                'ambiguous',
                'checkpoint_persist_failed_after_correction',
                'The correction voucher exists, but its result checkpoint could not be stored.',
                $remoteId,
                $reference,
            );
        }

        $remoteGross = $remoteVoucher['sumGross'] ?? $remoteVoucher['total'] ?? null;
        if (!is_int($remoteGross) && !is_float($remoteGross) && !is_string($remoteGross)) {
            return self::result(
                'ambiguous',
                'correction_remote_total_missing',
                'The created correction voucher has no verifiable total. Reconcile before retrying.',
                $remoteId,
                $reference,
            );
        }
        try {
            $remoteMinor = Decimal::toMinorUnits((string) $remoteGross);
        } catch (\InvalidArgumentException) {
            return self::result(
                'ambiguous',
                'correction_remote_total_invalid',
                'The created correction voucher returned an invalid total. Reconcile before retrying.',
                $remoteId,
                $reference,
            );
        }
        if ($remoteMinor !== -$correction['refundMinorUnits']) {
            return self::result(
                'ambiguous',
                'correction_remote_total_mismatch',
                'The correction voucher total differs from the confirmed refund. No mapping was written.',
                $remoteId,
                $reference,
                [
                    'expectedMinorUnits' => -$correction['refundMinorUnits'],
                    'remoteMinorUnits' => $remoteMinor,
                ],
            );
        }

        $persistFailure = $this->persist($reference, $remoteId, false);
        if ($persistFailure !== null) {
            return $persistFailure;
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'correction_mapping_persisted', [
            'invoiceId' => $correction['invoiceId'],
            'remoteId' => $remoteId,
            'dedupeReference' => $reference,
            ])
        ) {
            return self::result(
                'ambiguous',
                'checkpoint_persist_failed_after_correction_mapping',
                'The correction and its mapping exist, but the final checkpoint could not be stored.',
                $remoteId,
                $reference,
            );
        }

        return self::result(
            'succeeded',
            'correction_voucher_created',
            'The confirmed negative revenue voucher was created and linked to the refund reference.',
            $remoteId,
            $reference,
        );
    }

    /**
     * Build the exact negative Voucher payload. This method never adds an
     * enshrine operation or a CreditNote fallback.
     *
     * @param array{
     *     invoiceId: int,
     *     invoiceNumber: string,
     *     originalVoucherId: string,
     *     contactId: string,
     *     currency: string,
     *     voucherDate: string
     * } $correction
     * @param non-empty-list<LineItem> $positions
     * @return array<string, mixed>
     */
    public function buildPayload(
        array $correction,
        TaxDecision $taxDecision,
        array $positions,
        string $refundMarker,
    ): array {
        if (
            !$taxDecision->allowed
            || $taxDecision->accountDatevId === null
            || $taxDecision->taxRuleId === null
        ) {
            throw new \InvalidArgumentException('A correction requires an allowed tax decision.');
        }

        $voucherPositions = array_map(
            static function (LineItem $position) use ($taxDecision): array {
                $payload = [
                    'objectName' => 'VoucherPos',
                    'mapAll' => true,
                    'accountDatev' => [
                        'id' => self::payloadId($taxDecision->accountDatevId ?? ''),
                        'objectName' => 'AccountDatev',
                    ],
                    'taxRate' => Decimal::toFloat($position->taxRate),
                    'net' => $position->net,
                    'comment' => substr($position->description, 0, 255),
                ];
                $payload[$position->net ? 'sumNet' : 'sumGross'] = -abs(Decimal::toFloat($position->amount));

                return $payload;
            },
            $positions,
        );

        $descriptionPrefix = trim((string) preg_replace(
            '/[\x00-\x1F\x7F]+/',
            ' ',
            'Correction ' . $correction['invoiceNumber'],
        ));
        $description = substr($descriptionPrefix, 0, 80)
            . ' ' . VoucherExporter::marker($correction['invoiceId'])
            . ' ' . self::originalVoucherMarker($correction['originalVoucherId'])
            . ' ' . $refundMarker;

        return [
            'voucher' => [
                'objectName' => 'Voucher',
                'mapAll' => true,
                'description' => $description,
                'currency' => $correction['currency'],
                'voucherDate' => self::apiDate($correction['voucherDate']),
                'propertyForeignCurrencyDeadline' => self::apiDate($correction['voucherDate']),
                'payDate' => null,
                'status' => 100,
                'taxRule' => [
                    'id' => self::payloadId($taxDecision->taxRuleId),
                    'objectName' => 'TaxRule',
                ],
                'creditDebit' => 'D',
                'voucherType' => 'VOU',
                'supplier' => [
                    'id' => self::payloadId($correction['contactId']),
                    'objectName' => 'Contact',
                ],
            ],
            'voucherPosSave' => $voucherPositions,
            'voucherPosDelete' => null,
        ];
    }

    public static function dedupeReference(string $whmcsRefundTransactionId): string
    {
        return 'correction_voucher:refund:' . hash('sha256', trim($whmcsRefundTransactionId));
    }

    public static function refundMarker(string $whmcsRefundTransactionId): string
    {
        return '[WHMCS-REFUND:' . substr(hash('sha256', trim($whmcsRefundTransactionId)), 0, 24) . ']';
    }

    private static function originalVoucherMarker(string $voucherId): string
    {
        return '[SEVDESK-VOUCHER:' . $voucherId . ']';
    }

    /**
     * @param array<string, mixed> $request
     * @return array{
     *     kind: string,
     *     whmcsRefundTransactionId: string,
     *     invoiceId: int,
     *     invoiceNumber: string,
     *     originalVoucherId: string,
     *     contactId: string,
     *     refundAmount: string,
     *     refundMinorUnits: int,
     *     currency: string,
     *     voucherDate: string
     * }
     */
    private function normaliseRequest(array $request): array
    {
        $kind = strtolower(trim((string) ($request['kind'] ?? '')));
        if (!in_array($kind, ['refund', 'chargeback'], true)) {
            throw new \InvalidArgumentException('The correction kind must be refund or chargeback.');
        }

        $transactionId = trim((string) preg_replace(
            '/[\x00-\x1F\x7F]+/',
            ' ',
            (string) ($request['whmcsRefundTransactionId'] ?? ''),
        ));
        if ($transactionId === '' || strlen($transactionId) > 160) {
            throw new \InvalidArgumentException('A valid WHMCS refund transaction ID is required.');
        }

        $invoiceId = $request['invoiceId'] ?? null;
        if (!is_int($invoiceId) || $invoiceId < 1) {
            throw new \InvalidArgumentException('A positive WHMCS invoice ID is required.');
        }

        $invoiceNumber = trim((string) preg_replace(
            '/[\x00-\x1F\x7F]+/',
            ' ',
            (string) ($request['invoiceNumber'] ?? ''),
        ));
        if ($invoiceNumber === '') {
            throw new \InvalidArgumentException('A WHMCS invoice number is required.');
        }

        $originalVoucherId = self::numericId($request['originalVoucherId'] ?? null);
        $contactId = self::numericId($request['contactId'] ?? null);
        if ($originalVoucherId === null || $contactId === null) {
            throw new \InvalidArgumentException('Numeric original voucher and contact IDs are required.');
        }

        $refundValue = $request['refundAmount'] ?? null;
        if (!is_int($refundValue) && !is_float($refundValue) && !is_string($refundValue)) {
            throw new \InvalidArgumentException('A decimal refund amount is required.');
        }
        $refundAmount = Decimal::assert((string) $refundValue, 'Refund amount');
        $refundMinorUnits = Decimal::toMinorUnits($refundAmount);
        if ($refundMinorUnits <= 0) {
            throw new \InvalidArgumentException('The confirmed refund amount must be positive.');
        }

        $currency = strtoupper(trim((string) ($request['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('A three-letter refund currency is required.');
        }

        return [
            'kind' => $kind,
            'whmcsRefundTransactionId' => $transactionId,
            'invoiceId' => $invoiceId,
            'invoiceNumber' => substr($invoiceNumber, 0, 160),
            'originalVoucherId' => $originalVoucherId,
            'contactId' => $contactId,
            'refundAmount' => $refundAmount,
            'refundMinorUnits' => $refundMinorUnits,
            'currency' => $currency,
            'voucherDate' => self::normaliseDate((string) ($request['voucherDate'] ?? '')),
        ];
    }

    /**
     * @param list<LineItem> $positions
     * @param array{refundMinorUnits: int} $correction
     * @return CorrectionResult|null
     */
    private function validatePositions(
        array $positions,
        array $correction,
        TaxDecision $taxDecision,
    ): ?array {
        if (
            !$taxDecision->allowed
            || $taxDecision->accountDatevId === null
            || self::numericId($taxDecision->accountDatevId) === null
            || $taxDecision->taxRuleId === null
            || self::numericId($taxDecision->taxRuleId) === null
        ) {
            return self::result(
                'blocked',
                'invalid_tax_decision',
                'The original invoice tax decision is not allowed or complete.',
            );
        }
        if ($this->requireReceiptGuidance && !$taxDecision->guidanceValidated) {
            return self::result(
                'blocked',
                'receipt_guidance_not_validated',
                'The correction tax profile must be validated by Receipt Guidance.',
            );
        }
        if ($positions === []) {
            return self::result(
                'blocked',
                'correction_positions_required',
                'At least one explicit refund position is required.',
            );
        }

        $netMode = null;
        $grossMinor = 0;
        foreach ($positions as $position) {
            if (!$position instanceof LineItem) {
                return self::result(
                    'blocked',
                    'invalid_correction_position',
                    'Every correction position must be a LineItem.',
                );
            }
            if (Decimal::toMinorUnits($position->amount) <= 0) {
                return self::result(
                    'blocked',
                    'non_positive_correction_position',
                    'Correction input positions must use positive amounts.',
                );
            }
            if ($netMode !== null && $netMode !== $position->net) {
                return self::result(
                    'blocked',
                    'mixed_net_gross_modes',
                    'All correction positions must consistently use net or gross amounts.',
                );
            }
            $netMode = $position->net;

            if ($taxDecision->guidanceValidated) {
                try {
                    $allowed = $this->taxRateIsAllowed($position->taxRate, $taxDecision->allowedTaxRates);
                } catch (\InvalidArgumentException) {
                    return self::result(
                        'blocked',
                        'invalid_receipt_guidance',
                        'Receipt Guidance returned an invalid tax rate.',
                    );
                }
                if (!$allowed) {
                    return self::result(
                        'blocked',
                        'unsupported_tax_rate',
                        'A correction position uses a tax rate not allowed by Receipt Guidance.',
                    );
                }
            }

            $grossMinor += $position->grossMinorUnits();
        }

        if (abs($grossMinor - $correction['refundMinorUnits']) > 1) {
            return self::result(
                'blocked',
                'correction_total_mismatch',
                'The explicit correction positions do not match the confirmed refund amount.',
                context: [
                    'refundMinorUnits' => $correction['refundMinorUnits'],
                    'positionMinorUnits' => $grossMinor,
                ],
            );
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function findRemoteMarkers(string $refundMarker): array
    {
        $response = $this->client->get('/Voucher', [
            'descriptionLike' => $refundMarker,
            'creditDebit' => 'D',
            'limit' => 101,
        ]);

        return array_values(array_filter(
            self::records($response, 'voucher'),
            static fn (array $candidate): bool => str_contains(
                (string) ($candidate['description'] ?? ''),
                $refundMarker,
            ),
        ));
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array{
     *     invoiceId: int,
     *     originalVoucherId: string,
     *     contactId: string,
     *     refundMinorUnits: int,
     *     currency: string
     * } $correction
     */
    private function remoteCandidateMatches(
        array $candidate,
        array $correction,
        TaxDecision $taxDecision,
        string $refundMarker,
    ): bool {
        $description = (string) ($candidate['description'] ?? '');
        $supplierId = self::numericId($candidate['supplier']['id'] ?? $candidate['contact']['id'] ?? null);
        $taxRuleId = self::numericId($candidate['taxRule']['id'] ?? null);
        if (
            !str_contains($description, $refundMarker)
            || !str_contains($description, VoucherExporter::marker($correction['invoiceId']))
            || !str_contains($description, self::originalVoucherMarker($correction['originalVoucherId']))
            || strtoupper((string) ($candidate['currency'] ?? '')) !== $correction['currency']
            || (string) ($candidate['creditDebit'] ?? '') !== 'D'
            || $supplierId !== $correction['contactId']
            || $taxRuleId !== $taxDecision->taxRuleId
        ) {
            return false;
        }

        $gross = $candidate['sumGross'] ?? $candidate['total'] ?? null;
        if (!is_int($gross) && !is_float($gross) && !is_string($gross)) {
            return false;
        }
        try {
            return Decimal::toMinorUnits((string) $gross) === -$correction['refundMinorUnits'];
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $voucher
     * @param array{
     *     invoiceId: int,
     *     originalVoucherId: string,
     *     contactId: string,
     *     refundMinorUnits: int,
     *     currency: string
     * } $correction
     */
    private function originalVoucherMatches(
        array $voucher,
        array $correction,
        TaxDecision $taxDecision,
    ): bool {
        $supplierId = self::numericId($voucher['supplier']['id'] ?? $voucher['contact']['id'] ?? null);
        $taxRuleId = self::numericId($voucher['taxRule']['id'] ?? null);
        $gross = $voucher['sumGross'] ?? null;
        if (!is_int($gross) && !is_float($gross) && !is_string($gross)) {
            return false;
        }
        try {
            $grossMinor = Decimal::toMinorUnits((string) $gross);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return self::numericId($voucher['id'] ?? null) === $correction['originalVoucherId']
            && $supplierId === $correction['contactId']
            && strtoupper((string) ($voucher['currency'] ?? '')) === $correction['currency']
            && (string) ($voucher['creditDebit'] ?? '') === 'D'
            && $taxRuleId === $taxDecision->taxRuleId
            && $grossMinor > 0
            && $correction['refundMinorUnits'] <= $grossMinor;
    }

    /** @return array<string, mixed> */
    private function readOne(string $path, string $nestedKey): array
    {
        $records = self::records($this->client->get($path), $nestedKey);
        if (count($records) !== 1) {
            throw new ApiException(
                'sevdesk returned no unique object for a required correction read.',
                null,
                'unexpected_correction_response',
            );
        }

        return $records[0];
    }

    /**
     * @param array<array-key, mixed> $response
     * @return list<array<string, mixed>>
     */
    private static function records(array $response, string $nestedKey): array
    {
        if ($response === []) {
            return [];
        }
        $records = array_is_list($response) ? $response : [$response];
        $result = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (isset($record[$nestedKey]) && is_array($record[$nestedKey])) {
                $record = $record[$nestedKey];
            }
            $result[] = $record;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>
     */
    private static function unwrapVoucher(array $response): array
    {
        if (isset($response['voucher']) && is_array($response['voucher'])) {
            return $response['voucher'];
        }
        if (array_is_list($response) && count($response) === 1 && is_array($response[0])) {
            return self::unwrapVoucher($response[0]);
        }

        return $response;
    }

    /** @return CorrectionResult|null */
    private function persist(string $reference, string $remoteId, bool $reconciled): ?array
    {
        try {
            $persisted = ($this->persistReference)($reference, $remoteId);
            if ($persisted === false) {
                throw new \RuntimeException('Correction mapping callback returned false.');
            }
        } catch (Throwable) {
            return self::result(
                'ambiguous',
                $reconciled ? 'correction_reconciliation_persist_failed' : 'correction_mapping_persist_failed',
                $reconciled
                    ? 'The correction voucher was found, but its local reference could not be stored.'
                    : 'The correction voucher exists, but its local reference could not be stored.',
                $remoteId,
                $reference,
            );
        }

        return null;
    }

    /**
     * @param null|Closure(string, array<string, scalar>): (void|bool) $checkpoint
     * @param array<string, scalar> $context
     */
    private function emitCheckpoint(?Closure $checkpoint, string $name, array $context): bool
    {
        if ($checkpoint === null) {
            return true;
        }
        try {
            return $checkpoint($name, $context) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return CorrectionResult */
    private function apiFailure(ApiException $exception, string $code, string $reference): array
    {
        $ambiguous = $exception->outcomeUnknown;

        return self::result(
            $ambiguous ? 'ambiguous' : 'failed',
            $ambiguous ? $code . '_ambiguous' : $code,
            $ambiguous
                ? 'The correction write outcome is unknown. Search by refund marker before any retry.'
                : 'The sevdesk correction operation could not be completed.',
            dedupeReference: $reference,
            context: $exception->context(),
        );
    }

    /** @param list<string> $allowedRates */
    private function taxRateIsAllowed(string $taxRate, array $allowedRates): bool
    {
        $expected = round(Decimal::toFloat($taxRate), 4);
        foreach ($allowedRates as $allowedRate) {
            if (round(Decimal::toFloat($allowedRate), 4) === $expected) {
                return true;
            }
        }

        return false;
    }

    private static function normaliseDate(string $date): string
    {
        $date = trim($date);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new \InvalidArgumentException('The correction date must use YYYY-MM-DD.');
        }

        return $date;
    }

    private static function apiDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('d.m.Y') : $date;
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $id) === 1 ? $id : null;
    }

    private static function payloadId(string $id): int|string
    {
        return ctype_digit($id) && strlen($id) < 19 ? (int) $id : $id;
    }

    /**
     * @param 'succeeded'|'skipped'|'blocked'|'failed'|'ambiguous' $status
     * @param array<string, scalar|null> $context
     * @return CorrectionResult
     */
    private static function result(
        string $status,
        string $code,
        string $message,
        ?string $remoteId = null,
        ?string $dedupeReference = null,
        array $context = [],
    ): array {
        $result = ['status' => $status, 'code' => $code, 'message' => $message];
        if ($remoteId !== null) {
            $result['remoteId'] = $remoteId;
        }
        if ($dedupeReference !== null) {
            $result['dedupeReference'] = $dedupeReference;
        }
        if ($context !== []) {
            $result['context'] = $context;
        }

        return $result;
    }
}
