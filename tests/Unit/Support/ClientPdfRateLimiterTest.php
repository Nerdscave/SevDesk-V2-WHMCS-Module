<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\ClientPdfRateLimiter;

final class ClientPdfRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testSessionFallbackAllowsEightReadsAndThenThrottles(): void
    {
        for ($attempt = 1; $attempt <= 8; ++$attempt) {
            self::assertSame(
                ['allowed' => true, 'retryAfter' => 0],
                ClientPdfRateLimiter::consume(42, 1_000),
            );
        }

        self::assertSame(
            ['allowed' => false, 'retryAfter' => 60],
            ClientPdfRateLimiter::consume(42, 1_000),
        );
        self::assertSame(
            ['allowed' => true, 'retryAfter' => 0],
            ClientPdfRateLimiter::consume(43, 1_000),
        );
    }

    public function testWindowExpiresAndInvalidClientIdFailsClosed(): void
    {
        for ($attempt = 1; $attempt <= 8; ++$attempt) {
            ClientPdfRateLimiter::consume(42, 1_000);
        }

        self::assertSame(
            ['allowed' => true, 'retryAfter' => 0],
            ClientPdfRateLimiter::consume(42, 1_060),
        );
        self::assertSame(
            ['allowed' => false, 'retryAfter' => 60],
            ClientPdfRateLimiter::consume(0, 1_060),
        );
    }
}
