<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Jobs;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;

final class JobRunner
{
    /** @param array<string, callable(object, callable(string, array<string, scalar|null>):bool):JobOutcome> $handlers */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly Config $config,
        private readonly array $handlers,
    ) {
    }

    /** @return array{processed:int,locked:bool,duration:float} */
    public function run(int $maxItems = 10, int $maxSeconds = 50): array
    {
        $started = microtime(true);
        if ($this->runtimeBlocked()) {
            return ['processed' => 0, 'locked' => false, 'duration' => 0.0];
        }
        if (!$this->acquireLock()) {
            return ['processed' => 0, 'locked' => true, 'duration' => 0.0];
        }

        $processed = 0;
        try {
            $this->config->set('runner_last_seen', (new \DateTimeImmutable())->format(DATE_ATOM));
            while ($processed < max(1, $maxItems) && microtime(true) - $started < max(5, $maxSeconds)) {
                // Activation or an upgrade can quarantine a process that was
                // already inside this loop. Re-read both durable runtime gates
                // before every claim so only the currently handled item may
                // finish before the shared advisory lock becomes available.
                if ($this->runtimeBlocked()) {
                    break;
                }
                // Authentication failures are account-wide. Once one handler
                // raises the alarm, no other job may consume attempts or reach
                // sevdesk until setup has verified and cleared the credentials.
                if ($this->authenticationAlarmActive()) {
                    break;
                }

                // The fresh setting rows and the job/item claim are serialized
                // in one database transaction. Quarantine, deactivation and a
                // tenant-wide authentication alarm therefore linearize either
                // before this claim (no handler starts) or after it (this is the
                // one already-started item that may finish).
                $item = $this->jobs->claimNext(
                    claimAllowed: fn (): bool => $this->config->runtimeAllowsClaimWhileLocked(),
                );
                if ($item === null) {
                    break;
                }

                $handler = $this->handlers[(string) $item->action] ?? null;
                if (!is_callable($handler)) {
                    $outcome = JobOutcome::permanentFailure('Für diese Jobaktion ist kein Handler registriert.', errorCode: 'handler_missing');
                } else {
                    try {
                        $checkpoint = function (string $value, array $context = []) use ($item): bool {
                            $stored = $this->jobs->checkpoint(
                                (int) $item->id,
                                (string) $item->lease_token,
                                $value,
                                $context,
                            );
                            if (!$stored) {
                                return false;
                            }

                            // Keep the claimed snapshot aligned with the durable
                            // checkpoint so Throwable classification cannot fall
                            // back to the stale pre-handler state.
                            $item->checkpoint = $value;
                            $remoteId = $context['remoteId'] ?? null;
                            if (is_scalar($remoteId) && preg_match('/^\d+$/', (string) $remoteId) === 1) {
                                $item->sevdesk_id = (string) $remoteId;
                            }

                            return true;
                        };
                        $outcome = $handler($item, $checkpoint);
                        if (!$outcome instanceof JobOutcome) {
                            throw new \UnexpectedValueException('A job handler must return JobOutcome.');
                        }
                    } catch (Throwable $error) {
                        $errorCode = 'unhandled_' . strtolower((new \ReflectionClass($error))->getShortName());
                        $checkpoint = (string) ($item->checkpoint ?? '');
                        $writeOutcomeUnknown = JobRepository::isWriteOutcomeUnknownCheckpoint($checkpoint);
                        $verifiedSideEffect = JobRepository::isVerifiedSideEffectCheckpoint($checkpoint);
                        if ($writeOutcomeUnknown) {
                            $outcome = JobOutcome::ambiguous(
                                'Nach einem möglichen Remote-Schreibvorgang trat ein interner Fehler auf. Vor einer Wiederholung ist ein Abgleich erforderlich.',
                                $checkpoint,
                                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                                errorCode: $errorCode,
                            );
                        } elseif ($verifiedSideEffect && (int) ($item->attempts ?? 0) < 4) {
                            $outcome = JobOutcome::retry(
                                'Nach einem bereits bestätigten Remote-Effekt trat beim lokalen Abschluss ein '
                                    . 'interner Fehler auf. Der sichere Fortsetzungsschritt wird begrenzt wiederholt.',
                                60,
                                errorCode: $errorCode,
                                checkpoint: $checkpoint,
                            );
                        } elseif ($verifiedSideEffect) {
                            $outcome = JobOutcome::ambiguous(
                                'Der lokale Abschluss nach einem bestätigten Remote-Effekt ist wiederholt '
                                    . 'fehlgeschlagen. Vor einer weiteren Fortsetzung ist ein manueller Abgleich '
                                    . 'erforderlich.',
                                $checkpoint,
                                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                                errorCode: $errorCode,
                            );
                        } else {
                            $outcome = JobOutcome::permanentFailure(
                                'Interner Fehler in einer Jobposition. Die übrigen Belege werden weiterverarbeitet.',
                                errorCode: $errorCode,
                            );
                        }
                        if (function_exists('logActivity')) {
                            logActivity('sevdesk job item ' . $item->id . ' failed with ' . get_class($error));
                        }
                    }
                }

                $this->jobs->finish($item, $outcome);
                if ($this->config->bool('debug_logging') && function_exists('logActivity')) {
                    $safeCode = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', (string) ($outcome->errorCode ?? 'none'));
                    logActivity(sprintf(
                        'sevdesk job debug: job=%d item=%d action=%s status=%s code=%s',
                        (int) $item->job_id,
                        (int) $item->id,
                        preg_replace('/[^A-Za-z0-9_.:-]+/', '_', (string) $item->action),
                        $outcome->status,
                        $safeCode,
                    ));
                }
                ++$processed;
            }
        } finally {
            $this->releaseLock();
        }

        return ['processed' => $processed, 'locked' => false, 'duration' => microtime(true) - $started];
    }

    private function authenticationAlarmActive(): bool
    {
        return trim((string) $this->config->get('health_alarm', '')) === 'api_authentication_failed';
    }

    /** @phpstan-impure Reads process-external settings before every claim. */
    private function runtimeBlocked(): bool
    {
        $this->config->refresh();

        return !$this->config->bool('module_active')
            || $this->config->bool(Config::RUNTIME_REVIEW_SETTING)
            || (string) $this->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE;
    }

    private function acquireLock(): bool
    {
        $result = Capsule::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['whmcs_sevdesk_job_runner']);

        return isset($result->acquired) && (int) $result->acquired === 1;
    }

    private function releaseLock(): void
    {
        Capsule::selectOne('SELECT RELEASE_LOCK(?) AS released', ['whmcs_sevdesk_job_runner']);
    }
}
