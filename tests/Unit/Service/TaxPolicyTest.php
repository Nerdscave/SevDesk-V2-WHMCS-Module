<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
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

    public function testConfirmedThirdCountryExportProfileUsesItsExplicitRule(): void
    {
        $decision = $this->policy()->decide('CH', false, null, false, false, [
            new LineItem('Export', '100', '0', false),
        ]);

        self::assertTrue($decision->allowed);
        self::assertSame('2', $decision->taxRuleId);
        self::assertSame('third_country', $decision->profile);
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
                'allowedTaxRules' => [['id' => 1, 'taxRates' => ['ZERO', 'NINETEEN']]],
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
        ];
    }

    private function policy(): TaxPolicy
    {
        return new TaxPolicy($this->profiles(), TaxPolicy::EU_B2C_BLOCKED, $this->guidance());
    }

    private function policyWithConfirmedEuGoods(): TaxPolicy
    {
        $profiles = $this->profiles();
        $profiles['eu_b2b']['confirmed'] = true;

        return new TaxPolicy($profiles, TaxPolicy::EU_B2C_BLOCKED, $this->guidance());
    }
}
