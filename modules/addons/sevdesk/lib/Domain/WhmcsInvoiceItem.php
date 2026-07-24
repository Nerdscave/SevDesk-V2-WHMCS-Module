<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/**
 * A WHMCS invoice item with the structural fields required for safe
 * PromoHosting classification.
 */
final class WhmcsInvoiceItem
{
    public readonly LineItem $lineItem;

    public function __construct(
        public readonly string $type,
        public readonly int $relatedId,
        public readonly bool $taxed,
        #[\SensitiveParameter]
        string $description,
        string $amount,
        string $invoiceTaxRate,
        bool $net,
    ) {
        if ($relatedId < 0) {
            throw new \InvalidArgumentException('The related WHMCS item ID cannot be negative.');
        }

        $this->lineItem = new LineItem(
            $description,
            $amount,
            $taxed ? $invoiceTaxRate : '0',
            $net,
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function fromWhmcs(array $item, string $invoiceTaxRate, string $taxType): self
    {
        $taxType = strtolower(trim($taxType));
        if (!in_array($taxType, ['exclusive', 'inclusive'], true)) {
            throw new \InvalidArgumentException('WHMCS TaxType must be Inclusive or Exclusive.');
        }

        $relatedId = $item['relid'] ?? 0;
        if (
            (!is_int($relatedId) && !is_string($relatedId))
            || preg_match('/^\d+$/', (string) $relatedId) !== 1
        ) {
            throw new \InvalidArgumentException('The related WHMCS item ID is invalid.');
        }

        return new self(
            (string) ($item['type'] ?? ''),
            (int) $relatedId,
            self::taxed($item['taxed'] ?? null),
            (string) ($item['description'] ?? 'WHMCS invoice item'),
            (string) ($item['amount'] ?? ''),
            $invoiceTaxRate,
            $taxType === 'exclusive',
        );
    }

    public function amountMinorUnits(): int
    {
        return Decimal::toMinorUnits($this->lineItem->amount);
    }

    public function isType(string $expected): bool
    {
        return strcasecmp($this->type, $expected) === 0;
    }

    private static function taxed(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new \InvalidArgumentException(
            'The taxed flag is required and must be a boolean or zero/one.',
        );
    }
}
