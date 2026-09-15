<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use WHMCS\Module\Addon\SevDesk\Domain\Decimal;

/** Presentation-only setup drafts; never used as persisted configuration. */
final class SetupForm
{
    private const TEXT_FIELDS = [
        'custom_field_id',
        'export_mode',
        'document_authority',
        'invoice_lifecycle_mode',
        'dunning_mode',
        'late_fee_mode',
        'late_fee_accounting_source',
        'late_fee_rule22_account_datev_id',
        'sevdesk_reminder_subject',
        'sevdesk_reminder_body',
        'sevdesk_cancellation_subject',
        'sevdesk_cancellation_body',
        'invoice_discount_rule19_canary_rate',
        'invoice_sev_user_id',
        'invoice_unity_id',
        'e_invoice_mode',
        'e_invoice_client_field_id',
        'e_invoice_payment_method_id',
        'e_invoice_active_from',
        'oss_profile',
        'invoice_delivery_channel',
        'whmcs_invoice_email_template',
        'sevdesk_email_subject',
        'sevdesk_email_body',
        'import_after',
        'eu_b2c_mode',
        'small_business_until',
        'accountingTypeGeneral',
        'taxRuleGeneral',
        'accountingTypeInterCommunityBusiness',
        'taxRuleInterCommunityBusiness',
        'accountingTypeInterCommunityConsumer',
        'taxRuleInterCommunityConsumer',
        'accountingTypeThirdPartyCountry',
        'taxRuleThirdPartyCountry',
        'accountingTypeCredit',
        'taxRuleCredit',
        'accountingTypeSmallBusinessOwner',
        'taxRuleSmallBusinessOwner',
    ];

    private const CHECKBOX_FIELDS = [
        'customer_number_contact_creation_confirmed',
        'direct_invoice_canary_confirmed',
        'dunning_canary_confirmed',
        'cancellation_canary_confirmed',
        'e_invoice_cancellation_canary_confirmed',
        'late_fee_rule22_canary_confirmed',
        'late_fee_reminder_accounting_canary_confirmed',
        'invoice_canary_confirmed',
        'small_business_invoice_canary_confirmed',
        'invoice_discount_canary_confirmed',
        'invoice_discount_rule1_19_canary_confirmed',
        'invoice_discount_rule1_19_eu_b2c_domestic_canary_confirmed',
        'invoice_discount_rule17_0_canary_confirmed',
        'invoice_discount_rule19_canary_confirmed',
        'e_invoice_canary_confirmed',
        'e_invoice_profile_acknowledged',
        'oss_profile_acknowledged',
        'theme_adapter_confirmed',
        'import_only_paid',
        'sync_enabled',
        'debug_logging',
        'eu_b2c_acknowledged',
        'smallBusinessOwner',
        'eu_b2b_goods_confirmed',
        'third_country_confirmed',
        'add_funds_confirmed',
        'small_business_confirmed',
    ];

    /**
     * Keep only editable values in the response, excluding secrets and fresh inventory approvals.
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function draft(array $settings, array $post): array
    {
        unset($settings['sevdesk_api_key']);
        foreach (self::TEXT_FIELDS as $field) {
            if (isset($post[$field]) && is_string($post[$field])) {
                $settings[$field] = $post[$field];
            }
        }
        foreach (self::CHECKBOX_FIELDS as $field) {
            $settings[$field] = isset($post[$field]) ? 'on' : '';
        }
        foreach (['import_after', 'small_business_until', 'e_invoice_active_from'] as $field) {
            $settings[$field . '_iso'] = is_string($post[$field] ?? null) ? $post[$field] : '';
        }
        foreach (
            [
            'invoice_discount_rule1_19',
            'invoice_discount_rule1_19_eu_b2c_domestic',
            'invoice_discount_rule17_0',
            'invoice_discount_rule19',
            ] as $prefix
        ) {
            $settings[$prefix . '_canary_current'] = isset($post[$prefix . '_canary_confirmed']);
        }

        return $settings;
    }

    public static function numericFieldId(string $setting): string
    {
        return [
            'accountingTypeGeneral' => 'account-general',
            'accountingTypeInterCommunityBusiness' => 'account-eu-business',
            'accountingTypeInterCommunityConsumer' => 'account-eu-consumer',
            'accountingTypeThirdPartyCountry' => 'account-third-country',
            'accountingTypeCredit' => 'account-credit',
            'accountingTypeSmallBusinessOwner' => 'account-small-business',
            'taxRuleGeneral' => 'tax-rule-general',
            'taxRuleInterCommunityBusiness' => 'tax-rule-eu-business',
            'taxRuleInterCommunityConsumer' => 'tax-rule-eu-consumer',
            'taxRuleThirdPartyCountry' => 'tax-rule-third-country',
            'taxRuleCredit' => 'tax-rule-credit',
            'taxRuleSmallBusinessOwner' => 'tax-rule-small-business',
        ][$setting];
    }

    public static function rule19Rate(string $input): string
    {
        $value = trim(str_replace(',', '.', $input));
        if (
            preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $value) !== 1
            || Decimal::toMinorUnits($value) < 1
            || Decimal::toMinorUnits($value) > 10000
        ) {
            throw new SetupValidationException([
                'invoice-discount-rule19-canary-rate' => 'Rule-19-Rabattprüfung: Bitte den im Test geprüften '
                    . 'Zielsteuersatz zwischen 0,01 und 100 eingeben, z. B. 21 oder 21,5, ohne %-Zeichen. '
                    . 'Wenn diese Rabattprüfung nicht durchgeführt wurde, die zugehörige Bestätigung abwählen.',
            ]);
        }

        return Decimal::fromMinorUnits(Decimal::toMinorUnits($value));
    }
}
