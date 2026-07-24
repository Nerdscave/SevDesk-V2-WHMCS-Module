<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/**
 * Makes the tax classification explicit and testable before any remote write.
 *
 * Profile shape:
 *   ['accountDatev' => '123', 'taxRule' => '1', 'confirmed' => true]
 *
 * Supported keys are domestic, eu_b2b, eu_b2c_domestic, third_country,
 * small_business and add_funds. Every non-domestic special profile is
 * fail-closed until its documented business case is explicitly confirmed.
 */
final class TaxPolicy
{
    public const EU_B2C_BLOCKED = 'blocked';
    public const EU_B2C_DOMESTIC_CONFIRMED = 'domestic_confirmed';
    public const OSS_BLOCKED = 'blocked';
    public const OSS_RULE_19_CONFIRMED = 'rule19_digital_services_confirmed';

    /**
     * Fixed Invoice capability contract for normal (non-OSS) tax rules.
     * Rule 19 deliberately remains dynamic and is selected only by its
     * separately confirmed OSS profile.
     *
     * @var array<int, list<string>>
     */
    private const NORMAL_TAX_RATES_BY_RULE = [
        '1' => ['0', '7', '19'],
        '2' => ['0'],
        '3' => ['0'],
        '4' => ['0'],
        '5' => ['0'],
        '11' => ['0'],
        '17' => ['0'],
    ];

