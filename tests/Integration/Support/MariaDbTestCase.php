<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

abstract class MariaDbTestCase extends TestCase
{
    private static ?string $skipReason = null;

    /** @var array<string, int|string|bool> */
    private static array $connection = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $host = getenv('SEVDESK_TEST_DB_HOST');
        if (!is_string($host) || trim($host) === '') {
            if (self::databaseIsRequired()) {
                throw new RuntimeException('SEVDESK_TEST_DB_HOST is required for this integration test run.');
            }
            self::$skipReason = 'SEVDESK_TEST_DB_HOST is not set; MariaDB integration tests are opt-in locally.';

            return;
        }

        self::$connection = [
            'driver' => 'mysql',
            'host' => $host,
            'port' => self::environmentInt('SEVDESK_TEST_DB_PORT', 3306),
            'database' => self::environmentString('SEVDESK_TEST_DB_DATABASE', 'sevdesk_test'),
            'username' => self::environmentString('SEVDESK_TEST_DB_USERNAME', 'sevdesk'),
            'password' => self::environmentString('SEVDESK_TEST_DB_PASSWORD', 'sevdesk_test'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            // WHMCS explicitly requires MySQL strict mode to be disabled.
            'strict' => false,
        ];

        $lastError = null;
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            try {
                $capsule = new Capsule();
                $capsule->addConnection(self::$connection);
                $capsule->setAsGlobal();
                Capsule::connection()->getPdo();
                self::$skipReason = null;

                return;
            } catch (Throwable $error) {
                $lastError = $error;
                usleep(1_000_000);
            }
        }

        $message = sprintf(
            'MariaDB did not become available: %s',
            $lastError === null ? 'unknown connection failure' : $lastError->getMessage(),
        );
        if (self::databaseIsRequired()) {
            throw new RuntimeException($message, previous: $lastError);
        }
        self::$skipReason = $message;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        self::resetSchema();
    }

    protected static function createWhmcsTables(): void
    {
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module', 64);
            $table->string('setting', 191);
            $table->text('value')->nullable();
            $table->unique(['module', 'setting'], 'tbladdonmodules_module_setting_unique');
        });

        Capsule::schema()->create('tblinvoices', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('userid')->default(0);
            $table->string('invoicenum', 191)->nullable();
            $table->date('date')->nullable();
            $table->dateTime('datepaid')->nullable();
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->decimal('tax2', 16, 2)->default(0);
            $table->decimal('taxrate', 8, 4)->default(0);
            $table->decimal('taxrate2', 8, 4)->default(0);
            $table->string('status', 32)->default('Paid');
            $table->decimal('total', 16, 2)->default(0);
        });

        Capsule::schema()->create('tblclients', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('currency')->default(0);
            $table->string('firstname', 191)->default('');
            $table->string('lastname', 191)->default('');
            $table->string('companyname', 191)->default('');
            $table->string('country', 2)->default('');
            $table->boolean('taxexempt')->default(false);
            $table->string('tax_id', 191)->nullable();
        });

        Capsule::schema()->create('tblcurrencies', static function ($table): void {
            $table->increments('id');
            $table->string('code', 3);
        });

        Capsule::schema()->create('tblaccounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid')->default(0);
            $table->dateTime('date')->nullable();
            $table->decimal('amountin', 16, 2)->default(0);
            $table->decimal('amountout', 16, 2)->default(0);
            $table->string('transid', 191)->default('');
            $table->string('gateway', 191)->default('');
            $table->unsignedInteger('refundid')->default(0);
            $table->unsignedInteger('currency')->default(0);
        });
    }

    protected static function resetSchema(): void
    {
        foreach (
            [
                Migrator::ITEMS_TABLE,
                Migrator::JOBS_TABLE,
                Migrator::MAPPING_TABLE,
                'tblaccounts',
                'tblinvoices',
                'tblclients',
                'tblcurrencies',
                'tbladdonmodules',
            ] as $table
        ) {
            Capsule::schema()->dropIfExists($table);
        }

        self::createWhmcsTables();
    }

    private static function environmentString(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function environmentInt(string $name, int $default): int
    {
        $value = getenv($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : $default;
    }

    private static function databaseIsRequired(): bool
    {
        return getenv('SEVDESK_TEST_DB_REQUIRED') === '1';
    }
}
