<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/**
 * Pure, fail-closed capability matrix for the one supported PromoHosting
 * discount. API canaries remain separate because taxable discount semantics
 * are not fully described by sevdesk's Invoice schema.
 */
final class InvoiceDiscountCapabilityPolicy
{
    public const RULE_11_ZERO = 'promo_discount_rule11_0';

    public const RULE_1_NINETEEN = 'promo_discount_rule1_19';

    public const RULE_17_ZERO = 'promo_discount_rule17_0';

    public const RULE_19_DESTINATION = 'promo_discount_rule19_destination';

    public function __construct(
        private readonly bool $ruleElevenZeroConfirmed = false,
        private readonly string $ruleOneNineteenCapabilityKey = '',
        private readonly string $ruleSeventeenZeroCapabilityKey = '',
        private readonly string $ruleNineteenDestinationCapabilityKey = '',
    ) {
    }

    /**
     * @return array{
     *     allowed:bool,
     *     code:string,
     *     message:string,
     *     capabilityKey:?string
     * }
     */
    public function evaluate(
        InvoiceSnapshot $invoice,
        TaxDecision $taxDecision,
        DocumentTargetDecision $target,
        bool $isEInvoice = false,
    ): array {
        if ($invoice->discounts === []) {
            return self::allow(null);
        }
        if (
            !$target->allowed
            || $target->documentType !== DocumentTargetDecision::DOCUMENT_INVOICE
            || $target->exportMode !== DocumentTargetResolver::MODE_INVOICE_ONLY
        ) {
            return self::block(
                'invoice_discount_mode_not_supported',
                'Feste PromoHosting-Rabatte sind ausschließlich im Modus invoice_only freigegeben.',
            );
        }
        if (count($invoice->discounts) !== 1) {
            return self::block(
                'invoice_discount_structure_not_supported',
                'Genau ein strukturell zugeordneter PromoHosting-Rabatt wird unterstützt.',
            );
        }
        if ($isEInvoice) {
            return self::block(
                'e_invoice_discount_not_supported',
                'Native E-Rechnungen mit WHMCS-Rabatt sind nicht freigegeben.',
            );
        }
        if (!$taxDecision->allowed || $taxDecision->taxRuleId === null) {
            return self::block($taxDecision->code, $taxDecision->message);
        }

        $firstLine = $invoice->lineItems[0];
        $net = $firstLine->net;
        $rateMinor = Decimal::toMinorUnits($firstLine->taxRate);
        foreach ($invoice->lineItems as $lineItem) {
            if (
                $lineItem->net !== $net
                || Decimal::toMinorUnits($lineItem->taxRate) !== $rateMinor
            ) {
                return self::block(
                    'invoice_discount_tax_context_mismatch',
                    'Positionen und Rabatt müssen denselben Netto-/Bruttomodus und Steuersatz verwenden.',
                );
            }
        }

        $discount = $invoice->discounts[0];
        if (
            $discount->net !== $net
            || Decimal::toMinorUnits($discount->taxRate) !== $rateMinor
        ) {
            return self::block(
                'invoice_discount_tax_context_mismatch',
                'Positionen und Rabatt müssen denselben Netto-/Bruttomodus und Steuersatz verwenden.',
            );
        }

        $mode = $net ? 'exclusive' : 'inclusive';
        if ($taxDecision->taxRuleId === '11' && $rateMinor === 0) {
            return $this->ruleElevenZeroConfirmed
                ? self::allow(self::RULE_11_ZERO . '_' . ($net ? 'net' : 'gross') . '_v1')
                : self::block(
                    'invoice_discount_canary_not_confirmed',
                    'Der bestehende Rule-11-Rabatt-Canary ist noch nicht bestätigt.',
                );
        }
        if (
            $taxDecision->taxRuleId === '1'
            && $taxDecision->profile === 'domestic'
            && $rateMinor === 1900
        ) {
            return self::requireExactKey(
                $this->ruleOneNineteenCapabilityKey,
                self::capabilityKey('domestic', '1', $rateMinor, $mode),
                'invoice_discount_rule1_19_canary_not_confirmed',
                'Der separate Rabatt-Canary für Rule 1 mit 19 % ist nicht für diesen exakten '
                    . 'Steuer- und WHMCS-Modus bestätigt.',
            );
        }
        if (
            $taxDecision->taxRuleId === '17'
            && $taxDecision->profile === 'third_country'
            && $rateMinor === 0
        ) {
            return self::requireExactKey(
                $this->ruleSeventeenZeroCapabilityKey,
                self::capabilityKey('third_country', '17', $rateMinor, $mode),
                'invoice_discount_rule17_0_canary_not_confirmed',
                'Der separate Rabatt-Canary für Rule 17 mit 0 % ist nicht für diesen exakten '
                    . 'Steuer- und WHMCS-Modus bestätigt.',
            );
        }
        if (
            $taxDecision->taxRuleId === '19'
            && $taxDecision->profile === 'eu_b2c_rule19'
            && $rateMinor > 0
        ) {
            $rateAllowed = false;
            foreach ($taxDecision->allowedTaxRates as $allowedRate) {
                if (Decimal::toMinorUnits($allowedRate) === $rateMinor) {
                    $rateAllowed = true;
                    break;
                }
            }
            if (!$rateAllowed) {
                return self::block(
                    'invoice_discount_oss_tax_rate_mismatch',
                    'Der Rabattsteuersatz stimmt nicht mit der bestätigten Rule-19-Zielbesteuerung überein.',
                );
            }

            return self::requireExactKey(
                $this->ruleNineteenDestinationCapabilityKey,
                self::capabilityKey('eu_b2c_rule19', '19', $rateMinor, $mode),
                'invoice_discount_rule19_canary_not_confirmed',
                'Der separate Rabatt-Canary für Rule 19 ist nicht für diesen exakten '
                    . 'Zielsteuersatz und WHMCS-Modus bestätigt.',
            );
        }

        return self::block(
            'invoice_discount_tax_rule_not_supported',
            'Diese Kombination aus Tax Rule und Steuersatz ist für feste PromoHosting-Rabatte nicht freigegeben.',
        );
    }

