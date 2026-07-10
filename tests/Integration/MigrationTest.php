<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class MigrationTest extends MariaDbTestCase
{
    public function testFreshMigrationIsRepeatableAndCreatesRequiredUniqueIndexes(): void
    {
        Migrator::up();
        Migrator::up();

        self::assertTrue(Capsule::schema()->hasTable(Migrator::MAPPING_TABLE));
        self::assertTrue(Capsule::schema()->hasTable(Migrator::JOBS_TABLE));
        self::assertTrue(Capsule::schema()->hasTable(Migrator::ITEMS_TABLE));
        self::assertSame(
            ['mod_sevdesk_invoice_id_unique', 'mod_sevdesk_sevdesk_id_unique'],
            $this->mappingUniqueIndexNames(),
        );
        self::assertSame([
            'tables' => true,
            'missing_columns' => [],
            'mapping_invoice_unique' => true,
            'mapping_remote_unique' => true,
            'item_dedupe_unique' => true,
        ], Migrator::schemaReport());
    }

    public function testLegacyMappingInventorySurvivesMigrationUnchanged(): void
    {
        $this->createLegacyMappingTable();
        $this->insertSyntheticLegacyInventory();
        Capsule::table('tbladdonmodules')->insert([
            [
                'module' => 'sevdesk',
                'setting' => 'licensekey',
                'value' => 'synthetic-legacy-value',
            ],
            [
                'module' => 'sevdesk',
                'setting' => 'sevdesk_api_key',
                'value' => 'synthetic-token-not-a-secret',
            ],
            [
                'module' => 'sevdesk',
                'setting' => 'accountingTypeInterCommunityBusiness',
                'value' => '424242',
            ],
        ]);

        $before = $this->mappingChecksum();

        Migrator::up();
        Migrator::up();

        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->count());
        self::assertSame($before, $this->mappingChecksum());
        self::assertSame(
            ['complete' => 5, 'ambiguous' => 3, 'orphans' => 4],
            (new MappingRepository())->counts(),
        );
        self::assertSame(
            'synthetic-legacy-value',
            Capsule::table('tbladdonmodules')
                ->where('module', 'sevdesk')
                ->where('setting', 'licensekey')
                ->value('value'),
        );
        self::assertSame(
            'synthetic-token-not-a-secret',
            Capsule::table('tbladdonmodules')
                ->where('module', 'sevdesk')
                ->where('setting', 'sevdesk_api_key')
                ->value('value'),
        );
        self::assertSame(
            '424242',
            Capsule::table('tbladdonmodules')
                ->where('module', 'sevdesk')
                ->where('setting', 'accountingTypeInterCommunityBusiness')
                ->value('value'),
        );
        self::assertSame(
            '',
            Capsule::table('tbladdonmodules')
                ->where('module', 'sevdesk')
                ->where('setting', 'eu_b2b_goods_confirmed')
                ->value('value'),
        );
    }

    public function testEuB2bGoodsProfileRequiresPersistedConfirmation(): void
    {
        Migrator::up();
        $config = new Config();

        self::assertFalse($config->taxProfiles()['eu_b2b']['confirmed']);

        $config->set('eu_b2b_goods_confirmed', true);

        self::assertTrue($config->taxProfiles()['eu_b2b']['confirmed']);
    }

    public function testDuplicateLegacyMappingsAbortWithoutDeletingRows(): void
    {
        $this->createLegacyMappingTable();
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            ['invoice_id' => 77, 'sevdesk_id' => '88001'],
            ['invoice_id' => 77, 'sevdesk_id' => '88002'],
        ]);

        try {
            Migrator::up();
            self::fail('A duplicate legacy invoice mapping must abort the migration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Duplicate invoice mappings', $error->getMessage());
        }

        self::assertSame(2, Capsule::table(Migrator::MAPPING_TABLE)->count());
        self::assertSame(
            ['88001', '88002'],
            Capsule::table(Migrator::MAPPING_TABLE)->orderBy('id')->pluck('sevdesk_id')->all(),
        );
    }

    public function testMappingLinkNeverOverwritesDifferentCompleteRemoteId(): void
    {
        Migrator::up();
        $mappings = new MappingRepository();
        $mappings->link(901, '700901');
        $mappings->link(901, '700901');

        $this->expectException(RuntimeException::class);
        try {
            $mappings->link(901, '800901');
        } finally {
            self::assertSame(
                '700901',
                Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', 901)->value('sevdesk_id'),
            );
        }
    }

    public function testMappingLinkCanReconcileOneLegacyNullExactlyOnce(): void
    {
        Migrator::up();
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 902,
            'sevdesk_id' => null,
        ]);
        $mappings = new MappingRepository();

        $mappings->link(902, '700902');
        $mappings->link(902, '700902');

        self::assertSame(
            '700902',
            Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', 902)->value('sevdesk_id'),
        );
    }

    public function testMisnamedNonUniqueLegacyConstraintNeverPassesAsUnique(): void
    {
        Capsule::schema()->create(Migrator::MAPPING_TABLE, static function ($table): void {
            $table->increments('id');
            $table->integer('invoice_id')->nullable();
            $table->string('sevdesk_id', 255)->nullable();
            $table->index('invoice_id', 'mod_sevdesk_invoice_id_unique');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a unique single-column index');
        Migrator::up();
    }

    private function createLegacyMappingTable(): void
    {
        Capsule::schema()->create(Migrator::MAPPING_TABLE, static function ($table): void {
            $table->increments('id');
            $table->integer('invoice_id')->nullable();
            $table->string('sevdesk_id', 255)->nullable();
        });
    }

    private function insertSyntheticLegacyInventory(): void
    {
        $invoices = [];
        $mappings = [];
        for ($invoiceId = 1; $invoiceId <= 8; ++$invoiceId) {
            $invoices[] = [
                'id' => $invoiceId,
                'invoicenum' => 'SYN-' . $invoiceId,
                'date' => '2000-01-01',
                'status' => 'Paid',
                'total' => '12.34',
            ];
            $mappings[] = [
                'invoice_id' => $invoiceId,
                'sevdesk_id' => $invoiceId <= 5 ? (string) (500_000 + $invoiceId) : null,
            ];
        }
        for ($offset = 1; $offset <= 4; ++$offset) {
            $mappings[] = [
                'invoice_id' => 2_000 + $offset,
                'sevdesk_id' => (string) (600_000 + $offset),
            ];
        }

        foreach (array_chunk($invoices, 250) as $chunk) {
            Capsule::table('tblinvoices')->insert($chunk);
        }
        foreach (array_chunk($mappings, 250) as $chunk) {
            Capsule::table(Migrator::MAPPING_TABLE)->insert($chunk);
        }
    }

    private function mappingChecksum(): string
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

    /** @return list<string> */
    private function mappingUniqueIndexNames(): array
    {
        $names = [];
        foreach (Capsule::select('SHOW INDEX FROM `' . Migrator::MAPPING_TABLE . '`') as $index) {
            if ((int) $index->Non_unique === 0 && (string) $index->Key_name !== 'PRIMARY') {
                $names[] = (string) $index->Key_name;
            }
        }
        sort($names);

        return $names;
    }
}
