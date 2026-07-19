<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
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

    public function testPaidHybridTriggerMarksPendingCreatedOwnerWithoutReplacingIt(): void
    {
        $createdJob = $this->jobs->create('automatic_export', [[
            'invoice_id' => 421,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:421',
            'candidate' => $this->hybridCandidate('InvoiceCreated'),
        ]]);
        $paidJob = $this->jobs->create('automatic_export', [[
            'invoice_id' => 421,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:421',
            'candidate' => $this->hybridCandidate('InvoicePaid'),
        ]]);

        $owner = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $createdJob)->first();
        $loser = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $paidJob)->first();
        $candidate = json_decode((string) $owner->candidate_json, true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('pending', $owner->status);
        self::assertSame('export_voucher:421', $owner->dedupe_key);
        self::assertTrue($candidate['paidTriggerObserved']);
        self::assertSame('skipped', $loser->status);
        self::assertNull($loser->dedupe_key);

        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        self::assertSame((int) $owner->id, (int) $claimed->id);
    }

    public function testHistoricalBackfillCannotTakeOverAnAutomaticDeliveryOwner(): void
    {
        $ownerJob = $this->jobs->create('automatic_export', [[
            'invoice_id' => 425,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:425',
            'candidate' => [
                'trigger' => 'InvoicePaid',
                'delivery_requested' => true,
            ],
        ]]);
        $backfillJob = $this->jobs->create('historical_backfill', [[
            'invoice_id' => 425,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:425',
            'candidate' => [
                'historicalBackfill' => true,
                'delivery_requested' => false,
                'requestedEInvoiceMode' => 'off',
            ],
        ]], ['mail_free' => true, 'e_invoice' => false]);

        $owner = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $ownerJob)->first();
        $loser = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $backfillJob)->first();
        $ownerCandidate = json_decode((string) $owner->candidate_json, true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('pending', $owner->status);
        self::assertTrue($ownerCandidate['delivery_requested']);
        self::assertSame('InvoicePaid', $ownerCandidate['trigger']);
        self::assertSame('skipped', $loser->status);
        self::assertNull($loser->dedupe_key);
        self::assertSame('completed', $this->jobs->findJob($backfillJob)?->status);
    }

    public function testHistoricalBackfillCannotTakeOverLegacyRiskyFailedVoucherWrite(): void
    {
        $oldJob = $this->jobs->create('legacy_export', [[
            'invoice_id' => 426,
            'action' => 'export_voucher',
            'dedupe_key' => 'export_voucher:426',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        $this->jobs->finish($claim, JobOutcome::permanentFailure(
            'Synthetic legacy write uncertainty.',
            errorCode: 'synthetic_legacy_failure',
            checkpoint: 'voucher_write_requested',
        ));
        self::assertSame(
            'export_voucher:426',
            Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $oldJob)->value('dedupe_key'),
        );

        // Simulate a pre-rc.2 terminal row whose old finish() implementation
        // released the key despite the unresolved remote-write checkpoint.
        Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $oldJob)->update(['dedupe_key' => null]);
        $backfillJob = $this->jobs->create('historical_backfill', [[
            'invoice_id' => 426,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:426',
            'candidate' => [
                'historicalBackfill' => true,
                'delivery_requested' => false,
                'requestedEInvoiceMode' => 'off',
            ],
        ]], ['mail_free' => true, 'e_invoice' => false]);

        $blocked = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $backfillJob)->first();
        self::assertSame('skipped', $blocked->status);
        self::assertSame('unresolved_export_history', $blocked->error_code);
        self::assertNull($blocked->dedupe_key);
        self::assertSame('completed', $this->jobs->findJob($backfillJob)?->status);
        self::assertNull($this->jobs->claimNext());

        $singleJob = $this->jobs->create('single_export', [[
            'invoice_id' => 426,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:426',
            'candidate' => ['trigger' => 'admin'],
        ]]);
        $single = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $singleJob)->first();

        self::assertSame('skipped', $single->status);
        self::assertSame('unresolved_export_history', $single->error_code);
        self::assertNull($single->dedupe_key);
        self::assertSame('completed', $this->jobs->findJob($singleJob)?->status);
        self::assertNull($this->jobs->claimNext());
    }

    public function testPaidHybridTriggerRequeuesOwnerThatObservedUnpaidState(): void
    {
        $createdJob = $this->jobs->create('automatic_export', [[
            'invoice_id' => 422,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:422',
            'candidate' => $this->hybridCandidate('InvoiceCreated'),
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claimed->id,
            (string) $claimed->lease_token,
            'invoice_payment_pending',
            ['invoicePaymentPending' => true],
        ));

        $this->jobs->create('automatic_export', [[
            'invoice_id' => 422,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:422',
            'candidate' => $this->hybridCandidate('InvoicePaid'),
        ]]);
        $this->jobs->finish($claimed, JobOutcome::skipped('Invoice requires payment.'));

        $waiting = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $createdJob)->first();
        $candidate = json_decode((string) $waiting->candidate_json, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('retry_wait', $waiting->status);
        self::assertSame('invoice_payment_pending', $waiting->checkpoint);
        self::assertSame('export_voucher:422', $waiting->dedupe_key);
        self::assertSame('invoice_payment_event_followup', $waiting->error_code);
        self::assertFalse($candidate['paidTriggerObserved']);
        self::assertNotEmpty($candidate['paidTriggerConsumedAt']);

        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $waiting->id)->update([
            'available_at' => '2000-01-01 00:00:00',
        ]);
        $reclaimed = $this->jobs->claimNext();
        self::assertNotNull($reclaimed);
        self::assertSame((int) $claimed->id, (int) $reclaimed->id);
        self::assertSame(2, (int) $reclaimed->attempts);
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

    public function testFinishMergesAgainstCheckpointContextInsteadOfStaleClaim(): void
    {
        $jobId = $this->jobs->create('single_export', [[
            'invoice_id' => 434,
            'action' => 'export_voucher',
            'candidate' => ['credit_treatment' => 'full_gross_voucher'],
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claimed->id,
            (string) $claimed->lease_token,
            'contact_write_requested',
            ['whmcsClientId' => 20],
        ));

        // Deliberately finish with the stale object returned before checkpoint().
        $this->jobs->finish(
            $claimed,
            JobOutcome::ambiguous(
                'Synthetic unknown contact outcome.',
                'contact_write_requested',
                errorCode: 'contact_search_failed',
                candidate: ['httpStatus' => 500],
            ),
        );

        $stored = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        $candidate = json_decode((string) $stored->candidate_json, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('ambiguous', $stored->status);
        self::assertSame('export_voucher:434', $stored->dedupe_key);
        self::assertSame('full_gross_voucher', $candidate['credit_treatment']);
        self::assertSame(20, $candidate['whmcsClientId']);
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

    public function testUnknownWhmcsEmailNeedsFreshDuplicateRiskConfirmationForEveryRetry(): void
    {
        $jobId = $this->jobs->create('automatic_export', [[
            'invoice_id' => 435,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:435',
            'candidate' => [
                'targetDocumentType' => 'invoice',
                'targetDocumentAuthority' => 'sevdesk',
                'targetDeliveryChannel' => 'whmcs_template',
            ],
        ]]);
        $claimed = $this->jobs->claimNext();
        self::assertNotNull($claimed);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claimed->id,
            (string) $claimed->lease_token,
            'whmcs_email_write_requested',
            ['remoteId' => '900435', 'emailRetryConfirmed' => false],
        ));
        $this->jobs->finish(
            $claimed,
            JobOutcome::ambiguous('Synthetic unknown provider hand-off.', 'whmcs_email_write_requested'),
        );

        self::assertFalse($this->jobs->reconcile((int) $claimed->id));
        self::assertTrue($this->jobs->confirmEmailRetry((int) $claimed->id));
        $pending = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        $candidate = json_decode((string) $pending->candidate_json, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('pending', $pending->status);
        self::assertSame('export_voucher:435', $pending->dedupe_key);
        self::assertTrue($candidate['emailRetryConfirmed']);

        $retry = $this->jobs->claimNext();
        self::assertNotNull($retry);
        self::assertTrue($this->jobs->checkpoint(
            (int) $retry->id,
            (string) $retry->lease_token,
            'whmcs_email_write_requested',
            ['remoteId' => '900435', 'emailRetryConfirmed' => false],
        ));
        $this->jobs->finish(
            $retry,
            JobOutcome::ambiguous('Synthetic second unknown provider hand-off.', 'whmcs_email_write_requested'),
        );

        $again = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        $candidate = json_decode((string) $again->candidate_json, true, 32, JSON_THROW_ON_ERROR);
        self::assertFalse($candidate['emailRetryConfirmed']);
        self::assertTrue($this->jobs->confirmEmailRetry((int) $retry->id));
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
        self::assertSame(999, $job->succeeded_items);
        self::assertSame(1, $job->failed_items);
        self::assertSame(1_000, $job->processed_items);
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

    public function testManualReviewNoticeKeepsItsBusinessDedupeKey(): void
    {
        $dedupe = 'review:invoice_cancelled:460';
        $firstJob = $this->jobs->create('accounting_review', [[
            'invoice_id' => 460,
            'action' => 'review_notice',
            'dedupe_key' => $dedupe,
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        $this->jobs->finish($claim, JobOutcome::permanentFailure(
            'Synthetic manual review.',
            errorCode: 'manual_review_required',
        ));

        $duplicateJob = $this->jobs->create('accounting_review', [[
            'invoice_id' => 460,
            'action' => 'review_notice',
            'dedupe_key' => $dedupe,
        ]]);

        $first = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $firstJob)->first();
        $duplicate = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $duplicateJob)->first();
        self::assertSame('permanent_failed', $first->status);
        self::assertSame($dedupe, $first->dedupe_key);
        self::assertSame('skipped', $duplicate->status);
        self::assertNull($duplicate->dedupe_key);
        self::assertNull($this->jobs->claimNext());
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

    public function testClaimAndRetryUseDatabaseClockAcrossPhpTimezoneChanges(): void
    {
        $originalPhpTimezone = date_default_timezone_get();
        $originalDatabaseTimezone = (string) (
            Capsule::selectOne('SELECT @@session.time_zone AS current_timezone')->current_timezone ?? 'SYSTEM'
        );

        try {
            self::assertTrue(Capsule::statement("SET time_zone = '+02:00'"));
            self::assertTrue(date_default_timezone_set('Etc/GMT-2'));

            $jobId = $this->jobs->create('timezone-regression', [[
                'invoice_id' => 440,
                'action' => 'export_document',
            ]]);
            $queued = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
            self::assertNotNull($queued);

            self::assertTrue(date_default_timezone_set('UTC'));
            $claimed = $this->jobs->claimNext(300);

            self::assertNotNull($claimed);
            self::assertSame((int) $queued->id, (int) $claimed->id);
            $leaseSeconds = (int) (
                Capsule::selectOne(
                    'SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, ?) AS seconds',
                    [(string) $claimed->leased_until],
                )->seconds ?? 0
            );
            self::assertGreaterThanOrEqual(295, $leaseSeconds);
            self::assertLessThanOrEqual(300, $leaseSeconds);

            $this->jobs->finish($claimed, JobOutcome::retry(
                'Synthetic timezone-safe retry.',
                60,
                errorCode: 'timezone_retry',
            ));
            $waiting = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claimed->id)->first();
            self::assertNotNull($waiting);
            self::assertSame('retry_wait', $waiting->status);
            $retrySeconds = (int) (
                Capsule::selectOne(
                    'SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, ?) AS seconds',
                    [(string) $waiting->available_at],
                )->seconds ?? 0
            );
            self::assertGreaterThanOrEqual(55, $retrySeconds);
            self::assertLessThanOrEqual(60, $retrySeconds);

            $createdJob = $this->jobs->create('automatic_export', [[
                'invoice_id' => 441,
                'action' => 'export_document',
                'dedupe_key' => 'export_voucher:441',
                'candidate' => $this->hybridCandidate('InvoiceCreated'),
            ]]);
            $paymentPending = $this->jobs->claimNext();
            self::assertNotNull($paymentPending);
            self::assertTrue($this->jobs->checkpoint(
                (int) $paymentPending->id,
                (string) $paymentPending->lease_token,
                'invoice_payment_pending',
                ['invoicePaymentPending' => true],
            ));
            $this->jobs->create('automatic_export', [[
                'invoice_id' => 441,
                'action' => 'export_document',
                'dedupe_key' => 'export_voucher:441',
                'candidate' => $this->hybridCandidate('InvoicePaid'),
            ]]);
            $this->jobs->finish($paymentPending, JobOutcome::skipped('Invoice requires payment.'));

            $followUp = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $createdJob)->first();
            self::assertNotNull($followUp);
            self::assertSame('retry_wait', $followUp->status);
            self::assertSame('invoice_payment_event_followup', $followUp->error_code);
            $followUpSeconds = (int) (
                Capsule::selectOne(
                    'SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, ?) AS seconds',
                    [(string) $followUp->available_at],
                )->seconds ?? 0
            );
            self::assertGreaterThanOrEqual(55, $followUpSeconds);
            self::assertLessThanOrEqual(60, $followUpSeconds);
        } finally {
            date_default_timezone_set($originalPhpTimezone);
            Capsule::statement('SET time_zone = ?', [$originalDatabaseTimezone]);
        }
    }

    public function testTransactionalClaimGateCanStopBeforeHandlerOwnershipBegins(): void
    {
        $jobId = $this->jobs->create('runtime-gated', [[
            'invoice_id' => 441,
            'action' => 'must_not_start',
        ]]);
        $config = new Config();
        $config->set('module_active', 'on');
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
        $config->set(Config::RUNTIME_REVIEW_SETTING, 'on');

        $claim = $this->jobs->claimNext(
            claimAllowed: static fn (): bool => $config->runtimeAllowsClaimWhileLocked(),
        );

        self::assertNull($claim);
        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        self::assertSame('pending', $item->status);
        self::assertSame(0, (int) $item->attempts);
        self::assertNull($item->lease_token);
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

    public function testExpiredVerifiedSideEffectLeaseIsSafelyReclaimed(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 451,
            'action' => 'export_document',
        ]]);
        $claim = $this->jobs->claimNext(300);
        self::assertNotNull($claim);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claim->id,
            (string) $claim->lease_token,
            'mapping_persisted',
            ['remoteId' => '900451'],
        ));
        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->update([
            'leased_until' => '2000-01-01 00:00:00',
        ]);

        $reclaimed = $this->jobs->claimNext(300);

        self::assertNotNull($reclaimed);
        self::assertSame((int) $claim->id, (int) $reclaimed->id);
        self::assertSame('mapping_persisted', $reclaimed->checkpoint);
        self::assertSame('900451', $reclaimed->sevdesk_id);
        self::assertSame(2, (int) $reclaimed->attempts);
        self::assertSame('running', $this->jobs->findJob($jobId)?->status);
    }

    public function testManualReviewBookingFailuresCannotBypassRetryGuard(): void
    {
        foreach (['booking_not_applied', 'manual_review_required'] as $offset => $errorCode) {
            $jobId = $this->jobs->create('payment_booking', [[
                'invoice_id' => 460 + $offset,
                'action' => 'book_payment',
                'dedupe_key' => 'book_payment:guard-' . $offset,
            ]]);
            $claim = $this->jobs->claimNext();
            self::assertNotNull($claim);
            $this->jobs->finish($claim, JobOutcome::permanentFailure(
                'Synthetic guarded failure.',
                errorCode: $errorCode,
            ));

            self::assertFalse($this->jobs->retry((int) $claim->id));
            self::assertSame(
                'permanent_failed',
                Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->value('status'),
            );
        }
    }

    public function testStaleVoucherJobRequiresFreshMailFreeDocumentJob(): void
    {
        $oldJobId = $this->jobs->create('legacy_export', [[
            'invoice_id' => 469,
            'action' => 'export_voucher',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claim->id,
            (string) $claim->lease_token,
            'document_type_selected',
            [
                'targetAllowed' => true,
                'targetDocumentType' => 'voucher',
                'targetDocumentAuthority' => 'whmcs',
                'targetExportMode' => 'voucher_only',
                'targetOssProfile' => 'blocked',
                'targetEuB2cMode' => 'blocked',
            ],
        ));
        $this->jobs->finish($claim, JobOutcome::permanentFailure(
            'Synthetic configuration drift.',
            errorCode: 'stale_export_context_requeue_required',
            checkpoint: 'document_type_selected',
        ));

        self::assertFalse($this->jobs->retry((int) $claim->id));
        $newJobId = $this->jobs->requeueExportDocument((int) $claim->id, [
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => null,
        ], 1);

        self::assertNotNull($newJobId);
        self::assertNotSame($oldJobId, $newJobId);
        $old = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $oldJobId)->first();
        $new = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $newJobId)->first();
        self::assertSame('permanent_failed', $old->status);
        self::assertSame('export_voucher', $old->action);
        self::assertSame('pending', $new->status);
        self::assertSame('export_document', $new->action);
        self::assertSame('export_voucher:469', $new->dedupe_key);
        $candidate = json_decode((string) $new->candidate_json, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('invoice_only', $candidate['requestedExportMode']);
        self::assertTrue($candidate['historicalBackfill']);
        self::assertFalse($candidate['delivery_requested']);
        self::assertSame('off', $candidate['requestedEInvoiceMode']);
        self::assertArrayNotHasKey('targetDocumentType', $candidate);
        self::assertNull($this->jobs->requeueExportDocument((int) $claim->id, [
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => null,
        ], 1));
    }

    public function testDocumentWriteCheckpointCannotBeRequeuedIntoAnotherMode(): void
    {
        $jobId = $this->jobs->create('legacy_export', [[
            'invoice_id' => 470,
            'action' => 'export_voucher',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        $this->jobs->finish($claim, JobOutcome::permanentFailure(
            'Synthetic risky terminal state.',
            errorCode: 'synthetic_failure',
            checkpoint: 'voucher_write_requested',
        ));

        self::assertNull($this->jobs->requeueExportDocument((int) $claim->id, [
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => null,
        ], 1));
        self::assertSame(
            'permanent_failed',
            Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->value('status'),
        );
    }

    public function testXmlMismatchCannotReplaceTheFirstVerifiedRecoveryHash(): void
    {
        $expectedHash = hash('sha256', '<synthetic-original/>');
        $observedHash = hash('sha256', '<synthetic-changed/>');
        $jobId = $this->jobs->create('e_invoice_export', [[
            'invoice_id' => 471,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:471',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claim->id,
            (string) $claim->lease_token,
            'invoice_xml_verified',
            ['xmlSha256' => $expectedHash, 'remoteId' => '90471'],
        ));

        $this->jobs->finish($claim, JobOutcome::ambiguous(
            'Synthetic XML drift.',
            'invoice_xml_verified',
            '90471',
            errorCode: 'invoice_xml_hash_mismatch',
            candidate: ['xmlSha256' => $observedHash],
        ));

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->first();
        $candidate = json_decode((string) $item->candidate_json, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($expectedHash, $candidate['xmlSha256']);
        self::assertSame($observedHash, $candidate['observedXmlSha256']);
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

    public function testCancelledJobKeepsExpiredVerifiedSideEffectReserved(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 471,
            'action' => 'export_document',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claim->id,
            (string) $claim->lease_token,
            'invoice_created',
            ['remoteId' => '900471'],
        ));
        $this->jobs->cancel($jobId);
        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->update([
            'leased_until' => '2000-01-01 00:00:00',
        ]);

        self::assertNull($this->jobs->claimNext());

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->first();
        self::assertSame('ambiguous', $item->status);
        self::assertSame('export_voucher:471', $item->dedupe_key);
        self::assertSame('900471', $item->sevdesk_id);
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

    public function testCancelledRunningItemDiscardsASafeRetryAndReleasesItsDedupeKey(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 481,
            'action' => 'export_document',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claim->id,
            (string) $claim->lease_token,
            'invoice_write_requested',
        ));

        $this->jobs->cancel($jobId);
        $this->jobs->finish($claim, JobOutcome::retry(
            'Synthetic definite rate-limit rejection.',
            300,
            429,
            errorCode: 'api_rate_limited',
            checkpoint: 'document_type_selected',
        ));

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->first();
        self::assertSame('cancelled', $item->status);
        self::assertSame('document_type_selected', $item->checkpoint);
        self::assertSame('cancelled_by_admin', $item->error_code);
        self::assertNull($item->dedupe_key);
        self::assertNull($item->lease_token);
        self::assertSame('cancelled', $this->jobs->findJob($jobId)?->status);
        self::assertNull($this->jobs->claimNext());
    }

    public function testCancelledRunningItemKeepsAVerifiedSideEffectAsAmbiguous(): void
    {
        $jobId = $this->jobs->create('single', [[
            'invoice_id' => 482,
            'action' => 'export_document',
        ]]);
        $claim = $this->jobs->claimNext();
        self::assertNotNull($claim);
        self::assertTrue($this->jobs->checkpoint(
            (int) $claim->id,
            (string) $claim->lease_token,
            'mapping_persisted',
            ['remoteId' => '900482'],
        ));

        $this->jobs->cancel($jobId);
        $this->jobs->finish($claim, JobOutcome::retry(
            'Synthetic read failure after a verified side effect.',
            300,
            503,
            errorCode: 'invoice_read_failed',
            checkpoint: 'mapping_persisted',
        ));

        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $claim->id)->first();
        self::assertSame('ambiguous', $item->status);
        self::assertSame('mapping_persisted', $item->checkpoint);
        self::assertSame('invoice_read_failed', $item->error_code);
        self::assertSame('export_voucher:482', $item->dedupe_key);
        self::assertSame('900482', $item->sevdesk_id);
        self::assertNull($item->lease_token);
        self::assertSame('cancelled', $this->jobs->findJob($jobId)?->status);
        self::assertNull($this->jobs->claimNext());
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

    /** @return array<string,mixed> */
    private function hybridCandidate(string $trigger): array
    {
        return [
            'trigger' => $trigger,
            'requestedExportMode' => 'invoice_for_oss',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedOssProfile' => 'rule19_digital_services_confirmed',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => null,
            'delivery_requested' => false,
        ];
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
