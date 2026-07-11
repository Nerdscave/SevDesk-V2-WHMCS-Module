<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use DateTimeImmutable;
use WHMCS\Module\Addon\SevDesk\Jobs\ExportJobHandler;

/**
 * Fail-closed checks that are cheap enough to run on an admin invoice page.
 *
 * Tax and Receipt Guidance decisions remain in the worker. The quick action is
 * intentionally unavailable when the saved WHMCS data already proves that an
 * invoice needs the full single-export preflight.
 */
final class QuickExportGuard
{
    public const ALREADY_MAPPED = 'already_mapped';
    public const AMBIGUOUS_LEGACY = 'ambiguous_legacy';
    public const STATUS_BLOCKED = 'status_blocked';
    public const BEFORE_IMPORT_AFTER = 'before_import_after';
    public const CREDIT_REQUIRES_REVIEW = 'credit_requires_review';
    public const NON_POSITIVE_TOTAL = 'non_positive_total';
    public const FOREIGN_CURRENCY = 'foreign_currency';
    public const EMPTY_INVOICE = 'empty_invoice';
    public const NEGATIVE_LINE = 'negative_line';
    public const INVALID_CONFIGURATION = 'invalid_configuration';

    public static function blockReason(
        object $invoice,
        ?object $mapping,
        bool $onlyPaid,
        string $importAfter,
        bool $hasInvoiceItems,
        bool $hasNegativeLine,
    ): ?string {
        $remoteId = trim((string) ($mapping->sevdesk_id ?? ''));
        if ($mapping !== null && $remoteId !== '') {
            return self::ALREADY_MAPPED;
        }
        if ($mapping !== null) {
            return self::AMBIGUOUS_LEGACY;
        }
        if (!ExportJobHandler::statusIsExportable((string) ($invoice->status ?? ''), $onlyPaid)) {
            return self::STATUS_BLOCKED;
        }

        $start = DateTimeImmutable::createFromFormat('!d-m-Y', $importAfter);
        if (!$start instanceof DateTimeImmutable || $start->format('d-m-Y') !== $importAfter) {
            return self::INVALID_CONFIGURATION;
        }
        $invoiceDate = trim((string) ($invoice->date ?? ''));
        if ($invoiceDate === '' || $invoiceDate < $start->format('Y-m-d')) {
            return self::BEFORE_IMPORT_AFTER;
        }
        if ((float) ($invoice->credit ?? 0) > 0) {
            return self::CREDIT_REQUIRES_REVIEW;
        }
        if ((float) ($invoice->total ?? 0) <= 0) {
            return self::NON_POSITIVE_TOTAL;
        }
        if (strtoupper(trim((string) ($invoice->currencycode ?? ''))) !== 'EUR') {
            return self::FOREIGN_CURRENCY;
        }
        if (!$hasInvoiceItems) {
            return self::EMPTY_INVOICE;
        }
        if ($hasNegativeLine) {
            return self::NEGATIVE_LINE;
        }

        return null;
    }
}
