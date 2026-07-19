<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;
use RuntimeException;

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once dirname(__DIR__, 2) . '/modules/addons/sevdesk/sevdesk.php';

final class LegacyUpgradeEntrypointTest extends MariaDbTestCase
{
    public function testUnsignedRewriteUpgradeStopsAutomaticSyncOnceForReview(): void
    {
        Migrator::up();
        $config = new Config();
        $config->set('module_active', 'on');
        $config->set('sync_enabled', 'on');
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 1001,
            'sevdesk_id' => '70001',
        ]);

        \sevdesk_upgrade(['version' => '2.0.0']);

        $stored = (new Config())->stored();
        self::assertSame('on', $stored['module_active']);
        self::assertSame('', $stored['sync_enabled']);
        self::assertSame(Config::RUNTIME_SIGNATURE, $stored[Config::RUNTIME_SIGNATURE_SETTING]);
        self::assertSame('on', $stored[Config::RUNTIME_REVIEW_SETTING]);
        self::assertSame('70001', Capsule::table(Migrator::MAPPING_TABLE)->value('sevdesk_id'));
        self::assertNull(Capsule::table(Migrator::MAPPING_TABLE)->value('document_type'));
        Migrator::assertRuntimeSchema();
    }

    public function testOriginalModuleReplacementPreservesMappingsAndSettingsFailClosed(): void
    {
        Capsule::schema()->create(Migrator::MAPPING_TABLE, static function ($table): void {
            $table->increments('id');
            $table->integer('invoice_id')->nullable();
            $table->string('sevdesk_id', 255)->nullable();
        });
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            ['invoice_id' => 1101, 'sevdesk_id' => '71001'],
            ['invoice_id' => 1102, 'sevdesk_id' => null],
            ['invoice_id' => 9901, 'sevdesk_id' => '79901'],
        ]);
        Capsule::table('tbladdonmodules')->insert([
            ['module' => 'sevdesk', 'setting' => 'sevdesk_api_key', 'value' => 'synthetic-token'],
            ['module' => 'sevdesk', 'setting' => 'custom_field_id', 'value' => '42'],
            ['module' => 'sevdesk', 'setting' => 'accountingTypeGeneral', 'value' => '4400'],
            ['module' => 'sevdesk', 'setting' => 'licensekey', 'value' => 'legacy-license-value'],
            ['module' => 'sevdesk', 'setting' => 'sync_enabled', 'value' => 'on'],
            // A generic key collision must not masquerade as an established
            // rewrite installation while both rewrite job tables are absent.
            ['module' => 'sevdesk', 'setting' => 'module_active', 'value' => 'on'],
        ]);

        $before = $this->mappingFingerprint();

        // The original vendor's version is intentionally higher than ours:
        // product-family detection must use the missing runtime marker, not a
        // semantic comparison between unrelated version lines.
        \sevdesk_upgrade(['version' => '9.9.0']);
        \sevdesk_upgrade(['version' => '9.9.0']);

        $config = new Config();
        self::assertSame($before, $this->mappingFingerprint());
        self::assertSame(3, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('document_type')->count());
        self::assertSame('synthetic-token', $config->stored()['sevdesk_api_key']);
        self::assertSame('42', $config->stored()['custom_field_id']);
        self::assertSame('4400', $config->stored()['accountingTypeGeneral']);
        self::assertSame('legacy-license-value', $config->stored()['licensekey']);
        self::assertSame('', $config->stored()['sync_enabled']);
        self::assertSame('on', $config->stored()['module_active']);
        self::assertSame(Config::RUNTIME_SIGNATURE, $config->stored()[Config::RUNTIME_SIGNATURE_SETTING]);
        self::assertSame('on', $config->stored()[Config::RUNTIME_REVIEW_SETTING]);
        self::assertSame('9.9.0', $config->stored()['upgraded_from_version']);
        self::assertSame('voucher_only', $config->get('export_mode'));
        self::assertSame('whmcs', $config->get('document_authority'));
        self::assertSame('blocked', $config->get('oss_profile'));
        self::assertFalse($config->bool('invoice_canary_confirmed'));
    }

    public function testSameVersionAdminBootstrapStillRecognisesMissingRewriteRuntime(): void
    {
        Capsule::schema()->create(Migrator::MAPPING_TABLE, static function ($table): void {
            $table->increments('id');
            $table->integer('invoice_id')->nullable();
            $table->string('sevdesk_id', 255)->nullable();
        });
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 1201,
            'sevdesk_id' => '71201',
        ]);
        Capsule::table('tbladdonmodules')->insert([
            ['module' => 'sevdesk', 'setting' => 'module_active', 'value' => 'on'],
            ['module' => 'sevdesk', 'setting' => 'sync_enabled', 'value' => 'on'],
            ['module' => 'sevdesk', 'setting' => 'custom_field_id', 'value' => '42'],
        ]);

        \sevdesk_prepare_runtime();

        $config = new Config();
        self::assertSame('', $config->stored()['sync_enabled']);
        self::assertSame('on', $config->stored()['module_active']);
        self::assertSame(Config::RUNTIME_SIGNATURE, $config->stored()[Config::RUNTIME_SIGNATURE_SETTING]);
        self::assertSame('on', $config->stored()[Config::RUNTIME_REVIEW_SETTING]);
        self::assertSame('42', $config->stored()['custom_field_id']);
        self::assertTrue(Capsule::schema()->hasTable(Migrator::JOBS_TABLE));
        self::assertTrue(Capsule::schema()->hasTable(Migrator::ITEMS_TABLE));
        self::assertSame('71201', Capsule::table(Migrator::MAPPING_TABLE)->value('sevdesk_id'));
        self::assertNull(Capsule::table(Migrator::MAPPING_TABLE)->value('document_type'));
    }

    public function testReplacementDisablesSyncBeforeAFailingSchemaMigration(): void
    {
        Capsule::schema()->create(Migrator::MAPPING_TABLE, static function ($table): void {
            $table->increments('id');
            $table->integer('invoice_id')->nullable();
            $table->string('sevdesk_id', 255)->nullable();
        });
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            ['invoice_id' => 1301, 'sevdesk_id' => '71301'],
            ['invoice_id' => 1301, 'sevdesk_id' => '71302'],
        ]);
        Capsule::table('tbladdonmodules')->insert([
            ['module' => 'sevdesk', 'setting' => 'sync_enabled', 'value' => 'on'],
        ]);

        try {
            \sevdesk_prepare_runtime('9.9.0');
            self::fail('The duplicate legacy mapping must stop the migration.');
        } catch (RuntimeException $error) {
            self::assertSame(
                'Duplicate invoice mappings prevent creation of the unique index.',
                $error->getMessage(),
            );
        }

        self::assertSame('', (new Config())->stored()['sync_enabled']);
        self::assertSame('on', (new Config())->stored()[Config::RUNTIME_REVIEW_SETTING]);
        self::assertFalse(Capsule::schema()->hasTable(Migrator::JOBS_TABLE));
        self::assertFalse(Capsule::schema()->hasTable(Migrator::ITEMS_TABLE));
    }

    public function testReplacementQuarantinesBeforeWaitingForAnActiveRunner(): void
    {
        Capsule::schema()->create(Migrator::MAPPING_TABLE, static function ($table): void {
            $table->increments('id');
            $table->integer('invoice_id')->nullable();
            $table->string('sevdesk_id', 255)->nullable();
        });
        $config = new Config();
        $config->set('sync_enabled', 'on');
        $holder = new IlluminateCapsule();
        $holder->addConnection(Capsule::connection()->getConfig());
        $connection = $holder->getConnection();
        $acquired = $connection->selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            ['whmcs_sevdesk_job_runner'],
        );
        self::assertSame(1, (int) ($acquired->acquired ?? 0));

        try {
            try {
                \sevdesk_prepare_runtime('9.9.0');
                self::fail('Migration must not run concurrently with an active runner.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('runner is active', $error->getMessage());
            }
        } finally {
            $connection->selectOne(
                'SELECT RELEASE_LOCK(?) AS released',
                ['whmcs_sevdesk_job_runner'],
            );
        }

        $stored = (new Config())->stored();
        self::assertSame('', $stored['sync_enabled']);
        self::assertSame('', $stored[Config::RUNTIME_SIGNATURE_SETTING]);
        self::assertSame('on', $stored[Config::RUNTIME_REVIEW_SETTING]);
        self::assertFalse(Capsule::schema()->hasTable(Migrator::JOBS_TABLE));
        self::assertFalse(Capsule::schema()->hasTable(Migrator::ITEMS_TABLE));
    }

    private function mappingFingerprint(): string
    {
        $rows = Capsule::table(Migrator::MAPPING_TABLE)
            ->orderBy('id')
            ->get(['id', 'invoice_id', 'sevdesk_id'])
            ->map(static fn (object $row): array => [
                (int) $row->id,
                $row->invoice_id === null ? null : (int) $row->invoice_id,
                $row->sevdesk_id === null ? null : (string) $row->sevdesk_id,
            ])
            ->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
