<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once dirname(__DIR__, 2) . '/modules/addons/sevdesk/sevdesk.php';

final class SevdeskEntrypointTest extends TestCase
{
    public function testStandardWhmcsConfigurePanelExposesNoOperationalSettings(): void
    {
        $configuration = \sevdesk_config();

        self::assertArrayHasKey('fields', $configuration);
        self::assertSame([], $configuration['fields']);
        self::assertStringContainsString('Modul-Seite „Einrichtung“', (string) $configuration['description']);
    }

    public function testActivationAndUpgradeDisableAutomaticSyncBeforeEnablingRuntime(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/addons/sevdesk/sevdesk.php');
        self::assertIsString($source);

        foreach (
            [
                $this->sourceBetween($source, 'function sevdesk_activate()', 'function sevdesk_upgrade'),
                $this->sourceBetween($source, 'function sevdesk_upgrade', 'function sevdesk_deactivate'),
            ] as $method
        ) {
            $disableSync = strpos($method, "set('sync_enabled', '')");
            $enableRuntime = strpos($method, "set('module_active', 'on')");
            self::assertNotFalse($disableSync);
            self::assertNotFalse($enableRuntime);
            self::assertLessThan($enableRuntime, $disableSync);
        }
    }

    private function sourceBetween(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        $end = strpos($source, $endMarker);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }
}
