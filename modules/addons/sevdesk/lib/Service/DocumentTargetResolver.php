<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/** Selects Voucher or Invoice once, before any remote side effect. */
final class DocumentTargetResolver
{
    public const MODE_VOUCHER_ONLY = 'voucher_only';
    public const MODE_INVOICE_FOR_OSS = 'invoice_for_oss';
    public const MODE_INVOICE_ONLY = 'invoice_only';

    public const AUTHORITY_WHMCS = 'whmcs';
    public const AUTHORITY_SEVDESK = 'sevdesk';

    public const OSS_BLOCKED = 'blocked';
    public const OSS_RULE_19_CONFIRMED = 'rule19_digital_services_confirmed';

    public const DELIVERY_SEVDESK = 'sevdesk';
    public const DELIVERY_WHMCS_TEMPLATE = 'whmcs_template';

    /** @var list<string> */
    private const INVOICE_TAX_RULES = ['1', '2', '3', '4', '5', '11', '17', '19'];

    public function __construct(
        private readonly string $exportMode,
        private readonly string $documentAuthority,
        private readonly string $ossProfile,
    ) {
        if (
            !in_array($exportMode, [
            self::MODE_VOUCHER_ONLY,
            self::MODE_INVOICE_FOR_OSS,
            self::MODE_INVOICE_ONLY,
            ], true)
        ) {
            throw new \InvalidArgumentException('Unknown sevdesk export mode.');
        }
        if (!in_array($documentAuthority, [self::AUTHORITY_WHMCS, self::AUTHORITY_SEVDESK], true)) {
            throw new \InvalidArgumentException('Unknown document authority.');
        }
        if (!in_array($ossProfile, [self::OSS_BLOCKED, self::OSS_RULE_19_CONFIRMED], true)) {
            throw new \InvalidArgumentException('Unknown OSS capability profile.');
        }
    }

    public function resolve(
        TaxDecision $taxDecision,
        bool $invoicePaid,
        bool $hasFinalInvoiceNumber,
    ): DocumentTargetDecision {
        $taxRuleId = $taxDecision->taxRuleId;

        if ($this->documentAuthority === self::AUTHORITY_SEVDESK && $this->exportMode !== self::MODE_INVOICE_ONLY) {
            return $this->block(
                $taxRuleId,
                'invalid_document_authority_mode',
                'sevdesk document authority requires the global invoice_only export mode.',
            );
        }

        if (!$taxDecision->allowed) {
            return $this->block($taxRuleId, $taxDecision->code, $taxDecision->message);
        }

        if ($taxRuleId === null || preg_match('/^\d+$/', $taxRuleId) !== 1) {
            return $this->block(
                $taxRuleId,
                'invalid_tax_rule',
                'The tax decision contains no usable sevdesk tax rule.',
            );
        }

        if (in_array($taxRuleId, ['18', '20'], true)) {
            return $this->block(
                $taxRuleId,
                'unsupported_oss_rule',
                'OSS rules 18 and 20 are outside the confirmed electronic-services profile.',
            );
        }
        if ($taxRuleId === '21') {
            return $this->block(
                $taxRuleId,
                'unsupported_invoice_tax_rule',
                'Tax rule 21 is not enabled for this Invoice release.',
            );
        }

        if ($taxRuleId === '19') {
            if ($this->ossProfile !== self::OSS_RULE_19_CONFIRMED) {
                return $this->block(
                    $taxRuleId,
                    'oss_profile_not_confirmed',
                    'Rule 19 requires the explicitly confirmed digital-services OSS profile.',
                );
            }
            if ($this->exportMode === self::MODE_VOUCHER_ONLY) {
                return $this->block(
                    $taxRuleId,
                    'oss_requires_invoice_mode',
                    'Rule 19 cannot be written as a sevdesk Voucher.',
                );
            }
            if ($taxDecision->allowedTaxRates === []) {
                return $this->block(
                    $taxRuleId,
                    'oss_tax_rates_missing',
                    'Rule 19 requires the actual WHMCS position tax rates.',
                );
            }

            return $this->invoiceTarget(
                $taxRuleId,
                $invoicePaid,
                $hasFinalInvoiceNumber,
                'invoice_selected_oss',
                'A confirmed Rule 19 electronic-services case requires a sevdesk Invoice.',
            );
        }

        if ($this->exportMode !== self::MODE_INVOICE_ONLY) {
            return DocumentTargetDecision::select(
                DocumentTargetDecision::DOCUMENT_VOUCHER,
                $this->documentAuthority,
                $this->exportMode,
                $this->ossProfile,
                $taxRuleId,
                'voucher_selected',
                'The configured export mode selects the existing Voucher flow.',
            );
        }

        if (!in_array($taxRuleId, self::INVOICE_TAX_RULES, true)) {
            return $this->block(
                $taxRuleId,
                'unsupported_invoice_tax_rule',
                'The selected tax rule is not enabled for normal sevdesk Invoices.',
            );
        }

        return $this->invoiceTarget(
            $taxRuleId,
            $invoicePaid,
            $hasFinalInvoiceNumber,
            'invoice_selected_global',
            'The global invoice_only mode selects a sevdesk Invoice.',
        );
    }

