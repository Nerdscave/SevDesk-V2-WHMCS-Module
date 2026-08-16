<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;

final class TaxPolicyTest extends TestCase
{
    public function testGermanStandardCaseUsesRuleOneAfterGuidanceValidation(): void
    {
        $decision = $this->policy()->decide('DE', false, null, false, false, [
            new LineItem('Service', '119', '19', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('1', $decision->taxRuleId);
        self::assertSame('domestic', $decision->profile);
        self::assertTrue($decision->guidanceValidated);
    }

    public function testDomesticZeroRateIsBlockedOutsideSmallBusinessPeriod(): void
    {
        $lines = [new LineItem('Service', '100', '0', false)];

        $voucher = $this->policy()->decide('DE', false, null, false, false, $lines);
        $invoice = $this->policy()->decideInvoice('DE', false, null, false, false, $lines);

        self::assertFalse($voucher->allowed);
        self::assertSame('domestic_zero_tax_rate_outside_small_business_period', $voucher->code);
        self::assertSame('domestic', $voucher->profile);
        self::assertFalse($invoice->allowed);
        self::assertSame('domestic_zero_tax_rate_outside_small_business_period', $invoice->code);
        self::assertSame('domestic', $invoice->profile);
    }

    public function testDomesticZeroRateUsesRuleElevenDuringSmallBusinessPeriod(): void
    {
        $lines = [new LineItem('Service', '100', '0', false)];

        $voucher = $this->policy()->decide('DE', false, null, true, false, $lines);
        $invoice = $this->policy()->decideInvoice('DE', false, null, true, false, $lines);

        self::assertTrue($voucher->allowed);
        self::assertSame('11', $voucher->taxRuleId);
        self::assertSame('small_business', $voucher->profile);
        self::assertTrue($invoice->allowed);
        self::assertSame('11', $invoice->taxRuleId);
        self::assertSame('small_business', $invoice->profile);
    }

    public function testLegacyEuB2cDomesticModeCannotReintroduceRuleOneAtZeroPercent(): void
    {
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED,
        );

        $voucher = $policy->decide('BE', false, null, false, false, [
            new LineItem('Digital service', '100', '0', false),
        ]);
        $invoice = $policy->decideInvoice('BE', false, null, false, false, [
            new LineItem('Digital service', '100', '0', false),
        ]);

        self::assertFalse($voucher->allowed);
        self::assertSame('domestic_zero_tax_rate_outside_small_business_period', $voucher->code);
        self::assertFalse($invoice->allowed);
        self::assertSame('domestic_zero_tax_rate_outside_small_business_period', $invoice->code);
    }

    #[DataProvider('domesticPositiveTaxRateProvider')]
    public function testDomesticSevenAndNineteenPercentRemainAllowedForVoucherAndInvoice(
        string $amount,
        string $taxRate,
    ): void {
        $lines = [new LineItem('Service', $amount, $taxRate, false)];

        $voucher = $this->policy()->decide('DE', false, null, false, false, $lines);
        $invoice = $this->policy()->decideInvoice('DE', false, null, false, false, $lines);

        self::assertTrue($voucher->allowed);
        self::assertSame('1', $voucher->taxRuleId);
        self::assertSame(['7', '19'], $voucher->allowedTaxRates);
        self::assertTrue($invoice->allowed);
        self::assertSame('1', $invoice->taxRuleId);
        self::assertSame(['7', '19'], $invoice->allowedTaxRates);
    }

    /** @return iterable<string, array{string,string}> */
    public static function domesticPositiveTaxRateProvider(): iterable
    {
        yield 'seven percent' => ['107', '7'];
        yield 'nineteen percent' => ['119', '19'];
    }

    public function testTaxExemptGermanCustomerIsNotSilentlyPutOnDomesticRuleOne(): void
    {
        $decision = $this->policy()->decide('DE', true, null, false, false, [
            new LineItem('Service', '100', '0', false),
        ]);

        self::assertFalse($decision->allowed);
        self::assertSame('unsupported_domestic_tax_exempt', $decision->code);
    }

    public function testAccountThatOnlyAllowsExportsRejectsDomesticRuleLocally(): void
    {
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            [[
                'accountDatevId' => 100,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 2, 'taxRates' => ['ZERO']]],
            ]],
        );

