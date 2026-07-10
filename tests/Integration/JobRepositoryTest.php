<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class JobRepositoryTest extends MariaDbTestCase
{
    private JobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        Migrator::up();
        $this->jobs = new JobRepository();
    }

    public function testOverlappingJobsKeepOneActiveDedupeOwner(): void
    {
        $firstJob = $this->jobs->create('bulk', [[
            'invoice_id' => 42,
            'action' => 'export_voucher',
        ]]);
        $overlappingJob = $this->jobs->create('bulk', [[
            'invoice_id' => 42,
            'action' => 'export_voucher',
        ]]);

        $first = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $firstJob)->first();
        $overlap = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $overlappingJob)->first();

        self::assertSame('pending', $first->status);
        self::assertSame('export_voucher:42', $first->dedupe_key);
        self::assertSame('skipped', $overlap->status);
        self::assertNull($overlap->dedupe_key);
        self::assertSame('completed', $this->jobs->findJob($overlappingJob)?->status);
    }

    public function testAmbiguousOutcomeRetainsDedupeReservation(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 43,
            'action' => 'export_voucher',
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);

        $this->jobs->finish(
            $claimed,
            JobOutcome::ambiguous('Synthetic unknown write outcome.', 'voucher_write_requested'),
        );
        $overlappingJob = $this->jobs->create('single', [[
            'invoice_id' => 43,
            'action' => 'export_voucher',
        ]]);

        $ambiguous = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        $overlap = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $overlappingJob)->first();
        self::assertSame('ambiguous', $ambiguous->status);
        self::assertSame('export_voucher:43', $ambiguous->dedupe_key);
        self::assertSame('skipped', $overlap->status);
    }

    public function testRetryWaitRetainsDedupeReservation(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 430,
            'action' => 'export_voucher',
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        $this->jobs->finish($claimed, JobOutcome::retry('Synthetic transient error.', 300, 500));

        $overlappingJob = $this->jobs->create('single', [[
            'invoice_id' => 430,
            'action' => 'export_voucher',
        ]]);
        $waiting = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        $overlap = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $overlappingJob)->first();

        self::assertSame('retry_wait', $waiting->status);
        self::assertSame('export_voucher:430', $waiting->dedupe_key);
        self::assertSame('skipped', $overlap->status);
    }

    public function testFinishPreservesCheckpointRemoteIdAndNestedCandidate(): void
    {
        $jobId = $this->jobs->create('refund', [[
            'invoice_id' => 431,
            'action' => 'correction_voucher',
            'dedupe_key' => 'correction:431',
            'candidate' => [
                'request' => ['invoiceId' => 431],
                'positions' => [['amount' => '10.00']],
            ],
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claimed->id,
            (string) $claimed->lease_token,
            'correction_voucher_created',
            ['remoteId' => '900431'],
        ));
        $claimed = $this->jobs->findItem((int) $claimed->id);
        self::assertNotNull($claimed);
        $claimed->lease_token = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claimed->id)->value('lease_token');
        $this->jobs->finish(
            $claimed,
            JobOutcome::ambiguous(
                'Synthetic lost response.',
                'correction_voucher_created',
                candidate: ['httpStatus' => 500],
            ),
        );

        $stored = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        $candidate = json_decode((string) $stored->candidate_json, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('900431', $stored->sevdesk_id);
        self::assertSame(['invoiceId' => 431], $candidate['request']);
        self::assertSame([['amount' => '10.00']], $candidate['positions']);
        self::assertSame(500, $candidate['httpStatus']);
    }

    public function testReconciliationKeepsOriginalAccountingDedupeKey(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 432,
            'action' => 'export_voucher',
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        $this->jobs->finish(
            $claimed,
            JobOutcome::ambiguous('Synthetic unknown write.', 'voucher_write_requested'),
        );
        self::assertTrue($this->jobs->reconcile((int) $claimed->id));

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        self::assertSame('reconcile_voucher', $item->action);
        self::assertSame('export_voucher:432', $item->dedupe_key);
        $overlapId = $this->jobs->create('single', [[
            'invoice_id' => 432,
            'action' => 'export_voucher',
        ]]);
        self::assertSame(
            'skipped',
            Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $overlapId)->value('status'),
        );
    }

    public function testRetryRestoresTransactionLevelDedupeWithoutDoublePrefix(): void
    {
        $reference = 'book_payment:' . hash('sha256', 'whmcs-account:77');
        $jobId = $this->jobs->create('payment_booking', [[
            'invoice_id' => 433,
            'action' => 'book_payment',
            'dedupe_key' => $reference,
            'transaction_reference' => $reference,
            'candidate' => ['whmcsAccountId' => 77],
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        $this->jobs->finish($claimed, JobOutcome::permanentFailure('Synthetic safe failure.'));

        self::assertTrue($this->jobs->retry((int) $claimed->id));
        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        self::assertSame($reference, $item->dedupe_key);
    }

    public function testLargeJobContinuesAfterFailureInTheMiddle(): void
    {
        $items = [];
        for ($invoiceId = 10_000; $invoiceId < 11_000; ++$invoiceId) {
            $items[] = ['invoice_id' => $invoiceId, 'action' => 'export_voucher'];
        }
        $jobId = $this->jobs->create('bulk', $items);
        for ($position = 1; $position <= 1_000; ++$position) {
            $claimed = $this->jobs->claimNext();
            self::assertNotNull($claimed);
            $this->jobs->finish(
                $claimed,
                $position === 501
                    ? JobOutcome::permanentFailure('Synthetic middle failure.', errorCode: 'synthetic')
                    : JobOutcome::succeeded('Synthetic success.', (string) (900_000 + $position)),
            );
        }

        self::assertNull($this->jobs->claimNext());
        $job = $this->jobs->findJob($jobId);
        self::assertSame('completed_with_errors', $job?->status);
        self::assertSame(999, $job?->succeeded_items);
        self::assertSame(1, $job?->failed_items);
        self::assertSame(1_000, $job?->processed_items);
    }

    public function testSuccessfulOutcomeReleasesDedupeForHistoricalItems(): void
    {
        $firstJob = $this->jobs->create('single', [[
            'invoice_id' => 46,
            'action' => 'export_voucher',
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        $this->jobs->finish($claimed, JobOutcome::succeeded('Synthetic success.', '700046'));

        $nextJob = $this->jobs->create('single', [[
            'invoice_id' => 46,
            'action' => 'export_voucher',
        ]]);

        $first = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $firstJob)->first();
        $next = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $nextJob)->first();
        self::assertSame('succeeded', $first->status);
        self::assertNull($first->dedupe_key);
        self::assertSame('pending', $next->status);
        self::assertSame('export_voucher:46', $next->dedupe_key);
    }

    public function testExpiredSafeLeaseIsReclaimedWithNewToken(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 44,
            'action' => 'export_voucher',
        ]]);
        $firstClaim = $this->jobs->claimNext(300);
        self::assertNotNull($firstClaim);

        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $firstClaim->id)->update([
            'leased_until' => '2000-01-01 00:00:00',
        ]);

        $reclaimed = $this->jobs->claimNext(300);
        self::assertNotNull($reclaimed);
        self::assertSame((int) $firstClaim->id, (int) $reclaimed->id);
        self::assertNotSame($firstClaim->lease_token, $reclaimed->lease_token);
        self::assertSame(2, (int) $reclaimed->attempts);
        self::assertSame('running', $reclaimed->status);
        self::assertSame('running', $this->jobs->findJob($jobId)?->status);
    }

    public function testExpiredRiskyLeaseBecomesAmbiguousAndIsNotClaimedAgain(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 45,
            'action' => 'export_voucher',
        ]]);
        $claim = $this->jobs->claimNext(300);
        self::assertNotNull($claim);
        self::assertTrue(
            $this->jobs->checkpoint(
                (int) $claim->id,
                (string) $claim->lease_token,
                'voucher_write_requested',
            ),
        );
        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->update([
            'leased_until' => '2000-01-01 00:00:00',
        ]);

        self::assertNull($this->jobs->claimNext());

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->first();
        self::assertSame('ambiguous', $item->status);
        self::assertSame('export_voucher:45', $item->dedupe_key);
        self::assertNull($item->lease_token);
        self::assertSame('completed_with_errors', $this->jobs->findJob($jobId)?->status);
    }

    public function testCancelledJobKeepsExpiredRiskyLeaseAmbiguous(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 47,
            'action' => 'export_voucher',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claim->id,
            (string) $claim->lease_token,
            'voucher_write_requested',
        ));
        $this->jobs->cancel($jobId);
        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->update([
            'leased_until' => '2000-01-01 00:00:00',
        ]);

        self::assertNull($this->jobs->claimNext());

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->first();
        self::assertSame('ambiguous', $item->status);
        self::assertSame('export_voucher:47', $item->dedupe_key);
        self::assertNull($item->lease_token);
    }

    public function testCancelledJobReleasesExpiredSafeLeaseAsCancelled(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 48,
            'action' => 'export_voucher',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        $this->jobs->cancel($jobId);
        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->update([
            'leased_until' => '2000-01-01 00:00:00',
        ]);

        self::assertNull($this->jobs->claimNext());

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->first();
        self::assertSame('cancelled', $item->status);
        self::assertNull($item->dedupe_key);
        self::assertNull($item->lease_token);
    }

    public function testCancelledJobsRejectRetryAndReconciliation(): void
    {
        $failedJob = $this->jobs->create('single', [[
            'invoice_id' => 49,
            'action' => 'export_voucher',
        ]]);
        $failedClaim = $this->jobs->claimNext();
        self::assertNotNull($failedClaim);
        $this->jobs->finish($failedClaim, JobOutcome::permanentFailure('Synthetic terminal failure.'));
        $this->jobs->cancel($failedJob);
        $failedBefore = $this->jobs->findItem((int) $failedClaim->id);

        self::assertFalse($this->jobs->retry((int) $failedClaim->id));
        $failedAfter = $this->jobs->findItem((int) $failedClaim->id);
        self::assertSame($failedBefore?->status, $failedAfter?->status);
        self::assertSame($failedBefore?->dedupe_key, $failedAfter?->dedupe_key);

        $ambiguousJob = $this->jobs->create('single', [[
            'invoice_id' => 50,
            'action' => 'export_voucher',
        ]]);
        $ambiguousClaim = $this->jobs->claimNext();
        self::assertNotNull($ambiguousClaim);
        $this->jobs->finish(
            $ambiguousClaim,
            JobOutcome::ambiguous('Synthetic unknown outcome.', 'voucher_write_requested'),
        );
        $this->jobs->cancel($ambiguousJob);
        $ambiguousBefore = $this->jobs->findItem((int) $ambiguousClaim->id);

        self::assertFalse($this->jobs->reconcile((int) $ambiguousClaim->id));
        $ambiguousAfter = $this->jobs->findItem((int) $ambiguousClaim->id);
        self::assertSame($ambiguousBefore?->status, $ambiguousAfter?->status);
        self::assertSame($ambiguousBefore?->dedupe_key, $ambiguousAfter?->dedupe_key);
    }

    public function testTwoProcessesNeverClaimTheSameItem(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the concurrent claim integration test.');
        }

        $items = [];
        for ($invoiceId = 100; $invoiceId < 120; ++$invoiceId) {
            $items[] = ['invoice_id' => $invoiceId, 'action' => 'export_voucher'];
        }
        $this->jobs->create('bulk', $items);

        $processes = [$this->startClaimWorker(20), $this->startClaimWorker(20)];
        $claimedIds = [];
        foreach ($processes as $process) {
            $claimedIds = array_merge($claimedIds, $this->finishClaimWorker($process));
        }

        sort($claimedIds);
        self::assertCount(20, $claimedIds);
        self::assertCount(20, array_unique($claimedIds));
        self::assertSame(
            20,
            Capsule::table(Migrator::ITEMS_TABLE)->where('status', 'running')->count(),
        );
    }

    /**
     * @return array{resource,resource,resource}
     */
    private function startClaimWorker(int $limit): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/tests/Integration/Fixtures/claim-worker.php',
            (string) $limit,
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            null,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $pipes[1], $pipes[2]];
    }

    /**
     * @param array{resource,resource,resource} $worker
     * @return list<int>
     */
    private function finishClaimWorker(array $worker): array
    {
        [$process, $stdout, $stderr] = $worker;
        $output = stream_get_contents($stdout);
        $errors = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $errors === false ? 'Claim worker failed.' : $errors);
        self::assertIsString($output);
        $decoded = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values(array_map('intval', $decoded));
    }
}
