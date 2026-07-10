<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use DateTimeImmutable;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;

/** Repairs a lost local mapping without ever creating another remote voucher. */
final class ReconciliationService
{
    public function __construct(
        private readonly SevdeskClient $client,
        private readonly MappingRepository $mappings,
    ) {
    }

    public function reconcile(
        InvoiceSnapshot $invoice,
        ?string $contactId = null,
        ?string $knownRemoteId = null,
    ): ExportResult {
        $mapping = $this->mappings->findCompleteByInvoice($invoice->invoiceId);
        if ($mapping !== null) {
            return ExportResult::skipped($invoice->invoiceId, (string) $mapping->sevdesk_id);
        }

        try {
            if ($knownRemoteId !== null && preg_match('/^\d+$/', $knownRemoteId) === 1) {
                $response = $this->client->get('/Voucher/' . rawurlencode($knownRemoteId));
                $candidates = $this->normaliseCandidates($response);
            } else {
                $candidates = $this->normaliseCandidates($this->client->get('/Voucher', [
                    'descriptionLike' => VoucherExporter::marker($invoice->invoiceId),
                    'creditDebit' => 'D',
                    'startDate' => $invoice->invoiceDate->setTime(0, 0)->getTimestamp(),
                    'endDate' => $invoice->invoiceDate->setTime(23, 59, 59)->getTimestamp(),
                    'limit' => 100,
                ]));
            }
        } catch (ApiException $exception) {
            return ExportResult::failed(
                $invoice->invoiceId,
                $exception->isAuthenticationFailure() ? 'api_authentication_failed' : 'reconciliation_lookup_failed',
                'The sevdesk reconciliation lookup failed.',
                $exception->context(),
            );
        }

        $matches = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $this->matches($candidate, $invoice, $contactId),
        ));

        if (count($matches) !== 1) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                count($matches) === 0 ? 'reconciliation_no_match' : 'reconciliation_multiple_matches',
                count($matches) === 0
                    ? 'No matching sevdesk voucher was found. Manual review is required.'
                    : 'Multiple matching sevdesk vouchers were found. Manual review is required.',
                context: ['matchCount' => count($matches)],
            );
        }

        $remoteId = self::id($matches[0]);
        if ($remoteId === null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'reconciliation_invalid_id',
                'The matching sevdesk voucher has no usable ID.',
            );
        }

        try {
            $this->mappings->link($invoice->invoiceId, $remoteId);
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
     * @param array<array-key,mixed> $response
     * @return list<array<array-key, mixed>>
     */
    private function normaliseCandidates(array $response): array
    {
        if ($response === []) {
            return [];
        }
        if (!array_is_list($response)) {
            return [self::unwrapVoucher($response)];
        }

        return array_values(array_map(
            static fn (array $candidate): array => self::unwrapVoucher($candidate),
            array_filter($response, 'is_array'),
        ));
    }

    /** @param array<array-key, mixed> $candidate */
    private function matches(array $candidate, InvoiceSnapshot $invoice, ?string $contactId): bool
    {
        $description = (string) ($candidate['description'] ?? '');
        if (!str_contains($description, VoucherExporter::marker($invoice->invoiceId))) {
            return false;
        }

        if (strtoupper((string) ($candidate['currency'] ?? '')) !== $invoice->currency) {
            return false;
        }

        $amount = $candidate['sumGross'] ?? $candidate['total'] ?? $candidate['totalAmount'] ?? null;
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            return false;
        }
        try {
            if (abs(Decimal::toMinorUnits((string) $amount) - $invoice->totalMinorUnits()) > 1) {
                return false;
            }
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (!$this->sameDate($candidate['voucherDate'] ?? null, $invoice->invoiceDate)) {
            return false;
        }

        if ($contactId !== null && $contactId !== '') {
            $remoteContact = $candidate['supplier']['id']
                ?? $candidate['contact']['id']
                ?? null;
            if ((string) $remoteContact !== $contactId) {
                return false;
            }
        }

        return self::id($candidate) !== null;
    }

    private function sameDate(mixed $value, DateTimeImmutable $expected): bool
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return (new DateTimeImmutable('@' . $timestamp))
                    ->setTimezone($expected->getTimezone())
                    ->format('Y-m-d') === $expected->format('Y-m-d');
            }
        }
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        foreach (['!d.m.Y', '!Y-m-d', DATE_ATOM] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $expected->format('Y-m-d')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<array-key, mixed> $candidate */
    private static function id(array $candidate): ?string
    {
        $id = $candidate['id'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            return null;
        }
        $id = trim((string) $id);

        return preg_match('/^\d+$/', $id) === 1 ? $id : null;
    }

    /**
     * @param array<array-key, mixed> $candidate
     * @return array<array-key, mixed>
     */
    private static function unwrapVoucher(array $candidate): array
    {
        return isset($candidate['voucher']) && is_array($candidate['voucher'])
            ? $candidate['voucher']
            : $candidate;
    }
}
