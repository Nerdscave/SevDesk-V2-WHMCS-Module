<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Controllers\AdminController;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;
use WHMCS\Module\Addon\SevDesk\View;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class AdminMappingUnlinkBehaviorTest extends TestCase
{
    private static ?IlluminateCapsule $database = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!class_exists(Capsule::class, false)) {
            class_alias(IlluminateCapsule::class, Capsule::class);
        }
        self::$database = new IlluminateCapsule();
        self::$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        self::$database->setAsGlobal();
        $pdo = self::$database->getConnection()->getPdo();
        $dateFormat = static fn (string $timestamp, string $format): string => date(
            'Y-m-d H:i:s',
            strtotime($timestamp),
        );
        if (class_exists(\Pdo\Sqlite::class) && $pdo instanceof \Pdo\Sqlite) {
            $pdo->createFunction('DATE_FORMAT', $dateFormat, 2);
        } else {
            // PHP 8.3 exposes only this legacy PDO method; PHP 8.5 marks it
            // deprecated when the connection factory still returns base PDO.
            @$pdo->sqliteCreateFunction('DATE_FORMAT', $dateFormat, 2);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $tables = [
            'mod_sevdesk_job_items',
            'mod_sevdesk_jobs',
            'mod_sevdesk',
            'tblinvoices',
            'tbladdonmodules',
        ];
        foreach ($tables as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module');
            $table->string('setting');
            $table->text('value')->nullable();
            $table->unique(['module', 'setting']);
        });
        Capsule::schema()->create('tblinvoices', static function ($table): void {
            $table->increments('id');
            $table->string('invoicenum')->nullable();
        });
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->string('sevdesk_id')->nullable();
            $table->string('document_type')->nullable();
        });
        Capsule::schema()->create('mod_sevdesk_job_items', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('job_id');
            $table->unsignedInteger('invoice_id')->nullable();
            $table->string('action');
            $table->string('status');
            $table->string('dedupe_key')->nullable();
            $table->string('checkpoint')->default('queued');
            $table->string('lease_token')->nullable();
            $table->dateTime('leased_until')->nullable();
            $table->string('sevdesk_id')->nullable();
            $table->longText('candidate_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Capsule::schema()->create('mod_sevdesk_jobs', static function ($table): void {
            $table->increments('id');
            $table->string('status');
            $table->dateTime('cancel_requested_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Capsule::table('tblinvoices')->insert(['id' => 42, 'invoicenum' => 'SYNTHETIC-42']);
    }

    public function testCompleteMappingIsRemovedOnlyAfterBothRemoteTypesReturn404(): void
    {
        $mappingId = $this->insertMapping('7001', 'invoice');
        $application = $this->application([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]);

        $this->deleteMapping($application, $mappingId);

        self::assertFalse(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
    }

    public function testExistingRemoteDocumentKeepsTheCompleteMapping(): void
    {
        $mappingId = $this->insertMapping('7002', 'voucher');
        $application = $this->application([
            new Response(200, [], '{"id":"7002","objectName":"Voucher"}'),
        ]);

        $this->deleteMapping($application, $mappingId);

        self::assertTrue(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
    }

    public function testIndeterminateSecondReadKeepsTheCompleteMapping(): void
    {
        $mappingId = $this->insertMapping('7003', 'invoice');
        $application = $this->application([
            new Response(404, [], '{}'),
            new Response(500, [], '{}'),
        ]);

        $this->deleteMapping($application, $mappingId);

        self::assertTrue(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
    }

    public function testGenericBadRequestNeverProvesThatTheRemoteDocumentIsAbsent(): void
    {
        $mappingId = $this->insertMapping('7012', 'invoice');
        $application = $this->application([
            new Response(400, [], '{"error":{"code":"INVALID_PARAMETER"}}'),
        ]);

        $this->deleteMapping($application, $mappingId);

        self::assertTrue(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
    }

    public function testAuthenticationFailureKeepsMappingAndTripsTenantSafetyGates(): void
    {
        $mappingId = $this->insertMapping('7004', 'invoice');
        $application = $this->application([
            new Response(401, [], '{"error":{"code":"AUTHENTICATION_FAILED"}}'),
        ]);
        $application->config->set('sync_enabled', 'on');

        $this->deleteMapping($application, $mappingId);

        self::assertTrue(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
        self::assertFalse($application->config->bool('sync_enabled'));
        self::assertSame('api_authentication_failed', $application->config->get('health_alarm'));
    }

    public function testIncompleteReservationCanStillBeRemovedLocally(): void
    {
        $mappingId = $this->insertMapping(null, null);

        $this->deleteMapping($this->application([]), $mappingId);

        self::assertFalse(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
    }

    public function testMatchingTerminalRiskHistoryIsClosedWithTheMissingMapping(): void
    {
        $mappingId = $this->insertMapping('7005', 'invoice');
        $itemId = $this->insertExportHistory(
            '7005',
            'invoice',
            'permanent_failed',
            'mapping_persisted',
        );

        $this->deleteMapping($this->application([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]), $mappingId);

        self::assertFalse(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
        $item = Capsule::table('mod_sevdesk_job_items')->where('id', $itemId)->first();
        self::assertNotNull($item);
        self::assertSame('skipped', $item->status);
        self::assertSame('remote_absence_confirmed', $item->checkpoint);
        self::assertSame('remote_document_absence_confirmed', $item->error_code);
        self::assertNull($item->dedupe_key);
        self::assertSame(
            'completed',
            Capsule::table('mod_sevdesk_jobs')->where('id', $item->job_id)->value('status'),
        );
    }

    public function testDifferentHistoryRemoteIdKeepsMappingAndHistory(): void
    {
        $mappingId = $this->insertMapping('7006', 'invoice');
        $itemId = $this->insertExportHistory(
            '9999',
            'invoice',
            'permanent_failed',
            'mapping_persisted',
        );

        $this->deleteMapping($this->application([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]), $mappingId);

        self::assertTrue(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
        $item = Capsule::table('mod_sevdesk_job_items')->where('id', $itemId)->first();
        self::assertNotNull($item);
        self::assertSame('permanent_failed', $item->status);
        self::assertSame('mapping_persisted', $item->checkpoint);
    }

    public function testMissingDocumentDoesNotResolveAnUncertainDelivery(): void
    {
        $mappingId = $this->insertMapping('7007', 'invoice');
        $itemId = $this->insertExportHistory(
            '7007',
            'invoice',
            'ambiguous',
            'invoice_delivery_write_requested',
        );

        $this->deleteMapping($this->application([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]), $mappingId);

        self::assertTrue(Capsule::table('mod_sevdesk')->where('id', $mappingId)->exists());
        $item = Capsule::table('mod_sevdesk_job_items')->where('id', $itemId)->first();
        self::assertNotNull($item);
        self::assertSame('ambiguous', $item->status);
        self::assertSame('invoice_delivery_write_requested', $item->checkpoint);
    }

    public function testAlreadyUnmappedTerminalHistoryCanBeClosedAfterBothReadChecks(): void
    {
        $itemId = $this->insertExportHistory(
            '7008',
            'invoice',
            'permanent_failed',
            'mapping_persisted',
        );

        $resolved = $this->closeAbsentRemoteHistory($this->application([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]), $itemId);

        self::assertSame(1, $resolved);
        $item = Capsule::table('mod_sevdesk_job_items')->where('id', $itemId)->first();
        self::assertNotNull($item);
        self::assertSame('skipped', $item->status);
        self::assertSame('remote_absence_confirmed', $item->checkpoint);
        self::assertSame('remote_document_absence_confirmed', $item->error_code);
        self::assertNull($item->dedupe_key);
    }

    public function testAlreadyUnmappedCancelledHistoryRemainsVisibleAndCanBeClosed(): void
    {
        $itemId = $this->insertExportHistory(
            '7011',
            'invoice',
            'cancelled',
            'mapping_persisted',
        );
        $application = $this->application([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]);

        $visible = $application->jobs->reviewItems(status: 'cancelled');
        self::assertCount(1, $visible);
        self::assertSame($itemId, (int) $visible[0]->id);

        $resolved = $this->closeAbsentRemoteHistory($application, $itemId);

        self::assertSame(1, $resolved);
        $item = Capsule::table('mod_sevdesk_job_items')->where('id', $itemId)->first();
        self::assertNotNull($item);
        self::assertSame('skipped', $item->status);
        self::assertSame('remote_absence_confirmed', $item->checkpoint);
        self::assertSame(
            'completed',
            Capsule::table('mod_sevdesk_jobs')->where('id', $item->job_id)->value('status'),
        );
    }

    public function testAlreadyUnmappedHistoryStaysProtectedWhenOneRemoteTypeExists(): void
    {
        $itemId = $this->insertExportHistory(
            '7009',
            'invoice',
            'permanent_failed',
            'mapping_persisted',
        );

        try {
            $this->closeAbsentRemoteHistory($this->application([
                new Response(404, [], '{}'),
                new Response(200, [], '{"id":"7009","objectName":"Invoice"}'),
            ]), $itemId);
            self::fail('An existing remote document must keep the terminal history protected.');
        } catch (RuntimeException) {
            $item = Capsule::table('mod_sevdesk_job_items')->where('id', $itemId)->first();
            self::assertNotNull($item);
            self::assertSame('permanent_failed', $item->status);
            self::assertSame('mapping_persisted', $item->checkpoint);
        }
    }

    #[DataProvider('nonDocumentSideEffectProvider')]
    public function testAlreadyUnmappedNonDocumentSideEffectsCannotBeClosed(
        string $action,
        string $checkpoint,
    ): void {
        $itemId = $this->insertExportHistory(
            '7010',
            'invoice',
            'ambiguous',
            $checkpoint,
            $action,
        );

        try {
            $this->closeAbsentRemoteHistory($this->application([]), $itemId);
            self::fail('A non-document side effect must not be resolved by document absence.');
        } catch (RuntimeException) {
            $item = Capsule::table('mod_sevdesk_job_items')->where('id', $itemId)->first();
            self::assertNotNull($item);
            self::assertSame('ambiguous', $item->status);
            self::assertSame($checkpoint, $item->checkpoint);
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function nonDocumentSideEffectProvider(): iterable
    {
        yield 'sevdesk delivery' => ['export_document', 'invoice_delivery_write_requested'];
        yield 'WHMCS email' => ['export_document', 'whmcs_email_write_requested'];
        yield 'payment booking' => ['book_payment', 'booking_write_requested'];
    }

    /** @param list<Response> $responses */
    private function application(array $responses): Application
    {
        $application = new Application();
        $client = new SevdeskClient(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
            'synthetic-token',
            'http://127.0.0.1/api/v1',
            'WHMCS-sevdesk-test',
        );
        (new ReflectionProperty(Application::class, 'client'))->setValue($application, $client);

        return $application;
    }

    private function insertMapping(?string $remoteId, ?string $documentType): int
    {
        return (int) Capsule::table('mod_sevdesk')->insertGetId([
            'invoice_id' => 42,
            'sevdesk_id' => $remoteId,
            'document_type' => $documentType,
        ]);
    }

    private function insertExportHistory(
        string $remoteId,
        string $documentType,
        string $status,
        string $checkpoint,
        string $action = 'export_document',
    ): int {
        $now = '2030-01-02 03:04:05';
        $jobId = (int) Capsule::table('mod_sevdesk_jobs')->insertGetId([
            'status' => 'completed_with_errors',
            'updated_at' => $now,
        ]);

        return (int) Capsule::table('mod_sevdesk_job_items')->insertGetId([
            'job_id' => $jobId,
            'invoice_id' => 42,
            'action' => $action,
            'status' => $status,
            'dedupe_key' => 'export_voucher:42',
            'checkpoint' => $checkpoint,
            'sevdesk_id' => $remoteId,
            'candidate_json' => json_encode(
                ['targetDocumentType' => $documentType],
                JSON_THROW_ON_ERROR,
            ),
            'updated_at' => $now,
        ]);
    }

    private function deleteMapping(Application $application, int $mappingId): void
    {
        $csrf = new Csrf();
        $controller = new AdminController(
            $application,
            new View($csrf),
            $csrf,
            'addonmodules.php?module=sevdesk',
        );
        (new ReflectionMethod(AdminController::class, 'deleteMapping'))->invoke(
            $controller,
            $mappingId,
            42,
        );
    }

    private function closeAbsentRemoteHistory(Application $application, int $itemId): int
    {
        $csrf = new Csrf();
        $controller = new AdminController(
            $application,
            new View($csrf),
            $csrf,
            'addonmodules.php?module=sevdesk',
        );
        $resolved = (new ReflectionMethod(AdminController::class, 'closeAbsentRemoteHistory'))->invoke(
            $controller,
            $itemId,
        );
        self::assertIsInt($resolved);

        return $resolved;
    }
}
