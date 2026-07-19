<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HookActivationContractTest extends TestCase
{
    private string $hooks;

    protected function setUp(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/addons/sevdesk/hooks.php');
        self::assertIsString($source);
        $this->hooks = $source;
    }

    public function testSharedAutomaticEnqueueGateRequiresActivationAndSync(): void
    {
        $gate = $this->sourceBetween(
            'function sevdesk_automatic_enqueue_enabled',
            'function sevdesk_enqueue_invoice',
        );

        self::assertStringContainsString("bool('module_active')", $gate);
        self::assertStringContainsString("bool('sync_enabled')", $gate);
        self::assertStringContainsString('RUNTIME_SIGNATURE', $gate);
        self::assertStringContainsString('RUNTIME_REVIEW_SETTING', $gate);
    }

    public function testEveryEventDrivenEnqueuePathUsesTheSharedGate(): void
    {
        $methods = [
            $this->sourceBetween('function sevdesk_enqueue_invoice', 'function sevdesk_enqueue_review'),
            $this->sourceBetween('function sevdesk_enqueue_review', 'function sevdesk_enqueue_transaction_review'),
            $this->sourceBetween('function sevdesk_enqueue_transaction_review', "add_hook('AdminAreaHeadOutput'"),
        ];

        foreach ($methods as $method) {
            self::assertStringContainsString('sevdesk_automatic_enqueue_enabled($application)', $method);
        }
    }

    public function testCronRunnerRemainsAvailableForManualJobsWhenSyncIsDisabled(): void
    {
        $cronHook = $this->sourceBetween(
            "add_hook('AfterCronJob'",
            "add_hook('AdminInvoicesControlsOutput'",
        );

        self::assertStringContainsString("bool('module_active')", $cronHook);
        self::assertStringContainsString('RUNTIME_SIGNATURE', $cronHook);
        self::assertStringContainsString('RUNTIME_REVIEW_SETTING', $cronHook);
        self::assertStringNotContainsString('sync_enabled', $cronHook);
        self::assertStringContainsString('runner()->run(10, 50)', $cronHook);
    }

    public function testCliRunnerRemainsAvailableForManualJobsWhenSyncIsDisabled(): void
    {
        $worker = file_get_contents(dirname(__DIR__, 2) . '/modules/addons/sevdesk/cli/worker.php');
        self::assertIsString($worker);

        self::assertStringContainsString("bool('module_active')", $worker);
        self::assertStringContainsString('RUNTIME_SIGNATURE', $worker);
        self::assertStringContainsString('RUNTIME_REVIEW_SETTING', $worker);
        self::assertStringContainsString('Migrator::prepareWorkerRuntime($config)', $worker);
        self::assertStringContainsString('runner()->run($maxItems, $maxSeconds)', $worker);
    }

    public function testInvoiceQuickActionIsHiddenDuringRuntimeReview(): void
    {
        $start = strpos($this->hooks, "add_hook('AdminInvoicesControlsOutput'");
        self::assertNotFalse($start);
        $controls = substr($this->hooks, $start);

        self::assertStringContainsString('RUNTIME_REVIEW_SETTING', $controls);
    }

    private function sourceBetween(string $startMarker, string $endMarker): string
    {
        $start = strpos($this->hooks, $startMarker);
        $end = strpos($this->hooks, $endMarker);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);

        return substr($this->hooks, $start, $end - $start);
    }
}
