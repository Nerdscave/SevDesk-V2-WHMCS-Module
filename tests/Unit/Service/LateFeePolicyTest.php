<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceItemNormalizationException;
use WHMCS\Module\Addon\SevDesk\Service\LateFeePolicy;

final class LateFeePolicyTest extends TestCase
{
    public function testItSeparatesPositiveUntaxedLateFeesAndFreezesTheirEvidence(): void
    {
        $invoice = $this->invoice();

        $result = LateFeePolicy::split($invoice, true);

        self::assertSame('100.00', $result['invoice']['subtotal']);
        self::assertSame('119.00', $result['invoice']['total']);
        self::assertSame('19.00', $result['invoice']['tax']);
        self::assertSame('Hosting', $result['invoice']['items']['item'][0]['type']);
        self::assertSame(500, $result['lateFee']['amountMinor']);
        self::assertSame(1, $result['lateFee']['itemCount']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['lateFee']['fingerprint']);

        $sameEvidence = $invoice;
        $sameEvidence['items']['item'][1]['description'] = 'Late fee July';
        self::assertNotSame(
            $result['lateFee']['fingerprint'],
            LateFeePolicy::split($sameEvidence, true)['lateFee']['fingerprint'],
        );
    }

    public function testItAggregatesMultipleLateFeeRowsCentExactly(): void
    {
        $invoice = $this->invoice();
        $invoice['subtotal'] = '107.50';
        $invoice['total'] = '126.50';
        $invoice['items']['item'][] = [
            'id' => 3,
            'type' => 'LateFee',
            'relid' => 0,
            'taxed' => false,
            'description' => 'Second late fee',
            'amount' => '2.50',
        ];

        $result = LateFeePolicy::split($invoice, true);

        self::assertSame('100.00', $result['invoice']['subtotal']);
        self::assertSame('119.00', $result['invoice']['total']);
        self::assertSame(750, $result['lateFee']['amountMinor']);
        self::assertSame(2, $result['lateFee']['itemCount']);
    }

    #[DataProvider('blockedLateFeeProvider')]
    public function testUncertainLateFeeShapesRemainBlocked(
        array $invoice,
        bool $enabled,
        string $expectedCode,
    ): void {
        try {
            LateFeePolicy::split($invoice, $enabled);
            self::fail('The unsafe LateFee shape was accepted.');
        } catch (InvoiceItemNormalizationException $error) {
            self::assertSame($expectedCode, $error->resultCode);
        }
    }

    /** @return iterable<string,array{array<string,mixed>,bool,string}> */
    public static function blockedLateFeeProvider(): iterable
    {
        $base = self::invoiceFixture();

        yield 'feature disabled' => [
            $base,
            false,
            'late_fee_tax_treatment_requires_review',
        ];

        $taxed = $base;
        $taxed['items']['item'][1]['taxed'] = true;
        yield 'taxed fee' => [$taxed, true, 'late_fee_structure_blocked'];

        $negative = $base;
        $negative['items']['item'][1]['amount'] = '-5.00';
        yield 'negative fee' => [$negative, true, 'late_fee_structure_blocked'];

        $credited = $base;
        $credited['credit'] = '1.00';
        yield 'credit and fee' => [$credited, true, 'late_fee_with_credit_requires_review'];

        $feeOnly = $base;
        $feeOnly['items']['item'] = [$feeOnly['items']['item'][1]];
        yield 'fee without service' => [$feeOnly, true, 'late_fee_without_service_lines'];
    }

    /** @return array<string,mixed> */
    private function invoice(): array
    {
        return self::invoiceFixture();
    }

    /** @return array<string,mixed> */
    private static function invoiceFixture(): array
    {
        return [
            'subtotal' => '105.00',
            'tax' => '19.00',
            'tax2' => '0.00',
            'total' => '124.00',
            'credit' => '0.00',
            'items' => ['item' => [
                [
                    'id' => 1,
                    'type' => 'Hosting',
                    'relid' => 42,
                    'taxed' => true,
                    'description' => 'Hosting',
                    'amount' => '100.00',
                ],
                [
                    'id' => 2,
                    'type' => 'LateFee',
                    'relid' => 0,
                    'taxed' => false,
                    'description' => 'Late fee',
                    'amount' => '5.00',
                ],
            ]],
        ];
    }
}
