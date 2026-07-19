<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use DateTimeImmutable;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/** Restores one typed Invoice mapping through reads only; it never creates a document. */
final class InvoiceReconciliationService
{
    /** @var Closure(int): (int|string|null) */
    private readonly Closure $findMapping;

    /** @var Closure(int, string, string, string, bool=, string|null=): (bool|null) */
    private readonly Closure $persistMapping;

    private readonly InvoiceRemoteVerifier $remoteVerifier;

    private readonly InvoiceXml $invoiceXml;

    public function __construct(
        private readonly SevdeskClient $client,
        callable $findMapping,
        callable $persistMapping,
        private readonly string $sevUserId,
        private readonly string $unityId,
    ) {
        $this->findMapping = Closure::fromCallable($findMapping);
        $this->persistMapping = Closure::fromCallable($persistMapping);
        $this->remoteVerifier = new InvoiceRemoteVerifier($sevUserId, $unityId);
        $this->invoiceXml = new InvoiceXml($client);
    }

    public function withReferences(string $sevUserId, string $unityId): self
    {
        if ($sevUserId === $this->sevUserId && $unityId === $this->unityId) {
            return $this;
        }

        return new self(
            $this->client,
            $this->findMapping,
            $this->persistMapping,
            $sevUserId,
            $unityId,
        );
    }

    /**
     * Conservative read-only guard for historical backfills.
     *
     * Any object returned for the final Invoice number, the same date/contact/
     * amount tuple, a markerless Voucher candidate using the Invoice number or
     * the WHMCS Voucher marker blocks creation. The guard does not attempt to
     * turn a possible duplicate into a local mapping.
     */
    public function historicalDuplicateRisk(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        string $deliveryCountryCode,
    ): ExportResult {
        $deliveryCountryCode = strtoupper(trim($deliveryCountryCode));
        if (
            self::numericId($sevdeskContactId) === null
            || !$taxDecision->allowed
            || $taxDecision->taxRuleId === null
            || preg_match('/^[A-Z]{2}$/', $deliveryCountryCode) !== 1
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'historical_duplicate_guard_input_invalid',
                'The historical duplicate guard requires a frozen valid Invoice context.',
            );
        }

