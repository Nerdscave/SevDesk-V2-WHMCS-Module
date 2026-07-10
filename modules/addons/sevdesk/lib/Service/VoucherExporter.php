<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/** Creates an open revenue voucher and records a mapping only after validation. */
final class VoucherExporter
{
    /** @var Closure(int): (int|string|null) */
    private readonly Closure $findMapping;

    /** @var Closure(int, string): (bool|null) */
    private readonly Closure $persistMapping;

    /**
     * Collaborator signatures:
     *
     * - $findMapping(int $whmcsInvoiceId): int|string|null
     * - $persistMapping(int $whmcsInvoiceId, string $sevdeskVoucherId): bool|null
     *
     * The callbacks normally use mod_sevdesk. They are injected to keep this core
     * independent of Capsule and straightforward to contract-test.
     */
    public function __construct(
        private readonly SevdeskClient $client,
        callable $findMapping,
        callable $persistMapping,
        private readonly bool $requireReceiptGuidance = true,
    ) {
        $this->findMapping = Closure::fromCallable($findMapping);
        $this->persistMapping = Closure::fromCallable($persistMapping);
    }

    public function export(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        #[\SensitiveParameter]
        string $pdfContents,
        ?callable $checkpoint = null,
        bool $creditTreatmentConfirmed = false,
    ): ExportResult {
        $checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);

