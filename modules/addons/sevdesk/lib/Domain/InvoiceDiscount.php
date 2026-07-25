<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/**
 * A structurally matched PromoHosting reduction.
 *
 * The amount stays positive in the domain model so invoice-total calculations
 * remain explicit. At the sevdesk boundary it is emitted as one negative
 * InvoicePos; sevdesk's global discountSave contract is deliberately not used.
 */
final class InvoiceDiscount
{
    public readonly string $text;

    public readonly string $amount;

    public readonly string $taxRate;

    public function __construct(
        #[\SensitiveParameter]
        string $text,
        string $amount,
        string $taxRate,
        public readonly bool $net,
        public readonly int $relatedId,
        public readonly bool $taxed = false,
    ) {
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $text));
        $this->text = $text !== '' ? $text : 'WHMCS invoice discount';
        $this->amount = Decimal::assert($amount, 'Discount amount');
        $this->taxRate = Decimal::assert($taxRate, 'Discount tax rate');

        if ($relatedId < 1) {
            throw new \InvalidArgumentException('A PromoHosting discount requires a positive related item ID.');
        }
        if (Decimal::toMinorUnits($this->amount) <= 0) {
            throw new \InvalidArgumentException('The PromoHosting reduction amount must be positive.');
        }

        $rate = Decimal::toFloat($this->taxRate);
        if ($rate < 0 || $rate > 100) {
            throw new \InvalidArgumentException('Discount tax rate must be between 0 and 100.');
        }
    }

    public function amountMinorUnits(): int
    {
        return Decimal::toMinorUnits($this->amount);
    }

    public function grossMinorUnits(): int
    {
        return Decimal::grossMinorUnits($this->amount, $this->taxRate, $this->net);
    }

    public function negativeAmount(): string
    {
        return '-' . $this->amount;
    }
}
