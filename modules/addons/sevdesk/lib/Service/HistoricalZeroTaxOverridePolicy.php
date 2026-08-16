<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use DateTimeImmutable;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;

/**
 * Narrow operator override for historical, manually reclassified 0% documents.
 *
 * This is not a tax classification. It only provides a recoverable import path
 * when the current sevdesk tenant cannot finalise the historically correct
 * Rule 11 document. Positive invoices use a Voucher whose Rule 17 account is
 * authorised by current Receipt Guidance. The separate discount branch uses
 * a canary-gated Rule 17 Invoice because InvoicePos cannot carry accountDatev.
 */
final class HistoricalZeroTaxOverridePolicy
{
    public const PROFILE = 'historical_zero_tax_manual_override';

    public const TAX_RULE_ID = '17';

    public const VOUCHER_VERSION = 'rule17_voucher_v1';

    public const INVOICE_DISCOUNT_VERSION = 'rule17_invoice_discount_v1';

    /**
     * @param array<array-key,mixed> $guidance
     * @return list<array{id:string,accountNumber:string,name:string,description:string}>
     */
    public static function eligibleAccounts(array $guidance): array
    {
        if (isset($guidance['objects'])) {
            if (!is_array($guidance['objects'])) {
                return [];
            }
            $guidance = $guidance['objects'];
        }

        $accounts = [];
        foreach ($guidance as $row) {
            if (!is_array($row)) {
                return [];
            }
            $accountId = trim((string) ($row['accountDatevId'] ?? ''));
            $receiptTypes = $row['allowedReceiptTypes'] ?? null;
            $rules = $row['allowedTaxRules'] ?? null;
            if (
                preg_match('/^[1-9]\d*$/', $accountId) !== 1
                || !is_array($receiptTypes)
                || !is_array($rules)
            ) {
                continue;
            }

            $normalisedTypes = [];
            foreach ($receiptTypes as $receiptType) {
                if (!is_string($receiptType) && !is_int($receiptType)) {
                    return [];
                }
                $normalisedTypes[] = strtoupper(trim((string) $receiptType));
            }
            if (!in_array('REVENUE', $normalisedTypes, true)) {
                continue;
            }

            $allowsRuleSeventeenZero = false;
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    return [];
                }
                if (trim((string) ($rule['id'] ?? '')) !== self::TAX_RULE_ID) {
                    continue;
                }
                $rates = $rule['taxRates'] ?? null;
                if (!is_array($rates)) {
                    return [];
                }
                foreach ($rates as $rate) {
                    if (self::normalisedGuidanceRate($rate) === '0') {
                        $allowsRuleSeventeenZero = true;
                        break;
                    }
                }
            }
            if (!$allowsRuleSeventeenZero) {
                continue;
            }

