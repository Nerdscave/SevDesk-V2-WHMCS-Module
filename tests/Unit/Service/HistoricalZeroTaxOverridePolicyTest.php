<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceDiscount;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Service\HistoricalZeroTaxOverridePolicy;

final class HistoricalZeroTaxOverridePolicyTest extends TestCase
{
    public function testEligibleAccountsRequireRevenueRuleSeventeenAndZeroPercent(): void
    {
        $accounts = HistoricalZeroTaxOverridePolicy::eligibleAccounts([
            [
                'accountDatevId' => 4120,
                'accountNumber' => '4120',
                'accountName' => 'Steuerfreie Erlöse Drittland',
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 17, 'taxRates' => ['ZERO']]],
            ],
            [
                'accountDatevId' => 4982,
                'accountNumber' => '4982',
                'accountName' => 'Sonstige steuerfreie Betriebseinnahmen',
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 4, 'taxRates' => ['ZERO']]],
            ],
            [
                'accountDatevId' => 8400,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 17, 'taxRates' => ['NINETEEN']]],
            ],
        ]);

        self::assertCount(1, $accounts);
        self::assertSame('4120', $accounts[0]['id']);
        self::assertTrue(HistoricalZeroTaxOverridePolicy::accountIsEligible(
            [[
                'accountDatevId' => 4120,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 17, 'taxRates' => ['ZERO']]],
            ]],
            '4120',
        ));
        self::assertFalse(HistoricalZeroTaxOverridePolicy::accountIsEligible([], '4120'));
    }

    public function testMalformedGuidanceFailsClosed(): void
    {
        self::assertSame([], HistoricalZeroTaxOverridePolicy::eligibleAccounts([
            [
                'accountDatevId' => 4120,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => ['broken'],
            ],
        ]));
    }

    public function testNarrowPaidHistoricalZeroTaxInvoiceIsEligible(): void
    {
        self::assertNull(HistoricalZeroTaxOverridePolicy::validateInvoice(
            $this->invoice(),
            'Paid',
            true,
            true,
            '2025-12-31',
        ));
    }

    public function testCreditDiscountPositiveTaxAndNonHistoricalCasesAreBlocked(): void
    {
        self::assertSame(
            'historical_zero_tax_override_requires_backfill',
            HistoricalZeroTaxOverridePolicy::validateInvoice(
                $this->invoice(),
                'Paid',
                false,
                true,
                '2025-12-31',
            ),
        );
        self::assertSame(
            'historical_zero_tax_override_requires_paid',
            HistoricalZeroTaxOverridePolicy::validateInvoice(
                $this->invoice(),
                'Unpaid',
                true,
                true,
                '2025-12-31',
            ),
        );
        self::assertSame(
            'historical_zero_tax_override_structure_blocked',
            HistoricalZeroTaxOverridePolicy::validateInvoice(
                $this->invoice(credit: '10.00'),
                'Paid',
                true,
                true,
                '2025-12-31',
            ),
        );
        self::assertSame(
            'historical_zero_tax_override_structure_blocked',
            HistoricalZeroTaxOverridePolicy::validateInvoice(
                $this->invoice(rate: '19', taxTotal: '19.00', total: '119.00'),
                'Paid',
                true,
                true,
                '2025-12-31',
            ),
        );
        self::assertSame(
            'historical_zero_tax_override_structure_blocked',
            HistoricalZeroTaxOverridePolicy::validateInvoice(
                $this->invoice(discount: true, total: '90.00'),
                'Paid',
                true,
                true,
                '2025-12-31',
            ),
        );
    }

    public function testOneZeroTaxDiscountCanUseTheSeparateHistoricalInvoiceContract(): void
    {
        self::assertNull(HistoricalZeroTaxOverridePolicy::validateDiscountInvoice(
            $this->invoice(discount: true, total: '90.00'),
            'Paid',
            true,
            true,
            '2025-12-31',
        ));

        self::assertSame(
            'historical_zero_tax_override_structure_blocked',
            HistoricalZeroTaxOverridePolicy::validateDiscountInvoice(
                $this->invoice(discount: false),
                'Paid',
                true,
                true,
                '2025-12-31',
            ),
        );
        self::assertSame(
            'historical_zero_tax_override_structure_blocked',
            HistoricalZeroTaxOverridePolicy::validateDiscountInvoice(
                $this->invoice(discount: true, credit: '1.00', total: '90.00'),
                'Paid',
                true,
                true,
                '2025-12-31',
            ),
        );
    }

    private function invoice(
        string $credit = '0.00',
        string $rate = '0',
        string $taxTotal = '0.00',
        string $total = '100.00',
        bool $discount = false,
    ): InvoiceSnapshot {
        $discounts = $discount
            ? [new InvoiceDiscount('Promotion', '10.00', '0', true, 7, true)]
            : [];

        return new InvoiceSnapshot(
            10,
            20,
            'INV-2025-0001',
            new DateTimeImmutable('2025-06-01'),
            'EUR',
            $total,
            $credit,
            [new LineItem('Hosting', '100.00', $rate, true)],
            $discounts,
            (string) ((float) $total - (float) $taxTotal),
            $taxTotal,
        );
    }
}
