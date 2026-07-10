<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Database;

use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;

final class Migrator
{
    public const MAPPING_TABLE = 'mod_sevdesk';
    public const JOBS_TABLE = 'mod_sevdesk_jobs';
    public const ITEMS_TABLE = 'mod_sevdesk_job_items';

    public static function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::MAPPING_TABLE)) {
            $schema->create(self::MAPPING_TABLE, static function ($table): void {
                $table->increments('id');
                $table->integer('invoice_id')->nullable();
                $table->string('sevdesk_id', 255)->nullable();
                $table->unique('invoice_id', 'mod_sevdesk_invoice_id_unique');
                $table->unique('sevdesk_id', 'mod_sevdesk_sevdesk_id_unique');
            });
        } else {
            self::ensureMappingIndexes();
        }

        if (!$schema->hasTable(self::JOBS_TABLE)) {
            $schema->create(self::JOBS_TABLE, static function ($table): void {
                $table->bigIncrements('id');
                $table->string('type', 64);
                $table->string('status', 32)->default('pending');
                $table->longText('filters_json')->nullable();
                $table->unsignedInteger('requested_by_admin_id')->nullable();
                $table->unsignedInteger('total_items')->default(0);
                $table->dateTime('created_at');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->dateTime('cancel_requested_at')->nullable();
                $table->dateTime('updated_at');
                $table->index(['status', 'created_at'], 'mod_sevdesk_jobs_status_created');
            });
        }

        if (!$schema->hasTable(self::ITEMS_TABLE)) {
            $schema->create(self::ITEMS_TABLE, static function ($table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('job_id');
                $table->integer('invoice_id')->nullable();
                $table->string('action', 64)->default('export_voucher');
                $table->string('status', 32)->default('pending');
                $table->string('dedupe_key', 191)->nullable();
                $table->string('checkpoint', 64)->default('queued');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->dateTime('available_at');
                $table->string('lease_token', 64)->nullable();
                $table->dateTime('leased_until')->nullable();
                $table->string('sevdesk_id', 255)->nullable();
                $table->string('transaction_reference', 191)->nullable();
                $table->longText('candidate_json')->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('exception_uuid', 128)->nullable();
                $table->string('error_code', 128)->nullable();
                $table->text('message')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->dateTime('updated_at');
                $table->unique('dedupe_key', 'mod_sevdesk_job_items_dedupe_unique');
                $table->index(['job_id', 'status'], 'mod_sevdesk_items_job_status');
                $table->index(['status', 'available_at'], 'mod_sevdesk_items_available');
                $table->index('invoice_id', 'mod_sevdesk_items_invoice');
            });
        }

        (new Config())->ensureDefaults();
    }

    private static function ensureMappingIndexes(): void
    {
        $indexes = Capsule::select('SHOW INDEX FROM `' . self::MAPPING_TABLE . '`');

        if (!self::hasUniqueSingleColumnIndex($indexes, 'invoice_id')) {
            if (self::hasIndexNamed($indexes, 'mod_sevdesk_invoice_id_unique')) {
                throw new RuntimeException('The legacy invoice index has the expected name but is not a unique single-column index.');
            }
            $duplicate = Capsule::table(self::MAPPING_TABLE)
                ->select('invoice_id')
                ->whereNotNull('invoice_id')
                ->groupBy('invoice_id')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicate !== null) {
                throw new RuntimeException('Duplicate invoice mappings prevent creation of the unique index.');
            }

            Capsule::schema()->table(self::MAPPING_TABLE, static function ($table): void {
                $table->unique('invoice_id', 'mod_sevdesk_invoice_id_unique');
            });
        }

        if (!self::hasUniqueSingleColumnIndex($indexes, 'sevdesk_id')) {
            if (self::hasIndexNamed($indexes, 'mod_sevdesk_sevdesk_id_unique')) {
                throw new RuntimeException('The legacy sevdesk index has the expected name but is not a unique single-column index.');
            }
            $duplicate = Capsule::table(self::MAPPING_TABLE)
                ->select('sevdesk_id')
                ->whereNotNull('sevdesk_id')
                ->groupBy('sevdesk_id')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicate !== null) {
                throw new RuntimeException('Duplicate sevdesk mappings prevent creation of the unique index.');
            }

            Capsule::schema()->table(self::MAPPING_TABLE, static function ($table): void {
                $table->unique('sevdesk_id', 'mod_sevdesk_sevdesk_id_unique');
            });
        }
    }

    /** @return array{tables:bool,missing_columns:list<string>,mapping_invoice_unique:bool,mapping_remote_unique:bool,item_dedupe_unique:bool} */
    public static function schemaReport(): array
    {
        $schema = Capsule::schema();
        $required = [
            self::MAPPING_TABLE => ['id', 'invoice_id', 'sevdesk_id'],
            self::JOBS_TABLE => [
                'id', 'type', 'status', 'filters_json', 'requested_by_admin_id',
                'total_items', 'created_at', 'started_at', 'finished_at',
                'cancel_requested_at', 'updated_at',
            ],
            self::ITEMS_TABLE => [
                'id', 'job_id', 'invoice_id', 'action', 'status', 'dedupe_key',
                'checkpoint', 'attempts', 'available_at', 'lease_token', 'leased_until',
                'sevdesk_id', 'transaction_reference', 'candidate_json', 'http_status',
                'exception_uuid', 'error_code', 'message', 'created_at', 'started_at',
                'finished_at', 'updated_at',
            ],
        ];
        $missing = [];
        foreach ($required as $table => $columns) {
            if (!$schema->hasTable($table)) {
                $missing[] = $table . '.*';
                continue;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        $mappingIndexes = $schema->hasTable(self::MAPPING_TABLE)
            ? Capsule::select('SHOW INDEX FROM `' . self::MAPPING_TABLE . '`')
            : [];
        $itemIndexes = $schema->hasTable(self::ITEMS_TABLE)
            ? Capsule::select('SHOW INDEX FROM `' . self::ITEMS_TABLE . '`')
            : [];

        return [
            'tables' => count(array_filter(
                array_keys($required),
                static fn (string $table): bool => $schema->hasTable($table),
            )) === count($required),
            'missing_columns' => $missing,
            'mapping_invoice_unique' => self::hasUniqueSingleColumnIndex($mappingIndexes, 'invoice_id'),
            'mapping_remote_unique' => self::hasUniqueSingleColumnIndex($mappingIndexes, 'sevdesk_id'),
            'item_dedupe_unique' => self::hasUniqueSingleColumnIndex($itemIndexes, 'dedupe_key'),
        ];
    }

    /** @param list<object> $indexes */
    private static function hasIndexNamed(array $indexes, string $name): bool
    {
        foreach ($indexes as $index) {
            if ((string) ($index->Key_name ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    /** @param list<object> $indexes */
    private static function hasUniqueSingleColumnIndex(array $indexes, string $column): bool
    {
        $definitions = [];
        foreach ($indexes as $index) {
            $name = (string) ($index->Key_name ?? '');
            if ($name === '' || $name === 'PRIMARY' || (int) ($index->Non_unique ?? 1) !== 0) {
                continue;
            }
            $definitions[$name][(int) ($index->Seq_in_index ?? 0)] = (string) ($index->Column_name ?? '');
        }
        foreach ($definitions as $columns) {
            ksort($columns);
            if (array_values($columns) === [$column]) {
                return true;
            }
        }

        return false;
    }
}