    /** @var list<string> */
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SK', 'SI', 'ES', 'SE',
        // Northern Ireland remains relevant for the special EU goods treatment.
        'XI',
    ];

    /** @var array<string, array<string, mixed>> */
    private readonly array $profiles;

    private readonly string $euB2cMode;

    /** @var array<array-key, mixed>|null */
    private readonly ?array $receiptGuidance;

    private readonly string $ossProfile;

    private readonly bool $invoiceRuleElevenTenantScopeSupported;

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @param array<array-key, mixed>|null $receiptGuidance Unwrapped response from ReceiptGuidance/forRevenue.
     */
    public function __construct(
        array $profiles,
        string $euB2cMode = self::EU_B2C_BLOCKED,
        ?array $receiptGuidance = null,
        string $ossProfile = self::OSS_BLOCKED,
        private readonly bool $smallBusinessInvoiceCanaryConfirmed = false,
        ?bool $invoiceRuleElevenTenantScopeSupported = null,
    ) {
        if (!in_array($euB2cMode, [self::EU_B2C_BLOCKED, self::EU_B2C_DOMESTIC_CONFIRMED], true)) {
            throw new \InvalidArgumentException('Invalid EU B2C mode.');
        }
        if (!in_array($ossProfile, [self::OSS_BLOCKED, self::OSS_RULE_19_CONFIRMED], true)) {
            throw new \InvalidArgumentException('Invalid OSS profile.');
        }

        $this->profiles = $profiles;
        $this->euB2cMode = $euB2cMode;
        $this->receiptGuidance = $receiptGuidance;
        $this->ossProfile = $ossProfile;
        $this->invoiceRuleElevenTenantScopeSupported = $invoiceRuleElevenTenantScopeSupported
            ?? self::guidanceSupportsInvoiceRuleEleven($receiptGuidance);
    }

    /**
     * @param list<LineItem> $lineItems
     */
    public function decide(
        string $countryCode,
        bool $taxExempt,
        ?string $vatNumber,
        bool $smallBusinessOwner = false,
        bool $addFunds = false,
        array $lineItems = [],
        bool $isOrganisation = false,
    ): TaxDecision {
        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof LineItem) {
                throw new \InvalidArgumentException('Tax policy line items must be LineItem instances.');
            }
        }

        $countryCode = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            return TaxDecision::block('invalid_country', 'The customer country code is invalid.');
        }

        if ($smallBusinessOwner) {
            $decision = $this->fromProfile('small_business', true, '11');
        } elseif ($addFunds) {
            $decision = $this->fromProfile('add_funds', true, null);
        } elseif ($countryCode === 'DE') {
            if ($taxExempt) {
                return TaxDecision::block(
                    'unsupported_domestic_tax_exempt',
                    'A tax-exempt domestic customer requires a separately confirmed tax profile.',
                    'domestic_tax_exempt',
                );
            }
            $decision = $this->fromProfile('domestic', false, '1');
        } elseif (in_array($countryCode, self::EU_COUNTRIES, true)) {
            if ($taxExempt) {
                if (!$isOrganisation) {
                    return TaxDecision::block(
                        'eu_b2b_organisation_required',
                        'EU B2B export requires a WHMCS organisation in addition to tax exemption and a VAT ID.',
                        'eu_b2b',
                    );
                }
                if (trim((string) $vatNumber) === '') {
                    return TaxDecision::block(
                        'missing_vat_id',
                        'EU B2B export requires both WHMCS tax exemption and a VAT ID.',
                        'eu_b2b',
                    );
                }
                // Rule 3 represents an intra-community supply of goods. The
                // profile therefore remains disabled until the operator has
                // explicitly confirmed that exact business case. Hosting and
                // other services must stay on the manual-review path.
                $decision = $this->fromProfile('eu_b2b', true, '3');
            } elseif ($this->hasConflictingEuB2cProfiles()) {
                return TaxDecision::block(
                    'conflicting_eu_b2c_profiles',
                    'Domestic EU B2C taxation and the Rule 19 OSS profile cannot be enabled together.',
                    'eu_b2c',
                );
            } elseif ($this->euB2cMode === self::EU_B2C_DOMESTIC_CONFIRMED) {
                $decision = $this->fromProfile('eu_b2c_domestic', false, '1');
            } else {
                return TaxDecision::block(
                    'unsupported_oss',
                    'EU B2C voucher export is blocked because sevdesk does not support OSS tax rules for vouchers.',
                    'eu_b2c',
                );
            }
        } else {
            $decision = $this->fromProfile('third_country', true, null);
        }

        if ($decision->allowed && $decision->profile === 'eu_b2b') {
            foreach ($lineItems as $lineItem) {
                if (Decimal::toMinorUnits($lineItem->taxRate) !== 0) {
                    return TaxDecision::block(
                        'eu_b2b_tax_rate_mismatch',
                        'A tax-exempt EU B2B invoice must not contain a positive VAT rate.',
                        'eu_b2b',
                    );
                }
            }
        }

        if (!$decision->allowed || $this->receiptGuidance === null) {
            return $decision;
        }

        return $this->validateAgainstReceiptGuidance($decision, $this->receiptGuidance, $lineItems);
    }

    /**
     * Classify a normal sevdesk Invoice without requiring Voucher-only accountDatev data.
     *
     * @param list<LineItem> $lineItems
     */
    public function decideInvoice(
        string $countryCode,
        bool $taxExempt,
        ?string $vatNumber,
        bool $smallBusinessOwner = false,
        bool $addFunds = false,
        array $lineItems = [],
        bool $isOrganisation = false,
    ): TaxDecision {
        self::assertLineItems($lineItems);

        $countryCode = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            return TaxDecision::block('invalid_country', 'The customer country code is invalid.');
        }

        if ($smallBusinessOwner) {
            $decision = $this->fromInvoiceProfile('small_business', true, '11', $lineItems);
            if ($decision->allowed && !$this->smallBusinessInvoiceCanaryConfirmed) {
                return TaxDecision::block(
                    'small_business_invoice_canary_not_confirmed',
                    'Rule 11 Invoices require their separate sevdesk tenant canary.',
                    'small_business',
                );
            }
            if ($decision->allowed && !$this->invoiceRuleElevenTenantScopeSupported) {
                return TaxDecision::block(
                    'invoice_rule11_tenant_scope_unsupported',
                    'Receipt Guidance offers no REVENUE account with Rule 11 and a zero percent tax rate.',
                    'small_business',
                );
            }
        } elseif ($addFunds) {
            $decision = $this->fromInvoiceProfile('add_funds', true, null, $lineItems);
        } elseif ($countryCode === 'DE') {
            if ($taxExempt) {
                return TaxDecision::block(
                    'unsupported_domestic_tax_exempt',
                    'A tax-exempt domestic customer requires a separately confirmed tax profile.',
                    'domestic_tax_exempt',
                );
            }
            $decision = $this->fromInvoiceProfile('domestic', false, '1', $lineItems);
        } elseif (in_array($countryCode, self::EU_COUNTRIES, true)) {
            if ($taxExempt) {
                if (!$isOrganisation) {
                    return TaxDecision::block(
                        'eu_b2b_organisation_required',
                        'EU B2B export requires a WHMCS organisation in addition to tax exemption and a VAT ID.',
                        'eu_b2b',
                    );
                }
                if (trim((string) $vatNumber) === '') {
                    return TaxDecision::block(
                        'missing_vat_id',
                        'EU B2B export requires both WHMCS tax exemption and a VAT ID.',
                        'eu_b2b',
                    );
                }
                $decision = $this->fromInvoiceProfile('eu_b2b', true, '3', $lineItems);
            } elseif ($this->hasConflictingEuB2cProfiles()) {
                return TaxDecision::block(
                    'conflicting_eu_b2c_profiles',
                    'Domestic EU B2C taxation and the Rule 19 OSS profile cannot be enabled together.',
                    'eu_b2c',
                );
            } elseif ($this->ossProfile === self::OSS_RULE_19_CONFIRMED) {
                $rates = self::lineTaxRates($lineItems);
                if ($rates === []) {
                    return TaxDecision::block(
                        'oss_tax_rates_missing',
                        'Rule 19 requires the actual WHMCS position tax rates.',
                        'eu_b2c_rule19',
                    );
                }
                $decision = TaxDecision::allowInvoiceRule19(
                    'eu_b2c_rule19',
                    'Rule 19 selected from the explicitly confirmed all-digital EU B2C profile.',
                    $rates,
                );
            } elseif ($this->euB2cMode === self::EU_B2C_DOMESTIC_CONFIRMED) {
                $decision = $this->fromInvoiceProfile('eu_b2c_domestic', false, '1', $lineItems);
            } else {
                return TaxDecision::block(
                    'unsupported_oss',
                    'EU B2C Invoice export requires an explicitly confirmed tax profile.',
                    'eu_b2c',
                );
            }
        } else {
            $decision = $this->fromInvoiceProfile('third_country', true, null, $lineItems);
        }

        return self::validateEuB2bRates($decision, $lineItems);
    }

    public function invoiceRuleElevenTenantScopeSupported(): bool
    {
        return $this->invoiceRuleElevenTenantScopeSupported;
    }

    /**
     * InvoicePos has no accountDatev field. The guidance result therefore acts
     * only as a tenant capability gate; it does not select an account for the
     * Invoice payload.
     *
     * @param array<array-key, mixed>|null $guidance
     */
    public static function guidanceSupportsInvoiceRuleEleven(?array $guidance): bool
    {
        if ($guidance === null) {
            return false;
        }
        if (isset($guidance['objects']) && is_array($guidance['objects'])) {
            $guidance = $guidance['objects'];
        }

        foreach ($guidance as $accountGuide) {
            if (
                !is_array($accountGuide)
                || preg_match('/^[1-9]\d*$/', trim((string) ($accountGuide['accountDatevId'] ?? ''))) !== 1
            ) {
                continue;
            }
            $receiptTypes = $accountGuide['allowedReceiptTypes'] ?? null;
            if (
                !is_array($receiptTypes)
                || !in_array(
                    'REVENUE',
                    array_map('strtoupper', array_map('strval', $receiptTypes)),
                    true,
                )
            ) {
                continue;
            }
            $rules = $accountGuide['allowedTaxRules'] ?? null;
            if (!is_array($rules)) {
                continue;
            }
            foreach ($rules as $rule) {
                if (!is_array($rule) || trim((string) ($rule['id'] ?? '')) !== '11') {
                    continue;
                }
                $rates = $rule['taxRates'] ?? null;
                if (!is_array($rates)) {
                    continue;
                }
                foreach ($rates as $rate) {
                    if (self::normaliseGuidanceRate($rate) === '0') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Validate an already selected account/rule pair and its actual tax rates.
     *
     * @param array<array-key, mixed> $guidance
     * @param list<LineItem> $lineItems
     */
    public function validateAgainstReceiptGuidance(
        TaxDecision $decision,
        array $guidance,
        array $lineItems = [],
    ): TaxDecision {
        if (!$decision->allowed) {
            return $decision;
        }

        if (isset($guidance['objects']) && is_array($guidance['objects'])) {
            $guidance = $guidance['objects'];
        }

        foreach ($guidance as $accountGuide) {
            if (
                !is_array($accountGuide)
                || (string) ($accountGuide['accountDatevId'] ?? '') !== $decision->accountDatevId
            ) {
                continue;
            }

            $receiptTypes = $accountGuide['allowedReceiptTypes'] ?? [];
            if (!is_array($receiptTypes)) {
                continue;
            }
            if (
                $receiptTypes !== []
                && !in_array('REVENUE', array_map('strtoupper', array_map('strval', $receiptTypes)), true)
            ) {
                continue;
            }

            $rules = $accountGuide['allowedTaxRules'] ?? [];
            if (!is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (!is_array($rule) || (string) ($rule['id'] ?? '') !== $decision->taxRuleId) {
                    continue;
                }

                $allowedRates = [];
                $taxRates = $rule['taxRates'] ?? null;
                if (!is_array($taxRates)) {
                    continue;
                }
                foreach ($taxRates as $rate) {
                    $normalised = self::normaliseGuidanceRate($rate);
                    if ($normalised !== null) {
                        $allowedRates[] = $normalised;
                    }
                }
                $allowedRates = array_values(array_unique($allowedRates));
                $fixedRates = self::NORMAL_TAX_RATES_BY_RULE[$decision->taxRuleId ?? ''] ?? null;
                if ($fixedRates === null) {
                    return TaxDecision::block(
                        'unsupported_voucher_tax_rule',
                        'The selected tax rule is not enabled for sevdesk vouchers.',
                        $decision->profile,
                    );
                }
                $allowedRates = array_values(array_intersect($allowedRates, $fixedRates));

                foreach ($lineItems as $lineItem) {
                    if (!$lineItem instanceof LineItem) {
                        throw new \InvalidArgumentException('Tax policy line items must be LineItem instances.');
                    }

                    if (!in_array(self::normaliseNumericRate($lineItem->taxRate), $allowedRates, true)) {
                        return TaxDecision::block(
                            'unsupported_tax_rate',
                            'The selected sevdesk account and tax rule do not allow an invoice tax rate.',
                            $decision->profile,
                        );
                    }
                }

                return $decision->withValidatedGuidance($allowedRates);
            }
        }

        return TaxDecision::block(
            'unsupported_receipt_guidance',
            'The selected accountDatev and taxRule combination is not offered by sevdesk Receipt Guidance.',
            $decision->profile,
        );
    }

    private function fromProfile(string $profileName, bool $confirmationRequired, ?string $requiredRule): TaxDecision
    {
        $profile = $this->profiles[$profileName] ?? null;
        if (!is_array($profile)) {
            return TaxDecision::block(
                'missing_tax_profile',
                'The required tax profile is not configured.',
                $profileName,
            );
        }

        if ($confirmationRequired && !self::isConfirmed($profile['confirmed'] ?? false)) {
            return TaxDecision::block(
                'unconfirmed_tax_profile',
                'This tax profile requires explicit confirmation before export.',
                $profileName,
            );
        }

        $accountValue = $profile['accountDatev'] ?? $profile['accountDatevId'] ?? '';
        $ruleValue = $profile['taxRule'] ?? $requiredRule ?? '';
        if (
            (!is_string($accountValue) && !is_int($accountValue))
            || (!is_string($ruleValue) && !is_int($ruleValue))
        ) {
            return TaxDecision::block(
                'invalid_tax_profile',
                'sevdesk account and tax rule IDs must be scalar numeric values.',
                $profileName,
            );
        }
        $accountDatev = trim((string) $accountValue);
        $taxRule = trim((string) $ruleValue);
        if ($accountDatev === '' || $taxRule === '') {
            return TaxDecision::block(
                'incomplete_tax_profile',
                'The tax profile needs both accountDatev and taxRule.',
                $profileName,
            );
        }

        if ($requiredRule !== null && $taxRule !== $requiredRule) {
            return TaxDecision::block(
                'invalid_tax_rule',
                'The configured tax rule is not valid for this tax profile.',
                $profileName,
            );
        }

        if (preg_match('/^\d+$/', $accountDatev) !== 1 || preg_match('/^\d+$/', $taxRule) !== 1) {
            return TaxDecision::block(
                'invalid_tax_profile',
                'sevdesk account and tax rule IDs must be numeric.',
                $profileName,
            );
        }

        if (in_array($taxRule, ['18', '19', '20'], true)) {
            return TaxDecision::block(
                'unsupported_oss',
                'OSS tax rules 18 to 20 are not supported for sevdesk vouchers.',
                $profileName,
            );
        }
        if ($taxRule === '21') {
            return TaxDecision::block(
                'unsupported_voucher_tax_rule',
                'The configured tax rule is not supported for sevdesk vouchers.',
                $profileName,
            );
        }

        return TaxDecision::allow(
            $profileName,
            $accountDatev,
            $taxRule,
            'Tax profile selected; Receipt Guidance validation is required before export.',
        );
    }

    /** @param list<LineItem> $lineItems */
    private function fromInvoiceProfile(
        string $profileName,
        bool $confirmationRequired,
        ?string $requiredRule,
        array $lineItems,
    ): TaxDecision {
        $profile = $this->profiles[$profileName] ?? null;
        if (!is_array($profile)) {
            return TaxDecision::block(
                'missing_tax_profile',
                'The required tax profile is not configured.',
                $profileName,
            );
        }

        if ($confirmationRequired && !self::isConfirmed($profile['confirmed'] ?? false)) {
            return TaxDecision::block(
                'unconfirmed_tax_profile',
                'This tax profile requires explicit confirmation before export.',
                $profileName,
            );
        }

        $ruleValue = $profile['taxRule'] ?? $requiredRule ?? '';
        if (!is_string($ruleValue) && !is_int($ruleValue)) {
            return TaxDecision::block(
                'invalid_tax_profile',
                'The sevdesk Invoice tax rule ID must be a scalar numeric value.',
                $profileName,
            );
        }
        $taxRule = trim((string) $ruleValue);
        if ($taxRule === '') {
            return TaxDecision::block(
                'incomplete_tax_profile',
                'The Invoice tax profile needs a taxRule; accountDatev is not used.',
                $profileName,
            );
        }
        if ($requiredRule !== null && $taxRule !== $requiredRule) {
            return TaxDecision::block(
                'invalid_tax_rule',
                'The configured tax rule is not valid for this tax profile.',
                $profileName,
            );
        }
        if (preg_match('/^[1-9]\d*$/', $taxRule) !== 1) {
            return TaxDecision::block(
                'invalid_tax_profile',
                'The sevdesk Invoice tax rule ID must be numeric.',
                $profileName,
            );
        }
        if (in_array($taxRule, ['18', '20'], true)) {
            return TaxDecision::block(
                'unsupported_oss_rule',
                'OSS rules 18 and 20 are outside the confirmed electronic-services profile.',
                $profileName,
            );
        }
        if ($taxRule === '19') {
            return TaxDecision::block(
                'oss_profile_not_confirmed',
                'Rule 19 is selected only by the explicit digital-services OSS profile.',
                $profileName,
            );
        }
        if ($taxRule === '21' || !isset(self::NORMAL_TAX_RATES_BY_RULE[$taxRule])) {
            return TaxDecision::block(
                'unsupported_invoice_tax_rule',
                'The configured tax rule is not enabled for normal sevdesk Invoices.',
                $profileName,
            );
        }

        $allowedRates = self::NORMAL_TAX_RATES_BY_RULE[$taxRule];
        foreach ($lineItems as $lineItem) {
            if (in_array(self::normaliseNumericRate($lineItem->taxRate), $allowedRates, true)) {
                continue;
            }

            if ($taxRule === '3') {
                return TaxDecision::block(
                    'eu_b2b_tax_rate_mismatch',
                    'A tax-exempt EU B2B invoice must not contain a positive VAT rate.',
                    $profileName,
                );
            }

            return TaxDecision::block(
                'unsupported_invoice_tax_rate',
                'The selected sevdesk Invoice tax rule does not allow a WHMCS position tax rate.',
                $profileName,
            );
        }

        return TaxDecision::allowInvoice(
            $profileName,
            $taxRule,
            'Invoice tax profile selected without a Voucher accountDatev dependency.',
            $allowedRates,
        );
    }

    private function hasConflictingEuB2cProfiles(): bool
    {
        return $this->euB2cMode === self::EU_B2C_DOMESTIC_CONFIRMED
            && $this->ossProfile === self::OSS_RULE_19_CONFIRMED;
    }

    /** @param list<LineItem> $lineItems */
    private static function assertLineItems(array $lineItems): void
    {
        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof LineItem) {
                throw new \InvalidArgumentException('Tax policy line items must be LineItem instances.');
            }
        }
    }

    /**
     * @param list<LineItem> $lineItems
     * @return list<string>
     */
    private static function lineTaxRates(array $lineItems): array
    {
        return array_values(array_unique(array_map(
            static fn (LineItem $lineItem): string => $lineItem->taxRate,
            $lineItems,
        )));
    }

    /** @param list<LineItem> $lineItems */
    private static function validateEuB2bRates(TaxDecision $decision, array $lineItems): TaxDecision
    {
        if (!$decision->allowed || $decision->profile !== 'eu_b2b') {
            return $decision;
        }
        foreach ($lineItems as $lineItem) {
            if (Decimal::toMinorUnits($lineItem->taxRate) !== 0) {
                return TaxDecision::block(
                    'eu_b2b_tax_rate_mismatch',
                    'A tax-exempt EU B2B invoice must not contain a positive VAT rate.',
                    'eu_b2b',
                );
            }
        }

        return $decision;
    }

    private static function isConfirmed(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        return is_string($value)
            && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function normaliseGuidanceRate(mixed $rate): ?string
    {
        if (!is_string($rate) && !is_int($rate) && !is_float($rate)) {
            return null;
        }

        $rate = strtoupper(trim((string) $rate));
        $namedRates = [
            'ZERO' => '0',
            'SEVEN' => '7',
            'NINETEEN' => '19',
        ];
        if (isset($namedRates[$rate])) {
            return $namedRates[$rate];
        }

        $numeric = str_replace(',', '.', $rate);
        if (!is_numeric($numeric)) {
            return null;
        }

        return self::normaliseNumericRate($numeric);
    }

    private static function normaliseNumericRate(string $rate): string
    {
        $number = Decimal::toFloat($rate);
        $formatted = number_format($number, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