    /**
     * @return array{allowed:true,code:'allowed',message:string,capabilityKey:?string}
     */
    private static function allow(?string $capabilityKey): array
    {
        return [
            'allowed' => true,
            'code' => 'allowed',
            'message' => 'Der feste PromoHosting-Rabatt entspricht einer bestätigten Capability.',
            'capabilityKey' => $capabilityKey,
        ];
    }

    /**
     * @return array{allowed:false,code:string,message:string,capabilityKey:null}
     */
    private static function block(string $code, string $message): array
    {
        return [
            'allowed' => false,
            'code' => $code,
            'message' => $message,
            'capabilityKey' => null,
        ];
    }

    public static function capabilityKey(
        string $taxProfile,
        string $taxRule,
        int $rateMinor,
        string $whmcsMode,
    ): string {
        $countryClass = match ($taxProfile) {
            'domestic' => 'domestic',
            'third_country' => 'third_country',
            'eu_b2c_rule19' => 'eu_b2c',
            default => throw new \InvalidArgumentException('Unsupported Invoice discount tax profile.'),
        };
        if (
            !in_array($taxRule, ['1', '17', '19'], true)
            || $rateMinor < 0
            || !in_array($whmcsMode, ['exclusive', 'inclusive'], true)
        ) {
            throw new \InvalidArgumentException('Invalid Invoice discount capability context.');
        }

        return 'promo_discount_v2'
            . '_profile_' . $taxProfile
            . '_country_' . $countryClass
            . '_rule_' . $taxRule
            . '_rate_' . $rateMinor
            . '_whmcs_' . $whmcsMode;
    }

    /**
     * @return array{allowed:bool,code:string,message:string,capabilityKey:?string}
     */
    private static function requireExactKey(
        string $storedKey,
        string $expectedKey,
        string $code,
        string $message,
    ): array {
        $storedKey = trim($storedKey);
        if ($storedKey === '' || !hash_equals($storedKey, $expectedKey)) {
            return self::block($code, $message);
        }

        return self::allow($expectedKey);
    }
}
