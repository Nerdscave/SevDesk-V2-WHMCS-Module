<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;
use WHMCS\Module\Addon\SevDesk\Jobs\JobRunner;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class JobRunnerTest extends MariaDbTestCase
{
    public function testEmptyRunnerUpdatesHeartbeatWithoutClaimingWork(): void
    {
        Migrator::up();
        $config = $this->runtimeConfig();
        $started = new \DateTimeImmutable('-5 seconds');
        $runner = new JobRunner(new JobRepository(), $config, []);

        $result = $runner->run(1, 5);

        self::assertSame(0, $result['processed']);
        self::assertFalse($result['locked']);
        self::assertSame(0, Capsule::table(Migrator::JOBS_TABLE)->count());
        self::assertSame(0, Capsule::table(Migrator::ITEMS_TABLE)->count());

        $heartbeat = new \DateTimeImmutable((string) $config->get('runner_last_seen'));
        self::assertGreaterThanOrEqual($started->getTimestamp(), $heartbeat->getTimestamp());
    }

    public function testRuntimeReviewBlocksRunnerBeforeLockHeartbeatOrClaim(): void
    {
        Migrator::up();
        $jobs = new JobRepository();
        $jobId = $jobs->create('quarantined', [[
            'invoice_id' => 699,
            'action' => 'must_not_run',
        ]]);
        $config = $this->runtimeConfig();
        $config->set(Config::RUNTIME_REVIEW_SETTING, 'on');
        $handled = false;
        $runner = new JobRunner($jobs, $config, [
            'must_not_run' => static function () use (&$handled): JobOutcome {
                $handled = true;

                return JobOutcome::succeeded('Unexpected execution.');
            },
        ]);

        $result = $runner->run(1, 5);

        self::assertSame(0, $result['processed']);
        self::assertFalse($handled);
        self::assertSame('pending', Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->value('status'));
        self::assertSame('', $config->get('runner_last_seen', ''));
    }

    public function testRuntimeQuarantineRaisedInsideBatchStopsBeforeNextClaim(): void
    {
        Migrator::up();
        $jobs = new JobRepository();
        $jobId = $jobs->create('quarantine-race', [
            ['invoice_id' => 697, 'action' => 'quarantine_after_first'],
            ['invoice_id' => 698, 'action' => 'quarantine_after_first'],
        ]);
        $config = $this->runtimeConfig();
        $quarantineConfig = new Config();
        $handled = [];
        $runner = new JobRunner($jobs, $config, [
            'quarantine_after_first' => static function (object $item) use ($quarantineConfig, &$handled): JobOutcome {
                $handled[] = (int) $item->invoice_id;
                $quarantineConfig->set(Config::RUNTIME_REVIEW_SETTING, 'on');
                $quarantineConfig->set(Config::RUNTIME_SIGNATURE_SETTING, '');

                return JobOutcome::succeeded('Synthetic first item completed.');
            },
        ]);

        $result = $runner->run(2, 10);

        self::assertSame(1, $result['processed']);
        self::assertSame([697], $handled);
        self::assertSame(
            ['succeeded', 'pending'],
            Capsule::table(Migrator::ITEMS_TABLE)
                ->where('job_id', $jobId)
                ->orderBy('invoice_id')
                ->pluck('status')
                ->all(),
        );
    }

    public function testRunnerContinuesAfterFailuresAndKeepsRiskyThrowableAmbiguous(): void
    {
        Migrator::up();
        $jobs = new JobRepository();
        $jobId = $jobs->create('bulk', [
            ['invoice_id' => 701, 'action' => 'safe_failure'],
            ['invoice_id' => 702, 'action' => 'risky_failure'],
            ['invoice_id' => 703, 'action' => 'success_after_failures'],
        ]);
        $handledInvoices = [];
        $runner = new JobRunner($jobs, $this->runtimeConfig(), [
            'safe_failure' => static function (object $item) use (&$handledInvoices): JobOutcome {
                $handledInvoices[] = (int) $item->invoice_id;
                throw new RuntimeException('Synthetic safe handler failure.');
            },
            'risky_failure' => static function (
                object $item,
                callable $checkpoint,
            ) use (&$handledInvoices): JobOutcome {
                $handledInvoices[] = (int) $item->invoice_id;
                if (!$checkpoint('voucher_write_requested', ['remoteId' => '900702'])) {
                    throw new RuntimeException('Synthetic checkpoint persistence failure.');
                }
                throw new RuntimeException('Synthetic failure after a possible remote write.');
            },
            'success_after_failures' => static function (object $item) use (&$handledInvoices): JobOutcome {
                $handledInvoices[] = (int) $item->invoice_id;

                return JobOutcome::succeeded('Synthetic success.', '900703');
            },
        ]);

        $result = $runner->run(3, 10);

        self::assertSame(3, $result['processed']);
        self::assertFalse($result['locked']);
        self::assertSame([701, 702, 703], $handledInvoices);

        $items = Capsule::table(Migrator::ITEMS_TABLE)
            ->where('job_id', $jobId)
            ->orderBy('invoice_id')
            ->get()
            ->keyBy('invoice_id');
        self::assertSame('permanent_failed', $items->get(701)?->status);
        self::assertSame('ambiguous', $items->get(702)?->status);
        self::assertSame('voucher_write_requested', $items->get(702)?->checkpoint);
        self::assertSame('risky_failure:702', $items->get(702)?->dedupe_key);
        self::assertSame('900702', $items->get(702)?->sevdesk_id);
        self::assertSame('unhandled_runtimeexception', $items->get(702)?->error_code);
        self::assertSame('succeeded', $items->get(703)?->status);
        self::assertSame('900703', $items->get(703)?->sevdesk_id);
        self::assertSame('completed_with_errors', $jobs->findJob($jobId)?->status);
    }

    public function testVerifiedSideEffectThrowableRetriesOnlyThreeTimesBeforeBecomingAmbiguous(): void
    {
        Migrator::up();
        $jobs = new JobRepository();
        $jobId = $jobs->create('verified-side-effect-retry', [[
            'invoice_id' => 704,
            'action' => 'verified_failure',
        ]]);
        $handlerCalls = 0;
        $runner = new JobRunner($jobs, $this->runtimeConfig(), [
            'verified_failure' => static function (
                object $item,
                callable $checkpoint,
            ) use (&$handlerCalls): JobOutcome {
                ++$handlerCalls;
                if (!$checkpoint('mapping_persisted', ['remoteId' => '900704'])) {
                    throw new RuntimeException('Synthetic checkpoint persistence failure.');
                }
                throw new RuntimeException('Synthetic local failure after verified mapping.');
            },
        ]);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $result = $runner->run(1, 10);
            self::assertSame(1, $result['processed']);
            $item = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
            self::assertSame($attempt, (int) $item->attempts);
            self::assertSame('mapping_persisted', $item->checkpoint);
            self::assertSame('900704', $item->sevdesk_id);
            self::assertSame(
                $attempt < 4 ? 'retry_wait' : 'ambiguous',
                $item->status,
            );
            if ($attempt < 4) {
                Capsule::table(Migrator::ITEMS_TABLE)->where('id', $item->id)->update([
                    'available_at' => '2000-01-01 00:00:00',
                ]);
            }
        }

        self::assertSame(4, $handlerCalls);
        self::assertSame('completed_with_errors', $jobs->findJob($jobId)?->status);
    }

    public function testAuthenticationAlarmStopsCurrentAndFutureClaimsUntilCleared(): void
    {
        Migrator::up();
        $jobs = new JobRepository();
        $jobId = $jobs->create('bulk', [
            ['invoice_id' => 801, 'action' => 'auth_failure'],
            ['invoice_id' => 802, 'action' => 'success_after_authentication'],
        ]);
        $config = $this->runtimeConfig();
        $handledInvoices = [];
        $runner = new JobRunner($jobs, $config, [
            'auth_failure' => static function (object $item) use ($config, &$handledInvoices): JobOutcome {
                $handledInvoices[] = (int) $item->invoice_id;
                $config->set('health_alarm', 'api_authentication_failed');

                return JobOutcome::retry(
                    'Synthetic authentication failure.',
                    300,
                    401,
                    errorCode: 'api_authentication_failed',
                );
            },
            'success_after_authentication' => static function (object $item) use (&$handledInvoices): JobOutcome {
                $handledInvoices[] = (int) $item->invoice_id;

                return JobOutcome::succeeded('Synthetic success.', '900802');
            },
        ]);

        $firstRun = $runner->run(2, 10);

        self::assertSame(1, $firstRun['processed']);
        self::assertSame([801], $handledInvoices);
        self::assertSame('retry_wait', $jobs->findItem($this->itemId($jobId, 801))?->status);
        self::assertSame('pending', $jobs->findItem($this->itemId($jobId, 802))?->status);

        $secondRun = $runner->run(2, 10);
        self::assertSame(0, $secondRun['processed']);
        self::assertSame([801], $handledInvoices);

        $config->set('health_alarm', '');
        $thirdRun = $runner->run(2, 10);
        self::assertSame(1, $thirdRun['processed']);
        self::assertSame([801, 802], $handledInvoices);
        self::assertSame('succeeded', $jobs->findItem($this->itemId($jobId, 802))?->status);
    }

    private function itemId(int $jobId, int $invoiceId): int
    {
        return (int) Capsule::table(Migrator::ITEMS_TABLE)
            ->where('job_id', $jobId)
            ->where('invoice_id', $invoiceId)
            ->value('id');
    }

    private function runtimeConfig(): Config
    {
        $config = new Config();
        $config->set('module_active', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, '');

        return $config;
    }
}
