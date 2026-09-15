<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\SetupForm;
use WHMCS\Module\Addon\SevDesk\Support\SetupValidationException;

final class SetupFormTest extends TestCase
{
    public function testFailedDraftKeepsEditableValuesButCannotReplaceSafetyStateOrExposeTokens(): void
    {
        $stored = [
            'sevdesk_api_key' => 'synthetic-stored-secret',
            'module_active' => 'on',
            'runtime_review_required' => 'on',
            'runtime_quarantine_token' => 'current-quarantine',
            'sync_enabled' => '',
            'smallBusinessOwner' => 'on',
            'custom_field_id' => '1',
            'accountingTypeGeneral' => '17',
            'sevdesk_email_body' => 'Old message',
        ];
        $draft = SetupForm::draft($stored, [
            'sevdesk_api_key' => 'synthetic-new-secret',
            'token' => 'synthetic-csrf',
            'module_active' => '',
            'runtime_review_required' => '',
            'runtime_quarantine_token' => 'stale-quarantine',
            'runtime_review_confirmed' => '1',
            'transition_inventory_confirmed' => '1',
            'custom_field_id' => '2',
            'accountingTypeGeneral' => '123',
            'import_after' => '2030-05-01',
            'small_business_until' => '2029-12-31',
            'e_invoice_active_from' => '2030-06-01',
            'sevdesk_email_body' => "Changed\n<script>synthetic</script>",
            'sync_enabled' => 'on',
            'invoice_discount_rule19_canary_confirmed' => 'on',
            'invoice_discount_rule19_canary_rate' => 'invalid rate',
        ]);

        self::assertSame('2', $draft['custom_field_id']);
        self::assertSame('123', $draft['accountingTypeGeneral']);
        self::assertSame('', $draft['smallBusinessOwner']);
        self::assertSame('on', $draft['sync_enabled']);
        self::assertSame('2030-05-01', $draft['import_after_iso']);
        self::assertSame('2029-12-31', $draft['small_business_until_iso']);
        self::assertSame('2030-06-01', $draft['e_invoice_active_from_iso']);
        self::assertSame("Changed\n<script>synthetic</script>", $draft['sevdesk_email_body']);
        self::assertTrue($draft['invoice_discount_rule19_canary_current']);
        self::assertFalse($draft['invoice_discount_rule1_19_canary_current']);
        self::assertSame('invalid rate', $draft['invoice_discount_rule19_canary_rate']);
        self::assertSame('on', $draft['runtime_review_required']);
        self::assertSame('current-quarantine', $draft['runtime_quarantine_token']);
        self::assertSame('on', $draft['module_active']);
        foreach (['token', 'sevdesk_api_key', 'runtime_review_confirmed', 'transition_inventory_confirmed'] as $key) {
            self::assertArrayNotHasKey($key, $draft);
        }
        self::assertSame('', $stored['sync_enabled']);
    }

    #[DataProvider('validRates')]
    public function testRateAcceptsExactLocalizedInput(string $input, string $expected): void
    {
        self::assertSame($expected, SetupForm::rule19Rate($input));
    }

    public static function validRates(): iterable
    {
        yield ['21', '21.00'];
        yield [' 21,5 ', '21.50'];
        yield ['21.50', '21.50'];
        yield ['0,01', '0.01'];
        yield ['100', '100.00'];
    }

    #[DataProvider('invalidRates')]
    public function testRateRejectsAmbiguousInputWithoutRoundingOrEchoingIt(string $input): void
    {
        try {
            SetupForm::rule19Rate($input);
            self::fail('An invalid rate must not authorize a capability.');
        } catch (SetupValidationException $error) {
            self::assertArrayHasKey('invoice-discount-rule19-canary-rate', $error->fieldErrors);
            self::assertStringContainsString('21,5', $error->getMessage());
            self::assertStringNotContainsString('<script>', $error->getMessage());
        }
    }

    public static function invalidRates(): iterable
    {
        foreach (['', '0', '-1', '100.01', '21.555', '1,000.00', '21%', '2e1', '<script>', '999999999999'] as $rate) {
            yield [$rate];
        }
    }
}
