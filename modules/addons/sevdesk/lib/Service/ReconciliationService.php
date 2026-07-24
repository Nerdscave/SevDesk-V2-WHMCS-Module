<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;

/** Repairs a lost local mapping without ever creating another remote voucher. */
final class ReconciliationService
{
    private const SEARCH_PAGE_SIZE = 100;
    private const MAX_SEARCH_CANDIDATES = 1000;

    private readonly VoucherRemoteVerifier $remoteVerifier;

    public function __construct(
        private readonly SevdeskClient $client,
        private readonly MappingRepository $mappings,
    ) {
        $this->remoteVerifier = new VoucherRemoteVerifier();
    }

    public function reconcile(
        InvoiceSnapshot $invoice,
        ?string $contactId = null,
        ?string $knownRemoteId = null,
        ?string $taxRuleId = null,
        ?string $accountDatevId = null,
    ): ExportResult {
        $mapping = $this->mappings->findCompleteByInvoice($invoice->invoiceId);
        if ($mapping !== null) {
            return ExportResult::skipped($invoice->invoiceId, (string) $mapping->sevdesk_id);
        }

        if (
            self::numericId($contactId) === null
            || self::numericId($taxRuleId) === null
            || self::numericId($accountDatevId) === null
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'voucher_reconciliation_context_missing',
                'The frozen Voucher contact, Tax Rule or account reference is missing. Manual review is required.',
                $knownRemoteId,
            );
        }

        try {
            if ($knownRemoteId !== null) {
                $remoteId = self::numericId($knownRemoteId);
                if ($remoteId === null) {
                    return ExportResult::ambiguous(
                        $invoice->invoiceId,
                        'reconciliation_invalid_known_id',
                        'The known sevdesk Voucher ID is invalid. Manual review is required.',
                    );
                }
            } else {
                $search = $this->markerCandidates($invoice);
                if ($search['invalid']) {
                    return ExportResult::ambiguous(
                        $invoice->invoiceId,
                        'reconciliation_search_invalid',
                        'The read-only Voucher marker search returned an invalid page.',
                    );
                }
                if ($search['truncated']) {
                    return ExportResult::ambiguous(
                        $invoice->invoiceId,
                        'reconciliation_search_truncated',
                        'The read-only Voucher marker search reached its safety limit, so uniqueness cannot be proven.',
                        context: ['candidateCount' => count($search['candidates'])],
                    );
                }

                $matches = array_values(array_filter(
                    $search['candidates'],
                    static fn (array $candidate): bool => VoucherRemoteVerifier::markerMatches(
                        (string) ($candidate['description'] ?? ''),
                        $invoice->invoiceId,
                    ),
                ));
                if (count($matches) !== 1) {
                    return ExportResult::ambiguous(
                        $invoice->invoiceId,
                        count($matches) === 0
                            ? 'reconciliation_no_match'
                            : 'reconciliation_multiple_matches',
                        count($matches) === 0
                            ? 'No matching sevdesk voucher was found. Manual review is required.'
                            : 'Multiple matching sevdesk vouchers were found. Manual review is required.',
                        context: ['matchCount' => count($matches)],
                    );
                }

                $remoteId = VoucherRemoteVerifier::remoteId($matches[0]);
                if ($remoteId === null) {
                    return ExportResult::ambiguous(
                        $invoice->invoiceId,
                        'reconciliation_invalid_id',
                        'The matching sevdesk voucher has no usable ID.',
                    );
                }
            }

            // Search results are never authoritative document snapshots. Always
            // read the exact object and its positions before restoring a mapping.
            $remote = $this->client->get('/Voucher/' . rawurlencode($remoteId));
        } catch (ApiException $exception) {
            return ExportResult::failed(
                $invoice->invoiceId,
                $exception->isAuthenticationFailure()
                    ? 'api_authentication_failed'
                    : 'reconciliation_lookup_failed',
                'The sevdesk reconciliation lookup failed.',
                $exception->context(),
            );
        }

