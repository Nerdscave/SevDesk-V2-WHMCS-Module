<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceDiscount;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceDiscountCapabilityPolicy;

final class InvoiceDiscountCapabilityPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string,string,bool,string}>
     */
    public static function supportedCapabilities(): iterable
    {
        yield 'Rule 11 / 0 percent' => ['11', '0', false, 'promo_discount_rule11_0_gross_v1'];
        yield 'Rule 1 / 19 percent' => [
            '1',
            '19',
            true,
            'promo_discount_v2_profile_domestic_country_domestic_rule_1_rate_1900_whmcs_exclusive',
        ];
        yield 'Rule 17 / 0 percent' => [
            '17',
            '0',
            false,
            'promo_discount_v2_profile_third_country_country_third_country_rule_17_rate_0_whmcs_inclusive',
        ];
        yield 'Rule 19 / destination VAT' => [
            '19',
            '20',
            true,
            'promo_discount_v2_profile_eu_b2c_rule19_country_eu_b2c_rule_19_rate_2000_whmcs_exclusive',
        ];
    }

    #[DataProvider('supportedCapabilities')]
    public function testOnlyExplicitlyConfirmedRuleRateCapabilitiesAreAllowed(
        string $taxRule,
        string $taxRate,
        bool $net,
        string $expectedKey,
    ): void {
        $policy = new InvoiceDiscountCapabilityPolicy(
            ruleElevenZeroConfirmed: true,
            ruleOneNineteenCapabilityKey: $taxRule === '1' ? $expectedKey : '',
            ruleSeventeenZeroCapabilityKey: $taxRule === '17' ? $expectedKey : '',
            ruleNineteenDestinationCapabilityKey: $taxRule === '19' ? $expectedKey : '',
        );
        $decision = $policy->evaluate(
            $this->invoice($taxRate, $net),
            $this->taxDecision($taxRule, $taxRate),
            $this->target($taxRule),
        );

        self::assertTrue($decision['allowed'], $decision['message']);
        self::assertSame($expectedKey, $decision['capabilityKey']);
    }

    public function testLegacyRule11CanaryDoesNotUnlockNewTaxRules(): void
    {
        $policy = new InvoiceDiscountCapabilityPolicy(ruleElevenZeroConfirmed: true);
        $decision = $policy->evaluate(
            $this->invoice('19', true),
            $this->taxDecision('1', '19'),
            $this->target('1'),
        );

        self::assertFalse($decision['allowed']);
        self::assertSame('invoice_discount_rule1_19_canary_not_confirmed', $decision['code']);
    }

    public function testLegacyBooleanAndDifferentWhmcsModeKeysNeverUnlockRuleOne(): void
    {
        $invoice = $this->invoice('19', true);
        $tax = $this->taxDecision('1', '19');
        $target = $this->target('1');

        foreach (
            [
                'on',
                InvoiceDiscountCapabilityPolicy::capabilityKey(
                    'domestic',
                    '1',
                    1900,
                    'inclusive',
                ),
            ] as $wrongKey
        ) {
            $decision = (new InvoiceDiscountCapabilityPolicy(
                ruleOneNineteenCapabilityKey: $wrongKey,
            ))->evaluate($invoice, $tax, $target);

            self::assertFalse($decision['allowed']);
            self::assertSame('invoice_discount_rule1_19_canary_not_confirmed', $decision['code']);
        }
    }

    public function testDomesticRuleOneCanaryDoesNotUnlockEuB2cDomesticProfile(): void
    {
        $decision = (new InvoiceDiscountCapabilityPolicy(
            ruleOneNineteenCapabilityKey: InvoiceDiscountCapabilityPolicy::capabilityKey(
                'domestic',
                '1',
                1900,
                'exclusive',
            ),
        ))->evaluate(
            $this->invoice('19', true),
            TaxDecision::allowInvoice('eu_b2c_domestic', '1', 'Legacy EU B2C.', ['19']),
            $this->target('1'),
        );

        self::assertFalse($decision['allowed']);
        self::assertSame(
            'invoice_discount_rule1_19_eu_b2c_domestic_canary_not_confirmed',
            $decision['code'],
        );
    }

    public function testEuB2cDomesticRuleOneRequiresItsOwnExactCapabilityKey(): void
    {
        $expectedKey = InvoiceDiscountCapabilityPolicy::capabilityKey(
            'eu_b2c_domestic',
            '1',
            1900,
            'exclusive',
        );
        $decision = (new InvoiceDiscountCapabilityPolicy(
            ruleOneNineteenEuB2cDomesticCapabilityKey: $expectedKey,
        ))->evaluate(
            $this->invoice('19', true),
            TaxDecision::allowInvoice('eu_b2c_domestic', '1', 'EU B2C mit deutscher USt.', ['19']),
            $this->target('1'),
        );

        self::assertTrue($decision['allowed'], $decision['message']);
        self::assertSame($expectedKey, $decision['capabilityKey']);
    }

    public function testEuB2cDomesticCanaryDoesNotUnlockDomesticRuleOneProfile(): void
    {
        $decision = (new InvoiceDiscountCapabilityPolicy(
            ruleOneNineteenEuB2cDomesticCapabilityKey: InvoiceDiscountCapabilityPolicy::capabilityKey(
                'eu_b2c_domestic',
                '1',
                1900,
                'exclusive',
            ),
        ))->evaluate(
            $this->invoice('19', true),
            TaxDecision::allowInvoice('domestic', '1', 'Inland.', ['19']),
            $this->target('1'),
        );

        self::assertFalse($decision['allowed']);
        self::assertSame('invoice_discount_rule1_19_canary_not_confirmed', $decision['code']);
    }

    public function testStoredCapabilityComparisonIsTrimmedAndExact(): void
    {
        $expectedKey = InvoiceDiscountCapabilityPolicy::capabilityKey(
            'eu_b2c_domestic',
            '1',
            1900,
            'exclusive',
        );

        self::assertTrue(InvoiceDiscountCapabilityPolicy::storedCapabilityMatches(
            " \n" . $expectedKey . "\t",
            $expectedKey,
        ));
        self::assertFalse(InvoiceDiscountCapabilityPolicy::storedCapabilityMatches('', $expectedKey));
        self::assertFalse(InvoiceDiscountCapabilityPolicy::storedCapabilityMatches(
            InvoiceDiscountCapabilityPolicy::capabilityKey(
                'eu_b2c_domestic',
                '1',
                1900,
                'inclusive',
            ),
            $expectedKey,
        ));
    }

    /**
     * @return iterable<string, array{string,string}>
     */
    public static function unsupportedRuleRates(): iterable
    {
        yield 'Rule 1 with zero percent' => ['1', '0'];
        yield 'Rule 1 with reduced rate' => ['1', '7'];
        yield 'Rule 17 with positive rate' => ['17', '19'];
        yield 'Rule 2' => ['2', '0'];
        yield 'Rule 19 with zero percent' => ['19', '0'];
    }

    #[DataProvider('unsupportedRuleRates')]
    public function testUnconfirmedRuleRatePairsStayBlocked(string $taxRule, string $taxRate): void
    {
        $decision = (new InvoiceDiscountCapabilityPolicy())->evaluate(
            $this->invoice($taxRate, $taxRate !== '0'),
            $this->taxDecision($taxRule, $taxRate),
            $this->target($taxRule),
        );

        self::assertFalse($decision['allowed']);
        self::assertSame('invoice_discount_tax_rule_not_supported', $decision['code']);
    }

    public function testRule19RequiresTheExactFrozenDestinationRate(): void
    {
        $decision = (new InvoiceDiscountCapabilityPolicy(
            ruleNineteenDestinationCapabilityKey: InvoiceDiscountCapabilityPolicy::capabilityKey(
                'eu_b2c_rule19',
                '19',
                2000,
                'exclusive',
            ),
        ))->evaluate(
            $this->invoice('20', true),
            TaxDecision::allowInvoiceRule19('eu_b2c_rule19', 'OSS.', ['21']),
            $this->target('19'),
        );

        self::assertFalse($decision['allowed']);
        self::assertSame('invoice_discount_oss_tax_rate_mismatch', $decision['code']);
    }

    public function testHybridAndEInvoiceDiscountsRemainBlocked(): void
    {
        $invoice = $this->invoice('20', true);
        $tax = TaxDecision::allowInvoiceRule19('eu_b2c_rule19', 'OSS.', ['20']);
        $hybridTarget = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
        ))->resolve($tax, true, true);
        $policy = new InvoiceDiscountCapabilityPolicy(
            ruleNineteenDestinationCapabilityKey: InvoiceDiscountCapabilityPolicy::capabilityKey(
                'eu_b2c_rule19',
                '19',
                2000,
                'exclusive',
            ),
        );

        self::assertSame(
            'invoice_discount_mode_not_supported',
            $policy->evaluate($invoice, $tax, $hybridTarget)['code'],
        );
        self::assertSame(
            'e_invoice_discount_not_supported',
            $policy->evaluate($invoice, $tax, $this->target('19'), true)['code'],
        );
    }

    private function invoice(string $taxRate, bool $net): InvoiceSnapshot
    {
        $lineAmount = $net
            ? '100.00'
            : match ($taxRate) {
                '19' => '119.00',
                '20' => '120.00',
                default => '100.00',
            };
        $discountAmount = $net
            ? '20.00'
            : match ($taxRate) {
                '19' => '23.80',
                '20' => '24.00',
                default => '20.00',
            };
        $gross = match ($taxRate) {
            '19' => '95.20',
            '20' => '96.00',
            default => '80.00',
        };
        $netTotal = '80.00';
        $taxTotal = match ($taxRate) {
            '19' => '15.20',
            '20' => '16.00',
            default => '0.00',
        };

        return new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            $gross,
            '0',
            [new LineItem('Hosting', $lineAmount, $taxRate, $net)],
            [new InvoiceDiscount('Promotion', $discountAmount, $taxRate, $net, 42, $taxRate !== '0')],
            $netTotal,
            $taxTotal,
        );
    }

    private function taxDecision(string $taxRule, string $taxRate): TaxDecision
    {
        return $taxRule === '19'
            ? TaxDecision::allowInvoiceRule19('eu_b2c_rule19', 'OSS.', [$taxRate])
            : TaxDecision::allowInvoice(
                $taxRule === '1' ? 'domestic' : ($taxRule === '17' ? 'third_country' : 'profile'),
                $taxRule,
                'Profile.',
                [$taxRate],
            );
    }

    private function target(string $taxRule): DocumentTargetDecision
    {
        return (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            $taxRule === '19'
                ? DocumentTargetResolver::OSS_RULE_19_CONFIRMED
                : DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($this->taxDecision($taxRule, $taxRule === '19' ? '20' : '0'), true, true);
    }
}