        $decision = $policy->decide('DE', false, null, false, false, [
            new LineItem('Service', '119', '19', false),
        ]);

        self::assertFalse($decision->allowed);
        self::assertSame('unsupported_receipt_guidance', $decision->code);
    }

    public function testGuidanceWithoutExplicitRevenueScopeNeverAuthorisesAVoucher(): void
    {
        foreach ([[], null, ['REVENUE', ['malformed']]] as $receiptTypes) {
            $guidance = $this->guidance();
            $guidance[0]['allowedReceiptTypes'] = $receiptTypes;
            $decision = (new TaxPolicy(
                $this->profiles(),
                TaxPolicy::EU_B2C_BLOCKED,
                $guidance,
            ))->decide('DE', false, null, false, false, [
                new LineItem('Service', '119', '19', false),
            ]);

            self::assertFalse($decision->allowed);
            self::assertSame('unsupported_receipt_guidance', $decision->code);
        }
    }


    public function testEuPrivateCustomerIsNeverClassifiedAsEuB2b(): void
    {
        $decision = $this->policy()->decide('NL', false, null, false, false, [
            new LineItem('Service', '119', '19', false),
        ]);

        self::assertFalse($decision->allowed);
        self::assertSame('unsupported_oss', $decision->code);
        self::assertNotSame('3', $decision->taxRuleId);
    }

    public function testEuB2bRequiresAnOrganisationBeforeConsideringVatEvidence(): void
    {
        $person = $this->policyWithConfirmedEuGoods()->decide('NL', true, 'NL123456789B01');
        self::assertSame('eu_b2b_organisation_required', $person->code);

        $missingVat = $this->policyWithConfirmedEuGoods()->decide(
            'NL',
            true,
            null,
            false,
            false,
            [],
            true,
        );
        self::assertSame('missing_vat_id', $missingVat->code);
    }

    public function testEuB2bHostingRemainsBlockedWithoutExplicitGoodsConfirmation(): void
    {
        $decision = $this->policy()->decide('NL', true, 'NL123456789B01', false, false, [
            new LineItem('Hosting service', '100', '0', false),
        ], true);

        self::assertFalse($decision->allowed);
        self::assertSame('unconfirmed_tax_profile', $decision->code);
    }

    public function testConfirmedIntraCommunityGoodsProfileUsesRuleThree(): void
    {
        $allowed = $this->policyWithConfirmedEuGoods()->decide('NL', true, 'NL123456789B01', false, false, [
            new LineItem('Goods shipment', '100', '0', false),
        ], true);
        self::assertTrue($allowed->allowed);
        self::assertSame('3', $allowed->taxRuleId);
        self::assertTrue($allowed->guidanceValidated);
    }

    public function testEuB2bRejectsPlaceholderAndCountryMismatchedVatIds(): void
    {
        foreach (['-', 'n/a', '0', 'DE123456789'] as $vatNumber) {
            $lines = [new LineItem('Goods shipment', '100', '0', false)];
            $voucher = $this->policyWithConfirmedEuGoods()->decide(
                'NL',
                true,
                $vatNumber,
                false,
                false,
                $lines,
                true,
            );
            $invoice = $this->policyWithConfirmedEuGoods()->decideInvoice(
                'NL',
                true,
                $vatNumber,
                false,
                false,
                $lines,
                true,
            );

            self::assertFalse($voucher->allowed, $vatNumber);
            self::assertSame('invalid_vat_id_evidence', $voucher->code, $vatNumber);
            self::assertFalse($invoice->allowed, $vatNumber);
            self::assertSame('invalid_vat_id_evidence', $invoice->code, $vatNumber);
        }
    }

    public function testGreekVatPrefixUsesTheEuElConvention(): void
    {
        $decision = $this->policyWithConfirmedEuGoods()->decide(
            'GR',
            true,
            'EL123456789',
            false,
            false,
            [new LineItem('Goods shipment', '100', '0', false)],
            true,
        );

        self::assertTrue($decision->allowed);
        self::assertSame('3', $decision->taxRuleId);
    }

    public function testEuB2bWithPositiveVatIsBlockedEvenIfGuidanceWouldAllowIt(): void
    {
        $guidance = $this->guidance();
        $guidance[1]['allowedTaxRules'][0]['taxRates'][] = 'NINETEEN';
        $profiles = $this->profiles();
        $profiles['eu_b2b']['confirmed'] = true;
        $decision = (new TaxPolicy($profiles, TaxPolicy::EU_B2C_BLOCKED, $guidance))->decide(
            'NL',
            true,
            'NL123456789B01',
            false,
            false,
            [new LineItem('Service', '119', '19', false)],
            true,
        );

        self::assertFalse($decision->allowed);
        self::assertSame('eu_b2b_tax_rate_mismatch', $decision->code);
    }

    public function testEuB2cDomesticModeRequiresExplicitOptInAndUsesRuleOne(): void
    {
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED,
            $this->guidance(),
        );

        $decision = $policy->decide('NL', false, null, false, false, [
            new LineItem('Service', '119', '19', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('1', $decision->taxRuleId);
        self::assertSame('eu_b2c_domestic', $decision->profile);
    }

    public function testUnconfirmedThirdCountryProfileIsBlocked(): void
    {
        $profiles = $this->profiles();
        $profiles['third_country']['confirmed'] = false;

        $decision = (new TaxPolicy($profiles))->decide('CH', false, null);

        self::assertFalse($decision->allowed);
        self::assertSame('unconfirmed_tax_profile', $decision->code);
    }

    public function testOssRulesCannotBeEnabledThroughAnotherProfile(): void
    {
        $profiles = $this->profiles();
        $profiles['third_country']['taxRule'] = '18';

        $decision = (new TaxPolicy($profiles))->decide('CH', false, null);

        self::assertFalse($decision->allowed);
        self::assertSame('unsupported_oss', $decision->code);
    }

    public function testConfirmedSmallBusinessProfileUsesRuleElevenAndZeroRate(): void
    {
        $decision = $this->policy()->decide('DE', false, null, true, false, [
            new LineItem('Service', '100', '0', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('11', $decision->taxRuleId);
        self::assertSame('small_business', $decision->profile);
        self::assertTrue($decision->guidanceValidated);
    }

    public function testHistoricalZeroTaxVoucherUsesOnlyCurrentRuleSeventeenRevenueGuidance(): void
    {
        $guidance = $this->guidance();
        $guidance[] = [
            'accountDatevId' => 4120,
            'allowedReceiptTypes' => ['REVENUE'],
            'allowedTaxRules' => [['id' => 17, 'taxRates' => ['ZERO']]],
        ];
        $decision = (new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            $guidance,
        ))->decideHistoricalZeroTaxVoucher('4120', [
            new LineItem('Historical service', '100', '0', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('historical_zero_tax_manual_override', $decision->profile);
        self::assertSame('17', $decision->taxRuleId);
        self::assertSame('4120', $decision->accountDatevId);
        self::assertTrue($decision->guidanceValidated);
    }

    public function testHistoricalZeroTaxVoucherRejectsMissingGuidanceAndPositiveTax(): void
    {
        $missingGuidance = (new TaxPolicy($this->profiles()))->decideHistoricalZeroTaxVoucher(
            '4120',
            [new LineItem('Historical service', '100', '0', false)],
        );
        $positiveTax = $this->policy()->decideHistoricalZeroTaxVoucher(
            '400',
            [new LineItem('Historical service', '119', '19', false)],
        );

        self::assertFalse($missingGuidance->allowed);
        self::assertSame('historical_zero_tax_override_guidance_missing', $missingGuidance->code);
        self::assertFalse($positiveTax->allowed);
        self::assertSame('historical_zero_tax_override_structure_blocked', $positiveTax->code);
    }

    public function testHistoricalZeroTaxDiscountInvoiceSelectsRuleSeventeenWithoutVoucherAccount(): void
    {
        $decision = $this->policy()->decideHistoricalZeroTaxDiscountInvoice([
            new LineItem('Historical service', '100', '0', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('third_country', $decision->profile);
        self::assertSame('17', $decision->taxRuleId);
        self::assertNull($decision->accountDatevId);
        self::assertFalse($decision->guidanceValidated);
        self::assertSame(['0'], $decision->allowedTaxRates);
        self::assertTrue(TaxPolicy::isThirdCountryCode('US'));
        self::assertFalse(TaxPolicy::isThirdCountryCode('DE'));
        self::assertFalse(TaxPolicy::isThirdCountryCode('FR'));
    }

    public function testVoucherRuleElevenCannotSelfAuthoriseAPositiveGuidanceRate(): void
    {
        $guidance = $this->guidance();
        $guidance[3]['allowedTaxRules'][0]['taxRates'] = ['NINETEEN'];

        $decision = (new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            $guidance,
        ))->decide('DE', false, null, true, false, [
            new LineItem('Service', '119', '19', false),
        ]);

        self::assertFalse($decision->allowed);
        self::assertSame('unsupported_tax_rate', $decision->code);
    }

    #[DataProvider('smallBusinessCustomerProvider')]
    public function testSmallBusinessPeriodPrecedesDestinationAndCustomerClassification(
        string $countryCode,
        bool $taxExempt,
        ?string $vatNumber,
        bool $isOrganisation,
    ): void {
        $lines = [new LineItem('Service', '100', '0', false)];

        $voucher = $this->policy()->decide(
            $countryCode,
            $taxExempt,
            $vatNumber,
            true,
            false,
            $lines,
            $isOrganisation,
        );
        $invoice = $this->policy()->decideInvoice(
            $countryCode,
            $taxExempt,
            $vatNumber,
            true,
            false,
            $lines,
            $isOrganisation,
        );

        self::assertTrue($voucher->allowed);
        self::assertSame('small_business', $voucher->profile);
        self::assertSame('11', $voucher->taxRuleId);
        self::assertTrue($invoice->allowed);
        self::assertSame('small_business', $invoice->profile);
        self::assertSame('11', $invoice->taxRuleId);
    }

    /** @return iterable<string, array{string,bool,string|null,bool}> */
    public static function smallBusinessCustomerProvider(): iterable
    {
        yield 'German private customer' => ['DE', false, null, false];
        yield 'EU private customer' => ['NL', false, null, false];
        yield 'EU organisation with VAT evidence' => ['NL', true, 'NL123456789B01', true];
        yield 'third-country private customer' => ['CH', false, null, false];
        yield 'third-country organisation' => ['US', true, 'US-TAX-ID', true];
    }

    public function testSmallBusinessPeriodPrecedesTheConfirmedAddFundsProfile(): void
    {
        $lines = [new LineItem('Account credit', '100', '0', false)];

        $voucher = $this->policy()->decide('DE', false, null, true, true, $lines);
        $invoice = $this->policy()->decideInvoice('DE', false, null, true, true, $lines);

        self::assertTrue($voucher->allowed);
        self::assertSame('small_business', $voucher->profile);
        self::assertSame('11', $voucher->taxRuleId);
        self::assertTrue($invoice->allowed);
        self::assertSame('small_business', $invoice->profile);
        self::assertSame('11', $invoice->taxRuleId);

        $voucherAfterCutoff = $this->policy()->decide('DE', false, null, false, true, $lines);
        $invoiceAfterCutoff = $this->policy()->decideInvoice('DE', false, null, false, true, $lines);

        self::assertFalse($voucherAfterCutoff->allowed);
        self::assertSame('add_funds', $voucherAfterCutoff->profile);
        self::assertSame('domestic_zero_tax_rate_outside_small_business_period', $voucherAfterCutoff->code);
        self::assertFalse($invoiceAfterCutoff->allowed);
        self::assertSame('add_funds', $invoiceAfterCutoff->profile);
        self::assertSame('domestic_zero_tax_rate_outside_small_business_period', $invoiceAfterCutoff->code);
    }

    public function testAddFundsCannotBypassTheRuleElevenInvoiceCanary(): void
    {
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            $this->guidance(),
        );

        $decision = $policy->decideInvoice(
            'DE',
            false,
            null,
            true,
            true,
            [new LineItem('Account credit', '100', '0', false)],
        );

        self::assertFalse($decision->allowed);
        self::assertSame('small_business', $decision->profile);
        self::assertSame('small_business_invoice_canary_not_confirmed', $decision->code);
    }

    public function testRuleElevenInvoiceNeedsItsOwnCanaryWithoutChangingTheVoucherDecision(): void
    {
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            $this->guidance(),
        );
        $lines = [new LineItem('Service', '100', '0', false)];

        $voucher = $policy->decide('DE', false, null, true, false, $lines);
        $invoice = $policy->decideInvoice('DE', false, null, true, false, $lines);

        self::assertTrue($voucher->allowed);
        self::assertSame('11', $voucher->taxRuleId);
        self::assertFalse($invoice->allowed);
        self::assertSame('small_business_invoice_canary_not_confirmed', $invoice->code);
    }

    public function testRuleElevenInvoiceNeedsCurrentRevenueGuidanceEvenWithTheCanary(): void
    {
        $guidance = $this->guidance();
        $guidance[3]['allowedReceiptTypes'] = ['REGULAR'];
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            $guidance,
            TaxPolicy::OSS_BLOCKED,
            true,
        );

        $decision = $policy->decideInvoice(
            'DE',
            false,
            null,
            true,
            false,
            [new LineItem('Service', '100', '0', false)],
        );

        self::assertFalse($decision->allowed);
        self::assertSame('invoice_rule11_tenant_scope_unsupported', $decision->code);
        self::assertFalse($policy->invoiceRuleElevenTenantScopeSupported());
    }

    public function testRuleElevenGuidanceCapabilityRequiresRevenueRuleAndZeroRate(): void
    {
        $guidance = $this->guidance();
        self::assertTrue(TaxPolicy::guidanceSupportsInvoiceRuleEleven($guidance));

        $guidance[3]['allowedTaxRules'][0]['taxRates'] = ['NINETEEN'];
        self::assertFalse(TaxPolicy::guidanceSupportsInvoiceRuleEleven($guidance));

        self::assertTrue(TaxPolicy::guidanceSupportsInvoiceRuleEleven([
            'objects' => $this->guidance(),
        ]));
    }

    public function testConfirmedThirdCountryExportProfileUsesItsExplicitRule(): void
    {
        $decision = $this->policy()->decide('CH', false, null, false, false, [
            new LineItem('Export', '100', '0', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('2', $decision->taxRuleId);
        self::assertSame('third_country', $decision->profile);
    }

    public function testInvoiceClassificationDoesNotRequireAccountDatevOrReceiptGuidance(): void
    {
        $profiles = $this->profiles();
        $profiles['domestic']['accountDatev'] = '';
        $policy = new TaxPolicy(
            $profiles,
            TaxPolicy::EU_B2C_BLOCKED,
            [[
                'accountDatevId' => 999,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 2, 'taxRates' => ['ZERO']]],
            ]],
        );

        $decision = $policy->decideInvoice('DE', false, null, false, false, [
            new LineItem('Service', '119', '19', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('1', $decision->taxRuleId);
        self::assertNull($decision->accountDatevId);
        self::assertFalse($decision->guidanceValidated);
        self::assertSame(['7', '19'], $decision->allowedTaxRates);
    }

    /** @param list<string> $expectedAllowedRates */
    #[DataProvider('normalInvoiceRuleRateProvider')]
    public function testNormalInvoiceRulesEnforceFixedTaxRateContracts(
        string $rule,
        string $actualRate,
        bool $expectedAllowed,
        array $expectedAllowedRates,
        ?string $expectedCode,
    ): void {
        $decision = $this->invoiceDecisionForRule($rule, $actualRate);

        self::assertSame($expectedAllowed, $decision->allowed);
        if ($expectedAllowed) {
            self::assertSame($rule, $decision->taxRuleId);
            self::assertSame($expectedAllowedRates, $decision->allowedTaxRates);
            return;
        }

        self::assertSame($expectedCode, $decision->code);
        self::assertSame([], $decision->allowedTaxRates);
    }

    /** @return iterable<string, array{string,string,bool,list<string>,?string}> */
    public static function normalInvoiceRuleRateProvider(): iterable
    {
        yield 'domestic rule 1 rejects zero percent outside small-business period' => [
            '1',
            '0',
            false,
            [],
            'domestic_zero_tax_rate_outside_small_business_period',
        ];
        foreach (['7', '19'] as $rate) {
            yield 'domestic rule 1 allows ' . $rate . ' percent' => ['1', $rate, true, ['7', '19'], null];
        }
        yield 'rule 1 rejects an unapproved domestic rate' => [
            '1',
            '5',
            false,
            [],
            'unsupported_invoice_tax_rate',
        ];

        foreach (['2', '3', '4', '5', '11', '17'] as $rule) {
            yield 'rule ' . $rule . ' allows zero percent' => [$rule, '0', true, ['0'], null];
            yield 'rule ' . $rule . ' rejects seven percent' => [
                $rule,
                '7',
                false,
                [],
                $rule === '3' ? 'eu_b2b_tax_rate_mismatch' : 'unsupported_invoice_tax_rate',
            ];
            yield 'rule ' . $rule . ' rejects nineteen percent' => [
                $rule,
                '19',
                false,
                [],
                $rule === '3' ? 'eu_b2b_tax_rate_mismatch' : 'unsupported_invoice_tax_rate',
            ];
        }
    }

    public function testInvoiceClassificationStillRequiresConfiguredTaxRule(): void
    {
        $profiles = $this->profiles();
        $profiles['domestic']['accountDatev'] = '';
        $profiles['domestic']['taxRule'] = '';

        $decision = (new TaxPolicy($profiles))->decideInvoice(
            'DE',
            false,
            null,
            false,
            false,
            [new LineItem('Service', '119', '19', false)],
        );

        self::assertFalse($decision->allowed);
        self::assertSame('incomplete_tax_profile', $decision->code);
    }

    public function testInvoiceRuleThreeKeepsOrganisationVatGoodsAndZeroRateGuards(): void
    {
        $unconfirmed = $this->policy()->decideInvoice(
            'NL',
            true,
            'NL123456789B01',
            false,
            false,
            [new LineItem('Goods shipment', '100', '0', false)],
            true,
        );
        self::assertSame('unconfirmed_tax_profile', $unconfirmed->code);

        $person = $this->policyWithConfirmedEuGoods()->decideInvoice(
            'NL',
            true,
            'NL123456789B01',
        );
        self::assertSame('eu_b2b_organisation_required', $person->code);

        $missingVat = $this->policyWithConfirmedEuGoods()->decideInvoice(
            'NL',
            true,
            null,
            false,
            false,
            [],
            true,
        );
        self::assertSame('missing_vat_id', $missingVat->code);

        $positiveRate = $this->policyWithConfirmedEuGoods()->decideInvoice(
            'NL',
            true,
            'NL123456789B01',
            false,
            false,
            [new LineItem('Goods shipment', '119', '19', false)],
            true,
        );
        self::assertSame('eu_b2b_tax_rate_mismatch', $positiveRate->code);

        $allowed = $this->policyWithConfirmedEuGoods()->decideInvoice(
            'NL',
            true,
            'NL123456789B01',
            false,
            false,
            [new LineItem('Goods shipment', '100', '0', false)],
            true,
        );
        self::assertTrue($allowed->allowed);
        self::assertSame('3', $allowed->taxRuleId);
        self::assertNull($allowed->accountDatevId);
        self::assertFalse($allowed->guidanceValidated);
    }

    #[DataProvider('blockedInvoiceRuleProvider')]
    public function testInvoiceClassificationBlocksUnreleasedRules(string $rule, string $expectedCode): void
    {
        $profiles = $this->profiles();
        $profiles['third_country']['taxRule'] = $rule;

        $decision = (new TaxPolicy($profiles))->decideInvoice(
            'CH',
            false,
            null,
            false,
            false,
            [new LineItem('Service', '100', '0', false)],
        );

        self::assertFalse($decision->allowed);
        self::assertSame($expectedCode, $decision->code);
    }

    /** @return iterable<string, array{string,string}> */
    public static function blockedInvoiceRuleProvider(): iterable
    {
        yield 'OSS rule 18' => ['18', 'unsupported_oss_rule'];
        yield 'OSS rule 20' => ['20', 'unsupported_oss_rule'];
        yield 'rule 21' => ['21', 'unsupported_invoice_tax_rule'];
        yield 'rule 19 outside confirmed OSS route' => ['19', 'oss_profile_not_confirmed'];
    }

    public function testConfirmedDigitalEuB2cInvoiceSelectsRuleNineteenWithActualRates(): void
    {
        $decision = (new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            null,
            TaxPolicy::OSS_RULE_19_CONFIRMED,
        ))->decideInvoice('BE', false, null, false, false, [
            new LineItem('Digital service', '121', '21', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('eu_b2c_rule19', $decision->profile);
        self::assertSame('19', $decision->taxRuleId);
        self::assertSame(['21'], $decision->allowedTaxRates);
        self::assertNull($decision->accountDatevId);
        self::assertFalse($decision->guidanceValidated);
    }

    public function testRuleNineteenBlocksZeroAndMixedDestinationRates(): void
    {
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            null,
            TaxPolicy::OSS_RULE_19_CONFIRMED,
        );

        $zero = $policy->decideInvoice('BE', false, null, false, false, [
            new LineItem('Digital service', '100', '0', false),
        ]);
        $mixed = $policy->decideInvoice('BE', false, null, false, false, [
            new LineItem('Digital service A', '100', '21', false),
            new LineItem('Digital service B', '100', '6', false),
        ]);

        self::assertFalse($zero->allowed);
        self::assertSame('oss_tax_rate_non_positive', $zero->code);
        self::assertFalse($mixed->allowed);
        self::assertSame('oss_mixed_tax_rates', $mixed->code);
    }

    public function testRuleNineteenNormalisesEquivalentDestinationRates(): void
    {
        $decision = (new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            null,
            TaxPolicy::OSS_RULE_19_CONFIRMED,
        ))->decideInvoice('BE', false, null, false, false, [
            new LineItem('Digital service A', '100', '21', false),
            new LineItem('Digital service B', '100', '21.0000', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame(['21'], $decision->allowedTaxRates);
    }

    public function testEuB2cInvoiceWithoutConfirmedOssProfileRemainsBlocked(): void
    {
        $decision = $this->policy()->decideInvoice('BE', false, null, false, false, [
            new LineItem('Digital service', '121', '21', false),
        ]);

        self::assertFalse($decision->allowed);
        self::assertSame('unsupported_oss', $decision->code);
    }

    public function testConflictingEuB2cProfilesAreBlockedForVoucherAndInvoice(): void
    {
        $policy = new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED,
            $this->guidance(),
            TaxPolicy::OSS_RULE_19_CONFIRMED,
        );
        $lines = [new LineItem('Digital service', '121', '21', false)];

        $voucher = $policy->decide('BE', false, null, false, false, $lines);
        $invoice = $policy->decideInvoice('BE', false, null, false, false, $lines);

        self::assertFalse($voucher->allowed);
        self::assertFalse($invoice->allowed);
        self::assertSame('conflicting_eu_b2c_profiles', $voucher->code);
        self::assertSame('conflicting_eu_b2c_profiles', $invoice->code);
    }

    /** @return array<string, array<string, mixed>> */
    private function profiles(): array
    {
        return [
            'domestic' => ['accountDatev' => '100', 'taxRule' => '1'],
            'eu_b2b' => ['accountDatev' => '200', 'taxRule' => '3', 'confirmed' => false],
            'eu_b2c_domestic' => ['accountDatev' => '300', 'taxRule' => '1'],
            'third_country' => ['accountDatev' => '400', 'taxRule' => '2', 'confirmed' => true],
            'small_business' => ['accountDatev' => '500', 'taxRule' => '11', 'confirmed' => true],
            'add_funds' => ['accountDatev' => '600', 'taxRule' => '1', 'confirmed' => true],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function guidance(): array
    {
        return [
            [
                'accountDatevId' => 100,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 1, 'taxRates' => ['ZERO', 'SEVEN', 'NINETEEN']]],
            ],
            [
                'accountDatevId' => 200,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 3, 'taxRates' => ['ZERO']]],
            ],
            [
                'accountDatevId' => 300,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 1, 'taxRates' => ['NINETEEN']]],
            ],
            [
                'accountDatevId' => 500,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 11, 'taxRates' => ['ZERO']]],
            ],
            [
                'accountDatevId' => 400,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 2, 'taxRates' => ['ZERO']]],
            ],
            [
                'accountDatevId' => 600,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 1, 'taxRates' => ['ZERO']]],
            ],
        ];
    }

    private function policy(): TaxPolicy
    {
        return new TaxPolicy(
            $this->profiles(),
            TaxPolicy::EU_B2C_BLOCKED,
            $this->guidance(),
            TaxPolicy::OSS_BLOCKED,
            true,
        );
    }

    private function policyWithConfirmedEuGoods(): TaxPolicy
    {
        $profiles = $this->profiles();
        $profiles['eu_b2b']['confirmed'] = true;

        return new TaxPolicy(
            $profiles,
            TaxPolicy::EU_B2C_BLOCKED,
            $this->guidance(),
            TaxPolicy::OSS_BLOCKED,
            true,
        );
    }

    private function invoiceDecisionForRule(string $rule, string $rate): TaxDecision
    {
        $profiles = $this->profiles();
        $lineItems = [new LineItem('Synthetic invoice position', '100', $rate, false)];

        if ($rule === '1') {
            $profiles['domestic']['taxRule'] = '1';

            return (new TaxPolicy($profiles))->decideInvoice('DE', false, null, lineItems: $lineItems);
        }

        if ($rule === '3') {
            $profiles['eu_b2b']['taxRule'] = '3';
            $profiles['eu_b2b']['confirmed'] = true;

            return (new TaxPolicy(
                $profiles,
                TaxPolicy::EU_B2C_BLOCKED,
                $this->guidance(),
                TaxPolicy::OSS_BLOCKED,
                true,
            ))->decideInvoice(
                'NL',
                true,
                'NL123456789B01',
                lineItems: $lineItems,
                isOrganisation: true,
            );
        }

        if ($rule === '11') {
            $profiles['small_business']['taxRule'] = '11';
            $profiles['small_business']['confirmed'] = true;

            return (new TaxPolicy(
                $profiles,
                TaxPolicy::EU_B2C_BLOCKED,
                $this->guidance(),
                TaxPolicy::OSS_BLOCKED,
                true,
            ))->decideInvoice(
                'DE',
                false,
                null,
                smallBusinessOwner: true,
                lineItems: $lineItems,
            );
        }

        $profiles['third_country']['taxRule'] = $rule;
        $profiles['third_country']['confirmed'] = true;

        return (new TaxPolicy($profiles))->decideInvoice('CH', false, null, lineItems: $lineItems);
    }
}