        try {
            $existingMapping = ($this->findMapping)($invoice->invoiceId);
        } catch (Throwable) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'mapping_lookup_failed',
                'The existing sevdesk mapping could not be checked.',
            );
        }

        if ($existingMapping !== null && trim((string) $existingMapping) !== '') {
            return ExportResult::skipped($invoice->invoiceId, (string) $existingMapping);
        }

        $preflight = $this->preflight(
            $invoice,
            $sevdeskContactId,
            $taxDecision,
            $pdfContents,
            $creditTreatmentConfirmed,
        );
        if ($preflight !== null) {
            return $preflight;
        }

        if (
            !$this->emitCheckpoint(
                $checkpoint,
                'pdf_upload_requested',
                ['invoiceId' => $invoice->invoiceId],
            )
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'checkpoint_persist_failed',
                'The PDF upload checkpoint could not be stored.',
            );
        }

        try {
            $upload = $this->client->upload(
                '/Voucher/Factory/uploadTempFile',
                'whmcs-invoice-' . $invoice->invoiceId . '.pdf',
                $pdfContents,
            );
        } catch (ApiException $exception) {
            return $this->apiFailure($invoice->invoiceId, $exception, 'voucher_pdf_upload_failed');
        }

        $temporaryFileName = self::extractTemporaryFileName($upload);
        if ($temporaryFileName === null) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_upload_response',
                'sevdesk uploaded the PDF but did not return a usable temporary filename.',
            );
        }

        if (
            !$this->emitCheckpoint(
                $checkpoint,
                'pdf_uploaded',
                ['invoiceId' => $invoice->invoiceId],
            )
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'checkpoint_persist_failed',
                'The completed PDF upload checkpoint could not be stored.',
            );
        }

        // The PDF upload is safely repeatable. Re-check the authoritative mapping
        // immediately before the non-idempotent Voucher write to close the window
        // between initial preflight and the actual API request.
        try {
            $mappingBeforeWrite = ($this->findMapping)($invoice->invoiceId);
        } catch (Throwable) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'mapping_recheck_failed',
                'The sevdesk mapping could not be rechecked before voucher creation.',
            );
        }
        if ($mappingBeforeWrite !== null && trim((string) $mappingBeforeWrite) !== '') {
            return ExportResult::skipped($invoice->invoiceId, (string) $mappingBeforeWrite);
        }

        if (
            !$this->emitCheckpoint(
                $checkpoint,
                'voucher_write_requested',
                ['invoiceId' => $invoice->invoiceId],
            )
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'checkpoint_persist_failed',
                'The voucher write checkpoint could not be stored.',
            );
        }

        try {
            $response = $this->client->post(
                '/Voucher/Factory/saveVoucher',
                $this->buildPayload($invoice, $sevdeskContactId, $taxDecision, $temporaryFileName),
                true,
                [201],
            );
        } catch (ApiException $exception) {
            return $this->apiFailure($invoice->invoiceId, $exception, 'voucher_create_failed');
        }

        $remoteId = self::extractVoucherId($response);
        if ($remoteId === null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'voucher_id_missing',
                'sevdesk accepted the voucher request but returned no voucher ID. Reconcile before retrying.',
            );
        }

        if (
            !$this->emitCheckpoint(
                $checkpoint,
                'voucher_created',
                ['invoiceId' => $invoice->invoiceId, 'remoteId' => $remoteId],
            )
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_create',
                'The voucher exists, but its creation checkpoint could not be stored.',
                $remoteId,
            );
        }

        $remoteGross = self::extractRemoteGross($response);
        if ($remoteGross === null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'remote_total_missing',
                'The created sevdesk voucher has no verifiable gross total. Reconcile before retrying.',
                $remoteId,
            );
        }

        try {
            $remoteMinor = Decimal::toMinorUnits($remoteGross);
        } catch (\InvalidArgumentException) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'remote_total_invalid',
                'The created sevdesk voucher returned an invalid gross total. Reconcile before retrying.',
                $remoteId,
            );
        }

        if (abs($remoteMinor - $invoice->totalMinorUnits()) > 1) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'remote_total_mismatch',
                'The created sevdesk voucher total differs from WHMCS. No mapping was written.',
                $remoteId,
                [
                    'expectedMinorUnits' => $invoice->totalMinorUnits(),
                    'remoteMinorUnits' => $remoteMinor,
                ],
            );
        }

        try {
            $persisted = ($this->persistMapping)($invoice->invoiceId, $remoteId);
            if ($persisted === false) {
                throw new \RuntimeException('Mapping callback returned false.');
            }
        } catch (Throwable) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'mapping_persist_failed',
                'The voucher exists in sevdesk, but its WHMCS mapping could not be stored.',
                $remoteId,
            );
        }

        if (
            !$this->emitCheckpoint(
                $checkpoint,
                'mapping_persisted',
                ['invoiceId' => $invoice->invoiceId, 'remoteId' => $remoteId],
            )
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_mapping',
                'Voucher and mapping exist, but the final checkpoint could not be stored.',
                $remoteId,
            );
        }

        return ExportResult::succeeded($invoice->invoiceId, $remoteId);
    }

    /**
     * Build the Update 2.0 voucher payload. No legacy accountingType or taxType
     * fields are sent.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        string $temporaryFileName,
    ): array {
        if (
            !$taxDecision->allowed
            || $taxDecision->accountDatevId === null
            || $taxDecision->taxRuleId === null
        ) {
            throw new \InvalidArgumentException('Cannot build a voucher with a blocked tax decision.');
        }

        $positions = array_map(
            static function (LineItem $lineItem) use ($taxDecision): array {
                $position = [
                    'objectName' => 'VoucherPos',
                    'mapAll' => true,
                    'accountDatev' => [
                        'id' => self::payloadId($taxDecision->accountDatevId ?? ''),
                        'objectName' => 'AccountDatev',
                    ],
                    'taxRate' => Decimal::toFloat($lineItem->taxRate),
                    'net' => $lineItem->net,
                    'comment' => substr($lineItem->description, 0, 255),
                ];
                $position[$lineItem->net ? 'sumNet' : 'sumGross'] = Decimal::toFloat($lineItem->amount);

                return $position;
            },
            $invoice->lineItems,
        );

        $invoiceLabel = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $invoice->invoiceNumber));
        $description = substr($invoiceLabel, 0, 120) . ' ' . self::marker($invoice->invoiceId);

        return [
            'voucher' => [
                'objectName' => 'Voucher',
                'mapAll' => true,
                'description' => $description,
                'currency' => $invoice->currency,
                'voucherDate' => $invoice->invoiceDate->format('d.m.Y'),
                'propertyForeignCurrencyDeadline' => $invoice->invoiceDate->format('d.m.Y'),
                'payDate' => null,
                'status' => 100,
                'taxRule' => [
                    'id' => self::payloadId($taxDecision->taxRuleId),
                    'objectName' => 'TaxRule',
                ],
                'creditDebit' => 'D',
                'voucherType' => 'VOU',
                'supplier' => [
                    'id' => self::payloadId($sevdeskContactId),
                    'objectName' => 'Contact',
                ],
            ],
            'filename' => $temporaryFileName,
            'voucherPosSave' => $positions,
            'voucherPosDelete' => null,
        ];
    }

    public static function marker(int $invoiceId): string
    {
        return '[WHMCS-INVOICE:' . $invoiceId . ']';
    }

    private function preflight(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        #[\SensitiveParameter]
        string $pdfContents,
        bool $creditTreatmentConfirmed,
    ): ?ExportResult {
        if ($invoice->currency !== 'EUR') {
            return ExportResult::failed(
                $invoice->invoiceId,
                'foreign_currency_requires_review',
                'Foreign-currency invoices require separate accounting approval before export.',
            );
        }

        if (!$taxDecision->allowed) {
            return ExportResult::failed(
                $invoice->invoiceId,
                $taxDecision->code,
                $taxDecision->message,
            );
        }

        if ($this->requireReceiptGuidance && !$taxDecision->guidanceValidated) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'receipt_guidance_not_validated',
                'The tax profile must be validated with sevdesk Receipt Guidance before export.',
            );
        }

        if (
            $taxDecision->accountDatevId === null
            || preg_match('/^\d+$/', $taxDecision->accountDatevId) !== 1
            || $taxDecision->taxRuleId === null
            || preg_match('/^\d+$/', $taxDecision->taxRuleId) !== 1
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_tax_profile',
                'The validated tax decision contains invalid sevdesk IDs.',
            );
        }

        if (preg_match('/^\d+$/', $sevdeskContactId) !== 1) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_contact_id',
                'The sevdesk contact ID is missing or invalid.',
            );
        }

        if ($invoice->appliedCreditMinorUnits() > 0 && !$creditTreatmentConfirmed) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'credit_applied_requires_review',
                'Invoices with applied WHMCS credit require individual review in version 2.0.',
                [
                    'invoiceGrossMinorUnits' => $invoice->totalMinorUnits(),
                    'creditMinorUnits' => $invoice->appliedCreditMinorUnits(),
                    'remainingMinorUnits' => $invoice->totalMinorUnits() - $invoice->appliedCreditMinorUnits(),
                ],
            );
        }

        if ($invoice->totalMinorUnits() <= 0) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'non_positive_total_requires_review',
                'Zero or negative invoices are not automatically exported.',
            );
        }

        if ($invoice->hasMixedNetModes()) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'mixed_net_gross_modes',
                'sevdesk requires all voucher positions to use the same net/gross mode.',
            );
        }

        foreach ($invoice->lineItems as $lineItem) {
            if (Decimal::toFloat($lineItem->amount) < 0) {
                return ExportResult::failed(
                    $invoice->invoiceId,
                    'negative_line_requires_review',
                    'Invoices with negative positions require individual review.',
                );
            }

            if (
                $taxDecision->guidanceValidated
                && !$this->taxRateIsAllowed($lineItem->taxRate, $taxDecision->allowedTaxRates)
            ) {
                return ExportResult::failed(
                    $invoice->invoiceId,
                    'unsupported_tax_rate',
                    'An invoice tax rate is not allowed by the validated sevdesk Receipt Guidance.',
                );
            }
        }

        if (abs($invoice->lineGrossMinorUnits() - $invoice->totalMinorUnits()) > 1) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invoice_total_mismatch',
                'The calculated line total differs from the WHMCS invoice total.',
                [
                    'invoiceMinorUnits' => $invoice->totalMinorUnits(),
                    'lineMinorUnits' => $invoice->lineGrossMinorUnits(),
                ],
            );
        }

        if ($pdfContents === '' || strpos(substr($pdfContents, 0, 1024), '%PDF-') === false) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_invoice_pdf',
                'WHMCS did not generate a valid, non-empty PDF.',
            );
        }

        return null;
    }

    private function apiFailure(int $invoiceId, ApiException $exception, string $code): ExportResult
    {
        if ($exception->outcomeUnknown) {
            return ExportResult::ambiguous(
                $invoiceId,
                $code . '_ambiguous',
                'The sevdesk write outcome is unknown. Reconcile before retrying.',
                null,
                $exception->context(),
            );
        }

        if ($exception->isAuthenticationFailure()) {
            $code = 'api_authentication_failed';
        } elseif ($exception->isRateLimit()) {
            $code = 'api_rate_limited';
        } elseif ($exception->isPermanentClientFailure()) {
            $code .= '_permanent';
        }

        return ExportResult::failed(
            $invoiceId,
            $code,
            'The sevdesk API operation failed.',
            $exception->context(),
        );
    }

    /** @param array<array-key, mixed> $response */
    private static function extractTemporaryFileName(array $response): ?string
    {
        $fileName = $response['filename'] ?? null;
        if (
            !is_string($fileName)
            || $fileName === ''
            || strlen($fileName) > 255
            || preg_match('/^[A-Za-z0-9._-]+$/', $fileName) !== 1
        ) {
            return null;
        }

        return $fileName;
    }

    /** @param array<array-key, mixed> $response */
    private static function extractVoucherId(array $response): ?string
    {
        $voucher = isset($response['voucher']) && is_array($response['voucher'])
            ? $response['voucher']
            : $response;
        $id = $voucher['id'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        $id = trim((string) $id);

        return $id !== '' && preg_match('/^\d+$/', $id) === 1 ? $id : null;
    }

    /** @param array<array-key, mixed> $response */
    private static function extractRemoteGross(array $response): ?string
    {
        $voucher = isset($response['voucher']) && is_array($response['voucher'])
            ? $response['voucher']
            : $response;
        $gross = $voucher['sumGross'] ?? $voucher['total'] ?? null;
        if (!is_string($gross) && !is_int($gross) && !is_float($gross)) {
            return null;
        }

        return (string) $gross;
    }

    private static function payloadId(string $id): int|string
    {
        return ctype_digit($id) && strlen($id) < 19 ? (int) $id : $id;
    }

    /**
     * @param Closure(string, array<string, scalar|null>): (bool|null)|null $checkpoint
     * @param array<string, scalar|null> $context
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
}