        try {
            $invoiceCandidates = $this->client->get('/Invoice', [
                'invoiceNumber' => $invoice->invoiceNumber,
                'limit' => 1000,
                'offset' => 0,
            ]);
            if ($invoiceCandidates !== []) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'historical_remote_duplicate_possible',
                    'A sevdesk Invoice already uses the final WHMCS Invoice number.',
                    context: [
                        'invoiceCandidateCount' => self::responseCount($invoiceCandidates),
                        'contextCandidateCount' => 0,
                        'voucherCandidateCount' => 0,
                    ],
                );
            }

            $contextCandidates = $this->client->get('/Invoice', [
                'contact[id]' => $sevdeskContactId,
                'contact[objectName]' => 'Contact',
                'startDate' => $invoice->invoiceDate->setTime(0, 0)->getTimestamp(),
                'endDate' => $invoice->invoiceDate->setTime(23, 59, 59)->getTimestamp(),
                'limit' => 1000,
                'offset' => 0,
            ]);
            $contextRows = self::normaliseCandidates($contextCandidates);
            $contextMatches = array_values(array_filter(
                $contextRows,
                static fn (array $candidate): bool => self::matchesHistoricalContext(
                    $candidate,
                    $invoice,
                    $sevdeskContactId,
                ),
            ));
            if (count($contextRows) >= 1000 || $contextMatches !== []) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'historical_remote_duplicate_possible',
                    count($contextRows) >= 1000
                        ? 'The sevdesk Invoice duplicate search reached its safety limit.'
                        : 'A sevdesk Invoice has the same date, contact and amount as the WHMCS Invoice.',
                    context: [
                        'invoiceCandidateCount' => 0,
                        'contextCandidateCount' => count($contextMatches),
                        'contextSearchTruncated' => count($contextRows) >= 1000,
                        'voucherCandidateCount' => 0,
                    ],
                );
            }

            $amountWindow = self::amountWindow($invoice->total);
            $markerlessVoucherCandidates = $this->client->get('/Voucher', [
                'descriptionLike' => $invoice->invoiceNumber,
                'contact[id]' => $sevdeskContactId,
                'contact[objectName]' => 'Contact',
                'startDate' => $invoice->invoiceDate->setTime(0, 0)->getTimestamp(),
                'endDate' => $invoice->invoiceDate->setTime(23, 59, 59)->getTimestamp(),
                'startAmount' => $amountWindow['lower'],
                'endAmount' => $amountWindow['upper'],
                'limit' => 1000,
                'offset' => 0,
            ]);
            if ($markerlessVoucherCandidates !== []) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'historical_remote_duplicate_possible',
                    'A markerless sevdesk Voucher may already represent this WHMCS Invoice.',
                    context: [
                        'invoiceCandidateCount' => 0,
                        'contextCandidateCount' => 0,
                        'markerlessVoucherCandidateCount' => self::responseCount($markerlessVoucherCandidates),
                        'voucherCandidateCount' => 0,
                    ],
                );
            }

            $voucherCandidates = $this->client->get('/Voucher', [
                'descriptionLike' => VoucherExporter::marker($invoice->invoiceId),
                'limit' => 1000,
                'offset' => 0,
            ]);
        } catch (ApiException $exception) {
            return ExportResult::failed(
                $invoice->invoiceId,
                $exception->isAuthenticationFailure()
                    ? 'api_authentication_failed'
                    : 'historical_duplicate_guard_lookup_failed',
                'The read-only historical duplicate check could not be completed.',
                $exception->context(),
            );
        }

        if ($voucherCandidates !== []) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'historical_remote_duplicate_possible',
                'A sevdesk Voucher already carries the WHMCS Invoice marker.',
                context: [
                    'invoiceCandidateCount' => 0,
                    'contextCandidateCount' => 0,
                    'voucherCandidateCount' => self::responseCount($voucherCandidates),
                ],
            );
        }

        return ExportResult::succeeded(
            $invoice->invoiceId,
            null,
            'invoice_duplicate_guard_clear',
            'No Invoice-number or Voucher-marker collision was found.',
            [
                'invoiceCandidateCount' => 0,
                'contextCandidateCount' => 0,
                'markerlessVoucherCandidateCount' => 0,
                'voucherCandidateCount' => 0,
            ],
        );
    }

    /** @return array{lower:string,upper:string} */
    private static function amountWindow(string $amount): array
    {
        $numeric = (float) $amount;

        return [
            'lower' => number_format(max(0.0, $numeric - 0.005), 3, '.', ''),
            'upper' => number_format($numeric + 0.005, 3, '.', ''),
        ];
    }

    public function reconcile(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        string $deliveryCountryCode,
        ?string $knownRemoteId = null,
        ?callable $checkpoint = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ExportResult {
        $checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);

        try {
            $mapping = ($this->findMapping)($invoice->invoiceId);
        } catch (Throwable) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'mapping_lookup_failed',
                'The existing sevdesk mapping could not be checked.',
            );
        }
        if ($mapping !== null && trim((string) $mapping) !== '') {
            return ExportResult::skipped($invoice->invoiceId, (string) $mapping);
        }

        if (
            self::numericId($sevdeskContactId) === null
            || self::numericId($this->sevUserId) === null
            || self::numericId($this->unityId) === null
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_invoice_reference',
                'Valid sevdesk Contact, SevUser and Unity IDs are required for Invoice reconciliation.',
            );
        }
        $deliveryCountryCode = strtoupper(trim($deliveryCountryCode));
        if (preg_match('/^[A-Z]{2}$/', $deliveryCountryCode) !== 1) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_delivery_country',
                'A two-letter delivery country is required for Invoice reconciliation.',
            );
        }
        if (
            !$taxDecision->allowed
            || $taxDecision->taxRuleId === null
            || preg_match('/^\d+$/', $taxDecision->taxRuleId) !== 1
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_tax_decision',
                'A valid allowed tax decision is required for Invoice reconciliation.',
            );
        }
        if (
            $eInvoiceContext !== null
            && (
                $taxDecision->taxRuleId !== '1'
                || $deliveryCountryCode !== 'DE'
                || $sevdeskContactId !== $eInvoiceContext->contactId
                || $this->unityId !== $eInvoiceContext->unityId
            )
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'e_invoice_frozen_context_mismatch',
                'The current references differ from the frozen E-Invoice recovery context.',
            );
        }

        $searchTruncated = false;
        try {
            if ($knownRemoteId !== null) {
                if (self::numericId($knownRemoteId) === null) {
                    return ExportResult::ambiguous(
                        $invoice->invoiceId,
                        'reconciliation_invalid_known_id',
                        'The known sevdesk Invoice ID is invalid. Manual review is required.',
                    );
                }
                $candidates = self::normaliseCandidates(
                    $this->client->get('/Invoice/' . rawurlencode($knownRemoteId)),
                );
            } else {
                $candidates = self::normaliseCandidates($this->client->get('/Invoice', [
                    'invoiceNumber' => $invoice->invoiceNumber,
                    'contact[id]' => $sevdeskContactId,
                    'contact[objectName]' => 'Contact',
                    'startDate' => $invoice->invoiceDate->setTime(0, 0)->getTimestamp(),
                    'endDate' => $invoice->invoiceDate->setTime(23, 59, 59)->getTimestamp(),
                    'limit' => 1000,
                    'offset' => 0,
                ]));
                $searchTruncated = count($candidates) >= 1000;
            }
        } catch (ApiException $exception) {
            return ExportResult::failed(
                $invoice->invoiceId,
                $exception->isAuthenticationFailure()
                    ? 'api_authentication_failed'
                    : 'invoice_reconciliation_lookup_failed',
                'The read-only sevdesk Invoice lookup failed.',
                $exception->context(),
            );
        }

        if ($searchTruncated) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_reconciliation_search_truncated',
                'The read-only Invoice search reached the API page limit, so uniqueness cannot be proven.',
                context: ['candidateCount' => count($candidates)],
            );
        }

        $matches = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => self::matches(
                $candidate,
                $invoice,
                $sevdeskContactId,
                $taxDecision->taxRuleId ?? '',
                $deliveryCountryCode,
                $eInvoiceContext,
            ),
        ));

        if (count($matches) !== 1) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                count($matches) === 0
                    ? 'invoice_reconciliation_no_match'
                    : 'invoice_reconciliation_multiple_matches',
                count($matches) === 0
                    ? 'No exact matching sevdesk Invoice was found. Manual review is required.'
                    : 'Multiple exact matching sevdesk Invoices were found. Manual review is required.',
                context: ['matchCount' => count($matches)],
            );
        }

        $remoteId = self::numericId($matches[0]['id'] ?? null);
        if ($remoteId === null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_reconciliation_invalid_id',
                'The matching sevdesk Invoice has no usable ID.',
            );
        }

        try {
            $positions = $this->client->get(
                '/Invoice/' . rawurlencode($remoteId) . '/getPositions',
                ['limit' => 1000, 'offset' => 0],
            );
        } catch (ApiException $exception) {
            return $exception->isAuthenticationFailure()
                ? ExportResult::failed(
                    $invoice->invoiceId,
                    'api_authentication_failed',
                    'sevdesk rejected the credentials during read-only Invoice position reconciliation.',
                    $exception->context(),
                )
                : ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'invoice_reconciliation_position_lookup_failed',
                    'The matching Invoice was found, but its positions could not be proven by reads.',
                    $remoteId,
                    $exception->context(),
                );
        }
        $positionMismatch = $this->positionMismatch($positions, $invoice, $remoteId);
        if ($positionMismatch !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $positionMismatch,
                'The matching sevdesk Invoice positions do not exactly match the frozen WHMCS document.',
                $remoteId,
            );
        }

        $xmlSha256 = null;
        if ($eInvoiceContext !== null) {
            try {
                $xml = $this->invoiceXml->fetch($remoteId);
                $xmlSha256 = $xml['sha256'];
            } catch (ApiException $exception) {
                return $exception->isAuthenticationFailure()
                    ? ExportResult::failed(
                        $invoice->invoiceId,
                        'api_authentication_failed',
                        'sevdesk rejected the credentials during read-only E-Invoice XML reconciliation.',
                        $exception->context(),
                    )
                    : ExportResult::ambiguous(
                        $invoice->invoiceId,
                        'invoice_reconciliation_xml_lookup_failed',
                        'The matching E-Invoice was found, but its XML could not be proven by reads.',
                        $remoteId,
                        $exception->context(),
                    );
            } catch (Throwable) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'invoice_reconciliation_xml_invalid',
                    'The matching Invoice does not expose a structurally valid native E-Invoice XML.',
                    $remoteId,
                );
            }
            if (
                $eInvoiceContext->expectedXmlSha256 !== null
                && !hash_equals($eInvoiceContext->expectedXmlSha256, $xmlSha256)
            ) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'invoice_reconciliation_xml_hash_mismatch',
                    'The native E-Invoice XML differs from the frozen recovery hash.',
                    $remoteId,
                    array_merge(
                        $eInvoiceContext->frozenContext(),
                        ['observedXmlSha256' => $xmlSha256],
                    ),
                );
            }
        }

        try {
            $persisted = ($this->persistMapping)(
                $invoice->invoiceId,
                $remoteId,
                DocumentTargetDecision::DOCUMENT_INVOICE,
                $invoice->invoiceNumber,
                $eInvoiceContext !== null,
                $xmlSha256,
            );
            if ($persisted === false) {
                throw new \RuntimeException('Mapping callback returned false.');
            }
        } catch (Throwable) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'mapping_persist_failed',
                'The exact Invoice was found, but its typed mapping could not be stored.',
                $remoteId,
            );
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'mapping_persisted', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentNumber' => $invoice->invoiceNumber,
                ],
                $eInvoiceContext?->frozenContext($xmlSha256) ?? [
                    'isEInvoice' => false,
                    'xmlSha256' => null,
                ],
            ))
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_mapping',
                'The Invoice mapping was restored, but its checkpoint could not be stored.',
                $remoteId,
            );
        }

        return ExportResult::succeeded(
            $invoice->invoiceId,
            $remoteId,
            context: $eInvoiceContext?->frozenContext($xmlSha256) ?? ['isEInvoice' => false],
        );
    }

    /**
     * @param array<array-key, mixed> $response
     * @return list<array<array-key, mixed>>
     */
    private static function normaliseCandidates(array $response): array
    {
        if ($response === []) {
            return [];
        }
        if (!array_is_list($response)) {
            return [self::unwrapInvoice($response)];
        }

        return array_values(array_map(
            static fn (array $candidate): array => self::unwrapInvoice($candidate),
            array_filter($response, 'is_array'),
        ));
    }

    /** @param array<array-key, mixed> $response */
    private static function responseCount(array $response): int
    {
        return array_is_list($response) ? count($response) : 1;
    }

    /** @param array<array-key, mixed> $candidate */
    private static function matchesHistoricalContext(
        array $candidate,
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
    ): bool {
        $candidate = self::unwrapInvoice($candidate);
        if (
            (string) ($candidate['contact']['id'] ?? '') !== $sevdeskContactId
            || strtoupper(trim((string) ($candidate['currency'] ?? ''))) !== $invoice->currency
            || !self::sameHistoricalDate($candidate['invoiceDate'] ?? null, $invoice->invoiceDate)
        ) {
            return false;
        }

        $sumGross = $candidate['sumGross'] ?? null;
        if (!is_string($sumGross) && !is_int($sumGross) && !is_float($sumGross)) {
            return false;
        }

        try {
            return Decimal::toMinorUnits((string) $sumGross) === $invoice->totalMinorUnits();
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private static function sameHistoricalDate(mixed $value, DateTimeImmutable $expected): bool
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $parts) === 1) {
            return $parts[3] . '-' . $parts[2] . '-' . $parts[1] === $expected->format('Y-m-d');
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d') === $expected->format('Y-m-d');
        } catch (\Exception) {
            return false;
        }
    }

    /** @param array<array-key, mixed> $candidate */
    private function matches(
        array $candidate,
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        string $taxRuleId,
        string $deliveryCountryCode,
        ?EInvoiceContext $eInvoiceContext = null,
    ): bool {
        return $this->remoteVerifier->invoiceMismatch(
            $candidate,
            $invoice,
            $sevdeskContactId,
            $taxRuleId,
            100,
            deliveryCountryCode: $deliveryCountryCode,
            eInvoiceContext: $eInvoiceContext,
        ) === null;
    }

    /** @param array<array-key, mixed> $response */
    private function positionMismatch(array $response, InvoiceSnapshot $invoice, string $remoteId): ?string
    {
        $mismatch = $this->remoteVerifier->positionsMismatch(
            $response,
            $invoice,
            $remoteId,
            discardNonArrayListMembers: true,
        );

        return $mismatch === null ? null : 'invoice_reconciliation_' . $mismatch;
    }

    /**
     * @param array<array-key, mixed> $candidate
     * @return array<array-key, mixed>
     */
    private static function unwrapInvoice(array $candidate): array
    {
        return isset($candidate['invoice']) && is_array($candidate['invoice'])
            ? $candidate['invoice']
            : $candidate;
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
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
}
