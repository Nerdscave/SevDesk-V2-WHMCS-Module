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
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
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
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['mod_sevdesk_job_items', 'mod_sevdesk', 'tblinvoices', 'tbladdonmodules'] as $table) {
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
        });
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->string('sevdesk_id')->nullable();
            $table->string('document_type')->nullable();
        });
        Capsule::schema()->create('mod_sevdesk_job_items', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->nullable();
            $table->string('action');
            $table->string('status');
        });
        Capsule::table('tblinvoices')->insert(['id' => 42]);
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
}
