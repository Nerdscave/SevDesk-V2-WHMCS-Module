<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/** Fail-closed result of classifying WHMCS invoice items for a sevdesk Invoice. */
final class InvoiceItemNormalization
{
    /**
     * @param list<LineItem> $lines
     * @param list<InvoiceDiscount> $discounts
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
        public readonly array $lines,
        public readonly array $discounts,
        public readonly ?int $expectedGrossMinorUnits,
    ) {
        if (!$allowed && ($lines !== [] || $discounts !== [] || $expectedGrossMinorUnits !== null)) {
            throw new \InvalidArgumentException('A blocked normalization must not expose a partial payload.');
        }
        if ($allowed && ($lines === [] || $expectedGrossMinorUnits === null || $expectedGrossMinorUnits <= 0)) {
            throw new \InvalidArgumentException('An allowed normalization requires lines and a positive gross total.');
        }
    }

    /**
     * @param non-empty-list<LineItem> $lines
     * @param list<InvoiceDiscount> $discounts
     */
    public static function allow(array $lines, array $discounts, int $expectedGrossMinorUnits): self
    {
        return new self(
            true,
            'invoice_items_normalized',
            'WHMCS invoice items were normalized for the sevdesk Invoice contract.',
            array_values($lines),
            array_values($discounts),
            $expectedGrossMinorUnits,
        );
    }

    public static function block(string $code, string $message): self
    {
        return new self(false, $code, $message, [], [], null);
    }

    /**
     * @return list<array{
     *     discount: true,
     *     text: string,
     *     percentage: false,
     *     value: float,
     *     objectName: 'Discounts',
     *     mapAll: true
     * }>
     */
    public function discountSavePayload(): array
    {
        return array_map(
            static fn (InvoiceDiscount $discount): array => $discount->toSevdeskPayload(),
            $this->discounts,
        );
    }
}
