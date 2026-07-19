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

        self::assertSame('2.1.0-rc.1', $configuration['version']);
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
                $this->sourceBetween($source, 'function sevdesk_prepare_runtime', 'function sevdesk_activate'),
                $this->sourceBetween($source, 'function sevdesk_activate()', 'function sevdesk_upgrade'),
            ] as $method
        ) {
            $quarantine = strpos($method, 'quarantineRuntimeOrFail()');
            $migration = strpos($method, 'Migrator::up()');
            $signature = strpos(
                $method,
                'set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE)',
            );
            $enableRuntime = strpos($method, "set('module_active', 'on')");
            self::assertNotFalse($quarantine);
            self::assertNotFalse($migration);
            self::assertNotFalse($signature);
            self::assertNotFalse($enableRuntime);
            self::assertLessThan($migration, $quarantine);
            self::assertLessThan($signature, $migration);
            self::assertLessThan($enableRuntime, $signature);
            self::assertLessThan($enableRuntime, $quarantine);
            self::assertStringContainsString('quarantineRuntimeOrFail()', $method);
        }

        $configSource = file_get_contents(dirname(__DIR__, 2) . '/modules/addons/sevdesk/lib/Config.php');
        self::assertIsString($configSource);
        self::assertStringContainsString('RUNTIME_REVIEW_SETTING', $configSource);

        self::assertStringContainsString(
            'sevdesk_prepare_runtime($previousVersion)',
            $this->sourceBetween($source, 'function sevdesk_upgrade', 'function sevdesk_deactivate'),
        );
        self::assertStringContainsString(
            'sevdesk_prepare_runtime()',
            $this->sourceBetween($source, 'function sevdesk_output', 'function sevdesk_clientarea'),
        );
    }

    public function testMigrationDiagnosticsAreStableAndDoNotExposeUnknownErrors(): void
    {
        $known = \sevdesk_migration_diagnostic(new \RuntimeException(
            'Duplicate invoice mappings prevent creation of the unique index.',
        ));
        self::assertSame('legacy_duplicate_invoice_mapping', $known['code']);
        self::assertStringContainsString('Altzuordnungen', $known['message']);

        $unknown = \sevdesk_migration_diagnostic(new \RuntimeException(
            'SQLSTATE with private database details',
        ));
        self::assertSame('migration_failed', $unknown['code']);
        self::assertStringNotContainsString('SQLSTATE', $unknown['message']);

        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/addons/sevdesk/sevdesk.php');
        self::assertIsString($source);
        self::assertStringNotContainsString(
            "'description' => 'Die sevdesk-Tabellen konnten nicht sicher vorbereitet werden: ' . \$error->getMessage()",
            $source,
        );
    }

    public function testDeactivationPersistsTheClaimBlockingGateBeforeBestEffortSync(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/addons/sevdesk/sevdesk.php');
        self::assertIsString($source);
        $method = $this->sourceBetween($source, 'function sevdesk_deactivate', 'function sevdesk_sidebar');
        $moduleGate = strpos($method, "set('module_active', '')");
        $syncGate = strpos($method, "set('sync_enabled', '')");

        self::assertNotFalse($moduleGate);
        self::assertNotFalse($syncGate);
        self::assertLessThan($syncGate, $moduleGate);
        self::assertGreaterThanOrEqual(2, substr_count($method, 'catch (Throwable $error)'));
        self::assertStringContainsString("'status' => 'error'", $method);
        self::assertStringContainsString('if (!$moduleDisabled)', $method);
    }

    public function testReleaseAllowlistIncludesStandaloneUpgradeGuideAndLicense(): void
    {
        $root = dirname(__DIR__, 2);
        $script = file_get_contents($root . '/tools/build-release.sh');
        $upgrade = file_get_contents($root . '/modules/addons/sevdesk/UPGRADE.md');

        self::assertIsString($script);
        self::assertIsString($upgrade);
        self::assertFileExists($root . '/LICENSE');
        self::assertStringContainsString('${source_module}/UPGRADE.md', $script);
        self::assertStringContainsString('${root}/docs/operations.md', $script);
        self::assertStringContainsString('OPERATIONS.md', $script);
        self::assertStringContainsString('${root}/LICENSE', $script);
        self::assertStringContainsString('tar -C "${target}"', $script);
        self::assertStringContainsString('LICENSE modules', $script);
        self::assertStringContainsString('nicht deaktivieren', $upgrade);
        self::assertStringContainsString("configured_version=\"\$(sed", $script);
        self::assertStringContainsString('does not match module version', $script);
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
