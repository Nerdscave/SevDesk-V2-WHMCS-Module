<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Repository;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;

final class JobRepository
{
    private const RISKY_CHECKPOINTS = [
        'contact_write_requested',
        'voucher_write_requested',
        'voucher_created',
        'booking_write_requested',
        'booking_completed',
        'correction_write_requested',
        'correction_created',
        'correction_voucher_write_requested',
        'correction_voucher_created',
    ];

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
                $dedupeKey = $item['dedupe_key'] ?? ($invoiceId === null ? null : $action . ':' . $invoiceId);
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

                try {
                    Capsule::table(Migrator::ITEMS_TABLE)->insert($row);
                } catch (QueryException $error) {
                    if (!$this->isDuplicateKey($error)) {
                        throw $error;
                    }
                    // An active item already owns this accounting action. Keep a
                    // visible terminal row in this job without stealing its lock.
                    $row['status'] = 'skipped';
                    $row['dedupe_key'] = null;
                    $row['message'] = 'Die Rechnung ist bereits in einem anderen aktiven Job eingeplant.';
                    $row['finished_at'] = $now;
                    Capsule::table(Migrator::ITEMS_TABLE)->insert($row);
                }
            }

            $this->refreshJob($jobId);

            return $jobId;
        });
    }

    public function claimNext(int $leaseSeconds = 300): ?object
    {
        $this->recoverExpiredLeases();
        $now = $this->now();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
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

            $token = bin2hex(random_bytes(16));
            $leasedUntil = (new DateTimeImmutable())->add(new DateInterval('PT' . $leaseSeconds . 'S'))->format('Y-m-d H:i:s');
            $updated = Capsule::table(Migrator::ITEMS_TABLE)
                ->where('id', $candidate->id)
                ->whereIn('status', ['pending', 'retry_wait'])
                ->update([
                    'status' => 'running',
                    'lease_token' => $token,
                    'leased_until' => $leasedUntil,
                    'attempts' => Capsule::raw('attempts + 1'),
                    'started_at' => $candidate->started_at ?? $now,
                    'updated_at' => $now,
                ]);

            if ($updated === 1) {
                Capsule::table(Migrator::JOBS_TABLE)
                    ->where('id', $candidate->job_id)
                    ->whereNull('started_at')
                    ->update(['started_at' => $now]);
                Capsule::table(Migrator::JOBS_TABLE)->where('id', $candidate->job_id)->update([
                    'status' => 'running',
                    'updated_at' => $now,
                ]);

                return Capsule::table(Migrator::ITEMS_TABLE)->where('id', $candidate->id)->first();
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
        ): void {
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

            Capsule::table(Migrator::ITEMS_TABLE)
                ->where('id', $current->id)
                ->where('lease_token', $item->lease_token)
                ->update([
                    'status' => $outcome->status,
                    'checkpoint' => $outcome->checkpoint,
                    'available_at' => $availableAt,
                    'lease_token' => null,
                    'leased_until' => null,
                    'dedupe_key' => in_array($outcome->status, ['retry_wait', 'ambiguous'], true)
                        ? $current->dedupe_key
                        : null,
                    'sevdesk_id' => $outcome->sevdeskId ?? $current->sevdesk_id,
                    'candidate_json' => $this->mergeCandidateJson(
                        $current->candidate_json ?? null,
                        $outcome->candidate,
                    ),
                    'http_status' => $outcome->httpStatus,
                    'exception_uuid' => $outcome->exceptionUuid,
                    'error_code' => $outcome->errorCode,
                    'message' => mb_substr($outcome->message, 0, 4000),
                    'finished_at' => $terminal ? $now : null,
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
        if ($item === null || (string) $item->status !== 'permanent_failed') {
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
                ) {
                    return 0;
                }

                $businessReference = trim((string) ($current->transaction_reference ?? ''));
                $dedupe = trim((string) ($current->dedupe_key ?? ''));
                if ($dedupe === '') {
                    $actionPrefix = (string) $current->action . ':';
                    $dedupe = $businessReference !== '' && str_starts_with($businessReference, $actionPrefix)
                        ? $businessReference
                        : $actionPrefix . ($businessReference !== ''
                            ? $businessReference
                            : ($current->invoice_id ?? $current->id));
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
            ->where('item.job_id', $jobId)
            ->select(['item.*', 'invoice.invoicenum', 'invoice.date', 'invoice.total', 'invoice.status as invoice_status'])
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
            ->where('item.job_id', $jobId);
        if ($status !== null && $status !== '') {
            $query->where('item.status', $status);
        }
        $total = (clone $query)->count();
        $items = $query->select([
            'item.*', 'invoice.invoicenum', 'invoice.date', 'invoice.total',
            'invoice.status as invoice_status',
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
        return in_array($checkpoint, self::RISKY_CHECKPOINTS, true);
    }

    private function recoverExpiredLeases(): void
    {
        $expired = Capsule::table(Migrator::ITEMS_TABLE)
            ->where('status', 'running')
            ->whereNotNull('leased_until')
            ->where('leased_until', '<', $this->now())
            ->get();
        foreach ($expired as $item) {
            $job = Capsule::table(Migrator::JOBS_TABLE)->where('id', $item->job_id)->first();
            $risky = self::isRiskyCheckpoint((string) $item->checkpoint);
            if ($job !== null && $job->cancel_requested_at !== null && !$risky) {
                Capsule::table(Migrator::ITEMS_TABLE)->where('id', $item->id)->update([
                    'status' => 'cancelled',
                    'dedupe_key' => null,
                    'lease_token' => null,
                    'leased_until' => null,
                    'message' => 'Jobabbruch nach abgelaufener Worker-Lease abgeschlossen.',
                    'finished_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $this->refreshJob((int) $item->job_id);
                continue;
            }
            Capsule::table(Migrator::ITEMS_TABLE)->where('id', $item->id)->update([
                'status' => $risky ? 'ambiguous' : 'retry_wait',
                'available_at' => $this->now(),
                'lease_token' => null,
                'leased_until' => null,
                'message' => $risky
                    ? 'Worker-Abbruch nach möglichem Remote-Schreibvorgang; Reconciliation erforderlich.'
                    : 'Worker-Lease abgelaufen; Verarbeitung wird sicher wiederaufgenommen.',
                'finished_at' => $risky ? $this->now() : null,
                'updated_at' => $this->now(),
            ]);
            $this->refreshJob((int) $item->job_id);
        }
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
                'reconcile_voucher',
                'correction_voucher',
                'book_payment',
            ], true)
        ) {
            return null;
        }

        $checkpoint = (string) ($item->checkpoint ?? '');
        if ($currentAction === 'correction_voucher') {
            $action = 'correction_voucher';
            $dedupe = (string) ($item->transaction_reference ?: 'correction:' . $item->id);
        } elseif ($currentAction === 'book_payment') {
            $action = 'book_payment';
            $dedupe = (string) ($item->transaction_reference ?: 'book_payment:' . $item->id);
        } elseif (in_array($checkpoint, ['contact_write_requested', 'contact_linked'], true)) {
            // Contact recovery must run the normal contact resolver. It searches
            // by WHMCS customer number before any new contact can be created.
            $action = 'export_voucher';
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

        return $this->encode(array_replace($current, $newValues));
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
