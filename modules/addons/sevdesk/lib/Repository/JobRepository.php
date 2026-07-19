<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Repository;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;

final class JobRepository
{
    /** A crashed worker cannot prove whether the remote write ran. */
    private const UNKNOWN_WRITE_CHECKPOINTS = [
        'contact_write_requested',
        'voucher_write_requested',
        'invoice_write_requested',
        'invoice_open_write_requested',
        'invoice_delivery_write_requested',
        'whmcs_email_write_requested',
        'booking_write_requested',
        'correction_write_requested',
        'correction_voucher_write_requested',
    ];

    /** The side effect is durable and verified; a later step may resume safely. */
    private const VERIFIED_SIDE_EFFECT_CHECKPOINTS = [
        'voucher_created',
        'mapping_persisted',
        'invoice_created',
        'invoice_xml_verified',
        'invoice_opened',
        'invoice_delivered',
        'whmcs_email_handed_off',
        'booking_completed',
        'correction_created',
        'correction_voucher_created',
        'correction_mapping_persisted',
    ];

    private const DEDUPE_SKIPPED_MESSAGE = 'Die Rechnung ist bereits in einem anderen aktiven Job eingeplant.';

    private const UNRESOLVED_HISTORY_SKIPPED_MESSAGE =
        'Ein älterer Export endete nach einem möglichen Remote-Write und muss zuerst geklärt werden.';

    /**
     * Return the newest validated document decision for one WHMCS invoice.
     *
     * Before the worker freezes a target, automatic hooks persist a requested
     * snapshot. Once a mapping exists, callers must request a frozen context so
     * a later skipped export attempt cannot reinterpret an existing document.
     *
     * @return null|array{
     *     itemId:int,
     *     itemStatus:string,
     *     checkpoint:string,
     *     source:'frozen'|'requested',
     *     allowed:?bool,
     *     documentType:?string,
     *     documentAuthority:string,
     *     exportMode:string,
     *     ossProfile:string,
     *     euB2cMode:string,
     *     deliveryChannel:?string,
     *     taxRuleId:?string,
     *     deliveryState:?string,
     *     sevUserId:?string,
     *     unityId:?string,
     *     isEInvoice:?bool,
     *     eInvoiceMode:string,
     *     eInvoiceContactId:?string,
     *     paymentMethodId:?string,
     *     eInvoiceCountryId:?string,
     *     addressHash:?string
     * }
     */
    public function latestDocumentContextForInvoice(int $invoiceId, bool $frozenOnly = false): ?array
    {
        return $this->documentContextsForInvoices([$invoiceId], $frozenOnly)[$invoiceId] ?? null;
    }

    /**
     * Batch variant of latestDocumentContextForInvoice(). A malformed newest
     * decision blocks fallback only for its own invoice; dedupe-losing rows are
     * ignored, and frozenOnly may skip valid requested rows to find their owner.
     *
     * @param list<int> $invoiceIds
     * @return array<int,array{
     *     itemId:int,itemStatus:string,checkpoint:string,source:'frozen'|'requested',
     *     allowed:?bool,documentType:?string,documentAuthority:string,exportMode:string,
     *     ossProfile:string,euB2cMode:string,deliveryChannel:?string,taxRuleId:?string,
     *     deliveryState:?string,sevUserId:?string,unityId:?string,
     *     isEInvoice:?bool,eInvoiceMode:string,eInvoiceContactId:?string,
     *     paymentMethodId:?string,eInvoiceCountryId:?string,addressHash:?string
     * }>
     */
    public function documentContextsForInvoices(array $invoiceIds, bool $frozenOnly = false): array
    {
        $invoiceIds = array_values(array_unique(array_filter(
            array_map('intval', $invoiceIds),
            static fn (int $invoiceId): bool => $invoiceId > 0,
        )));
        if ($invoiceIds === [] || !Capsule::schema()->hasTable(Migrator::ITEMS_TABLE)) {
            return [];
        }

        $contexts = [];
        $resolved = [];
        $items = Capsule::table(Migrator::ITEMS_TABLE)
            ->whereIn('invoice_id', $invoiceIds)
            ->where('action', 'export_document')
            ->orderBy('invoice_id')
            ->orderByDesc('id')
            ->get(['id', 'invoice_id', 'status', 'checkpoint', 'candidate_json', 'message']);

        foreach ($items as $item) {
            $invoiceId = (int) ($item->invoice_id ?? 0);
            if ($invoiceId < 1 || isset($resolved[$invoiceId])) {
                continue;
            }
            if (self::isDedupeSkippedItem($item)) {
                continue;
            }

            $context = self::documentContextFromItem($item);
            if ($context === null) {
                // Do not expose an older authority after malformed newer state.
                $resolved[$invoiceId] = true;
                continue;
            }
            if ($frozenOnly && $context['source'] === 'requested') {
                continue;
            }

            $contexts[$invoiceId] = $context;
            $resolved[$invoiceId] = true;
        }

        return $contexts;
    }

