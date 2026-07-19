<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Controllers\AdminController;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;
use WHMCS\Module\Addon\SevDesk\View;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class AdminHardeningBehaviorTest extends TestCase
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

        Capsule::schema()->dropIfExists('tbladdonmodules');
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module', 64);
            $table->string('setting', 191);
            $table->text('value')->nullable();
            $table->unique(['module', 'setting']);
        });
        Capsule::schema()->dropIfExists('tblinvoices');
        Capsule::schema()->create('tblinvoices', static function ($table): void {
            $table->increments('id');
            $table->string('invoicenum', 191)->nullable();
        });
        Capsule::schema()->dropIfExists('mod_sevdesk');
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->string('sevdesk_id', 255)->nullable();
            $table->string('document_type', 16)->nullable();
        });
    }

    public function testInactiveModuleBlocksJobMutationAndActiveModuleAllowsIt(): void
    {
        $application = new Application();
        $controller = $this->controller($application);
        $guard = new ReflectionMethod(AdminController::class, 'assertJobMutationAllowed');

        try {
            $guard->invoke($controller);
            self::fail('An inactive module must reject job mutations.');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            self::assertInstanceOf(RuntimeException::class, $error->getPrevious() ?? $error);
            self::assertStringContainsString('deaktiviert', ($error->getPrevious() ?? $error)->getMessage());
        }

        $application->config->set('module_active', true);
        $guard->invoke($controller);
        self::addToAssertionCount(1);
    }

    public function testRuntimeReviewBlocksExecutionMutationsButAllowsSafetyActions(): void
    {
        $application = new Application();
        $application->config->set('module_active', true);
        $application->config->set(Config::RUNTIME_REVIEW_SETTING, true);
        $controller = $this->controller($application);
        $executionGuard = new ReflectionMethod(AdminController::class, 'assertJobMutationAllowed');
        $safetyGuard = new ReflectionMethod(AdminController::class, 'assertModuleActiveForJobSafetyAction');

        try {
            $executionGuard->invoke($controller);
            self::fail('Runtime quarantine must block remote-capable job mutations.');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $runtime = $error->getPrevious() ?? $error;
            self::assertInstanceOf(RuntimeException::class, $runtime);
            self::assertStringContainsString('Quarantäne', $runtime->getMessage());
        }

        $safetyGuard->invoke($controller);
        self::addToAssertionCount(1);
    }

    public function testRuntimeQuarantineAttemptsEverySafetyWriteIndependently(): void
    {
        $config = new Config();
        $config->set('sync_enabled', true);
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        Capsule::connection()->unprepared(<<<'SQL'
CREATE TRIGGER fail_runtime_sync_update
BEFORE UPDATE ON tbladdonmodules
WHEN OLD.module = 'sevdesk' AND OLD.setting = 'sync_enabled'
BEGIN
    SELECT RAISE(ABORT, 'synthetic sync setting failure');
END
SQL);

        $config->quarantineRuntime();

        self::assertTrue($config->bool('sync_enabled'), 'The synthetic first safety write must fail.');
        self::assertSame('', $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertTrue($config->bool(Config::RUNTIME_REVIEW_SETTING));
    }

    public function testRuntimeQuarantineEstablishesAtomicIntentBeforeIndependentWrites(): void
    {
        $method = new ReflectionMethod(Config::class, 'quarantineRuntime');
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $intent = strpos($body, 'persistRuntimeQuarantineIntentAtomically(');
        $signature = strpos($body, 'RUNTIME_SIGNATURE_SETTING');
        $sync = strpos($body, "'sync_enabled'");
        self::assertNotFalse($intent);
        self::assertNotFalse($signature);
        self::assertNotFalse($sync);
        self::assertLessThan($signature, $intent);
        self::assertLessThan($sync, $signature);
    }

    public function testRuntimeQuarantineIntentRollsBackAsAUnitWhenTokenWriteFails(): void
    {
        $config = new Config();
        $config->ensureDefaults();
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set(Config::RUNTIME_QUARANTINE_TOKEN_SETTING, 'old-token');
        Capsule::connection()->unprepared(<<<'SQL'
CREATE TRIGGER fail_atomic_runtime_token_update
BEFORE UPDATE ON tbladdonmodules
WHEN OLD.module = 'sevdesk' AND OLD.setting = 'runtime_quarantine_token'
BEGIN
    SELECT RAISE(ABORT, 'synthetic atomic token failure');
END
SQL);

        $persist = new ReflectionMethod(Config::class, 'persistRuntimeQuarantineIntentAtomically');
        self::assertFalse($persist->invoke($config, 'new-token'));

        self::assertFalse($config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertSame('old-token', $config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING));
        self::assertSame(Config::RUNTIME_SIGNATURE, $config->get(Config::RUNTIME_SIGNATURE_SETTING));
    }

    public function testRuntimeQuarantinePersistsAllClaimGatesAsOneIntent(): void
    {
        $config = new Config();
        $config->ensureDefaults();
        $config->set('module_active', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set(Config::RUNTIME_QUARANTINE_TOKEN_SETTING, 'old-token');
        $persist = new ReflectionMethod(Config::class, 'persistRuntimeQuarantineIntentAtomically');

        self::assertTrue($persist->invoke($config, 'new-token'));

        self::assertTrue($config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertSame('new-token', $config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING));
        self::assertSame('', $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertFalse(Capsule::connection()->transaction(
            static fn (): bool => $config->runtimeAllowsClaimWhileLocked(),
        ));
    }

    public function testAuthenticationReviewIntentKeepsSignatureButBlocksClaims(): void
    {
        $config = new Config();
        $config->ensureDefaults();
        $config->set('module_active', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set(Config::RUNTIME_QUARANTINE_TOKEN_SETTING, 'old-token');
        $persist = new ReflectionMethod(Config::class, 'persistRuntimeReviewIntentAtomically');

        self::assertTrue($persist->invoke($config, 'new-token'));

        self::assertTrue($config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertSame('new-token', $config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING));
        self::assertSame(Config::RUNTIME_SIGNATURE, $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertFalse(Capsule::connection()->transaction(
            static fn (): bool => $config->runtimeAllowsClaimWhileLocked(),
        ));
    }

    public function testInvalidRuntimeReviewFallbackPersistsAClaimBlockingLatch(): void
    {
        $config = new Config();
        $config->ensureDefaults();
        $config->set('module_active', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set(Config::RUNTIME_QUARANTINE_TOKEN_SETTING, 'old-token');
        $persist = new ReflectionMethod(Config::class, 'persistInvalidRuntimeReviewAtomically');

        self::assertTrue($persist->invoke($config));

        self::assertTrue($config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertSame('', $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertSame('old-token', $config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING));
        self::assertFalse(Capsule::connection()->transaction(
            static fn (): bool => $config->runtimeAllowsClaimWhileLocked(),
        ));
    }

    public function testMigrationCannotContinueWhenReviewMarkerWriteFails(): void
    {
        $config = new Config();
        $config->set('sync_enabled', true);
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        Capsule::connection()->unprepared(<<<'SQL'
CREATE TRIGGER fail_runtime_review_update
BEFORE UPDATE ON tbladdonmodules
WHEN OLD.module = 'sevdesk' AND OLD.setting = 'runtime_review_required'
BEGIN
    SELECT RAISE(ABORT, 'synthetic review setting failure');
END
SQL);

        try {
            $config->quarantineRuntimeOrFail();
            self::fail('Migration must stop unless its review marker was durably stored.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('quarantine could not be persisted', $error->getMessage());
        }

        self::assertFalse($config->bool('sync_enabled'));
        self::assertSame('', $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertFalse($config->bool(Config::RUNTIME_REVIEW_SETTING));
    }

    public function testMigrationCannotContinueWhenQuarantineTokenWriteFails(): void
    {
        $config = new Config();
        $config->set('sync_enabled', true);
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set(Config::RUNTIME_QUARANTINE_TOKEN_SETTING, 'old-token');
        Capsule::connection()->unprepared(<<<'SQL'
CREATE TRIGGER fail_runtime_quarantine_token_update
BEFORE UPDATE ON tbladdonmodules
WHEN OLD.module = 'sevdesk' AND OLD.setting = 'runtime_quarantine_token'
BEGIN
    SELECT RAISE(ABORT, 'synthetic quarantine token failure');
END
SQL);

        try {
            $config->quarantineRuntimeOrFail();
            self::fail('Migration must stop unless the new quarantine token was durably stored.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('quarantine could not be persisted', $error->getMessage());
        }

        self::assertTrue($config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertSame('old-token', $config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING));
        self::assertSame('', $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertFalse($config->bool('sync_enabled'));
    }

    public function testLockedRuntimeClaimGateIncludesAuthenticationAlarm(): void
    {
        $config = new Config();
        $config->ensureDefaults();
        $config->set('module_active', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set('health_alarm', '');

        self::assertTrue(Capsule::connection()->transaction(
            static fn (): bool => $config->runtimeAllowsClaimWhileLocked(),
        ));

        $config->set('health_alarm', 'api_authentication_failed');
        self::assertFalse(Capsule::connection()->transaction(
            static fn (): bool => $config->runtimeAllowsClaimWhileLocked(),
        ));
    }

    public function testAuthenticationAlarmFailureUsesFreshTokenisedReviewFallback(): void
    {
        $config = new Config();
        $config->ensureDefaults();
        $config->set('module_active', 'on');
        $config->set('sync_enabled', 'on');
        $config->set('health_alarm', '');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set(Config::RUNTIME_QUARANTINE_TOKEN_SETTING, 'old-token');
        Capsule::connection()->unprepared(<<<'SQL'
CREATE TRIGGER fail_authentication_alarm_update
BEFORE UPDATE ON tbladdonmodules
WHEN OLD.module = 'sevdesk' AND OLD.setting = 'health_alarm'
BEGIN
    SELECT RAISE(ABORT, 'synthetic authentication alarm failure');
END
SQL);

        $result = $config->tripAuthenticationSafetyGates();

        self::assertFalse($result['alarm']);
        self::assertTrue($result['reviewFallback']);
        self::assertTrue($result['syncDisabled']);
        self::assertTrue($config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertNotSame('old-token', $config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING));
        self::assertSame(Config::RUNTIME_SIGNATURE, $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertFalse($config->bool('sync_enabled'));
    }

    public function testAuthenticationFallbackDoesNotReassertReviewAfterItsAtomicLatch(): void
    {
        $method = new ReflectionMethod(Config::class, 'tripAuthenticationSafetyGates');
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $lastResort = strpos($body, 'if (!$intentPersisted && !$invalidReviewPersisted)');
        $reviewWrite = strpos($body, "set(self::RUNTIME_REVIEW_SETTING, 'on')");
        self::assertNotFalse($lastResort);
        self::assertNotFalse($reviewWrite);
        self::assertLessThan($reviewWrite, $lastResort);
    }

    public function testAuthenticationFallbackInvalidatesRuntimeAtomicallyWhenTokenWriteFails(): void
    {
        $config = new Config();
        $config->ensureDefaults();
        $config->set('module_active', 'on');
        $config->set('sync_enabled', 'on');
        $config->set('health_alarm', '');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');
        $config->set(Config::RUNTIME_QUARANTINE_TOKEN_SETTING, 'old-token');
        Capsule::connection()->unprepared(<<<'SQL'
CREATE TRIGGER fail_authentication_alarm_and_token_update
BEFORE UPDATE ON tbladdonmodules
WHEN OLD.module = 'sevdesk'
    AND OLD.setting IN ('health_alarm', 'runtime_quarantine_token')
BEGIN
    SELECT RAISE(ABORT, 'synthetic authentication latch failure');
END
SQL);

        $result = $config->tripAuthenticationSafetyGates();

        self::assertFalse($result['alarm']);
        self::assertTrue($result['reviewFallback']);
        self::assertTrue($result['syncDisabled']);
        self::assertTrue($config->bool(Config::RUNTIME_REVIEW_SETTING));
        self::assertSame('old-token', $config->get(Config::RUNTIME_QUARANTINE_TOKEN_SETTING));
        self::assertSame('', $config->get(Config::RUNTIME_SIGNATURE_SETTING));
        self::assertFalse($config->bool('sync_enabled'));
    }

    public function testLegacyMappingContextAllowsAnEmptyWhmcsNumberForSafeIdFallback(): void
    {
        Capsule::table('tblinvoices')->insert(['id' => 42, 'invoicenum' => '']);
        $mappingId = (int) Capsule::table('mod_sevdesk')->insertGetId([
            'invoice_id' => 42,
            'sevdesk_id' => '88',
            'document_type' => null,
        ]);
        $method = new ReflectionMethod(AdminController::class, 'legacyMappingContext');

        $mapping = $method->invoke($this->controller(new Application()), $mappingId);

        self::assertSame(42, (int) $mapping->invoice_id);
        self::assertSame('', (string) $mapping->invoicenum);
        self::assertSame('88', (string) $mapping->sevdesk_id);
    }

    public function testCorrectionPositionTotalsAreRejectedBeforeAJobIsCreated(): void
    {
        $method = new ReflectionMethod(AdminController::class, 'assertCorrectionPositionsMatchRefund');

        $method->invoke(null, [new LineItem('Refund', '10.00', '19', false)], '10.00');
        self::addToAssertionCount(1);

        try {
            $method->invoke(null, [new LineItem('Refund', '9.00', '19', false)], '10.00');
            self::fail('A mismatching correction total must be rejected before queueing.');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $runtime = $error->getPrevious() ?? $error;
            self::assertInstanceOf(RuntimeException::class, $runtime);
            self::assertStringContainsString('Bruttosumme', $runtime->getMessage());
        }
    }

    public function testLegacyMappingContextStillRejectsAnOrphanMapping(): void
    {
        $mappingId = (int) Capsule::table('mod_sevdesk')->insertGetId([
            'invoice_id' => 42,
            'sevdesk_id' => '88',
            'document_type' => null,
        ]);
        $method = new ReflectionMethod(AdminController::class, 'legacyMappingContext');

        $this->expectException(RuntimeException::class);
        $method->invoke($this->controller(new Application()), $mappingId);
    }

    /** @param list<mixed> $input @param list<string> $expected */
    #[DataProvider('csvRows')]
    public function testEveryCsvCellIsNeutralisedBeforeOutput(array $input, array $expected): void
    {
        $method = new ReflectionMethod(AdminController::class, 'safeCsvRow');

        self::assertSame($expected, $method->invoke(null, $input));
    }

    /** @return iterable<string, array{list<mixed>,list<string>}> */
    public static function csvRows(): iterable
    {
        yield 'formula leaders and leading controls' => [[
            '=1+1', '+cmd', '-2+3', '@SUM(A1)', "\t=cmd", "\r@cmd", '  =cmd', "\n+cmd",
        ], [
            "'=1+1", "'+cmd", "'-2+3", "'@SUM(A1)", "'\t=cmd", "'\r@cmd", "'  =cmd", "'\n+cmd",
        ]];
        yield 'ordinary and already quoted values' => [[null, 42, 'text', "'=safe", '  text'], [
            '', '42', 'text', "'=safe", '  text',
        ]];
    }

    #[DataProvider('mutatingAdminMethods')]
    public function testEveryMutatingJobActionUsesTheActivationGuard(string $methodName): void
    {
        $source = $this->methodSource($methodName);

        self::assertStringContainsString('$this->assertJobMutationAllowed();', $source);
    }

    public function testPauseAndCancelUseOnlyTheLocalSafetyGuardWhileResumeNeedsReviewClearance(): void
    {
        $source = $this->methodSource('jobDetail');

        self::assertGreaterThanOrEqual(2, substr_count($source, 'assertModuleActiveForJobSafetyAction();'));
        self::assertStringNotContainsString('recoverExpiredLeasesForSafety', $source);
        self::assertStringContainsString("elseif (isset(\$_POST['resume']))", $source);
        self::assertStringContainsString('$this->assertJobMutationAllowed();', $source);
    }

    /** @return iterable<string, array{string}> */
    public static function mutatingAdminMethods(): iterable
    {
        yield 'single export' => ['singleImport'];
        yield 'invoice quick export' => ['quickExport'];
        yield 'bulk export' => ['massImport'];
        yield 'job controls' => ['jobDetail'];
        yield 'booking job' => ['bookingAssistant'];
        yield 'correction and recovery jobs' => ['corrections'];
    }

    public function testSetupHealthAndReadOnlyJobViewsDoNotUseTheMutationGuard(): void
    {
        foreach (['setup', 'health', 'jobs', 'jobStatus', 'jobCsv'] as $methodName) {
            self::assertStringNotContainsString(
                '$this->assertJobMutationAllowed();',
                $this->methodSource($methodName),
                $methodName,
            );
        }
    }

    public function testSetupFailureHandlerNeverRestoresAStaleSnapshotOutsideTheRunnerLock(): void
    {
        $source = $this->methodSource('setup');

        self::assertStringNotContainsString('setupSettingKeys', $source);
        self::assertStringNotContainsString('config->stored()', $source);
        self::assertStringNotContainsString("config->set('sync_enabled'", $source);
        self::assertStringNotContainsString('config->delete(', $source);
    }

    public function testCsvEndpointPassesHeaderAndDataRowsThroughTheCellGuard(): void
    {
        $source = $this->methodSource('jobCsv');

        self::assertSame(2, substr_count($source, 'fputcsv($stream, self::safeCsvRow(['));
        self::assertStringNotContainsString('fputcsv($stream, [', $source);
    }

    public function testBookingAssistantSuppressesAlreadyCompletedRemotePaymentsOnly(): void
    {
        $method = new ReflectionMethod(AdminController::class, 'bookingPreviewNeedsAttention');

        self::assertFalse($method->invoke(null, ['code' => 'voucher_already_paid']));
        self::assertFalse($method->invoke(null, ['code' => 'invoice_already_paid']));
        self::assertTrue($method->invoke(null, ['code' => 'no_payment_candidate']));
        self::assertTrue($method->invoke(null, ['code' => 'booking_ready']));
    }

    public function testSetupCommitsTheHookSafetyGateBeforeAtomicSettingWrites(): void
    {
        $source = $this->methodSource('saveSetup');
        $syncGate = strpos($source, "config->set('sync_enabled', '')");
        $transaction = strpos($source, 'Capsule::connection()->transaction(');
        $lock = strpos($source, 'GET_LOCK');
        $leaseRecovery = strpos($source, 'recoverExpiredLeasesForSafety();');
        $activeInspection = strpos($source, '$activeQuery =');
        self::assertNotFalse($syncGate);
        self::assertNotFalse($transaction);
        self::assertNotFalse($lock);
        self::assertNotFalse($leaseRecovery);
        self::assertNotFalse($activeInspection);
        self::assertLessThan($transaction, $syncGate);
        self::assertLessThan($leaseRecovery, $lock);
        self::assertLessThan($activeInspection, $leaseRecovery);
        self::assertStringContainsString('$this->saveSetupWhileLocked();', $source);
    }

    public function testSetupClearsRuntimeReviewOnlyAtTheValidatedEnd(): void
    {
        $source = $this->methodSource('saveSetupWhileLocked');
        $confirmation = strpos($source, "\$_POST['runtime_review_confirmed']");
        $gateLock = strpos($source, 'lockRuntimeGates()');
        $tokenCheck = strpos($source, 'runtime_quarantine_token');
        $tenantRead = strrpos($source, 'referenceData()->bookkeepingVersion()');
        $clearReview = strpos($source, 'clearRuntimeReviewIfUnchanged(');
        $restoreSync = strpos($source, "set('sync_enabled', \$enableSync)");

        self::assertNotFalse($confirmation);
        self::assertNotFalse($gateLock);
        self::assertNotFalse($tokenCheck);
        self::assertNotFalse($tenantRead);
        self::assertNotFalse($clearReview);
        self::assertNotFalse($restoreSync);
        self::assertLessThan($confirmation, $gateLock);
        self::assertLessThan($tenantRead, $tokenCheck);
        self::assertLessThan($tenantRead, $confirmation);
        self::assertLessThan($clearReview, $tenantRead);
        self::assertLessThan($restoreSync, $clearReview);
    }

    public function testSetupAlwaysRefreshesConfigAfterTransactionCompletion(): void
    {
        $source = $this->methodSource('saveSetup');
        $transaction = strpos($source, 'Capsule::connection()->transaction(');
        $release = strpos($source, 'RELEASE_LOCK');
        $refresh = strrpos($source, 'config->refresh()');

        self::assertNotFalse($transaction);
        self::assertNotFalse($release);
        self::assertNotFalse($refresh);
        self::assertLessThan($release, $transaction);
        self::assertLessThan($refresh, $release);
    }

    public function testLegacyMappingAuthenticationAlarmIsFirstAndSafetyWritesAreIndependent(): void
    {
        $source = $this->methodSource('handleLegacyMappingTypeFailure');

        self::assertStringContainsString('tripAuthenticationSafetyGates()', $source);
        self::assertStringContainsString("!\$safety['alarm'] || !\$safety['syncDisabled']", $source);
    }

    public function testEveryWorkerAuthenticationPathUsesIndependentSafetyGatesBeforePause(): void
    {
        foreach (
            [
                \WHMCS\Module\Addon\SevDesk\Jobs\ExportJobHandler::class,
                \WHMCS\Module\Addon\SevDesk\Jobs\BookingJobHandler::class,
                \WHMCS\Module\Addon\SevDesk\Jobs\CorrectionJobHandler::class,
            ] as $handler
        ) {
            $method = new ReflectionMethod($handler, 'tripAuthenticationAlarm');
            $file = $method->getFileName();
            self::assertIsString($file);
            $lines = file($file);
            self::assertIsArray($lines);
            $source = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
            $safety = strpos($source, 'tripAuthenticationSafetyGates()');
            $pause = strpos($source, 'jobs->pause(');

            self::assertNotFalse($safety, $handler);
            self::assertNotFalse($pause, $handler);
            self::assertLessThan($pause, $safety, $handler);
        }
    }

    private function controller(Application $application): AdminController
    {
        $csrf = new Csrf();

        return new AdminController($application, new View($csrf), $csrf, 'addonmodules.php?module=sevdesk');
    }

    private function methodSource(string $methodName): string
    {
        $method = new ReflectionMethod(AdminController::class, $methodName);
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
