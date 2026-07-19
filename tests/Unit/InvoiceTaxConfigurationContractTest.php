<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InvoiceTaxConfigurationContractTest extends TestCase
{
    public function testApplicationComposesInvoiceTaxPolicyWithoutReceiptGuidance(): void
    {
        $application = $this->source('lib/Application.php');
        $method = $this->between(
            $application,
            'public function invoiceTaxPolicy(): TaxPolicy',
            'public function runner(): JobRunner',
        );

        self::assertStringContainsString('new TaxPolicy(', $method);
        self::assertStringContainsString("get('eu_b2c_mode'", $method);
        self::assertStringContainsString("get('oss_profile'", $method);
        self::assertStringContainsString("\n            null,", $method);
        self::assertStringNotContainsString('receiptGuidance(', $method);
    }

    public function testSetupUsesInvoicePolicyWithoutVoucherGuidanceInInvoiceOnlyMode(): void
    {
        $controller = $this->source('lib/Controllers/AdminController.php');
        $method = $this->between(
            $controller,
            'private function saveSetupWhileLocked(): void',
            'private function operationalSettingsChanged(): bool',
        );

        self::assertStringContainsString(
            "\$invoiceOnly = \$exportMode === DocumentTargetResolver::MODE_INVOICE_ONLY;",
            $method,
        );
        self::assertStringContainsString(
            "if (!\$invoiceOnly) {\n                \$this->application->referenceData()->receiptGuidance(true);",
            $method,
        );
        self::assertStringContainsString('$this->application->invoiceTaxPolicy()', $method);
        self::assertStringContainsString('$policy->decideInvoice(', $method);
        self::assertStringContainsString("\$ossProfile === 'rule19_digital_services_confirmed' && \$mode !== 'blocked'", $method);
    }

    public function testDryRunUsesInvoiceClassificationForInvoiceOnly(): void
    {
        $controller = $this->source('lib/Controllers/AdminController.php');
        $method = $this->between(
            $controller,
            'private function decorateDryRun(array $rows): array',
            'private function dryRunTaxReason(string $code, string $fallback = \'\'): string',
        );

        self::assertStringContainsString('$this->application->invoiceTaxPolicy()', $method);
        self::assertStringContainsString('$invoiceTaxPolicy->decideInvoice(...$arguments)', $method);
        self::assertStringContainsString('$voucherTaxPolicy ??= $this->application->taxPolicy();', $method);
        self::assertStringContainsString(
            '$exportMode === DocumentTargetResolver::MODE_INVOICE_FOR_OSS',
            $method,
        );
    }

    public function testHealthSkipsReceiptGuidanceForInvoiceOnly(): void
    {
        $health = $this->source('lib/Health/HealthService.php');
        $method = $this->between(
            $health,
            'public function run(bool $remote = true): array',
            'private function addTaxChecks(array &$checks): void',
        );

        self::assertStringContainsString("\$exportMode === 'invoice_only'", $method);
        self::assertStringContainsString('$this->addInvoiceTaxChecks($checks);', $method);
        self::assertStringContainsString('$this->application->referenceData()->receiptGuidance(true);', $method);
        $voucherBranch = strpos($method, '} elseif ($modeValid) {');
        $guidanceRead = strpos($method, '$this->application->referenceData()->receiptGuidance(true);');
        self::assertNotFalse($voucherBranch);
        self::assertNotFalse($guidanceRead);
        self::assertLessThan($guidanceRead, $voucherBranch);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/modules/addons/sevdesk/' . $relativePath;
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    private function between(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        $end = strpos($source, $endMarker, $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }
}