    /**
     * @param list<array{invoice_id?:int,action?:string,dedupe_key?:string,transaction_reference?:string,candidate?:array<string,mixed>}> $items
     * @param array<string,mixed> $filters
     */
    public function create(string $type, array $items, array $filters = [], ?int $adminId = null): int
    {
        return Capsule::connection()->transaction(function () use ($type, $items, $filters, $adminId): int {
            $now = $this->now();
            $jobId = (int) Capsule::table(Migrator::JOBS_TABLE)->insertGetId([
                'type' => $type,
                'status' => 'pending',
                'filters_json' => $this->encode($filters),
                'requested_by_admin_id' => $adminId,
                'total_items' => count($items),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($items as $item) {
                $invoiceId = isset($item['invoice_id']) ? (int) $item['invoice_id'] : null;
                $action = $item['action'] ?? $type;
                $dedupeAction = $action === 'export_document' ? 'export_voucher' : $action;
                $dedupeKey = $item['dedupe_key'] ?? ($invoiceId === null ? null : $dedupeAction . ':' . $invoiceId);
                $row = [
                    'job_id' => $jobId,
                    'invoice_id' => $invoiceId,
                    'action' => $action,
                    'status' => 'pending',
                    'dedupe_key' => $dedupeKey,
                    'checkpoint' => 'queued',
                    'attempts' => 0,
                    'available_at' => $now,
                    'transaction_reference' => $item['transaction_reference'] ?? null,
                    'candidate_json' => isset($item['candidate']) ? $this->encode($item['candidate']) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $candidate = is_array($item['candidate'] ?? null) ? $item['candidate'] : [];
                if (
                    $invoiceId !== null
                    && $invoiceId > 0
                    && $action === 'export_document'
                    && $this->lockUnresolvedRiskyExportHistory($invoiceId) !== null
                ) {
                    $row['status'] = 'skipped';
                    $row['dedupe_key'] = null;
                    $row['error_code'] = 'unresolved_export_history';
                    $row['message'] = self::UNRESOLVED_HISTORY_SKIPPED_MESSAGE;
                    $row['finished_at'] = $now;
                    Capsule::table(Migrator::ITEMS_TABLE)->insert($row);
                    continue;
                }

                try {
                    Capsule::table(Migrator::ITEMS_TABLE)->insert($row);
                } catch (QueryException $error) {
                    if (!$this->isDuplicateKey($error)) {
                        throw $error;
                    }
                    $this->markPaidTriggerOnActiveExport(
                        $dedupeKey,
                        $invoiceId,
                        $action,
                        $candidate !== [] ? $candidate : null,
                        $now,
                    );
                    // An active item already owns this accounting action. Keep a
                    // visible terminal row in this job without stealing its lock.
                    $row['status'] = 'skipped';
                    $row['dedupe_key'] = null;
                    $row['message'] = self::DEDUPE_SKIPPED_MESSAGE;
                    $row['finished_at'] = $now;
                    Capsule::table(Migrator::ITEMS_TABLE)->insert($row);
                }
            }

            $this->refreshJob($jobId);

            return $jobId;
        });
    }

    /**
     * @param null|callable():bool $claimAllowed Called inside the claim
     *     transaction before job/item locks are taken. Implementations may lock
     *     durable runtime settings; the global lock order is settings -> job ->
     *     item, matching setup, cancellation and lease recovery.
     */
    public function claimNext(int $leaseSeconds = 300, ?callable $claimAllowed = null): ?object
    {
        $this->recoverExpiredLeasesForSafety();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $claimed = Capsule::connection()->transaction(function () use (
                $leaseSeconds,
                $claimAllowed,
            ): object|bool|null {
                if ($claimAllowed !== null && !$claimAllowed()) {
                    return null;
                }

                $now = $this->now();
                $candidate = Capsule::table(Migrator::ITEMS_TABLE . ' as item')
                    ->join(Migrator::JOBS_TABLE . ' as job', 'item.job_id', '=', 'job.id')
                    ->whereIn('item.status', ['pending', 'retry_wait'])
                    ->where('item.available_at', '<=', $now)
                    ->whereNull('job.cancel_requested_at')
                    ->whereNotIn('job.status', ['paused', 'cancelled'])
                    ->orderBy('item.available_at')
                    ->orderBy('item.id')
                    ->select('item.*')
                    ->first();

                if ($candidate === null) {
                    return null;
                }

                $job = Capsule::table(Migrator::JOBS_TABLE)
                    ->where('id', $candidate->job_id)
                    ->lockForUpdate()
                    ->first();
                if (
                    $job === null
                    || $job->cancel_requested_at !== null
                    || in_array((string) $job->status, ['paused', 'cancelled'], true)
                ) {
                    return false;
                }

                $current = Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $candidate->id)
                    ->lockForUpdate()
                    ->first();
                if (
                    $current === null
                    || !in_array((string) $current->status, ['pending', 'retry_wait'], true)
                    || (string) $current->available_at > $now
                ) {
                    return false;
                }

                $token = bin2hex(random_bytes(16));
                $leasedUntil = (new DateTimeImmutable())
                    ->add(new DateInterval('PT' . $leaseSeconds . 'S'))
                    ->format('Y-m-d H:i:s');
                $updated = Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $current->id)
                    ->whereIn('status', ['pending', 'retry_wait'])
                    ->update([
                        'status' => 'running',
                        'lease_token' => $token,
                        'leased_until' => $leasedUntil,
                        'attempts' => Capsule::raw('attempts + 1'),
                        'started_at' => $current->started_at ?? $now,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    return false;
                }

                Capsule::table(Migrator::JOBS_TABLE)
                    ->where('id', $job->id)
                    ->whereNull('started_at')
                    ->update(['started_at' => $now]);
                Capsule::table(Migrator::JOBS_TABLE)->where('id', $job->id)->update([
                    'status' => 'running',
                    'updated_at' => $now,
                ]);

                return Capsule::table(Migrator::ITEMS_TABLE)->where('id', $current->id)->first();
            });

            if ($claimed === null) {
                return null;
            }
            if (is_object($claimed)) {
                return $claimed;
            }
        }

        return null;
    }

    /** @param array<string, scalar|null> $context */
    public function checkpoint(int $itemId, string $token, string $checkpoint, array $context = []): bool
    {
        $query = Capsule::table(Migrator::ITEMS_TABLE)
            ->where('id', $itemId)
            ->where('lease_token', $token)
            ->where('status', 'running');
        $item = (clone $query)->first();
        if ($item === null) {
            return false;
        }

        $candidate = [];
        if (is_string($item->candidate_json) && $item->candidate_json !== '') {
            try {
                $decoded = json_decode($item->candidate_json, true, 32, JSON_THROW_ON_ERROR);
                $candidate = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $candidate = [];
            }
        }
        $context = self::preserveFrozenXmlHash($candidate, $context);
        foreach ($context as $key => $value) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key) === 1) {
                $candidate[$key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
            }
        }

        $remoteId = $context['remoteId'] ?? null;

