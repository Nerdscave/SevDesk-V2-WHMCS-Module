<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\WhmcsInvoiceItem;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceItemNormalizer;

final class InvoiceItemNormalizerTest extends TestCase
{
    private InvoiceItemNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new InvoiceItemNormalizer();
    }

    public function testItConvertsAStructurallyMatchedPromoHostingItemIntoAFixedDiscount(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, true, 'Hosting package', '100.00', '19', true),
            $this->item('promohosting', 42, true, 'Any operator-defined promo text', '-20.00', '19', true),
        ]);

        self::assertTrue($result->allowed);
        self::assertSame('invoice_items_normalized', $result->code);
        self::assertCount(1, $result->lines);
        self::assertCount(1, $result->discounts);
        self::assertSame('100.00', $result->lines[0]->amount);
        self::assertSame('20.00', $result->discounts[0]->amount);
        self::assertSame(2_000, $result->discounts[0]->amountMinorUnits());
        self::assertSame(9_520, $result->expectedGrossMinorUnits);
        self::assertSame([
            [
                'discount' => true,
                'text' => 'Any operator-defined promo text',
                'percentage' => false,
                'value' => 20.0,
                'objectName' => 'Discounts',
                'mapAll' => true,
            ],
        ], $result->discountSavePayload());
    }

    public function testDescriptionNeverClassifiesADiscount(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '20.00'),
            $this->item('Manual', 42, false, 'PromoHosting discount for hosting', '-5.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('unsupported_negative_item', $result->code);
    }

    public function testMismatchedRelationIsBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '20.00'),
            $this->item('PromoHosting', 43, false, 'Discount', '-5.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('promohosting_pair_not_found', $result->code);
    }

    public function testMismatchedTaxedFlagIsBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '20.00', '0'),
            $this->item('PromoHosting', 42, true, 'Discount', '-5.00', '0'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('promohosting_pair_not_found', $result->code);
    }

    public function testMultiplePossibleHostingPartnersAreBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting A', '20.00'),
            $this->item('HOSTING', 42, false, 'Hosting B', '10.00'),
            $this->item('PromoHosting', 42, false, 'Discount', '-5.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('promohosting_pair_ambiguous', $result->code);
    }

    public function testForeignNegativeItemTypeIsBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '20.00'),
            $this->item('Domain', 99, false, 'Negative domain correction', '-5.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('unsupported_negative_item', $result->code);
    }

    public function testPositivePromoHostingItemIsBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '20.00'),
            $this->item('PromoHosting', 42, false, 'Not a discount', '5.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('promohosting_must_be_negative', $result->code);
    }

    public function testMultiplePromoHostingDiscountsRequireIndividualReview(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Domain', 7, false, 'Domain', '12.00'),
            $this->item('Hosting', 42, false, 'Hosting A', '20.00'),
            $this->item('PromoHosting', 42, false, 'Discount A', '-5.00'),
            $this->item('Hosting', 43, false, 'Hosting B', '30.00'),
            $this->item('PROMOHOSTING', 43, false, 'Discount B', '-10.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('multiple_promohosting_discounts_require_review', $result->code);
        self::assertSame([], $result->lines);
        self::assertSame([], $result->discounts);
    }

    public function testMixedTaxRatesAreBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, true, 'Hosting', '20.00', '19'),
            $this->item('Domain', 7, true, 'Domain', '10.00', '7'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('mixed_tax_rates', $result->code);
    }

    public function testMixedNetModesAreBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '20.00', '0', true),
            $this->item('Domain', 7, false, 'Domain', '10.00', '0', false),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('mixed_net_gross_modes', $result->code);
    }

    public function testNonPositiveNormalizedTotalIsBlocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '5.00'),
            $this->item('PromoHosting', 42, false, 'Discount', '-5.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('non_positive_total_requires_review', $result->code);
        self::assertSame([], $result->lines);
        self::assertSame([], $result->discounts);
        self::assertNull($result->expectedGrossMinorUnits);
    }

    public function testDiscountCannotExceedItsMatchedHostingPosition(): void
    {
        $result = $this->normalizer->normalize([
            $this->item('Hosting', 42, false, 'Hosting', '10.00'),
            $this->item('PromoHosting', 42, false, 'Discount', '-20.00'),
            $this->item('Domain', 7, false, 'Domain', '100.00'),
        ]);

        self::assertFalse($result->allowed);
        self::assertSame('promohosting_exceeds_hosting_amount', $result->code);
    }

    public function testWhmcsFactoryMapsTaxTypeAndTaxedFlagLikeLineItem(): void
    {
        $taxed = WhmcsInvoiceItem::fromWhmcs([
            'type' => 'Hosting',
            'relid' => '42',
            'taxed' => '1',
            'description' => 'Hosting',
            'amount' => '100.00',
        ], '19', 'Exclusive');
        $untaxed = WhmcsInvoiceItem::fromWhmcs([
            'type' => 'Domain',
            'relid' => 7,
            'taxed' => false,
            'description' => 'Domain',
            'amount' => '10.00',
        ], '19', 'Inclusive');

        self::assertTrue($taxed->lineItem->net);
        self::assertSame('19', $taxed->lineItem->taxRate);
        self::assertFalse($untaxed->lineItem->net);
        self::assertSame('0', $untaxed->lineItem->taxRate);
    }

    public function testWhmcsFactoryRejectsMissingOrAmbiguousTaxedFlags(): void
    {
        foreach ([null, 'foo', 'true', 2] as $taxed) {
            $item = [
                'type' => 'PromoHosting',
                'relid' => 42,
                'description' => 'Discount',
                'amount' => '-5.00',
            ];
            if ($taxed !== null) {
                $item['taxed'] = $taxed;
            }

            try {
                WhmcsInvoiceItem::fromWhmcs($item, '0', 'Exclusive');
                self::fail('Invalid taxed value was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('taxed flag', $exception->getMessage());
            }
        }
    }

    private function item(
        string $type,
        int $relatedId,
        bool $taxed,
        string $description,
        string $amount,
        string $taxRate = '0',
        bool $net = false,
    ): WhmcsInvoiceItem {
        return new WhmcsInvoiceItem(
            $type,
            $relatedId,
            $taxed,
            $description,
            $amount,
            $taxRate,
            $net,
        );
    }
}
