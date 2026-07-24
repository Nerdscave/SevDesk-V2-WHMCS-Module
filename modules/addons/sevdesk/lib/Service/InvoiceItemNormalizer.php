<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceDiscount;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceItemNormalization;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\WhmcsInvoiceItem;

/**
 * Converts only structurally proven PromoHosting reductions into fixed
 * sevdesk discounts. Descriptions never participate in classification.
 */
final class InvoiceItemNormalizer
{
    /**
     * @param list<WhmcsInvoiceItem> $items
     */
    public function normalize(array $items): InvoiceItemNormalization
    {
        if ($items === []) {
            return InvoiceItemNormalization::block(
                'invoice_items_missing',
                'The WHMCS Invoice has no items to normalize.',
            );
        }

        foreach ($items as $item) {
            if (!$item instanceof WhmcsInvoiceItem) {
                return InvoiceItemNormalization::block(
                    'invalid_invoice_item',
                    'Every source item must include the required WHMCS structure.',
                );
            }
        }

        $taxContext = $this->validateSharedTaxContext($items);
        if ($taxContext !== null) {
            return $taxContext;
        }

        $positiveItems = [];
        $promoItems = [];
        foreach ($items as $index => $item) {
            $minor = $item->amountMinorUnits();
            if ($item->isType('PromoHosting')) {
                if ($minor >= 0) {
                    return InvoiceItemNormalization::block(
                        'promohosting_must_be_negative',
                        'A PromoHosting item is accepted only as a negative fixed discount.',
                    );
                }
                if ($item->relatedId < 1) {
                    return InvoiceItemNormalization::block(
                        'promohosting_pair_invalid_relid',
                        'A PromoHosting discount requires a positive related Hosting item ID.',
                    );
                }

                $promoItems[$index] = $item;
                continue;
            }

            if ($minor < 0) {
                return InvoiceItemNormalization::block(
                    'unsupported_negative_item',
                    'Only structurally matched PromoHosting items may be negative.',
                );
            }
            if ($minor === 0) {
                return InvoiceItemNormalization::block(
                    'non_positive_line_requires_review',
                    'Normal sevdesk Invoice positions must be positive.',
                );
            }

            $positiveItems[$index] = $item;
        }

        if ($positiveItems === []) {
            return InvoiceItemNormalization::block(
                'invoice_positive_items_missing',
                'The WHMCS Invoice has no positive position.',
            );
        }
        if (count($promoItems) > 1) {
            return InvoiceItemNormalization::block(
                'multiple_promohosting_discounts_require_review',
                'Only one structurally matched PromoHosting discount is supported per Invoice.',
            );
        }

        $discounts = [];
        $usedHostingIndices = [];
        foreach ($promoItems as $promo) {
            $matches = [];
            foreach ($positiveItems as $positiveIndex => $candidate) {
                if (
                    $candidate->isType('Hosting')
                    && $candidate->relatedId === $promo->relatedId
                    && $candidate->taxed === $promo->taxed
                ) {
                    $matches[$positiveIndex] = $candidate;
                }
            }

            if ($matches === []) {
                return InvoiceItemNormalization::block(
                    'promohosting_pair_not_found',
                    'The PromoHosting item has no positive Hosting partner with the same relation and tax flag.',
                );
            }
            if (count($matches) !== 1) {
                return InvoiceItemNormalization::block(
                    'promohosting_pair_ambiguous',
                    'The PromoHosting item matches more than one positive Hosting position.',
                );
            }

            $hostingIndex = array_key_first($matches);
            if ($hostingIndex === null || isset($usedHostingIndices[$hostingIndex])) {
                return InvoiceItemNormalization::block(
                    'promohosting_pair_ambiguous',
                    'Each PromoHosting item requires its own unambiguous Hosting partner.',
                );
            }
            $usedHostingIndices[$hostingIndex] = true;

            $hosting = $matches[$hostingIndex];
            if (
                $hosting->lineItem->net !== $promo->lineItem->net
                || self::canonicalDecimal($hosting->lineItem->taxRate)
                    !== self::canonicalDecimal($promo->lineItem->taxRate)
            ) {
                return InvoiceItemNormalization::block(
                    'promohosting_tax_context_mismatch',
                    'The PromoHosting item and its Hosting partner use different tax contexts.',
                );
            }
            if (abs($promo->amountMinorUnits()) > $hosting->amountMinorUnits()) {
                return InvoiceItemNormalization::block(
                    'promohosting_exceeds_hosting_amount',
                    'The PromoHosting discount exceeds its structurally matched Hosting position.',
                );
            }

            $discounts[] = new InvoiceDiscount(
                $promo->lineItem->description,
                self::absoluteDecimal($promo->lineItem->amount),
                $promo->lineItem->taxRate,
                $promo->lineItem->net,
                $promo->relatedId,
                $promo->taxed,
            );
        }

        $lines = array_map(
            static fn (WhmcsInvoiceItem $item): LineItem => $item->lineItem,
            array_values($positiveItems),
        );
        $expectedGrossMinorUnits = array_sum(array_map(
            static fn (LineItem $line): int => $line->grossMinorUnits(),
            $lines,
        )) - array_sum(array_map(
            static fn (InvoiceDiscount $discount): int => $discount->grossMinorUnits(),
            $discounts,
        ));

        if ($expectedGrossMinorUnits <= 0) {
            return InvoiceItemNormalization::block(
                'non_positive_total_requires_review',
                'The normalized Invoice total must be positive.',
            );
        }

        return InvoiceItemNormalization::allow($lines, $discounts, $expectedGrossMinorUnits);
    }

    /**
     * @param non-empty-list<WhmcsInvoiceItem> $items
     */
    private function validateSharedTaxContext(array $items): ?InvoiceItemNormalization
    {
        $net = $items[0]->lineItem->net;
        $taxRate = self::canonicalDecimal($items[0]->lineItem->taxRate);

        foreach ($items as $item) {
            if ($item->lineItem->net !== $net) {
                return InvoiceItemNormalization::block(
                    'mixed_net_gross_modes',
                    'All sevdesk Invoice positions and discounts must use the same net or gross mode.',
                );
            }
            if (self::canonicalDecimal($item->lineItem->taxRate) !== $taxRate) {
                return InvoiceItemNormalization::block(
                    'mixed_tax_rates',
                    'All positions and fixed discounts must use the same effective tax rate.',
                );
            }
        }

        return null;
    }

    private static function absoluteDecimal(string $value): string
    {
        $value = Decimal::assert($value, 'Discount amount');

        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }

    private static function canonicalDecimal(string $value): string
    {
        $value = Decimal::assert($value, 'Tax rate');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        return ($whole !== '' ? $whole : '0') . ($fraction !== '' ? '.' . $fraction : '');
    }
}
