<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceItemNormalizationException;

/**
 * Separates WHMCS LateFee rows from the service invoice. The original rows
 * remain part of the immutable contract; only the accounting snapshot is
 * shortened.
 */
final class LateFeePolicy
{
    /**
     * @param array<string,mixed> $invoice
     * @return array{
     *     invoice:array<string,mixed>,
     *     lateFee:null|array{amountMinor:int,fingerprint:string,itemCount:int}
     * }
     */
    public static function split(array $invoice, bool $enabled): array
    {
        $rows = self::rows($invoice['items']['item'] ?? []);
        $serviceRows = [];
        $feeRows = [];
        $feeMinor = 0;
        foreach ($rows as $row) {
            if (strcasecmp(trim((string) ($row['type'] ?? '')), 'LateFee') !== 0) {
                $serviceRows[] = $row;
                continue;
            }
            if (!$enabled) {
                throw new InvoiceItemNormalizationException(
                    InvoiceItemExportPolicy::LATE_FEE_REQUIRES_REVIEW,
                    InvoiceItemExportPolicy::LATE_FEE_MESSAGE,
                );
            }
            $amountMinor = Decimal::toMinorUnits((string) ($row['amount'] ?? ''));
            if ($amountMinor <= 0 || self::truthy($row['taxed'] ?? false)) {
                throw new InvoiceItemNormalizationException(
                    'late_fee_structure_blocked',
                    'Nur positive, unbesteuerte WHMCS-LateFee-Positionen können getrennt verbucht werden.',
                );
            }
            if ($feeMinor > PHP_INT_MAX - $amountMinor) {
                throw new InvoiceItemNormalizationException(
                    'late_fee_amount_overflow',
                    'Die Summe der WHMCS-LateFee-Positionen liegt außerhalb des unterstützten Bereichs.',
                );
            }
            $feeMinor += $amountMinor;
            $feeRows[] = [
                'id' => self::identifier($row['id'] ?? null),
                'relid' => self::identifier($row['relid'] ?? null),
                'amountMinor' => $amountMinor,
                'descriptionHash' => hash('sha256', trim((string) ($row['description'] ?? ''))),
            ];
        }

        if ($feeRows === []) {
            return ['invoice' => $invoice, 'lateFee' => null];
        }
        if ($serviceRows === []) {
            throw new InvoiceItemNormalizationException(
                'late_fee_without_service_lines',
                'Eine Rechnung, die nur aus Mahngebühren besteht, benötigt eine manuelle Prüfung.',
            );
        }
        if (Decimal::toMinorUnits((string) ($invoice['credit'] ?? '0')) !== 0) {
            throw new InvoiceItemNormalizationException(
                'late_fee_with_credit_requires_review',
                'Late Fees und angewendetes WHMCS-Guthaben lassen sich nicht automatisch eindeutig aufteilen.',
            );
        }

        $subtotalMinor = Decimal::toMinorUnits((string) ($invoice['subtotal'] ?? ''));
        $cashMinor = Decimal::toMinorUnits((string) ($invoice['total'] ?? ''));
        if ($subtotalMinor < $feeMinor || $cashMinor < $feeMinor) {
            throw new InvoiceItemNormalizationException(
                'late_fee_total_mismatch',
                'Die Late-Fee-Summe stimmt nicht mit den WHMCS-Rechnungssummen überein.',
            );
        }

        $shortened = $invoice;
        $shortened['subtotal'] = Decimal::fromMinorUnits($subtotalMinor - $feeMinor);
        $shortened['total'] = Decimal::fromMinorUnits($cashMinor - $feeMinor);
        $shortened['items']['item'] = $serviceRows;

        return [
            'invoice' => $shortened,
            'lateFee' => [
                'amountMinor' => $feeMinor,
                'fingerprint' => hash('sha256', json_encode([
                    'version' => 'whmcs_late_fee_v1',
                    'items' => $feeRows,
                    'amountMinor' => $feeMinor,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'itemCount' => count($feeRows),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        if (isset($rows['id']) || isset($rows['description'])) {
            return [$rows];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvoiceItemNormalizationException(
                    'invalid_invoice_item_response',
                    'WHMCS lieferte eine strukturell unvollständige Rechnungsposition.',
                );
            }
        }

        return array_values($rows);
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1
            || (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true));
    }

    private static function identifier(mixed $value): string
    {
        return is_int($value) || is_string($value) ? trim((string) $value) : '';
    }
}
