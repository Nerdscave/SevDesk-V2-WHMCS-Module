<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Controllers\AdminController;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;
use WHMCS\Module\Addon\SevDesk\View;

final class AdminSetupBehaviorTest extends MariaDbTestCase
{
    private bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        Capsule::schema()->dropIfExists('tblcustomfields');
        Capsule::schema()->create('tblcustomfields', static function ($table): void {
            $table->increments('id');
            $table->string('type', 32);
            $table->string('fieldname', 191);
        });
        Capsule::table('tblcustomfields')->insert([
            'id' => 1,
            'type' => 'client',
            'fieldname' => 'sevdesk contact id',
        ]);
        $this->schemaReady = true;
        Migrator::up();
        $_POST = $this->baseSetupPost();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        if ($this->schemaReady) {
            Capsule::schema()->dropIfExists('tblcustomfields');
        }

        parent::tearDown();
    }

    public function testActiveJobBlocksSetupWithoutChangingSettingsAndReleasesTheLock(): void
    {
        $application = $this->application();
        $this->insertItem('pending', 'pending');
        $before = $application->config->stored();

        $this->expectSetupFailure($application, 'aktive Jobs');

        self::assertSame($before, $application->config->stored());
        $this->assertRunnerLockIsFree();
    }

    public function testPausedJobBlocksOperationalChangeButKeepsEverySetting(): void
    {
        $application = $this->application();
        $this->insertItem('paused', 'pending');
        $_POST['taxRuleGeneral'] = '2';
        $before = $application->config->stored();

        $this->expectSetupFailure($application, 'Bei pausierten Jobs');

        self::assertSame($before, $application->config->stored());
        $this->assertRunnerLockIsFree();
    }

    public function testPausedJobAllowsDiagnosticOnlyChange(): void
    {
        $application = $this->application();
        $this->insertItem('paused', 'pending');
        $_POST['debug_logging'] = 'on';

        $this->invokeSaveSetup($application);

        self::assertTrue($application->config->bool('debug_logging'));
        self::assertFalse($application->config->bool('sync_enabled'));
        self::assertSame('1', $application->config->get('taxRuleGeneral'));
        $this->assertRunnerLockIsFree();
    }

    public function testContactCreationConfirmationCanBePersistedAndRevoked(): void
    {
        $application = $this->application();
        $_POST['customer_number_contact_creation_confirmed'] = 'on';

        $this->invokeSaveSetup($application);

        self::assertTrue($application->config->bool('customer_number_contact_creation_confirmed'));

        unset($_POST['customer_number_contact_creation_confirmed']);
        $this->invokeSaveSetup($application);

        self::assertFalse($application->config->bool('customer_number_contact_creation_confirmed'));
    }

    public function testSevdeskDocumentAuthorityRequiresAutomaticInvoiceEnqueue(): void
    {
        $application = $this->application();
        $_POST['export_mode'] = 'invoice_only';
        $_POST['document_authority'] = 'sevdesk';
        $_POST['invoice_canary_confirmed'] = 'on';
        $_POST['invoice_sev_user_id'] = '5';
        $_POST['invoice_unity_id'] = '6';
        unset($_POST['sync_enabled']);
        $before = $application->config->stored();
        unset($before['sync_enabled']);

        $this->expectSetupFailure($application, 'automatische Einreihung neuer Rechnungen');

        $after = $application->config->stored();
        unset($after['sync_enabled']);
        self::assertSame($before, $after);
        self::assertSame('whmcs', $application->config->get('document_authority'));
        self::assertFalse($application->config->bool('sync_enabled'));
        $this->assertRunnerLockIsFree();
    }

    public function testAmbiguousExportModeChangeRollsBackSettingsAndKeepsHooksDisabled(): void
    {
        $application = $this->application();
        $this->insertItem('completed', 'ambiguous', 'export_document');
        $application->config->set('sevdesk_api_key', 'old-token');
        $application->config->set('sync_enabled', true);
        $_POST['sevdesk_api_key'] = 'new-token';
        $_POST['export_mode'] = 'invoice_only';
        $_POST['invoice_canary_confirmed'] = 'on';
        $_POST['invoice_sev_user_id'] = '5';
        $_POST['invoice_unity_id'] = '6';
        $before = $application->config->stored();
        unset($before['sync_enabled']);

        $this->expectSetupFailure($application, 'ungeklärte Exportjobs');

        $after = $application->config->stored();
        unset($after['sync_enabled']);
        self::assertSame($before, $after);
        self::assertSame('old-token', $application->config->get('sevdesk_api_key'));
        self::assertFalse($application->config->bool('sync_enabled'));
        self::assertSame('voucher_only', $application->config->get('export_mode'));
        $this->assertRunnerLockIsFree();
    }

    public function testOccupiedRunnerLockBlocksSetupWithoutChangingSettings(): void
    {
        $application = $this->application();
        $before = $application->config->stored();
        $holder = new IlluminateCapsule();
        $holder->addConnection(Capsule::connection()->getConfig());
        $connection = $holder->getConnection();
        $acquired = $connection->selectOne(
            'SELECT GET_LOCK(?, 0) AS acquired',
            ['whmcs_sevdesk_job_runner'],
        );
        self::assertSame(1, (int) ($acquired->acquired ?? 0));

        try {
            $this->expectSetupFailure($application, 'Worker ist gerade aktiv');
            self::assertSame($before, $application->config->stored());
        } finally {
            $connection->selectOne(
                'SELECT RELEASE_LOCK(?) AS released',
                ['whmcs_sevdesk_job_runner'],
            );
        }

        $this->assertRunnerLockIsFree();
    }

    public function testRuntimeReviewRequiresExplicitInventoryConfirmation(): void
    {
        $application = $this->quarantinedApplication();

        $this->expectSetupFailure($application, 'ausdrücklich geprüft und bestätigt');

        self::assertTrue($application->config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertFalse($application->config->bool('sync_enabled'));
    }

    public function testValidatedSetupClearsRuntimeReviewOnlyAfterReadOnlyTenantChecks(): void
    {
        $application = $this->quarantinedApplication([
            new Response(200, [], '{"version":"2.0"}'),
            new Response(200, [], '{"objects":[]}'),
        ]);
        $_POST['runtime_review_confirmed'] = '1';

        $this->invokeSaveSetup($application);

        self::assertFalse($application->config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertFalse($application->config->bool('sync_enabled'));
        self::assertSame(Config::RUNTIME_SIGNATURE, $application->config->get(Config::RUNTIME_SIGNATURE_SETTING));
    }

    public function testFailedTenantCheckPreservesRuntimeReview(): void
    {
        $application = $this->quarantinedApplication([
            new Response(401, [], '{"error":{"code":"AUTHENTICATION_FAILED"}}'),
        ]);
        $_POST['runtime_review_confirmed'] = '1';

        $this->expectSetupFailure($application, 'HTTP 401');

        self::assertTrue($application->config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertFalse($application->config->bool('sync_enabled'));
    }

    public function testStaleSetupFormCannotClearANewerRuntimeQuarantine(): void
    {
        $application = $this->quarantinedApplication();
        $_POST['runtime_review_confirmed'] = '1';
        $submittedToken = (string) $_POST['runtime_quarantine_token'];

        $application->config->quarantineRuntime();
        self::assertNotSame(
            $submittedToken,
            $application->config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING),
        );

        $this->expectSetupFailure($application, 'seit dem Öffnen der Einrichtung erneuert');

        self::assertTrue($application->config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertFalse($application->config->bool('sync_enabled'));
    }

    public function testFreshSetupFormCannotReleaseQuarantineBeforeMigrationRestoresSignature(): void
    {
        $application = $this->quarantinedApplication();
        $application->config->quarantineRuntime();
        $_POST['runtime_review_confirmed'] = '1';
        $_POST['runtime_quarantine_token'] = (string) $application->config->get(
            Config::RUNTIME_QUARANTINE_TOKEN_SETTING,
            '',
        );

        $this->expectSetupFailure($application, 'Laufzeitprüfung ist nicht vollständig');

        self::assertTrue($application->config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertSame('', $application->config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertFalse($application->config->bool('sync_enabled'));
    }

    public function testLateSetupFailureRefreshesRolledBackConfigValues(): void
    {
        $application = $this->quarantinedApplication([
            new Response(401, [], '{"error":{"code":"AUTHENTICATION_FAILED"}}'),
        ]);
        $application->config->set('sevdesk_api_key', 'old-token');
        $_POST['runtime_review_confirmed'] = '1';
        $_POST['sevdesk_api_key'] = 'new-token';
        $_POST['debug_logging'] = 'on';

        $this->expectSetupFailure($application, 'HTTP 401');

        $durable = $application->config->all();
        self::assertSame('old-token', $durable['sevdesk_api_key']);
        self::assertSame('', $durable['debug_logging']);
        self::assertSame('on', $durable[Config::RUNTIME_REVIEW_SETTING]);
        self::assertSame('', $durable['sync_enabled']);
    }

    public function testSetupRecoversExpiredSafeAndRiskyLegacyLeasesWithoutRunningHandlers(): void
    {
        $application = $this->quarantinedApplication([
            new Response(200, [], '{"version":"2.0"}'),
            new Response(200, [], '{"objects":[]}'),
        ]);
        $jobs = new JobRepository();
        $safeJobId = $jobs->create('legacy-safe', [[
            'invoice_id' => 501,
            'action' => 'export_voucher',
        ]]);
        $safe = $jobs->claimNext();
        self::assertNotNull($safe);
        $jobs->pause($safeJobId);

        $riskyJobId = $jobs->create('legacy-risky', [[
            'invoice_id' => 502,
            'action' => 'export_voucher',
        ]]);
        $risky = $jobs->claimNext();
        self::assertNotNull($risky);
        self::assertTrue($jobs->checkpoint(
            (int) $risky->id,
            (string) $risky->lease_token,
            'voucher_write_requested',
        ));
        $jobs->cancel($riskyJobId);
        Capsule::table(Migrator::ITEMS_TABLE)
            ->whereIn('id', [(int) $safe->id, (int) $risky->id])
            ->update(['leased_until' => '2000-01-01 00:00:00']);
        $_POST['runtime_review_confirmed'] = '1';

        $this->invokeSaveSetup($application);

        self::assertSame('retry_wait', $jobs->findItem((int) $safe->id)?->status);
        self::assertSame('ambiguous', $jobs->findItem((int) $risky->id)?->status);
        self::assertSame('paused', $jobs->findJob($safeJobId)?->status);
        self::assertFalse($application->config->bool(Config::RUNTIME_REVIEW_SETTING));
    }

    private function application(): Application
    {
        $application = new Application();
        $application->config->set('custom_field_id', 1);

        return $application;
    }

    /** @param list<Response> $responses */
    private function quarantinedApplication(array $responses = []): Application
    {
        $application = $this->application();
        $application->config->set('module_active', 'on');
        $application->config->set('sync_enabled', 'on');
        $application->config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $application->config->set(Config::RUNTIME_REVIEW_SETTING, 'on');
        $application->config->set('sevdesk_api_key', 'synthetic-token');
        $_POST['runtime_quarantine_token'] = (string) $application->config->get(
            Config::RUNTIME_QUARANTINE_TOKEN_SETTING,
            '',
        );
        if ($responses !== []) {
            $client = new SevdeskClient(
                new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
                'synthetic-token',
                'http://127.0.0.1/api/v1',
                'WHMCS-sevdesk-test',
            );
            $property = new ReflectionProperty(Application::class, 'client');
            $property->setValue($application, $client);
        }

        return $application;
    }

    private function invokeSaveSetup(Application $application): void
    {
        $csrf = new Csrf();
        $controller = new AdminController(
            $application,
            new View($csrf),
            $csrf,
            'addonmodules.php?module=sevdesk',
        );
        (new ReflectionMethod(AdminController::class, 'saveSetup'))->invoke($controller);
    }

    private function expectSetupFailure(Application $application, string $messageFragment): void
    {
        try {
            $this->invokeSaveSetup($application);
            self::fail('The setup operation should have been blocked.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString($messageFragment, $error->getMessage());
        }
    }

    private function insertItem(string $jobStatus, string $itemStatus, string $action = 'export_document'): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $jobId = (int) Capsule::table(Migrator::JOBS_TABLE)->insertGetId([
            'type' => 'test',
            'status' => $jobStatus,
            'filters_json' => null,
            'requested_by_admin_id' => 1,
            'total_items' => 1,
            'created_at' => $now,
            'started_at' => null,
            'finished_at' => null,
            'cancel_requested_at' => null,
            'updated_at' => $now,
        ]);
        Capsule::table(Migrator::ITEMS_TABLE)->insert([
            'job_id' => $jobId,
            'invoice_id' => 42,
            'action' => $action,
            'status' => $itemStatus,
            'dedupe_key' => 'setup-test:' . $jobId,
            'checkpoint' => 'queued',
            'attempts' => 0,
            'available_at' => $now,
            'lease_token' => null,
            'leased_until' => null,
            'sevdesk_id' => null,
            'transaction_reference' => null,
            'candidate_json' => null,
            'http_status' => null,
            'exception_uuid' => null,
            'error_code' => null,
            'message' => null,
            'created_at' => $now,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => $now,
        ]);
    }

    private function assertRunnerLockIsFree(): void
    {
        $result = Capsule::selectOne(
            'SELECT IS_FREE_LOCK(?) AS lock_is_free',
            ['whmcs_sevdesk_job_runner'],
        );
        self::assertSame(1, (int) ($result->lock_is_free ?? 0));
    }

    /** @return array<string,string> */
    private function baseSetupPost(): array
    {
        return [
            'sevdesk_api_key' => '',
            'import_after' => '1999-01-01',
            'custom_field_id' => '1',
            'export_mode' => 'voucher_only',
            'document_authority' => 'whmcs',
            'oss_profile' => 'blocked',
            'invoice_sev_user_id' => '',
            'invoice_unity_id' => '',
            'invoice_delivery_channel' => 'sevdesk',
            'whmcs_invoice_email_template' => '',
            'sevdesk_email_subject' => 'Ihre Rechnung {invoice_number}',
            'sevdesk_email_body' => "Guten Tag,\n\nim Anhang finden Sie Ihre Rechnung {invoice_number}.",
            'import_only_paid' => 'on',
            'eu_b2c_mode' => 'blocked',
            'accountingTypeGeneral' => '',
            'accountingTypeInterCommunityBusiness' => '',
            'accountingTypeInterCommunityConsumer' => '',
            'accountingTypeThirdPartyCountry' => '',
            'accountingTypeCredit' => '',
            'accountingTypeSmallBusinessOwner' => '',
            'taxRuleGeneral' => '1',
            'taxRuleInterCommunityBusiness' => '3',
            'taxRuleInterCommunityConsumer' => '1',
            'taxRuleThirdPartyCountry' => '',
            'taxRuleCredit' => '',
            'taxRuleSmallBusinessOwner' => '11',
        ];
    }
}
