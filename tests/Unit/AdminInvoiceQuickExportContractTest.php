<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminInvoiceQuickExportContractTest extends TestCase
{
    public function testQuickExportIsAnExplicitCsrfProtectedAddonRoute(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root . '/modules/addons/sevdesk/lib/Controller.php');
        $controller = file_get_contents($root . '/modules/addons/sevdesk/lib/Controllers/AdminController.php');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertStringContainsString("'quickExport' => 'quickExport'", $routes);

        $method = $this->methodSource($controller, 'public function quickExport(): void', 'public function massImport(): void');
        self::assertStringContainsString('$this->csrf->assertPost();', $method);
        self::assertStringContainsString("'dedupe_key' => 'export_voucher:' . \$invoiceId", $method);
        self::assertStringContainsString("'source' => 'admin_invoice_quick_export'", $method);
        self::assertStringContainsString('$this->redirectToInvoice($invoiceId);', $method);
        self::assertStringContainsString("\$notice = 'queued';", $method);
        self::assertStringContainsString('catch (Throwable $readError)', $method);
        self::assertStringNotContainsString(
            'Der angelegte Exportjob enthält keine Jobposition.',
            $method,
        );
    }

    public function testBrowserRequestOnlyQueuesLocalWork(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/modules/addons/sevdesk/lib/Controllers/AdminController.php',
        );
        self::assertIsString($controller);
        $method = $this->methodSource($controller, 'public function quickExport(): void', 'public function massImport(): void');

        self::assertStringContainsString('jobs->create(', $method);
        foreach (['->client(', '->contacts(', '->exporter(', '->referenceData(', '->runner(', '->run('] as $remoteCall) {
            self::assertStringNotContainsString($remoteCall, $method);
        }
    }

    public function testEveryAdminExportFreezesTheFullRequestedDocumentContext(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/modules/addons/sevdesk/lib/Controllers/AdminController.php',
        );
        self::assertIsString($controller);

        $single = $this->methodSource(
            $controller,
            'public function singleImport(): void',
            'public function quickExport(): void',
        );
        $quick = $this->methodSource(
            $controller,
            'public function quickExport(): void',
            'public function massImport(): void',
        );
        $bulk = $this->methodSource(
            $controller,
            'public function massImport(): void',
            'public function jobs(): void',
        );
        self::assertStringContainsString('$candidate = $this->requestedExportContext();', $single);
        self::assertStringContainsString("'candidate' => \$candidate", $single);
        self::assertStringContainsString("'candidate' => \$this->requestedExportContext()", $quick);
        self::assertStringContainsString('$requestedContext = $this->requestedExportContext();', $bulk);
        self::assertStringContainsString("'candidate' => \$requestedContext", $bulk);

        $snapshot = $this->methodSource(
            $controller,
            'private function requestedExportContext(): array',
            'private function saveSetup(): void',
        );
        foreach (
            [
                'requestedExportMode',
                'requestedDocumentAuthority',
                'requestedOssProfile',
                'requestedEuB2cMode',
                'requestedDeliveryChannel',
            ] as $field
        ) {
            self::assertStringContainsString("'" . $field . "'", $snapshot);
        }
        self::assertStringContainsString("'delivery_requested' => false", $snapshot);
    }

    private function methodSource(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        $end = strpos($source, $endMarker);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }
}