    /**
     * Canonical validation for persisted and queued document context. The
     * resolver still returns specific domain failures for live decisions; this
     * method prevents snapshots and recovery code from maintaining their own
     * copies of the mode/profile matrix.
     */
    public static function contextValuesAreValid(
        string $exportMode,
        string $documentAuthority,
        string $ossProfile,
        string $euB2cMode,
        ?string $deliveryChannel,
    ): bool {
        return self::contextValidationError(
            $exportMode,
            $documentAuthority,
            $ossProfile,
            $euB2cMode,
            $deliveryChannel,
        ) === null;
    }

    public static function contextValidationError(
        string $exportMode,
        string $documentAuthority,
        string $ossProfile,
        string $euB2cMode,
        ?string $deliveryChannel,
    ): ?string {
        if (
            !in_array($exportMode, [self::MODE_VOUCHER_ONLY, self::MODE_INVOICE_FOR_OSS, self::MODE_INVOICE_ONLY], true)
            || !in_array($documentAuthority, [self::AUTHORITY_WHMCS, self::AUTHORITY_SEVDESK], true)
            || !in_array($ossProfile, [self::OSS_BLOCKED, self::OSS_RULE_19_CONFIRMED], true)
            || !in_array($euB2cMode, [TaxPolicy::EU_B2C_BLOCKED, TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED], true)
            || ($exportMode === self::MODE_VOUCHER_ONLY && $ossProfile !== self::OSS_BLOCKED)
            || ($documentAuthority === self::AUTHORITY_SEVDESK && $exportMode !== self::MODE_INVOICE_ONLY)
            || ($documentAuthority !== self::AUTHORITY_SEVDESK && $deliveryChannel !== null)
        ) {
            return 'invalid_document_context';
        }
        if ($ossProfile === self::OSS_RULE_19_CONFIRMED && $euB2cMode !== TaxPolicy::EU_B2C_BLOCKED) {
            return 'conflicting_eu_b2c_profiles';
        }

        if (
            $documentAuthority === self::AUTHORITY_SEVDESK
            && !in_array($deliveryChannel, [self::DELIVERY_SEVDESK, self::DELIVERY_WHMCS_TEMPLATE], true)
        ) {
            return 'invalid_document_context';
        }

        return null;
    }

    private function invoiceTarget(
        string $taxRuleId,
        bool $invoicePaid,
        bool $hasFinalInvoiceNumber,
        string $code,
        string $message,
    ): DocumentTargetDecision {
        if (!$invoicePaid) {
            return $this->block(
                $taxRuleId,
                'invoice_requires_payment',
                'sevdesk Invoice targets are created only after full WHMCS payment.',
            );
        }
        if (!$hasFinalInvoiceNumber) {
            return $this->block(
                $taxRuleId,
                'invoice_number_not_final',
                'A final WHMCS invoice number is required before Invoice export.',
            );
        }

        return DocumentTargetDecision::select(
            DocumentTargetDecision::DOCUMENT_INVOICE,
            $this->documentAuthority,
            $this->exportMode,
            $this->ossProfile,
            $taxRuleId,
            $code,
            $message,
        );
    }

    private function block(?string $taxRuleId, string $code, string $message): DocumentTargetDecision
    {
        return DocumentTargetDecision::block(
            $this->documentAuthority,
            $this->exportMode,
            $this->ossProfile,
            $taxRuleId,
            $code,
            $message,
        );
    }
}
