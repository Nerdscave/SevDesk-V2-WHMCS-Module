<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceItemExportPolicy;

final class InvoiceItemExportPolicyTest extends TestCase
{
    /** @return iterable<string, array{list<string>,bool}> */
    public static function itemTypes(): iterable
    {
        yield 'WHMCS spelling' => [['Hosting', 'LateFee'], true];
        yield 'case and whitespace do not bypass the guard' => [[' lateFEE '], true];
        yield 'ordinary revenue items' => [['Hosting', 'PromoHosting'], false];
        yield 'empty list' => [[], false];
    }

    /** @param list<string> $itemTypes */
    #[DataProvider('itemTypes')]
    public function testItRecognisesTheUnsupportedLateFeeType(
        array $itemTypes,
        bool $expected,
    ): void {
        self::assertSame($expected, InvoiceItemExportPolicy::containsLateFee($itemTypes));
    }
}
