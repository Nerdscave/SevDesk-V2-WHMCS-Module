<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

use DateTimeImmutable;

/** Immutable, already-normalised WHMCS invoice data used by the exporter. */
final class InvoiceSnapshot
{
    public readonly string $invoiceNumber;

    public readonly string $currency;

    public readonly string $total;

    public readonly string $creditApplied;

    /** @var non-empty-list<LineItem> */
    public readonly array $lineItems;

    /**
     * @param non-empty-list<LineItem> $lineItems
     */
    public function __construct(
        public readonly int $invoiceId,
        public readonly int $clientId,
        string $invoiceNumber,
        public readonly DateTimeImmutable $invoiceDate,
        string $currency,
        string $total,
        string $creditApplied,
        array $lineItems,
    ) {
        if ($invoiceId < 1 || $clientId < 1) {
            throw new \InvalidArgumentException('Invoice and client IDs must be positive.');
        }

        $invoiceNumber = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $invoiceNumber));
        if ($invoiceNumber === '') {
            throw new \InvalidArgumentException('The invoice number is required.');
        }

        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Currency must be a three-letter ISO code.');
        }

        if ($lineItems === []) {
            throw new \InvalidArgumentException('At least one invoice line item is required.');
        }
        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof LineItem) {
                throw new \InvalidArgumentException('Every invoice line must be a LineItem.');
            }
        }

        $this->invoiceNumber = substr($invoiceNumber, 0, 160);
        $this->currency = $currency;
        $this->total = Decimal::assert($total, 'Invoice total');
        $this->creditApplied = Decimal::assert($creditApplied, 'Applied credit');
        $this->lineItems = array_values($lineItems);

        if (Decimal::toMinorUnits($this->creditApplied) < 0) {
            throw new \InvalidArgumentException('Applied credit cannot be negative.');
        }
    }

    public function totalMinorUnits(): int
    {
        return Decimal::toMinorUnits($this->total);
    }

    public function appliedCreditMinorUnits(): int
    {
        return Decimal::toMinorUnits($this->creditApplied);
    }

    public function lineGrossMinorUnits(): int
    {
        return array_sum(array_map(
            static fn (LineItem $lineItem): int => $lineItem->grossMinorUnits(),
            $this->lineItems,
        ));
    }

    public function hasMixedNetModes(): bool
    {
        $first = $this->lineItems[0]->net;
        foreach ($this->lineItems as $lineItem) {
            if ($lineItem->net !== $first) {
                return true;
            }
        }

        return false;
    }
}
