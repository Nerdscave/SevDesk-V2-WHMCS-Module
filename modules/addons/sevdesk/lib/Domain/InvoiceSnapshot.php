<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

use DateTimeImmutable;

/** Immutable, already-normalised WHMCS invoice data used by the exporter. */
final class InvoiceSnapshot
{
    public readonly string $invoiceNumber;

    public readonly string $currency;

    /** Complete revenue-document gross, including applied WHMCS credit. */
    public readonly string $total;

    /** WHMCS credit included in the document gross. */
    public readonly string $creditApplied;

    /** @var non-empty-list<LineItem> */
    public readonly array $lineItems;

    /** @var list<InvoiceDiscount> */
    public readonly array $discounts;

    /** WHMCS subtotal after line discounts, used for exact taxable-discount readback. */
    public readonly ?string $netTotal;

    /** Combined WHMCS tax and tax2 amount. */
    public readonly ?string $taxTotal;

    /**
     * @param non-empty-list<LineItem> $lineItems
     * @param list<InvoiceDiscount> $discounts
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
        array $discounts = [],
        ?string $netTotal = null,
        ?string $taxTotal = null,
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
        foreach ($discounts as $discount) {
            if (!$discount instanceof InvoiceDiscount) {
                throw new \InvalidArgumentException('Every invoice discount must be an InvoiceDiscount.');
            }
        }

        $this->invoiceNumber = substr($invoiceNumber, 0, 160);
        $this->currency = $currency;
        $this->total = Decimal::assert($total, 'Invoice total');
        $this->creditApplied = Decimal::assert($creditApplied, 'Applied credit');
        $this->lineItems = array_values($lineItems);
        $this->discounts = array_values($discounts);
        if (($netTotal === null) !== ($taxTotal === null)) {
            throw new \InvalidArgumentException('Invoice net and tax totals must be provided together.');
        }
        $this->netTotal = $netTotal === null
            ? null
            : Decimal::assert($netTotal, 'Invoice net total');
        $this->taxTotal = $taxTotal === null
            ? null
            : Decimal::assert($taxTotal, 'Invoice tax total');

        if (Decimal::toMinorUnits($this->creditApplied) < 0) {
            throw new \InvalidArgumentException('Applied credit cannot be negative.');
        }
        if (
            $this->netTotal !== null
            && $this->taxTotal !== null
            && (
                Decimal::toMinorUnits($this->netTotal) < 0
                || Decimal::toMinorUnits($this->taxTotal) < 0
                || Decimal::toMinorUnits($this->netTotal)
                    + Decimal::toMinorUnits($this->taxTotal) !== $this->totalMinorUnits()
            )
        ) {
            throw new \InvalidArgumentException(
                'Invoice net and tax totals must be non-negative and equal the document gross.',
            );
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

    /** Amount paid directly on the invoice, excluding applied WHMCS credit. */
    public function directCashMinorUnits(): int
    {
        return $this->totalMinorUnits() - $this->appliedCreditMinorUnits();
    }

    public function lineGrossMinorUnits(): int
    {
        return array_sum(array_map(
            static fn (LineItem $lineItem): int => $lineItem->grossMinorUnits(),
            $this->lineItems,
        ));
    }

    public function discountGrossMinorUnits(): int
    {
        return array_sum(array_map(
            static fn (InvoiceDiscount $discount): int => $discount->grossMinorUnits(),
            $this->discounts,
        ));
    }

    /** Number of InvoicePos records expected from sevdesk, including the reduction. */
    public function remotePositionCount(): int
    {
        return count($this->lineItems) + count($this->discounts);
    }

    public function discountFingerprint(): ?string
    {
        if ($this->discounts === []) {
            return null;
        }

        $context = array_map(
            static fn (InvoiceDiscount $discount): array => [
                'sourceType' => 'PromoHosting',
                'remoteRepresentation' => 'negative_invoice_position',
                'text' => $discount->text,
                'valueMinor' => $discount->amountMinorUnits(),
                'positionValueMinor' => -$discount->amountMinorUnits(),
                'taxRateMinor' => Decimal::toMinorUnits($discount->taxRate),
                'net' => $discount->net,
                'relatedId' => $discount->relatedId,
                'taxed' => $discount->taxed,
            ],
            $this->discounts,
        );

        return hash('sha256', json_encode([
            'version' => 'whmcs_invoice_discount_negative_position_v2',
            'discounts' => $context,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function calculatedDocumentGrossMinorUnits(): int
    {
        return $this->lineGrossMinorUnits() - $this->discountGrossMinorUnits();
    }

    public function expectedNetMinorUnits(): ?int
    {
        if ($this->netTotal !== null) {
            return Decimal::toMinorUnits($this->netTotal);
        }
        if ($this->allRatesAreZero()) {
            return $this->totalMinorUnits();
        }

        return null;
    }

    public function expectedTaxMinorUnits(): ?int
    {
        if ($this->taxTotal !== null) {
            return Decimal::toMinorUnits($this->taxTotal);
        }
        if ($this->allRatesAreZero()) {
            return 0;
        }

        return null;
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

    private function allRatesAreZero(): bool
    {
        foreach ($this->lineItems as $lineItem) {
            if (Decimal::toMinorUnits($lineItem->taxRate) !== 0) {
                return false;
            }
        }
        foreach ($this->discounts as $discount) {
            if (Decimal::toMinorUnits($discount->taxRate) !== 0) {
                return false;
            }
        }

        return true;
    }
}
