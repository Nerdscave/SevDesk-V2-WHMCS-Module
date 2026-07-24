<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceDiscount;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;

final class InvoiceSnapshotTest extends TestCase
{
    public function testItCalculatesGrossLineTotalsAtCentPrecision(): void
    {
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'eur',
            '119.00',
            '0',
            [new LineItem('Hosting', '100.00', '19', true)],
        );

        self::assertSame(11_900, $invoice->lineGrossMinorUnits());
        self::assertSame(11_900, $invoice->totalMinorUnits());
        self::assertSame(11_900, $invoice->directCashMinorUnits());
        self::assertSame('EUR', $invoice->currency);
    }

    public function testItSeparatesAppliedCreditFromTheDirectCashAmount(): void
    {
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '119.00',
            '119.00',
            [new LineItem('Hosting', '100.00', '19', true)],
        );

        self::assertSame(11_900, $invoice->totalMinorUnits());
        self::assertSame(11_900, $invoice->appliedCreditMinorUnits());
        self::assertSame(0, $invoice->directCashMinorUnits());
    }

    public function testDecimalMinorUnitsUseHalfUpRounding(): void
    {
        self::assertSame(101, Decimal::toMinorUnits('1.005'));
        self::assertSame(-101, Decimal::toMinorUnits('-1.005'));
        self::assertSame('1.01', Decimal::fromMinorUnits(101));
        self::assertSame('-1.01', Decimal::fromMinorUnits(-101));
    }

    public function testItSubtractsFixedDiscountsFromTheDocumentGross(): void
    {
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '80.00',
            '0',
            [new LineItem('Hosting', '100.00', '0', false)],
            [new InvoiceDiscount('Promotion', '20.00', '0', false, 42)],
        );

        self::assertSame(10_000, $invoice->lineGrossMinorUnits());
        self::assertSame(2_000, $invoice->discountGrossMinorUnits());
        self::assertSame(8_000, $invoice->calculatedDocumentGrossMinorUnits());
    }

    public function testItDetectsMixedNetAndGrossLines(): void
    {
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '219',
            '0',
            [
                new LineItem('Net', '100', '19', true),
                new LineItem('Gross', '100', '19', false),
            ],
        );

        self::assertTrue($invoice->hasMixedNetModes());
    }
}
