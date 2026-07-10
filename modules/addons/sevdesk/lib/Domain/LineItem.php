<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

final class LineItem
{
    public readonly string $amount;

    public readonly string $taxRate;

    public readonly string $description;

    public function __construct(
        #[\SensitiveParameter]
        string $description,
        string $amount,
        string $taxRate,
        public readonly bool $net,
    ) {
        $description = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $description));
        $this->description = $description !== '' ? $description : 'WHMCS invoice item';
        $this->amount = Decimal::assert($amount, 'Line amount');
        $this->taxRate = Decimal::assert($taxRate, 'Tax rate');

        $rate = Decimal::toFloat($this->taxRate);
        if ($rate < 0 || $rate > 100) {
            throw new \InvalidArgumentException('Tax rate must be between 0 and 100.');
        }
    }

    public function grossMinorUnits(): int
    {
        return Decimal::grossMinorUnits($this->amount, $this->taxRate, $this->net);
    }
}
