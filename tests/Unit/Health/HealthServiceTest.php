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

    public function testDomesticConfirmedEuB2cModeIsAWarning(): void
    {
        $notice = HealthService::euB2cPolicyNotice('domestic_confirmed', 'blocked');

        self::assertSame('warning', $notice['status']);
        self::assertStringContainsString('Deutsche USt', $notice['message']);
    }

    public function testConfirmedRule19ProfileIsAWarning(): void
    {
        $notice = HealthService::euB2cPolicyNotice('blocked', 'rule19_digital_services_confirmed');

        self::assertSame('warning', $notice['status']);
        self::assertStringContainsString('Rule 19', $notice['message']);
    }

    public function testFailClosedEuB2cConfigurationIsHealthy(): void
    {
        $notice = HealthService::euB2cPolicyNotice('blocked', 'blocked');

        self::assertSame('healthy', $notice['status']);
        self::assertStringContainsString('fail-closed', $notice['message']);
    }

    public function testUnconfirmedContactCreationPolicyIsAWarning(): void
    {
        $notice = HealthService::contactCreationPolicyNotice(false);

        self::assertSame('warning', $notice['status']);
        self::assertStringContainsString('kein Kontakt angelegt', $notice['message']);
        self::assertStringContainsString('Vorhandene IDs', $notice['message']);
    }

    public function testConfirmedContactCreationPolicyIsHealthy(): void
    {
        $notice = HealthService::contactCreationPolicyNotice(true);

        self::assertSame('healthy', $notice['status']);
        self::assertStringContainsString('customerNumber', $notice['message']);
    }

    #[DataProvider('euB2cWarningProvider')]
    public function testConfiguredTaxRiskBecomesAnActualWarningCheck(
        string $euB2cMode,
        string $ossProfile,
    ): void {
        $notice = HealthService::euB2cPolicyNotice($euB2cMode, $ossProfile);
        $service = (new \ReflectionClass(HealthService::class))->newInstanceWithoutConstructor();
        $checks = [];
        $arguments = [
            &$checks,
            'EU B2C / OSS Rule 19',
            $notice['status'] === 'healthy',
            $notice['message'],
            'warning',
        ];

        (new \ReflectionMethod(HealthService::class, 'add'))->invokeArgs($service, $arguments);

        self::assertFalse($checks[0]['ok']);
        self::assertSame('warning', $checks[0]['status']);
    }

    /** @return iterable<string, array{string,string}> */
    public static function euB2cWarningProvider(): iterable
    {
        yield 'legacy domestic confirmation' => ['domestic_confirmed', 'blocked'];
        yield 'Rule 19 profile' => ['blocked', 'rule19_digital_services_confirmed'];
    }

    #[DataProvider('documentConfigurationProvider')]
    public function testDocumentConfigurationValidation(
        string $exportMode,
        string $documentAuthority,
        string $ossProfile,
        string $euB2cMode,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            HealthService::documentConfigurationValid(
                $exportMode,
                $documentAuthority,
                $ossProfile,
                $euB2cMode,
            ),
        );
    }

    /** @return iterable<string, array{string,string,string,string,bool}> */
    public static function documentConfigurationProvider(): iterable
    {
        yield 'upgrade default' => ['voucher_only', 'whmcs', 'blocked', 'blocked', true];
        yield 'legacy domestic EU B2C voucher' => [
            'voucher_only', 'whmcs', 'blocked', 'domestic_confirmed', true,
        ];
        yield 'mixed OSS mode' => [
            'invoice_for_oss', 'whmcs', 'rule19_digital_services_confirmed', 'blocked', true,
        ];
        yield 'invoice only with WHMCS authority' => ['invoice_only', 'whmcs', 'blocked', 'blocked', true];
        yield 'invoice only with sevdesk authority' => [
            'invoice_only', 'sevdesk', 'rule19_digital_services_confirmed', 'blocked', true,
        ];
        yield 'sevdesk authority requires invoice only' => [
            'invoice_for_oss', 'sevdesk', 'rule19_digital_services_confirmed', 'blocked', false,
        ];
        yield 'OSS profile requires an Invoice capable mode' => [
            'voucher_only', 'whmcs', 'rule19_digital_services_confirmed', 'blocked', false,
        ];
        yield 'OSS and domestic EU B2C profiles conflict' => [
            'invoice_only', 'whmcs', 'rule19_digital_services_confirmed', 'domestic_confirmed', false,
        ];
        yield 'invalid OSS profile' => ['invoice_only', 'whmcs', 'rule20', 'blocked', false];
        yield 'invalid EU B2C mode' => ['invoice_only', 'whmcs', 'blocked', 'guess', false];
        yield 'invalid export mode' => ['auto', 'whmcs', 'blocked', 'blocked', false];
        yield 'invalid authority' => ['invoice_only', 'both', 'blocked', 'blocked', false];
    }
}
