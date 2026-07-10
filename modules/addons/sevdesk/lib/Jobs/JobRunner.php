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
        if (!$this->acquireLock()) {
            return ['processed' => 0, 'locked' => true, 'duration' => 0.0];
        }

        $processed = 0;
        try {
            $this->config->set('runner_last_seen', (new \DateTimeImmutable())->format(DATE_ATOM));
            while ($processed < max(1, $maxItems) && microtime(true) - $started < max(5, $maxSeconds)) {
                // Authentication failures are account-wide. Once one handler
                // raises the alarm, no other job may consume attempts or reach
                // sevdesk until setup has verified and cleared the credentials.
                if ($this->authenticationAlarmActive()) {
                    break;
                }

                $item = $this->jobs->claimNext();
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
                        $risky = JobRepository::isRiskyCheckpoint($checkpoint);
                        $outcome = $risky
                            ? JobOutcome::ambiguous(
                                'Nach einem möglichen Remote-Schreibvorgang trat ein interner Fehler auf. Vor einer Wiederholung ist ein Abgleich erforderlich.',
                                $checkpoint,
                                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                                errorCode: $errorCode,
                            )
                            : JobOutcome::permanentFailure(
                                'Interner Fehler in einer Jobposition. Die übrigen Belege werden weiterverarbeitet.',
                                errorCode: $errorCode,
                            );
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
