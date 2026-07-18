<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Health\HealthService;

final class HealthServiceTest extends TestCase
{
    #[DataProvider('whmcsVersionProvider')]
    public function testWhmcsVersionCompatibility(string $version, bool $expected): void
    {
        self::assertSame($expected, HealthService::supportsWhmcsVersion($version));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function whmcsVersionProvider(): iterable
    {
        yield 'minimum stable version' => ['8.13.4', true];
        yield 'WHMCS stable release suffix' => ['8.13.4-release.1', true];
        yield 'newer WHMCS 8 release suffix' => ['8.13.5-release.2', true];
        yield 'older patch version' => ['8.13.3-release.1', false];
        yield 'beta is not a stable release' => ['8.13.4-beta.1', false];
        yield 'newer release candidate remains unsupported' => ['8.13.5-rc.1', false];
        yield 'WHMCS 9 remains unsupported' => ['9.0.0-release.1', false];
    }

    public function testUnconfirmedOptionalTaxProfileIsAWarning(): void
    {
        self::assertSame(
            'warning',
            HealthService::taxProfileFailureStatus('unconfirmed_tax_profile', true),
        );
    }

    public function testRequiredOrInvalidTaxProfilesRemainErrors(): void
    {
        self::assertSame(
            'error',
            HealthService::taxProfileFailureStatus('unconfirmed_tax_profile', false),
        );
        self::assertSame(
            'error',
            HealthService::taxProfileFailureStatus('unsupported_receipt_guidance', true),
        );
    }
}
