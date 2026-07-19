<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

final class TaxDecision
{
    /**
     * @param list<string> $allowedTaxRates Normalised numeric rates, for example "0", "7", "19".
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
        public readonly string $profile,
        public readonly ?string $accountDatevId,
        public readonly ?string $taxRuleId,
        public readonly bool $guidanceValidated,
        public readonly array $allowedTaxRates,
    ) {
    }

    public static function allow(
        string $profile,
        string $accountDatevId,
        string $taxRuleId,
        string $message,
    ): self {
        return new self(
            true,
            'allowed',
            $message,
            $profile,
            $accountDatevId,
            $taxRuleId,
            false,
            [],
        );
    }

    /**
     * Select Invoice-only OSS rule 19 without weakening Voucher validation.
     *
     * Normal sevdesk Invoices do not accept the Voucher position field
     * accountDatev and are not validated through Receipt Guidance. The document
     * resolver still enforces the configured capability profile before a write.
     *
     * @param list<string> $allowedTaxRates Actual WHMCS line rates, normalised as decimal strings.
     */
    public static function allowInvoiceRule19(
        string $profile,
        string $message,
        array $allowedTaxRates,
    ): self {
        return self::allowInvoice($profile, '19', $message, $allowedTaxRates);
    }

    /**
     * Select an Invoice tax rule without inventing a Voucher accountDatev.
     *
     * @param list<string> $allowedTaxRates Rates admitted by the selected rule's contract.
     */
    public static function allowInvoice(
        string $profile,
        string $taxRuleId,
        string $message,
        array $allowedTaxRates,
    ): self {
        if (preg_match('/^[1-9]\d*$/', $taxRuleId) !== 1) {
            throw new \InvalidArgumentException('Invoice tax rule must be a positive numeric ID.');
        }

        $normalisedRates = [];
        foreach ($allowedTaxRates as $rate) {
            if (!is_string($rate)) {
                throw new \InvalidArgumentException('Invoice tax rates must be decimal strings.');
            }
            $normalisedRates[] = Decimal::assert($rate, 'Invoice tax rate');
        }

        return new self(
            true,
            'allowed',
            $message,
            $profile,
            null,
            $taxRuleId,
            false,
            array_values(array_unique($normalisedRates)),
        );
    }

    public static function block(string $code, string $message, string $profile = 'none'): self
    {
        return new self(false, $code, $message, $profile, null, null, false, []);
    }

    /** @param list<string> $allowedTaxRates */
    public function withValidatedGuidance(array $allowedTaxRates): self
    {
        if (!$this->allowed) {
            return $this;
        }

        $normalisedRates = [];
        foreach ($allowedTaxRates as $rate) {
            if (!is_string($rate)) {
                throw new \InvalidArgumentException('Validated tax rates must be decimal strings.');
            }
            $normalisedRates[] = Decimal::assert($rate, 'Validated tax rate');
        }

        return new self(
            true,
            $this->code,
            $this->message,
            $this->profile,
            $this->accountDatevId,
            $this->taxRuleId,
            true,
            array_values(array_unique($normalisedRates)),
        );
    }
}
