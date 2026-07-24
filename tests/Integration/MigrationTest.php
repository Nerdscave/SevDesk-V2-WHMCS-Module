<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
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
        foreach (
            [
                'document_type', 'document_authority', 'document_number', 'document_ready_at', 'delivered_at',
                'pdf_sha256', 'is_e_invoice', 'xml_sha256',
            ] as $column
        ) {
            self::assertTrue(Capsule::schema()->hasColumn(Migrator::MAPPING_TABLE, $column));
        }
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
        Migrator::assertRuntimeSchema();
        self::assertSame('voucher_only', (new Config())->get('export_mode'));
        self::assertSame('off', (new Config())->get('e_invoice_mode'));
        self::assertFalse((new Config())->bool('e_invoice_canary_confirmed'));
        self::assertFalse((new Config())->bool('small_business_invoice_canary_confirmed'));
        self::assertSame('', (new Config())->get('small_business_until'));
    }

    public function testWorkerRuntimeRejectsUnsignedSchemaBeforeMigrationAndDisablesSync(): void
    {
        Migrator::up();
        $config = new Config();
        $config->set('sync_enabled', 'on');
        Capsule::schema()->table(Migrator::MAPPING_TABLE, static function ($table): void {
            $table->dropColumn('document_type');
        });

        try {
            Migrator::prepareWorkerRuntime($config);
            self::fail('An unsigned worker runtime must stop before repairing the schema.');
        } catch (RuntimeException $error) {
            self::assertSame(
                'The sevdesk replacement requires an admin-side upgrade review.',
                $error->getMessage(),
            );
        }

        self::assertFalse(Capsule::schema()->hasColumn(Migrator::MAPPING_TABLE, 'document_type'));
        $stored = (new Config())->stored();
        self::assertSame('', $stored['sync_enabled']);
        self::assertSame('', $stored[Config::RUNTIME_SIGNATURE_SETTING]);
        self::assertSame('on', $stored[Config::RUNTIME_REVIEW_SETTING]);
    }

    public function testWorkerRuntimeAcceptsOnlySignedCompleteSchema(): void
    {
        Migrator::up();
        $config = new Config();
        $config->set('sync_enabled', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);

        Migrator::prepareWorkerRuntime($config);

        self::assertSame('on', (new Config())->stored()['sync_enabled']);
        Migrator::assertRuntimeSchema();
    }

    public function testWorkerRuntimeDoesNotProcessAQuarantinedSignedSchema(): void
    {
        Migrator::up();
        $config = new Config();
        $config->set('sync_enabled', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, 'on');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inventory review');
        try {
            Migrator::prepareWorkerRuntime($config);
        } finally {
            $stored = (new Config())->stored();
            self::assertSame(Config::RUNTIME_SIGNATURE, $stored[Config::RUNTIME_SIGNATURE_SETTING]);
            self::assertSame('on', $stored[Config::RUNTIME_REVIEW_SETTING]);
            self::assertSame('on', $stored['sync_enabled']);
        }
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
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('document_type')->count());
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('document_authority')->count());
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('document_number')->count());
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('document_ready_at')->count());
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('delivered_at')->count());
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('pdf_sha256')->count());
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('is_e_invoice')->count());
        self::assertSame(12, Capsule::table(Migrator::MAPPING_TABLE)->whereNull('xml_sha256')->count());
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

    public function testInvoiceDefaultsAreSafeAndExistingOrUnknownSettingsSurvive(): void
    {
        Capsule::table('tbladdonmodules')->insert([
            [
                'module' => 'sevdesk',
                'setting' => 'export_mode',
                'value' => 'invoice_only',
            ],
            [
                'module' => 'sevdesk',
                'setting' => 'future_unknown_setting',
                'value' => 'preserve-me',
            ],
        ]);

        Migrator::up();
        Migrator::up();
        $config = new Config();

        self::assertSame('invoice_only', $config->get('export_mode'));
        self::assertSame('whmcs', $config->get('document_authority'));
        self::assertSame('blocked', $config->get('oss_profile'));
        self::assertFalse($config->bool('invoice_canary_confirmed'));
        self::assertSame('', $config->get('invoice_sev_user_id'));
        self::assertSame('', $config->get('invoice_unity_id'));
        self::assertSame('sevdesk', $config->get('invoice_delivery_channel'));
        self::assertSame('', $config->get('whmcs_invoice_email_template'));
        self::assertSame('Ihre Rechnung {invoice_number}', $config->get('sevdesk_email_subject'));
        self::assertSame(
            "Guten Tag,\n\nim Anhang finden Sie Ihre Rechnung {invoice_number}.",
            $config->get('sevdesk_email_body'),
        );
        self::assertFalse($config->bool('theme_adapter_confirmed'));
        self::assertFalse($config->bool('customer_number_contact_creation_confirmed'));
        self::assertSame('preserve-me', $config->stored()['future_unknown_setting']);
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
        self::assertNull(
            Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', 902)->value('document_type'),
        );
    }

    public function testTypedMappingLinkAndMetadataEnrichmentAreMonotonicAndIdempotent(): void
    {
        Migrator::up();
        $mappings = new MappingRepository();
        $readyAt = new DateTimeImmutable('2030-02-03 04:05:06');
        $deliveredAt = new DateTimeImmutable('2030-02-03 04:06:07');
        $hash = hash('sha256', 'synthetic-pdf');

        $mappings->linkDocument(
            903,
            '700903',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            ' INV-903 ',
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
        );
        $mappings->linkDocument(
            903,
            '700903',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'INV-903',
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
        );
        $mappings->enrichDocumentMetadata(
            903,
            '700903',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'INV-903',
            $readyAt,
            $deliveredAt,
            strtoupper($hash),
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
        );
        $mappings->enrichDocumentMetadata(
            903,
            '700903',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'INV-903',
            new DateTimeImmutable('2030-02-04 00:00:00'),
            new DateTimeImmutable('2030-02-04 00:00:01'),
            $hash,
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
        );

        $mapping = $mappings->findCompleteByInvoiceAndType(903, MappingRepository::DOCUMENT_TYPE_INVOICE);
        self::assertNotNull($mapping);
        self::assertSame('700903', (string) $mapping->sevdesk_id);
        self::assertSame('invoice', (string) $mapping->document_type);
        self::assertSame('sevdesk', (string) $mapping->document_authority);
        self::assertSame('INV-903', (string) $mapping->document_number);
        self::assertSame('2030-02-03 04:05:06', (string) $mapping->document_ready_at);
        self::assertSame('2030-02-03 04:06:07', (string) $mapping->delivered_at);
        self::assertSame($hash, (string) $mapping->pdf_sha256);
        self::assertNull(
            $mappings->findCompleteByInvoiceAndType(903, MappingRepository::DOCUMENT_TYPE_VOUCHER),
        );
    }

    public function testMetadataCanSafelyTypeAnExistingCompleteLegacyMapping(): void
    {
        Migrator::up();
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 904,
            'sevdesk_id' => '700904',
        ]);

        (new MappingRepository())->enrichDocumentMetadata(
            904,
            '700904',
            MappingRepository::DOCUMENT_TYPE_VOUCHER,
            'VOU-904',
            new DateTimeImmutable('2030-03-04 05:06:07'),
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
        );

        $mapping = Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', 904)->first();
        self::assertSame('voucher', (string) $mapping->document_type);
        self::assertSame('whmcs', (string) $mapping->document_authority);
        self::assertSame('VOU-904', (string) $mapping->document_number);
        self::assertSame('2030-03-04 05:06:07', (string) $mapping->document_ready_at);
    }

    public function testAssignmentPageUsesFrozenJobContextForAuthorityRuleAndDelivery(): void
    {
        Migrator::up();
        Capsule::table('tblinvoices')->insert([
            'id' => 907,
            'userid' => 20,
            'invoicenum' => 'INV-907',
            'date' => '2030-02-03',
            'status' => 'Paid',
        ]);
        $mappings = new MappingRepository();
        $mappings->linkDocument(907, '700907', MappingRepository::DOCUMENT_TYPE_INVOICE, 'INV-907');
        (new JobRepository())->create('single_export', [[
            'invoice_id' => 907,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:907',
            'candidate' => [
                'targetAllowed' => true,
                'targetDocumentType' => 'invoice',
                'targetDocumentAuthority' => 'sevdesk',
                'targetExportMode' => 'invoice_only',
                'targetOssProfile' => 'rule19_digital_services_confirmed',
                'targetEuB2cMode' => 'blocked',
                'targetDeliveryChannel' => 'sevdesk',
                'targetTaxRuleId' => '19',
                'deliveryState' => 'ready_not_delivered',
            ],
        ]]);

        $page = $mappings->paginate(1, 10);
        self::assertCount(1, $page['items']);
        self::assertSame('sevdesk', $page['items'][0]->document_authority);
        self::assertSame('19', $page['items'][0]->tax_rule);
        self::assertSame('ready_not_delivered', $page['items'][0]->delivery_state);
    }

    public function testAssignmentPageFailsClosedForMalformedNewestFrozenContext(): void
    {
        Migrator::up();
        Capsule::table('tblinvoices')->insert([
            'id' => 908,
            'userid' => 20,
            'invoicenum' => 'INV-908',
            'date' => '2030-02-03',
            'status' => 'Paid',
        ]);
        $mappings = new MappingRepository();
        $mappings->linkDocument(908, '700908', MappingRepository::DOCUMENT_TYPE_INVOICE, 'INV-908');
        $jobs = new JobRepository();
        $jobId = $jobs->create('single_export', [[
            'invoice_id' => 908,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:908',
            'candidate' => [
                'targetAllowed' => true,
                'targetDocumentType' => 'invoice',
                'targetDocumentAuthority' => 'sevdesk',
                'targetExportMode' => 'invoice_only',
                'targetOssProfile' => 'blocked',
                'targetEuB2cMode' => 'blocked',
                'targetDeliveryChannel' => 'whmcs_template',
                'targetTaxRuleId' => '1',
                'deliveryState' => 'ready_not_delivered',
            ],
        ]]);
        $now = '2030-02-03 04:05:06';
        Capsule::table(Migrator::ITEMS_TABLE)->insert([
            'job_id' => $jobId,
            'invoice_id' => 908,
            'action' => 'export_document',
            'status' => 'pending',
            'dedupe_key' => null,
            'checkpoint' => 'document_type_selected',
            'attempts' => 0,
            'available_at' => $now,
            'candidate_json' => '{invalid',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $page = $mappings->paginate(1, 10);

        self::assertCount(1, $page['items']);
        self::assertSame('', $page['items'][0]->document_authority);
        self::assertSame('', $page['items'][0]->tax_rule);
        self::assertSame('not_recorded', $page['items'][0]->delivery_state);
    }

    public function testTypedMappingNeverOverwritesConflictingTypeOrMetadata(): void
    {
        Migrator::up();
        $mappings = new MappingRepository();
        $mappings->linkDocument(905, '700905', MappingRepository::DOCUMENT_TYPE_VOUCHER, 'VOU-905');
        $mappings->enrichDocumentMetadata(
            905,
            '700905',
            MappingRepository::DOCUMENT_TYPE_VOUCHER,
            pdfSha256: hash('sha256', 'first-pdf'),
        );

        try {
            $mappings->linkDocument(905, '700905', MappingRepository::DOCUMENT_TYPE_INVOICE, 'INV-905');
            self::fail('A conflicting complete document type must not be overwritten.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('different document type', $error->getMessage());
        }

        try {
            $mappings->enrichDocumentMetadata(
                905,
                '700905',
                MappingRepository::DOCUMENT_TYPE_VOUCHER,
                pdfSha256: hash('sha256', 'different-pdf'),
            );
            self::fail('A conflicting PDF checksum must not be overwritten.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('different PDF checksum', $error->getMessage());
        }

        $mapping = Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', 905)->first();
        self::assertSame('voucher', (string) $mapping->document_type);
        self::assertSame('VOU-905', (string) $mapping->document_number);
        self::assertSame(hash('sha256', 'first-pdf'), (string) $mapping->pdf_sha256);
    }

    public function testDocumentAuthorityIsAdditiveAndCannotBeReinterpreted(): void
    {
        Migrator::up();
        $mappings = new MappingRepository();
        $mappings->linkDocument(
            909,
            '700909',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'INV-909',
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
        );

        try {
            $mappings->enrichDocumentMetadata(
                909,
                '700909',
                MappingRepository::DOCUMENT_TYPE_INVOICE,
                'INV-909',
                documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
            );
            self::fail('A confirmed document authority must not be overwritten.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('different document authority', $error->getMessage());
        }

        self::assertSame(
            MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
            Capsule::table(Migrator::MAPPING_TABLE)
                ->where('invoice_id', 909)
                ->value('document_authority'),
        );
    }

    public function testTypedMappingRejectsInvalidTypesAndDeliveryBeforeReadiness(): void
    {
        Migrator::up();
        $mappings = new MappingRepository();

        try {
            $mappings->linkDocument(906, '700906', 'credit_note');
            self::fail('An unsupported document type must be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('voucher or invoice', $error->getMessage());
        }

        $mappings->linkDocument(906, '700906', MappingRepository::DOCUMENT_TYPE_INVOICE, 'INV-906');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be ready');
        $mappings->enrichDocumentMetadata(
            906,
            '700906',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            deliveredAt: new DateTimeImmutable('2030-04-05 06:07:08'),
        );
    }

    public function testBlankLegacyRemoteIdIsAnIncompleteRecoveryMapping(): void
    {
        $this->createLegacyMappingTable();
        Capsule::table('tblinvoices')->insert([
            'id' => 905,
            'invoicenum' => 'SYN-905',
            'date' => '2000-01-01',
            'status' => 'Paid',
            'total' => '12.34',
        ]);
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 905,
            'sevdesk_id' => '   ',
        ]);

        Migrator::up();
        $mappings = new MappingRepository();

        self::assertNull($mappings->findCompleteByInvoice(905));
        self::assertSame(['complete' => 0, 'ambiguous' => 1, 'orphans' => 0], $mappings->counts());
        self::assertSame(1, $mappings->paginate(1, 20, status: 'incomplete')['total']);
        self::assertSame(0, $mappings->paginate(1, 20, status: 'mapped')['total']);
        self::assertSame(0, $mappings->paginate(1, 20, status: 'untyped')['total']);
        self::assertNull($mappings->paginate(1, 20, status: 'incomplete')['items'][0]->sevdesk_id);
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
