<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\QuickExportGuard;

final class QuickExportGuardTest extends TestCase
{
    public function testStraightforwardSavedEuroInvoiceAllowsQuickQueueing(): void
    {
        self::assertNull(QuickExportGuard::blockReason(
            $this->invoice(),
            null,
            true,
            '01-01-2026',
            true,
            false,
        ));
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('blockedInvoiceProvider')]
    public function testKnownReviewCasesFailClosed(
        array $overrides,
        bool $hasItems,
        bool $hasNegativeLine,
        string $expected,
    ): void {
        self::assertSame($expected, QuickExportGuard::blockReason(
            $this->invoice($overrides),
            null,
            true,
            '01-01-2026',
            $hasItems,
            $hasNegativeLine,
        ));
    }

    /** @return iterable<string, array{array<string,mixed>,bool,bool,string}> */
    public static function blockedInvoiceProvider(): iterable
    {
        yield 'unpaid while only-paid enabled' => [
            ['status' => 'Unpaid'], true, false, QuickExportGuard::STATUS_BLOCKED,
        ];
        yield 'applied credit' => [
            ['credit' => '10.00'], true, false, QuickExportGuard::CREDIT_REQUIRES_REVIEW,
        ];
        yield 'full credit with zero direct cash' => [
            ['credit' => '119.00', 'total' => '0.00'],
            true,
            false,
            QuickExportGuard::CREDIT_REQUIRES_REVIEW,
        ];
        yield 'zero total' => [
            ['total' => '0.00'], true, false, QuickExportGuard::NON_POSITIVE_TOTAL,
        ];
        yield 'foreign currency' => [
            ['currencycode' => 'USD'], true, false, QuickExportGuard::FOREIGN_CURRENCY,
        ];
        yield 'no invoice lines' => [
            [], false, false, QuickExportGuard::EMPTY_INVOICE,
        ];
        yield 'negative invoice line' => [
            [], true, true, QuickExportGuard::NEGATIVE_LINE,
        ];
    }

    public function testExistingMappingsAlwaysTakePrecedence(): void
    {
        self::assertSame(
            QuickExportGuard::ALREADY_MAPPED,
            QuickExportGuard::blockReason(
                $this->invoice(),
                (object) ['sevdesk_id' => '123'],
                true,
                '01-01-2026',
                true,
                false,
            ),
        );
        self::assertSame(
            QuickExportGuard::AMBIGUOUS_LEGACY,
            QuickExportGuard::blockReason(
                $this->invoice(),
                (object) ['sevdesk_id' => null],
                true,
                '01-01-2026',
                true,
                false,
            ),
        );
    }

    public function testExportStartDateAndConfigurationFailClosed(): void
    {
        self::assertSame(
            QuickExportGuard::BEFORE_IMPORT_AFTER,
            QuickExportGuard::blockReason(
                $this->invoice(['date' => '2025-12-31']),
                null,
                true,
                '01-01-2026',
                true,
                false,
            ),
        );
        self::assertSame(
            QuickExportGuard::INVALID_CONFIGURATION,
            QuickExportGuard::blockReason(
                $this->invoice(),
                null,
                true,
                '2026-01-01',
                true,
                false,
            ),
        );
    }

    public function testPublishedUnpaidInvoiceIsAllowedWhenConfigured(): void
    {
        self::assertNull(QuickExportGuard::blockReason(
            $this->invoice(['status' => 'Unpaid']),
            null,
            false,
            '01-01-2026',
            true,
            false,
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(array $overrides = []): object
    {
        return (object) array_merge([
            'status' => 'Paid',
            'date' => '2026-07-10',
            'total' => '119.00',
            'credit' => '0.00',
            'currencycode' => 'EUR',
        ], $overrides);
    }
}
