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
        $runner = new JobRunner($jobs, new Config(), [
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

    public function testAuthenticationAlarmStopsCurrentAndFutureClaimsUntilCleared(): void
    {
        Migrator::up();
        $jobs = new JobRepository();
        $jobId = $jobs->create('bulk', [
            ['invoice_id' => 801, 'action' => 'auth_failure'],
            ['invoice_id' => 802, 'action' => 'success_after_authentication'],
        ]);
        $config = new Config();
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
}
