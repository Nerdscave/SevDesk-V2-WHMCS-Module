<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/** Restores one typed Invoice mapping through reads only; it never creates a document. */
final class InvoiceReconciliationService
{
    /** @var Closure(int): (int|string|null) */
    private readonly Closure $findMapping;

    /** @var Closure(int, string, string, string): (bool|null) */
    private readonly Closure $persistMapping;

    private readonly InvoiceRemoteVerifier $remoteVerifier;

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

    public function reconcile(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        string $deliveryCountryCode,
        ?string $knownRemoteId = null,
        ?callable $checkpoint = null,
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

        try {
            $persisted = ($this->persistMapping)(
                $invoice->invoiceId,
                $remoteId,
                DocumentTargetDecision::DOCUMENT_INVOICE,
                $invoice->invoiceNumber,
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
            !$this->emitCheckpoint($checkpoint, 'mapping_persisted', [
            'invoiceId' => $invoice->invoiceId,
            'remoteId' => $remoteId,
            'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
            'documentNumber' => $invoice->invoiceNumber,
            ])
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_mapping',
                'The Invoice mapping was restored, but its checkpoint could not be stored.',
                $remoteId,
            );
        }

        return ExportResult::succeeded($invoice->invoiceId, $remoteId);
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

    /** @param array<array-key, mixed> $candidate */
    private function matches(
        array $candidate,
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        string $taxRuleId,
        string $deliveryCountryCode,
    ): bool {
        return $this->remoteVerifier->invoiceMismatch(
            $candidate,
            $invoice,
            $sevdeskContactId,
            $taxRuleId,
            100,
            deliveryCountryCode: $deliveryCountryCode,
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