            $name = trim((string) ($row['accountName'] ?? ''));
            $accounts[] = [
                'id' => $accountId,
                'accountNumber' => trim((string) ($row['accountNumber'] ?? '')),
                'name' => $name !== '' ? $name : 'Erlöskonto ' . $accountId,
                'description' => trim((string) ($row['description'] ?? '')),
            ];
        }

        usort(
            $accounts,
            static fn (array $left, array $right): int => strnatcmp(
                $left['accountNumber'] !== '' ? $left['accountNumber'] : $left['id'],
                $right['accountNumber'] !== '' ? $right['accountNumber'] : $right['id'],
            ),
        );

        return $accounts;
    }

    /** @param array<array-key,mixed> $guidance */
    public static function accountIsEligible(array $guidance, string $accountId): bool
    {
        $accountId = trim($accountId);
        foreach (self::eligibleAccounts($guidance) as $account) {
            if (hash_equals($account['id'], $accountId)) {
                return true;
            }
        }

        return false;
    }

    public static function validateInvoice(
        InvoiceSnapshot $invoice,
        string $status,
        bool $historicalBackfill,
        bool $smallBusinessPeriod,
        string $cutoff,
    ): ?string {
        if (!$historicalBackfill) {
            return 'historical_zero_tax_override_requires_backfill';
        }
        if ($status !== 'Paid') {
            return 'historical_zero_tax_override_requires_paid';
        }
        if (!$smallBusinessPeriod) {
            return 'historical_zero_tax_override_outside_small_business_period';
        }

        $cutoffDate = DateTimeImmutable::createFromFormat('!Y-m-d', trim($cutoff));
        if (
            !$cutoffDate instanceof DateTimeImmutable
            || $cutoffDate->format('Y-m-d') !== trim($cutoff)
            || DateTimeImmutable::getLastErrors() !== false
            || $invoice->invoiceDate > $cutoffDate
        ) {
            return 'historical_zero_tax_override_cutoff_invalid';
        }
        if (
            $invoice->currency !== 'EUR'
            || $invoice->totalMinorUnits() <= 0
            || $invoice->appliedCreditMinorUnits() !== 0
            || $invoice->discounts !== []
            || $invoice->expectedTaxMinorUnits() !== 0
            || $invoice->calculatedDocumentGrossMinorUnits() !== $invoice->totalMinorUnits()
        ) {
            return 'historical_zero_tax_override_structure_blocked';
        }
        foreach ($invoice->lineItems as $lineItem) {
            if (
                Decimal::toMinorUnits($lineItem->amount) <= 0
                || Decimal::toMinorUnits($lineItem->taxRate) !== 0
            ) {
                return 'historical_zero_tax_override_structure_blocked';
            }
        }

        return null;
    }

    public static function validateDiscountInvoice(
        InvoiceSnapshot $invoice,
        string $status,
        bool $historicalBackfill,
        bool $smallBusinessPeriod,
        string $cutoff,
    ): ?string {
        if (!$historicalBackfill) {
            return 'historical_zero_tax_override_requires_backfill';
        }
        if ($status !== 'Paid') {
            return 'historical_zero_tax_override_requires_paid';
        }
        if (!$smallBusinessPeriod) {
            return 'historical_zero_tax_override_outside_small_business_period';
        }

        $cutoffDate = DateTimeImmutable::createFromFormat('!Y-m-d', trim($cutoff));
        if (
            !$cutoffDate instanceof DateTimeImmutable
            || $cutoffDate->format('Y-m-d') !== trim($cutoff)
            || DateTimeImmutable::getLastErrors() !== false
            || $invoice->invoiceDate > $cutoffDate
        ) {
            return 'historical_zero_tax_override_cutoff_invalid';
        }
        if (
            $invoice->currency !== 'EUR'
            || $invoice->totalMinorUnits() <= 0
            || $invoice->appliedCreditMinorUnits() !== 0
            || count($invoice->discounts) !== 1
            || $invoice->expectedTaxMinorUnits() !== 0
            || $invoice->calculatedDocumentGrossMinorUnits() !== $invoice->totalMinorUnits()
        ) {
            return 'historical_zero_tax_override_structure_blocked';
        }
        foreach ($invoice->lineItems as $lineItem) {
            if (
                Decimal::toMinorUnits($lineItem->amount) <= 0
                || Decimal::toMinorUnits($lineItem->taxRate) !== 0
            ) {
                return 'historical_zero_tax_override_structure_blocked';
            }
        }

        $discount = $invoice->discounts[0];
        if (Decimal::toMinorUnits($discount->taxRate) !== 0) {
            return 'historical_zero_tax_override_structure_blocked';
        }

        return null;
    }

    private static function normalisedGuidanceRate(mixed $rate): ?string
    {
        if (is_string($rate)) {
            $named = strtoupper(trim($rate));
            if ($named === 'ZERO') {
                return '0';
            }
            if (is_numeric($rate)) {
                return Decimal::assert($rate, 'Receipt Guidance tax rate');
            }
        }
        if (is_int($rate) || is_float($rate)) {
            return Decimal::assert((string) $rate, 'Receipt Guidance tax rate');
        }
        if (is_array($rate)) {
            foreach (['value', 'rate', 'taxRate'] as $key) {
                if (array_key_exists($key, $rate)) {
                    return self::normalisedGuidanceRate($rate[$key]);
                }
            }
        }

        return null;
    }
}