        return $query->update([
            'checkpoint' => mb_substr($checkpoint, 0, 64),
            'candidate_json' => $candidate === [] ? $item->candidate_json : $this->encode($candidate),
            'sevdesk_id' => is_scalar($remoteId) && preg_match('/^\d+$/', (string) $remoteId) === 1
                ? (string) $remoteId
                : $item->sevdesk_id,
            'updated_at' => $this->now(),
        ]) === 1;
    }

    public function finish(object $item, JobOutcome $outcome): void
    {
        $now = $this->now();
        $jobId = (int) $item->job_id;
        $terminal = in_array($outcome->status, ['succeeded', 'skipped', 'permanent_failed', 'ambiguous'], true);
        $availableAt = $now;
        if ($outcome->status === 'retry_wait') {
            $availableAt = (new DateTimeImmutable())
                ->add(new DateInterval('PT' . max(60, $outcome->retryAfterSeconds ?? 300) . 'S'))
                ->format('Y-m-d H:i:s');
        }

        Capsule::connection()->transaction(function () use (
            $item,
            $outcome,
            $availableAt,
            $terminal,
            $now,
            $jobId,
        ): void {
            // Use the same job-then-item lock order as cancel() and retry(). A
            // cancellation racing with finish() must either cancel a safe retry
            // or retain a risky side effect for manual reconciliation; it must
            // never leave an unclaimable retry_wait item behind.
            $job = Capsule::table(Migrator::JOBS_TABLE)
                ->where('id', $jobId)
                ->lockForUpdate()
                ->first();
            if ($job === null) {
                return;
            }

            // Checkpoints update candidate_json and remote IDs while the handler
            // still holds its original claim snapshot. Lock and merge against the
            // authoritative row so finish() cannot erase newer recovery context.
            $current = Capsule::table(Migrator::ITEMS_TABLE)
                ->where('id', $item->id)
                ->where('lease_token', $item->lease_token)
                ->lockForUpdate()
                ->first();
            if ($current === null) {
                return;
            }

            $candidateJson = $this->mergeCandidateJson(
                $current->candidate_json ?? null,
                $outcome->candidate,
            );
            $cancelRequested = $job->cancel_requested_at !== null;
            $resumePaidExport = !$cancelRequested
                && $this->shouldResumeAfterPaidTrigger($current, $outcome, $candidateJson);
            if ($resumePaidExport) {
                $candidate = self::decodeCandidate($candidateJson);
                $candidate['paidTriggerObserved'] = false;
                $candidate['paidTriggerConsumedAt'] = $now;
                $candidateJson = $this->encode($candidate);
            }

            $storedStatus = $resumePaidExport ? 'retry_wait' : $outcome->status;
            $storedCheckpoint = $resumePaidExport
                ? 'invoice_payment_pending'
                : $outcome->checkpoint;
            $storedAvailableAt = $resumePaidExport
                ? (new DateTimeImmutable())->add(new DateInterval('PT60S'))->format('Y-m-d H:i:s')
                : $availableAt;
            $storedTerminal = $resumePaidExport ? false : $terminal;
            $storedErrorCode = $resumePaidExport ? 'invoice_payment_event_followup' : $outcome->errorCode;
            $storedMessage = $resumePaidExport
                ? 'Das bezahlte WHMCS-Ereignis wurde während der Verarbeitung erkannt; der Invoice-Pfad wird erneut geprüft.'
                : mb_substr($outcome->message, 0, 4000);

            if ($cancelRequested && $storedStatus === 'retry_wait') {
                $riskyCheckpoint = self::isRiskyCheckpoint($storedCheckpoint);
                $storedStatus = $riskyCheckpoint ? 'ambiguous' : 'cancelled';
                $storedAvailableAt = $now;
                $storedTerminal = true;
                $storedErrorCode = $riskyCheckpoint
                    ? ($outcome->errorCode ?? 'cancelled_after_side_effect')
                    : 'cancelled_by_admin';
                $storedMessage = $riskyCheckpoint
                    ? 'Jobabbruch nach möglichem oder bestätigtem Remote-Effekt; der lokale Abschluss muss geprüft werden.'
                    : 'Job wurde durch einen Administrator abgebrochen; der sichere Wiederholungsversuch wurde verworfen.';
            }
            $retainReviewDedupe = !$cancelRequested
                && (string) ($current->action ?? '') === 'review_notice'
                && $storedStatus === 'permanent_failed'
                && $storedErrorCode === 'manual_review_required';
            $retainRiskDedupe = !$cancelRequested
                && $storedStatus === 'permanent_failed'
                && self::isRiskyCheckpoint($storedCheckpoint);

            Capsule::table(Migrator::ITEMS_TABLE)
                ->where('id', $current->id)
                ->where('lease_token', $item->lease_token)
                ->update([
                    'status' => $storedStatus,
                    'checkpoint' => $storedCheckpoint,
                    'available_at' => $storedAvailableAt,
                    'lease_token' => null,
                    'leased_until' => null,
                    'dedupe_key' => in_array($storedStatus, ['retry_wait', 'ambiguous'], true)
                        || $retainReviewDedupe
                        || $retainRiskDedupe
                        ? $current->dedupe_key
                        : null,
                    'sevdesk_id' => $outcome->sevdeskId ?? $current->sevdesk_id,
                    'candidate_json' => $candidateJson,
                    'http_status' => $outcome->httpStatus,
                    'exception_uuid' => $outcome->exceptionUuid,
                    'error_code' => $storedErrorCode,
                    'message' => $storedMessage,
                    'finished_at' => $storedTerminal ? $now : null,
                    'updated_at' => $now,
                ]);
        });

        $this->refreshJob($jobId);
    }

    public function cancel(int $jobId): void
    {
        $now = $this->now();
        Capsule::connection()->transaction(function () use ($jobId, $now): void {
            Capsule::table(Migrator::JOBS_TABLE)->where('id', $jobId)->update([
                'cancel_requested_at' => $now,
                'updated_at' => $now,
            ]);
            Capsule::table(Migrator::ITEMS_TABLE)
                ->where('job_id', $jobId)
                ->whereIn('status', ['pending', 'retry_wait'])
                ->update([
                    'status' => 'cancelled',
                    'dedupe_key' => null,
                    'message' => 'Job wurde durch einen Administrator abgebrochen.',
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
        });
        $this->refreshJob($jobId);
    }

    public function pause(int $jobId): void
    {
        Capsule::table(Migrator::JOBS_TABLE)->where('id', $jobId)->update([
            'status' => 'paused',
            'updated_at' => $this->now(),
        ]);
    }

    public function resume(int $jobId): bool
    {
        $job = Capsule::table(Migrator::JOBS_TABLE)->where('id', $jobId)->first();
        if ($job === null || (string) $job->status !== 'paused') {
            return false;
        }

        Capsule::table(Migrator::JOBS_TABLE)->where('id', $jobId)->update([
            'status' => 'pending',
            'updated_at' => $this->now(),
        ]);
        $this->refreshJob($jobId);

        return true;
    }

    public function retry(int $itemId): bool
    {
        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $itemId)->first();
        if (
            $item === null
            || (string) $item->status !== 'permanent_failed'
            || in_array((string) ($item->error_code ?? ''), [
                'booking_not_applied',
                'manual_review_required',
                'stale_export_context_requeue_required',
            ], true)
        ) {
            return false;
        }

        try {
            $updated = Capsule::connection()->transaction(function () use ($itemId, $item): int {
                if (!$this->lockRunnableJob((int) $item->job_id)) {
                    return 0;
                }

                $current = Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $itemId)
                    ->lockForUpdate()
                    ->first();
                if (
                    $current === null
                    || (int) $current->job_id !== (int) $item->job_id
                    || (string) $current->status !== 'permanent_failed'
                    || in_array((string) ($current->error_code ?? ''), [
                        'booking_not_applied',
                        'manual_review_required',
                        'stale_export_context_requeue_required',
                    ], true)
                ) {
                    return 0;
                }

                $businessReference = trim((string) ($current->transaction_reference ?? ''));
                $dedupe = trim((string) ($current->dedupe_key ?? ''));
                if ($dedupe === '') {
                    $exportAction = in_array((string) $current->action, [
                        'export_document',
                        'export_voucher',
                        'reconcile_voucher',
                    ], true);
                    if ($exportAction && $current->invoice_id !== null) {
                        // This historical key intentionally protects the shared
                        // WHMCS invoice identity across Voucher and Invoice writes.
                        $dedupe = 'export_voucher:' . $current->invoice_id;
                    } else {
                        $actionPrefix = (string) $current->action . ':';
                        $dedupe = $businessReference !== '' && str_starts_with($businessReference, $actionPrefix)
                            ? $businessReference
                            : $actionPrefix . ($businessReference !== ''
                                ? $businessReference
                                : ($current->invoice_id ?? $current->id));
                    }
                }

                return Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $itemId)
                    ->where('status', 'permanent_failed')
                    ->update([
                        'status' => 'pending',
                        'dedupe_key' => $dedupe,
                        'available_at' => $this->now(),
                        'finished_at' => null,
                        'message' => null,
                        'updated_at' => $this->now(),
                    ]);
            });
        } catch (QueryException $error) {
            if (!$this->isDuplicateKey($error)) {
                throw $error;
            }
            return false;
        }

        if ($updated !== 1) {
            return false;
        }

        $this->refreshJob((int) $item->job_id);

        return true;
    }

    /**
     * Create a fresh document job after an explicit mode-change review. The old
     * item is retained as evidence and none of its frozen target fields are
     * copied. Only checkpoints before a document write are eligible.
     *
     * @param array<string,scalar|null> $requestedContext
     */
    public function requeueExportDocument(
        int $itemId,
        array $requestedContext,
        ?int $adminId = null,
    ): ?int {
        $candidate = self::normaliseCurrentModeRequeueContext($requestedContext);
        if ($candidate === null) {
            return null;
        }
        $candidate['trigger'] = 'mode_change_requeue';
        $candidate['requeuedFromItemId'] = $itemId;
        $candidate['historicalBackfill'] = true;
        $candidate['delivery_requested'] = false;
        $candidate['requestedEInvoiceMode'] = 'off';

        return Capsule::connection()->transaction(function () use (
            $itemId,
            $candidate,
            $adminId,
        ): ?int {
            $old = Capsule::table(Migrator::ITEMS_TABLE)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();
            if (!self::safeForCurrentModeRequeue($old)) {
                return null;
            }

            $invoiceId = (int) ($old->invoice_id ?? 0);
            if (
                $invoiceId < 1
                || Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', $invoiceId)->exists()
                || Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('invoice_id', $invoiceId)
                    ->where('id', '<>', $itemId)
                    ->whereIn('action', ['export_document', 'export_voucher', 'reconcile_voucher'])
                    ->whereIn('status', ['pending', 'running', 'retry_wait', 'ambiguous'])
                    ->exists()
            ) {
                return null;
            }

            return $this->create('mode_change_requeue', [[
                'invoice_id' => $invoiceId,
                'action' => 'export_document',
                'dedupe_key' => 'export_voucher:' . $invoiceId,
                'candidate' => $candidate,
            ]], [
                'source_item_id' => $itemId,
                'mail_free' => true,
                'e_invoice' => false,
            ], $adminId);
        });
    }

    public function reconcile(int $itemId): bool
    {
        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $itemId)->first();
        if ($item === null || (string) $item->status !== 'ambiguous' || $item->invoice_id === null) {
            return false;
        }

        try {
            $updated = Capsule::connection()->transaction(function () use ($itemId, $item): int {
                if (!$this->lockRunnableJob((int) $item->job_id)) {
                    return 0;
                }

                $current = Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $itemId)
                    ->lockForUpdate()
                    ->first();
                if (
                    $current === null
                    || (int) $current->job_id !== (int) $item->job_id
                    || (string) $current->status !== 'ambiguous'
                    || $current->invoice_id === null
                ) {
                    return 0;
                }

                $target = $this->reconciliationTarget($current);
                if ($target === null) {
                    return 0;
                }

                return Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $itemId)
                    ->where('status', 'ambiguous')
                    ->update([
                        'action' => $target['action'],
                        'status' => 'pending',
                        'dedupe_key' => $target['dedupe'],
                        'available_at' => $this->now(),
                        'finished_at' => null,
                        'message' => null,
                        'updated_at' => $this->now(),
                    ]);
            });
        } catch (QueryException $error) {
            if (!$this->isDuplicateKey($error)) {
                throw $error;
            }
            return false;
        }
        if ($updated !== 1) {
            return false;
        }

        $this->refreshJob((int) $item->job_id);

        return true;
    }

    /**
     * Requeue an indeterminate WHMCS SendEmail hand-off only after the admin has
     * accepted the duplicate-delivery risk. The handler consumes this one-shot
     * flag before the next hand-off, so every later retry needs a new consent.
     */
    public function confirmEmailRetry(int $itemId): bool
    {
        $item = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $itemId)->first();
        if (
            $item === null
            || (string) $item->status !== 'ambiguous'
            || (string) $item->action !== 'export_document'
            || (string) $item->checkpoint !== 'whmcs_email_write_requested'
            || $item->invoice_id === null
        ) {
            return false;
        }

        try {
            $updated = Capsule::connection()->transaction(function () use ($itemId, $item): int {
                if (!$this->lockRunnableJob((int) $item->job_id)) {
                    return 0;
                }

                $current = Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $itemId)
                    ->lockForUpdate()
                    ->first();
                if (
                    $current === null
                    || (string) $current->status !== 'ambiguous'
                    || (string) $current->action !== 'export_document'
                    || (string) $current->checkpoint !== 'whmcs_email_write_requested'
                    || $current->invoice_id === null
                ) {
                    return 0;
                }

                $candidate = [];
                try {
                    $decoded = json_decode((string) ($current->candidate_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
                    $candidate = is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    return 0;
                }
                if (
                    (string) ($candidate['targetDocumentType'] ?? '') !== 'invoice'
                    || (string) ($candidate['targetDocumentAuthority'] ?? '') !== 'sevdesk'
                    || (string) ($candidate['targetDeliveryChannel'] ?? '') !== 'whmcs_template'
                ) {
                    return 0;
                }

                $candidate['emailRetryConfirmed'] = true;
                $candidate['emailRetryConfirmedAt'] = $this->now();
                $dedupe = trim((string) ($current->dedupe_key ?? ''));
                if ($dedupe === '') {
                    $dedupe = 'export_voucher:' . $current->invoice_id;
                }

                return Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $itemId)
                    ->where('status', 'ambiguous')
                    ->update([
                        'status' => 'pending',
                        'dedupe_key' => $dedupe,
                        'candidate_json' => $this->encode($candidate),
                        'available_at' => $this->now(),
                        'finished_at' => null,
                        'message' => null,
                        'updated_at' => $this->now(),
                    ]);
            });
        } catch (QueryException $error) {
            if (!$this->isDuplicateKey($error)) {
                throw $error;
            }

            return false;
        }
        if ($updated !== 1) {
            return false;
        }

        $this->refreshJob((int) $item->job_id);

        return true;
    }

    public function findItem(int $itemId): ?object
    {
        return Capsule::table(Migrator::ITEMS_TABLE)->where('id', $itemId)->first();
    }

    /** @return list<object> */
    public function reviewItems(?int $jobId = null, string $status = '', string $queryText = '', int $limit = 500): array
    {
        $query = Capsule::table(Migrator::ITEMS_TABLE . ' as item')
            ->leftJoin('tblinvoices as invoice', 'item.invoice_id', '=', 'invoice.id')
            ->whereIn('item.status', ['permanent_failed', 'ambiguous'])
            ->select(['item.*', 'invoice.invoicenum']);
        if ($jobId !== null && $jobId > 0) {
            $query->where('item.job_id', $jobId);
        }
        if (in_array($status, ['permanent_failed', 'ambiguous'], true)) {
            $query->where('item.status', $status);
        }
        $queryText = trim($queryText);
        if ($queryText !== '') {
            $query->where(static function ($query) use ($queryText): void {
                $query->where('invoice.invoicenum', 'like', '%' . $queryText . '%')
                    ->orWhere('item.invoice_id', $queryText)
                    ->orWhere('item.message', 'like', '%' . $queryText . '%')
                    ->orWhere('item.error_code', 'like', '%' . $queryText . '%');
            });
        }

        return $query->orderByDesc('item.updated_at')->limit(max(1, min(2000, $limit)))->get()->all();
    }

    public function findJob(int $jobId): ?object
    {
        $job = Capsule::table(Migrator::JOBS_TABLE)->where('id', $jobId)->first();
        if ($job === null) {
            return null;
        }

        return $this->decorateJob($job);
    }

    /** @return list<object> */
    public function items(int $jobId, ?string $status = null): array
    {
        $query = Capsule::table(Migrator::ITEMS_TABLE . ' as item')
            ->leftJoin('tblinvoices as invoice', 'item.invoice_id', '=', 'invoice.id')
            ->leftJoin(Migrator::MAPPING_TABLE . ' as mapping', 'item.invoice_id', '=', 'mapping.invoice_id')
            ->where('item.job_id', $jobId)
            ->select([
                'item.*', 'invoice.invoicenum', 'invoice.date', 'invoice.total',
                'invoice.status as invoice_status',
                'mapping.document_type as mapping_document_type',
                'mapping.document_number as mapping_document_number',
                'mapping.document_ready_at', 'mapping.delivered_at',
            ])
            ->orderBy('item.id');
        if ($status !== null && $status !== '') {
            $query->where('item.status', $status);
        }

        return $query->get()->all();
    }

    /** @return array{items:list<object>,total:int,page:int,pages:int} */
    public function paginatedItems(int $jobId, ?string $status, int $page, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(250, $perPage));
        $query = Capsule::table(Migrator::ITEMS_TABLE . ' as item')
            ->leftJoin('tblinvoices as invoice', 'item.invoice_id', '=', 'invoice.id')
            ->leftJoin(Migrator::MAPPING_TABLE . ' as mapping', 'item.invoice_id', '=', 'mapping.invoice_id')
            ->where('item.job_id', $jobId);
        if ($status !== null && $status !== '') {
            $query->where('item.status', $status);
        }
        $total = (clone $query)->count();
        $items = $query->select([
            'item.*', 'invoice.invoicenum', 'invoice.date', 'invoice.total',
            'invoice.status as invoice_status',
            'mapping.document_type as mapping_document_type',
            'mapping.document_number as mapping_document_number',
            'mapping.document_ready_at', 'mapping.delivered_at',
        ])->orderBy('item.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return list<object> */
    public function recent(int $limit = 25): array
    {
        return array_map(
            fn (object $job): object => $this->decorateJob($job),
            Capsule::table(Migrator::JOBS_TABLE)->orderByDesc('id')->limit($limit)->get()->all(),
        );
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        $counts = ['pending' => 0, 'running' => 0, 'failed' => 0, 'ambiguous' => 0];
        foreach (Capsule::table(Migrator::ITEMS_TABLE)->select('status', Capsule::raw('COUNT(*) AS aggregate'))->groupBy('status')->get() as $row) {
            $status = (string) $row->status;
            if ($status === 'permanent_failed') {
                $counts['failed'] += (int) $row->aggregate;
            } elseif ($status === 'retry_wait') {
                $counts['pending'] += (int) $row->aggregate;
            } elseif (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    public function hasRunnableItems(): bool
    {
        return Capsule::table(Migrator::ITEMS_TABLE . ' as item')
            ->join(Migrator::JOBS_TABLE . ' as job', 'item.job_id', '=', 'job.id')
            ->whereIn('item.status', ['pending', 'retry_wait'])
            ->where('item.available_at', '<=', $this->now())
            ->whereNull('job.cancel_requested_at')
            ->whereNotIn('job.status', ['paused', 'cancelled'])
            ->exists();
    }

    public static function isRiskyCheckpoint(string $checkpoint): bool
    {
        return in_array($checkpoint, self::riskyCheckpoints(), true);
    }

    /** @return list<string> */
    public static function riskyCheckpoints(): array
    {
        return array_values(array_unique(array_merge(
            self::UNKNOWN_WRITE_CHECKPOINTS,
            self::VERIFIED_SIDE_EFFECT_CHECKPOINTS,
        )));
    }

    public static function isWriteOutcomeUnknownCheckpoint(string $checkpoint): bool
    {
        return in_array($checkpoint, self::UNKNOWN_WRITE_CHECKPOINTS, true);
    }

    public static function isVerifiedSideEffectCheckpoint(string $checkpoint): bool
    {
        return in_array($checkpoint, self::VERIFIED_SIDE_EFFECT_CHECKPOINTS, true);
    }

    private function lockUnresolvedRiskyExportHistory(int $invoiceId): ?object
    {
        return Capsule::table(Migrator::ITEMS_TABLE)
            ->where('invoice_id', $invoiceId)
            ->whereIn('action', ['export_document', 'export_voucher', 'reconcile_voucher'])
            ->whereIn('status', ['permanent_failed', 'cancelled'])
            ->whereIn('checkpoint', self::riskyCheckpoints())
            ->lockForUpdate()
            ->first(['id']);
    }

    /**
     * Resolve only expired local leases; no handler or remote service is
     * invoked. This remains available while a replaced installation is in
     * quarantine so a crashed old worker cannot deadlock the inventory review.
     */
    public function recoverExpiredLeasesForSafety(?int $jobId = null): int
    {
        $cutoff = $this->now();
        $query = Capsule::table(Migrator::ITEMS_TABLE)
            ->where('status', 'running')
            ->whereNotNull('leased_until')
            ->where('leased_until', '<', $cutoff);
        if ($jobId !== null) {
            $query->where('job_id', $jobId);
        }
        $expired = $query->select(['id', 'job_id', 'lease_token', 'leased_until'])->get();
        $recovered = 0;
        $refreshJobIds = [];
        foreach ($expired as $snapshot) {
            $didRecover = Capsule::connection()->transaction(function () use ($snapshot, $cutoff): bool {
                // Keep the same job -> item order as finish(), cancel() and the
                // transactional claim. The initial scan is only a hint; both
                // the cancellation state and exact lease are re-read under lock.
                $job = Capsule::table(Migrator::JOBS_TABLE)
                    ->where('id', $snapshot->job_id)
                    ->lockForUpdate()
                    ->first();
                $current = Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $snapshot->id)
                    ->lockForUpdate()
                    ->first();
                if (
                    $current === null
                    || (string) $current->status !== 'running'
                    || (string) ($current->lease_token ?? '') !== (string) ($snapshot->lease_token ?? '')
                    || (string) ($current->leased_until ?? '') !== (string) ($snapshot->leased_until ?? '')
                    || trim((string) ($current->leased_until ?? '')) === ''
                    || (string) $current->leased_until >= $cutoff
                ) {
                    return false;
                }

                $writeOutcomeUnknown = self::isWriteOutcomeUnknownCheckpoint((string) $current->checkpoint);
                $verifiedSideEffect = self::isVerifiedSideEffectCheckpoint((string) $current->checkpoint);
                $cancelRequested = $job !== null
                    && ($job->cancel_requested_at !== null || (string) $job->status === 'cancelled');
                $jobMissing = $job === null;
                $sideEffectNeedsReview = $writeOutcomeUnknown || $verifiedSideEffect;

                $status = match (true) {
                    $jobMissing => 'ambiguous',
                    $cancelRequested && $sideEffectNeedsReview => 'ambiguous',
                    $cancelRequested => 'cancelled',
                    $writeOutcomeUnknown => 'ambiguous',
                    default => 'retry_wait',
                };
                $terminal = in_array($status, ['ambiguous', 'cancelled'], true);
                $message = match (true) {
                    $jobMissing => 'Worker-Lease gehört zu keinem vorhandenen Job; lokale Klärung erforderlich.',
                    $cancelRequested && $sideEffectNeedsReview =>
                        'Jobabbruch nach möglichem oder bestätigtem Remote-Effekt; lokaler Abschluss muss geprüft werden.',
                    $cancelRequested => 'Jobabbruch nach abgelaufener Worker-Lease abgeschlossen.',
                    $writeOutcomeUnknown =>
                        'Worker-Abbruch nach möglichem Remote-Schreibvorgang; Reconciliation erforderlich.',
                    default => 'Worker-Lease abgelaufen; Verarbeitung wird sicher wiederaufgenommen.',
                };

                return Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('id', $current->id)
                    ->where('status', 'running')
                    ->where('lease_token', $snapshot->lease_token)
                    ->where('leased_until', $snapshot->leased_until)
                    ->update([
                        'status' => $status,
                        'available_at' => $cutoff,
                        'dedupe_key' => $status === 'cancelled' ? null : $current->dedupe_key,
                        'lease_token' => null,
                        'leased_until' => null,
                        'message' => $message,
                        'finished_at' => $terminal ? $cutoff : null,
                        'updated_at' => $cutoff,
                    ]) === 1;
            });
            if (!$didRecover) {
                continue;
            }

            ++$recovered;
            $refreshJobIds[(int) $snapshot->job_id] = true;
        }
        foreach (array_keys($refreshJobIds) as $refreshJobId) {
            $this->refreshJob($refreshJobId);
        }

        return $recovered;
    }

    private function lockRunnableJob(int $jobId): bool
    {
        $job = Capsule::table(Migrator::JOBS_TABLE)
            ->where('id', $jobId)
            ->lockForUpdate()
            ->first();

        return $job !== null
            && $job->cancel_requested_at === null
            && (string) $job->status !== 'cancelled';
    }

    /** @return array{action:string,dedupe:string}|null */
    private function reconciliationTarget(object $item): ?array
    {
        $currentAction = (string) $item->action;
        if (
            !in_array($currentAction, [
                'export_voucher',
                'export_document',
                'reconcile_voucher',
                'correction_voucher',
                'book_payment',
            ], true)
        ) {
            return null;
        }

        $checkpoint = (string) ($item->checkpoint ?? '');
        if ($checkpoint === 'whmcs_email_write_requested') {
            // Unlike sevdesk objects, a WHMCS mail-provider hand-off has no
            // read-only reconciliation endpoint. It needs the dedicated,
            // warning-gated confirmEmailRetry() path above.
            return null;
        }
        if ($currentAction === 'correction_voucher') {
            $action = 'correction_voucher';
            $dedupe = (string) ($item->transaction_reference ?: 'correction:' . $item->id);
        } elseif ($currentAction === 'book_payment') {
            $action = 'book_payment';
            $dedupe = (string) ($item->transaction_reference ?: 'book_payment:' . $item->id);
        } elseif (
            $currentAction === 'export_document'
            || in_array($checkpoint, ['contact_write_requested', 'contact_linked'], true)
        ) {
            // Contact recovery must run the normal contact resolver. It searches
            // by WHMCS customer number before any new contact can be created. New
            // document jobs also own their Invoice read-only reconciliation.
            $action = $currentAction === 'export_document' ? 'export_document' : 'export_voucher';
            $dedupe = 'export_voucher:' . $item->invoice_id;
        } else {
            $action = 'reconcile_voucher';
            $dedupe = 'export_voucher:' . $item->invoice_id;
        }
        if (is_string($item->dedupe_key ?? null) && trim((string) $item->dedupe_key) !== '') {
            // Reconciliation changes the workflow, never the protected
            // accounting identity. Releasing it would allow a parallel write.
            $dedupe = (string) $item->dedupe_key;
        }

        return ['action' => $action, 'dedupe' => $dedupe];
    }

    private function refreshJob(int $jobId): void
    {
        $counts = [];
        foreach (Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $jobId)->select('status', Capsule::raw('COUNT(*) AS aggregate'))->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate;
        }

        $active = array_sum(array_intersect_key($counts, array_flip(['pending', 'running', 'retry_wait'])));
        $errors = array_sum(array_intersect_key($counts, array_flip(['permanent_failed', 'ambiguous'])));
        $job = Capsule::table(Migrator::JOBS_TABLE)->where('id', $jobId)->first();
        $status = $active > 0 ? (($counts['running'] ?? 0) > 0 ? 'running' : 'pending') : ($errors > 0 ? 'completed_with_errors' : 'completed');
        if ($job !== null && (string) $job->status === 'paused') {
            $status = 'paused';
        }
        if ($job !== null && $job->cancel_requested_at !== null && $active === 0) {
            $status = 'cancelled';
        }

        Capsule::table(Migrator::JOBS_TABLE)->where('id', $jobId)->update([
            'status' => $status,
            'finished_at' => $active === 0 && $status !== 'paused' ? $this->now() : null,
            'updated_at' => $this->now(),
        ]);
    }

    private function decorateJob(object $job): object
    {
        $counts = [];
        foreach (Capsule::table(Migrator::ITEMS_TABLE)->where('job_id', $job->id)->select('status', Capsule::raw('COUNT(*) AS aggregate'))->groupBy('status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->aggregate;
        }
        $job->succeeded_items = $counts['succeeded'] ?? 0;
        $job->skipped_items = $counts['skipped'] ?? 0;
        $job->failed_items = $counts['permanent_failed'] ?? 0;
        $job->ambiguous_items = $counts['ambiguous'] ?? 0;
        $job->pending_items = ($counts['pending'] ?? 0) + ($counts['retry_wait'] ?? 0);
        $job->running_items = $counts['running'] ?? 0;
        $job->processed_items = $job->succeeded_items + $job->skipped_items + $job->failed_items + $job->ambiguous_items + ($counts['cancelled'] ?? 0);
        $job->progress_percent = $job->total_items > 0 ? min(100, (int) floor($job->processed_items * 100 / $job->total_items)) : 100;

        return $job;
    }

    /**
     * @return null|array{
     *     itemId:int,
     *     itemStatus:string,
     *     checkpoint:string,
     *     source:'frozen'|'requested',
     *     allowed:?bool,
     *     documentType:?string,
     *     documentAuthority:string,
     *     exportMode:string,
     *     ossProfile:string,
     *     euB2cMode:string,
     *     deliveryChannel:?string,
     *     taxRuleId:?string,
     *     deliveryState:?string,
     *     sevUserId:?string,
     *     unityId:?string,
     *     isEInvoice:?bool,
     *     eInvoiceMode:string,
     *     eInvoiceContactId:?string,
     *     paymentMethodId:?string,
     *     eInvoiceCountryId:?string,
     *     addressHash:?string
     * }
     */
    public static function documentContextFromItem(object $item, bool $frozenOnly = false): ?array
    {
        if (self::isDedupeSkippedItem($item)) {
            return null;
        }

        $itemId = (int) ($item->id ?? 0);
        $status = trim((string) ($item->status ?? ''));
        $checkpoint = trim((string) ($item->checkpoint ?? ''));
        if (
            $itemId < 1
            || $checkpoint === ''
            || !in_array($status, [
                'pending', 'running', 'retry_wait', 'succeeded', 'skipped',
                'permanent_failed', 'ambiguous', 'cancelled',
            ], true)
        ) {
            return null;
        }

        try {
            $decoded = json_decode((string) ($item->candidate_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        $frozenKeys = [
            'targetAllowed', 'targetDocumentType', 'targetDocumentAuthority',
            'targetExportMode', 'targetOssProfile', 'targetEuB2cMode',
        ];
        $hasFrozenContext = array_intersect($frozenKeys, array_keys($decoded)) !== [];

        if ($hasFrozenContext) {
            $allowed = $decoded['targetAllowed'] ?? null;
            $documentType = $decoded['targetDocumentType'] ?? null;
            $authority = self::contextString($decoded, 'targetDocumentAuthority');
            $mode = self::contextString($decoded, 'targetExportMode');
            $ossProfile = self::contextString($decoded, 'targetOssProfile');
            $euB2cMode = self::contextString($decoded, 'targetEuB2cMode');
            $deliveryChannel = self::contextDeliveryChannel($decoded, 'targetDeliveryChannel');
            $taxRuleId = self::contextOptionalNumericId($decoded, 'targetTaxRuleId');
            $deliveryState = self::contextDeliveryState($decoded);
            $sevUserId = self::contextOptionalNumericId($decoded, 'targetSevUserId');
            $unityId = self::contextOptionalNumericId($decoded, 'targetUnityId');
            $isEInvoice = array_key_exists('targetIsEInvoice', $decoded)
                ? self::contextBool($decoded, 'targetIsEInvoice')
                : false;
            $eInvoiceMode = self::contextEInvoiceMode($decoded, 'targetEInvoiceMode');
            $eInvoiceContactId = self::contextOptionalNumericId($decoded, 'targetEInvoiceContactId');
            $paymentMethodId = self::contextOptionalNumericId($decoded, 'targetEInvoicePaymentMethodId');
            $eInvoiceUnityId = self::contextOptionalNumericId($decoded, 'targetEInvoiceUnityId');
            $eInvoiceCountryId = self::contextOptionalNumericId($decoded, 'targetEInvoiceCountryId');
            $addressHash = self::contextOptionalSha256($decoded, 'targetEInvoiceAddressHash');
            if (
                !is_bool($allowed)
                || ($documentType !== null && !is_string($documentType))
                || (array_key_exists('targetTaxRuleId', $decoded) && $taxRuleId === null && $decoded['targetTaxRuleId'] !== null)
                || (array_key_exists('targetSevUserId', $decoded) && $sevUserId === null && $decoded['targetSevUserId'] !== null)
                || (array_key_exists('targetUnityId', $decoded) && $unityId === null && $decoded['targetUnityId'] !== null)
                || $isEInvoice === null
                || (array_key_exists('targetEInvoiceMode', $decoded) && $eInvoiceMode === '')
                || (array_key_exists('targetEInvoiceContactId', $decoded)
                    && $decoded['targetEInvoiceContactId'] !== null && $eInvoiceContactId === null)
                || (array_key_exists('targetEInvoicePaymentMethodId', $decoded)
                    && $decoded['targetEInvoicePaymentMethodId'] !== null && $paymentMethodId === null)
                || (array_key_exists('targetEInvoiceUnityId', $decoded)
                    && $decoded['targetEInvoiceUnityId'] !== null && $eInvoiceUnityId === null)
                || (array_key_exists('targetEInvoiceCountryId', $decoded)
                    && $decoded['targetEInvoiceCountryId'] !== null && $eInvoiceCountryId === null)
                || (array_key_exists('targetEInvoiceAddressHash', $decoded)
                    && $decoded['targetEInvoiceAddressHash'] !== null && $addressHash === null)
                || (array_key_exists('deliveryState', $decoded) && $deliveryState === null)
                || ($eInvoiceMode === 'zugferd_domestic_b2b' && (
                    $authority !== DocumentTargetResolver::AUTHORITY_SEVDESK
                    || $mode !== DocumentTargetResolver::MODE_INVOICE_ONLY
                ))
                || ($isEInvoice && (
                    trim((string) $documentType) !== 'invoice'
                    || $authority !== DocumentTargetResolver::AUTHORITY_SEVDESK
                    || $mode !== DocumentTargetResolver::MODE_INVOICE_ONLY
                    || $eInvoiceMode !== 'zugferd_domestic_b2b'
                    || $eInvoiceContactId === null
                    || $paymentMethodId === null
                    || $unityId === null
                    || $eInvoiceUnityId === null
                    || $eInvoiceUnityId !== $unityId
                    || $eInvoiceCountryId === null
                    || $addressHash === null
                ))
                || !self::validDocumentContext(
                    $allowed,
                    is_string($documentType) ? trim($documentType) : null,
                    $authority,
                    $mode,
                    $ossProfile,
                    $euB2cMode,
                    $deliveryChannel,
                )
            ) {
                return null;
            }

            return [
                'itemId' => $itemId,
                'itemStatus' => $status,
                'checkpoint' => $checkpoint,
                'source' => 'frozen',
                'allowed' => $allowed,
                'documentType' => is_string($documentType) ? trim($documentType) : null,
                'documentAuthority' => $authority,
                'exportMode' => $mode,
                'ossProfile' => $ossProfile,
                'euB2cMode' => $euB2cMode,
                'deliveryChannel' => $deliveryChannel,
                'taxRuleId' => $taxRuleId,
                'deliveryState' => $deliveryState,
                'sevUserId' => $sevUserId,
                'unityId' => $unityId,
                'isEInvoice' => $isEInvoice,
                'eInvoiceMode' => $eInvoiceMode,
                'eInvoiceContactId' => $eInvoiceContactId,
                'paymentMethodId' => $paymentMethodId,
                'eInvoiceCountryId' => $eInvoiceCountryId,
                'addressHash' => $addressHash,
            ];
        }

        if ($frozenOnly) {
            return null;
        }

        $authority = self::contextString($decoded, 'requestedDocumentAuthority');
        $mode = self::contextString($decoded, 'requestedExportMode');
        $ossProfile = self::contextString($decoded, 'requestedOssProfile');
        $euB2cMode = self::contextString($decoded, 'requestedEuB2cMode');
        $deliveryChannel = self::contextDeliveryChannel($decoded, 'requestedDeliveryChannel');
        $eInvoiceMode = self::contextEInvoiceMode($decoded, 'requestedEInvoiceMode');
        $documentType = match ($mode) {
            'voucher_only' => 'voucher',
            'invoice_only' => 'invoice',
            default => null,
        };
        if (
            $eInvoiceMode === ''
            || ($eInvoiceMode === 'zugferd_domestic_b2b' && (
                $authority !== DocumentTargetResolver::AUTHORITY_SEVDESK
                || $mode !== DocumentTargetResolver::MODE_INVOICE_ONLY
            ))
            || !self::validRequestedContext(
                $documentType,
                $authority,
                $mode,
                $ossProfile,
                $euB2cMode,
                $deliveryChannel,
            )
        ) {
            return null;
        }

        return [
            'itemId' => $itemId,
            'itemStatus' => $status,
            'checkpoint' => $checkpoint,
            'source' => 'requested',
            'allowed' => null,
            'documentType' => $documentType,
            'documentAuthority' => $authority,
            'exportMode' => $mode,
            'ossProfile' => $ossProfile,
            'euB2cMode' => $euB2cMode,
            'deliveryChannel' => $deliveryChannel,
            'taxRuleId' => null,
            'deliveryState' => null,
            'sevUserId' => null,
            'unityId' => null,
            'isEInvoice' => null,
            'eInvoiceMode' => $eInvoiceMode,
            'eInvoiceContactId' => null,
            'paymentMethodId' => null,
            'eInvoiceCountryId' => null,
            'addressHash' => null,
        ];
    }

    private static function isDedupeSkippedItem(object $item): bool
    {
        return (string) ($item->status ?? '') === 'skipped'
            && (string) ($item->checkpoint ?? '') === 'queued'
            && (string) ($item->message ?? '') === self::DEDUPE_SKIPPED_MESSAGE;
    }

    /** @param array<mixed> $candidate */
    private static function contextString(array $candidate, string $key): string
    {
        return is_string($candidate[$key] ?? null) ? trim($candidate[$key]) : '';
    }

    /** @param array<mixed> $candidate */
    private static function contextDeliveryChannel(array $candidate, string $key): ?string
    {
        $value = $candidate[$key] ?? null;

        return is_string($value) ? trim($value) : null;
    }

    /** @param array<mixed> $candidate */
    private static function contextOptionalNumericId(array $candidate, string $key): ?string
    {
        $value = $candidate[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }

    /** @param array<mixed> $candidate */
    private static function contextBool(array $candidate, string $key): ?bool
    {
        $value = $candidate[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, '0'], true)) {
            return false;
        }
        if (in_array($value, [1, '1'], true)) {
            return true;
        }

        return null;
    }

    /** @param array<mixed> $candidate */
    private static function contextEInvoiceMode(array $candidate, string $key): string
    {
        if (!array_key_exists($key, $candidate)) {
            return 'off';
        }
        $value = is_string($candidate[$key]) ? trim($candidate[$key]) : '';

        return in_array($value, ['off', 'zugferd_domestic_b2b'], true) ? $value : '';
    }

    /** @param array<mixed> $candidate */
    private static function contextOptionalSha256(array $candidate, string $key): ?string
    {
        $value = is_string($candidate[$key] ?? null)
            ? strtolower(trim((string) $candidate[$key]))
            : '';

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    /** @param array<mixed> $candidate */
    private static function contextDeliveryState(array $candidate): ?string
    {
        if (!array_key_exists('deliveryState', $candidate)) {
            return null;
        }
        $value = is_string($candidate['deliveryState']) ? trim($candidate['deliveryState']) : '';

        return in_array($value, ['not_requested', 'ready_not_delivered', 'delivered', 'handed_off'], true)
            ? $value
            : null;
    }

    private static function validRequestedContext(
        ?string $documentType,
        string $authority,
        string $mode,
        string $ossProfile,
        string $euB2cMode,
        ?string $deliveryChannel,
    ): bool {
        if ($mode === DocumentTargetResolver::MODE_INVOICE_FOR_OSS) {
            $documentType = null;
        }

        return self::validContextValues($authority, $mode, $ossProfile, $euB2cMode, $deliveryChannel)
            && match ($mode) {
                DocumentTargetResolver::MODE_VOUCHER_ONLY =>
                    $documentType === 'voucher' && $authority === DocumentTargetResolver::AUTHORITY_WHMCS,
                DocumentTargetResolver::MODE_INVOICE_FOR_OSS =>
                    $documentType === null && $authority === DocumentTargetResolver::AUTHORITY_WHMCS,
                DocumentTargetResolver::MODE_INVOICE_ONLY => $documentType === 'invoice',
                default => false,
            };
    }

    private static function validDocumentContext(
        bool $allowed,
        ?string $documentType,
        string $authority,
        string $mode,
        string $ossProfile,
        string $euB2cMode,
        ?string $deliveryChannel,
    ): bool {
        if (!self::validContextValues($authority, $mode, $ossProfile, $euB2cMode, $deliveryChannel)) {
            return false;
        }
        if (!$allowed) {
            return $documentType === null;
        }
        if (!in_array($documentType, ['voucher', 'invoice'], true)) {
            return false;
        }

        if ($documentType === 'voucher') {
            return $authority === DocumentTargetResolver::AUTHORITY_WHMCS
                && $mode !== DocumentTargetResolver::MODE_INVOICE_ONLY;
        }

        return in_array(
            $mode,
            [DocumentTargetResolver::MODE_INVOICE_FOR_OSS, DocumentTargetResolver::MODE_INVOICE_ONLY],
            true,
        );
    }

    private static function validContextValues(
        string $authority,
        string $mode,
        string $ossProfile,
        string $euB2cMode,
        ?string $deliveryChannel,
    ): bool {
        return DocumentTargetResolver::contextValuesAreValid(
            $mode,
            $authority,
            $ossProfile,
            $euB2cMode,
            $deliveryChannel,
        );
    }

    /** @param array<mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, scalar|null>|null $newValues */
    private function mergeCandidateJson(mixed $currentJson, ?array $newValues): ?string
    {
        if ($newValues === null) {
            return is_string($currentJson) && $currentJson !== '' ? $currentJson : null;
        }

        $current = [];
        if (is_string($currentJson) && $currentJson !== '') {
            try {
                $decoded = json_decode($currentJson, true, 64, JSON_THROW_ON_ERROR);
                $current = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $current = [];
            }
        }

        return $this->encode(array_replace(
            $current,
            self::preserveFrozenXmlHash($current, $newValues),
        ));
    }

    /**
     * The first verified native XML hash is part of the immutable accounting
     * snapshot. A later mismatch may be recorded for diagnosis, but must never
     * replace the value against which read-only recovery is evaluated.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private static function preserveFrozenXmlHash(array $current, array $incoming): array
    {
        if (!array_key_exists('xmlSha256', $incoming)) {
            return $incoming;
        }

        $expected = self::candidateSha256($current['xmlSha256'] ?? null);
        if ($expected === null) {
            return $incoming;
        }

        $observed = self::candidateSha256($incoming['xmlSha256']);
        if ($observed === $expected) {
            return $incoming;
        }

        unset($incoming['xmlSha256']);
        if ($observed !== null) {
            $incoming['observedXmlSha256'] = $observed;
        }

        return $incoming;
    }

    private static function candidateSha256(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    /**
     * A paid hook that loses the cross-type dedupe race leaves a monotonic
     * signal on the active hybrid-mode owner. The owner still decides the
     * document type; this never copies or changes a frozen target.
     *
     * @param array<string,mixed>|null $incomingCandidate
     */
    private function markPaidTriggerOnActiveExport(
        ?string $dedupeKey,
        ?int $invoiceId,
        string $action,
        ?array $incomingCandidate,
        string $now,
    ): void {
        if (
            $dedupeKey === null
            || $dedupeKey === ''
            || $invoiceId === null
            || $invoiceId < 1
            || $action !== 'export_document'
            || ($incomingCandidate['trigger'] ?? null) !== 'InvoicePaid'
            || ($incomingCandidate['requestedExportMode'] ?? null) !== 'invoice_for_oss'
        ) {
            return;
        }

        $owner = Capsule::table(Migrator::ITEMS_TABLE)
            ->where('dedupe_key', $dedupeKey)
            ->where('invoice_id', $invoiceId)
            ->where('action', 'export_document')
            ->lockForUpdate()
            ->first();
        if ($owner === null) {
            return;
        }

        $candidate = self::decodeCandidate($owner->candidate_json ?? null);
        $ownerMode = $candidate['targetExportMode'] ?? $candidate['requestedExportMode'] ?? null;
        if ($ownerMode !== 'invoice_for_oss') {
            return;
        }
        $candidate['paidTriggerObserved'] = true;
        $candidate['paidTriggerObservedAt'] = $now;

        Capsule::table(Migrator::ITEMS_TABLE)
            ->where('id', $owner->id)
            ->where('dedupe_key', $dedupeKey)
            ->update([
                'candidate_json' => $this->encode($candidate),
                'updated_at' => $now,
            ]);
    }

    private function shouldResumeAfterPaidTrigger(
        object $current,
        JobOutcome $outcome,
        ?string $candidateJson,
    ): bool {
        if (
            $outcome->status !== 'skipped'
            || (string) ($current->action ?? '') !== 'export_document'
            || (string) ($current->checkpoint ?? '') !== 'invoice_payment_pending'
            || trim((string) ($current->dedupe_key ?? '')) === ''
        ) {
            return false;
        }

        $candidate = self::decodeCandidate($candidateJson);

        return ($candidate['requestedExportMode'] ?? null) === 'invoice_for_oss'
            && ($candidate['invoicePaymentPending'] ?? null) === true
            && ($candidate['paidTriggerObserved'] ?? null) === true;
    }

    private static function safeForCurrentModeRequeue(?object $item): bool
    {
        if (
            $item === null
            || (string) ($item->status ?? '') !== 'permanent_failed'
            || !in_array((string) ($item->action ?? ''), ['export_voucher', 'export_document'], true)
            || trim((string) ($item->sevdesk_id ?? '')) !== ''
        ) {
            return false;
        }

        return in_array((string) ($item->checkpoint ?? ''), [
            'queued',
            'finished',
            'invoice_payment_pending',
            'document_type_selected',
            'preflight_complete',
            'pdf_validated',
            'contact_linked',
            'e_invoice_target_selected',
        ], true);
    }

    /**
     * @param array<string,scalar|null> $context
     * @return null|array<string,scalar|null>
     */
    private static function normaliseCurrentModeRequeueContext(array $context): ?array
    {
        $mode = trim((string) ($context['requestedExportMode'] ?? ''));
        $authority = trim((string) ($context['requestedDocumentAuthority'] ?? ''));
        $ossProfile = trim((string) ($context['requestedOssProfile'] ?? ''));
        $euB2cMode = trim((string) ($context['requestedEuB2cMode'] ?? ''));
        $deliveryChannel = $authority === DocumentTargetResolver::AUTHORITY_SEVDESK
            ? trim((string) ($context['requestedDeliveryChannel'] ?? ''))
            : null;
        if (
            !DocumentTargetResolver::contextValuesAreValid(
                $mode,
                $authority,
                $ossProfile,
                $euB2cMode,
                $deliveryChannel,
            )
        ) {
            return null;
        }

        return [
            'requestedExportMode' => $mode,
            'requestedDocumentAuthority' => $authority,
            'requestedOssProfile' => $ossProfile,
            'requestedEuB2cMode' => $euB2cMode,
            'requestedDeliveryChannel' => $deliveryChannel,
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeCandidate(mixed $candidateJson): array
    {
        if (!is_string($candidateJson) || $candidateJson === '') {
            return [];
        }
        try {
            $candidate = json_decode($candidateJson, true, 64, JSON_THROW_ON_ERROR);

            return is_array($candidate) ? $candidate : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function isDuplicateKey(QueryException $error): bool
    {
        $driverCode = $error->errorInfo[1] ?? null;

        return (int) $driverCode === 1062 || str_contains($error->getMessage(), 'Duplicate entry');
    }
}