        $mismatch = $this->remoteVerifier->voucherMismatch(
            $remote,
            $invoice,
            $contactId,
            $taxRuleId,
            $accountDatevId,
            100,
            $remoteId,
        );
        if ($mismatch !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'voucher_reconciliation_' . $mismatch,
                'The matching sevdesk Voucher does not exactly match the frozen WHMCS document.',
                $remoteId,
            );
        }

        try {
            $positions = $this->client->get('/VoucherPos', [
                'voucher[id]' => $remoteId,
                'voucher[objectName]' => 'Voucher',
                'limit' => 1000,
                'offset' => 0,
            ]);
        } catch (ApiException $exception) {
            return $exception->isAuthenticationFailure()
                ? ExportResult::failed(
                    $invoice->invoiceId,
                    'api_authentication_failed',
                    'sevdesk rejected the credentials during read-only Voucher position reconciliation.',
                    $exception->context(),
                )
                : ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'voucher_reconciliation_position_lookup_failed',
                    'The matching Voucher was found, but its positions could not be proven by reads.',
                    $remoteId,
                    $exception->context(),
                );
        }

        $positionMismatch = $this->remoteVerifier->positionsMismatch(
            $positions,
            $invoice,
            $remoteId,
            $accountDatevId,
        );
        if ($positionMismatch !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'voucher_reconciliation_' . $positionMismatch,
                'The matching sevdesk Voucher positions do not exactly match the frozen WHMCS document.',
                $remoteId,
            );
        }

        try {
            $this->mappings->linkDocument(
                $invoice->invoiceId,
                $remoteId,
                MappingRepository::DOCUMENT_TYPE_VOUCHER,
                $invoice->invoiceNumber,
                false,
                documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
            );
        } catch (Throwable) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'mapping_persist_failed',
                'The matching voucher was found but its mapping could not be stored.',
                $remoteId,
            );
        }

        return ExportResult::succeeded($invoice->invoiceId, $remoteId);
    }

    /**
     * @return array{
     *     candidates:list<array<array-key,mixed>>,
     *     truncated:bool,
     *     invalid:bool
     * }
     */
    private function markerCandidates(InvoiceSnapshot $invoice): array
    {
        $candidates = [];
        for ($offset = 0; $offset < self::MAX_SEARCH_CANDIDATES; $offset += self::SEARCH_PAGE_SIZE) {
            $page = self::normaliseSearchPage($this->client->get('/Voucher', [
                'descriptionLike' => VoucherExporter::marker($invoice->invoiceId),
                'creditDebit' => 'D',
                'startDate' => $invoice->invoiceDate->setTime(0, 0)->getTimestamp(),
                'endDate' => $invoice->invoiceDate->setTime(23, 59, 59)->getTimestamp(),
                'limit' => self::SEARCH_PAGE_SIZE,
                'offset' => $offset,
            ]));
            if ($page === null || count($page) > self::SEARCH_PAGE_SIZE) {
                return ['candidates' => $candidates, 'truncated' => false, 'invalid' => true];
            }

            array_push($candidates, ...$page);
            if (count($page) < self::SEARCH_PAGE_SIZE) {
                return ['candidates' => $candidates, 'truncated' => false, 'invalid' => false];
            }
        }

        // A completely full final page cannot prove whether another matching
        // object exists. Stop without restoring any mapping.
        return ['candidates' => $candidates, 'truncated' => true, 'invalid' => false];
    }

    /**
     * @param array<array-key, mixed> $response
     * @return list<array<array-key, mixed>>|null
     */
    private static function normaliseSearchPage(array $response): ?array
    {
        if ($response === []) {
            return [];
        }
        $rows = array_is_list($response) ? $response : [$response];
        $candidates = [];
        foreach ($rows as $candidate) {
            if (!is_array($candidate)) {
                return null;
            }
            $candidate = isset($candidate['voucher']) && is_array($candidate['voucher'])
                ? $candidate['voucher']
                : $candidate;
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }
}
