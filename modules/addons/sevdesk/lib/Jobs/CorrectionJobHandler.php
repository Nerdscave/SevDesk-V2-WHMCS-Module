<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Jobs;

use JsonException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

/** Creates one explicitly confirmed, marker-protected negative revenue voucher. */
final class CorrectionJobHandler
{
    private const READ_ONLY_RECOVERY_CHECKPOINTS = [
        'correction_write_requested',
        'correction_created',
        'correction_voucher_write_requested',
        'correction_voucher_created',
        'correction_mapping_persisted',
    ];

    /** @var \Closure(): TaxPolicy */
    private readonly \Closure $taxPolicy;

    /** @param callable(): TaxPolicy $taxPolicy */
    public function __construct(
        private readonly CorrectionService $corrections,
        private readonly WhmcsGateway $whmcs,
        private readonly MappingRepository $mappings,
        private readonly JobRepository $jobs,
        private readonly Config $config,
        callable $taxPolicy,
    ) {
        $this->taxPolicy = \Closure::fromCallable($taxPolicy);
    }

    /** @param callable(string, array<string, scalar|null>): bool $checkpoint */
    public function __invoke(object $item, callable $checkpoint): JobOutcome
    {
        $currentCheckpoint = (string) ($item->checkpoint ?? '');
        try {
            $candidate = json_decode((string) ($item->candidate_json ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::preflightFailure(
                $item,
                'Die gespeicherten Korrekturdaten sind ungültig.',
                'invalid_correction_candidate',
            );
        }
        if (!is_array($candidate) || !is_array($candidate['request'] ?? null) || !is_array($candidate['positions'] ?? null)) {
            return self::preflightFailure(
                $item,
                'Die gespeicherten Korrekturdaten sind unvollständig.',
                'missing_correction_candidate',
            );
        }

        $request = $candidate['request'];
        $invoiceId = (int) ($item->invoice_id ?? $request['invoiceId'] ?? 0);
        $accountId = (int) ($candidate['whmcsAccountId'] ?? 0);
        $transaction = $accountId > 0 ? Capsule::table('tblaccounts')->where('id', $accountId)->first() : null;
        if (
            $transaction === null
            || (int) $transaction->invoiceid !== $invoiceId
            || (float) $transaction->amountout <= 0
            || abs((float) $transaction->amountout - (float) ($request['refundAmount'] ?? 0)) > 0.005
            || !$this->whmcs->isVerifiedRefundTransaction($transaction)
        ) {
            return self::preflightFailure(
                $item,
                'Die zugrunde liegende WHMCS-Rückzahlung hat sich geändert oder wurde nicht gefunden.',
                'refund_transaction_changed',
            );
        }

        $mapping = $this->mappings->findCompleteByInvoice($invoiceId);
        if ($mapping === null) {
            return self::preflightFailure(
                $item,
                'Die Originalrechnung besitzt keine vollständige sevdesk-Zuordnung.',
                'original_mapping_missing',
            );
        }
        $documentType = strtolower(trim((string) ($mapping->document_type ?? '')));
        if ($documentType === 'invoice') {
            return self::preflightFailure(
                $item,
                'Invoice-Zuordnungen können nicht mit einem negativen Voucher korrigiert werden. '
                    . 'Dafür ist ein separat bestätigter CreditNote-Pfad erforderlich.',
                'invoice_correction_not_supported',
            );
        }
        if ($documentType !== 'voucher') {
            return self::preflightFailure(
                $item,
                'Der Dokumenttyp der Legacy-Zuordnung ist nicht bestätigt. Bitte zuerst im Recovery klären.',
                'correction_mapping_document_type_unknown',
            );
        }

        try {
            $invoice = $this->whmcs->invoiceSnapshot($invoiceId);
            $transactionCurrency = (string) Capsule::table('tblcurrencies')->where('id', (int) $transaction->currency)->value('code');
            if ($transactionCurrency !== '' && strtoupper($transactionCurrency) !== $invoice->currency) {
                return self::preflightFailure(
                    $item,
                    'Rückzahlung und Originalrechnung verwenden unterschiedliche Währungen.',
                    'refund_currency_mismatch',
                );
            }
            $contact = $this->whmcs->contactData($invoice->clientId);
            if ($contact->sevdeskContactId === null) {
                return self::preflightFailure(
                    $item,
                    'Die sevdesk-Kontakt-ID der Originalrechnung fehlt.',
                    'original_contact_missing',
                );
            }
            $positions = [];
            foreach ($candidate['positions'] as $position) {
                if (!is_array($position)) {
                    throw new \InvalidArgumentException('Invalid correction position.');
                }
                $positions[] = new LineItem(
                    (string) ($position['description'] ?? 'Refund correction'),
                    (string) ($position['amount'] ?? ''),
                    (string) ($position['taxRate'] ?? ''),
                    (bool) ($position['net'] ?? false),
                );
            }

            $tax = ($this->taxPolicy)()->decide(
                $contact->countryCode,
                $contact->taxExempt,
                $contact->vatNumber,
                $this->config->bool('smallBusinessOwner'),
                $this->whmcs->isAddFundsInvoice($invoiceId),
                $positions,
                $contact->isOrganisation(),
            );
            $trustedRequest = array_merge($request, [
                'kind' => 'refund',
                'documentType' => 'voucher',
                'invoiceId' => $invoiceId,
                'invoiceNumber' => $invoice->invoiceNumber,
                'originalVoucherId' => (string) $mapping->sevdesk_id,
                'contactId' => $contact->sevdeskContactId,
                'refundAmount' => (string) $transaction->amountout,
                'currency' => $invoice->currency,
                'voucherDate' => substr((string) $transaction->date, 0, 10),
            ]);
        } catch (ApiException $error) {
            if ($error->isAuthenticationFailure()) {
                $this->tripAuthenticationAlarm((int) $item->job_id);

                return JobOutcome::retry(
                    'sevdesk hat die Authentifizierung abgelehnt; der Job wurde pausiert.',
                    300,
                    $error->httpStatus,
                    $error->exceptionUuid,
                    'api_authentication_failed',
                    self::preflightResumeCheckpoint($currentCheckpoint),
                );
            }
            if ($error->httpStatus === 429 && (int) ($item->attempts ?? 1) < 10) {
                return JobOutcome::retry(
                    'Receipt Guidance ist rate-limited; die sichere Vorprüfung wird später wiederholt.',
                    max(60, min(21_600, $error->retryAfterSeconds ?? 300)),
                    429,
                    $error->exceptionUuid,
                    'correction_preflight_rate_limited',
                    self::preflightResumeCheckpoint($currentCheckpoint),
                );
            }
            if (
                ($error->httpStatus === null || $error->httpStatus === 408 || $error->httpStatus >= 500)
                && (int) ($item->attempts ?? 1) < 4
            ) {
                $delays = [300, 900, 3600];

                return JobOutcome::retry(
                    'Die read-only Korrekturvorprüfung ist vor dem Write fehlgeschlagen und wird wiederholt.',
                    $delays[min(max(1, (int) $item->attempts) - 1, 2)],
                    $error->httpStatus,
                    $error->exceptionUuid,
                    'correction_preflight_failed',
                    self::preflightResumeCheckpoint($currentCheckpoint),
                );
            }

            return self::preflightFailure(
                $item,
                'Die Korrektur-Vorprüfung wurde von sevdesk abgelehnt.',
                'correction_preflight_failed',
                $error->httpStatus,
                $error->exceptionUuid,
            );
        } catch (\Throwable $error) {
            return self::preflightFailure(
                $item,
                'Die Korrektur-Vorprüfung konnte nicht abgeschlossen werden.',
                'correction_preflight_failed',
            );
        }

        $persistCheckpoint = static function (string $name, array $context = []) use ($checkpoint, $item): bool {
            $stored = $checkpoint($name, $context);
            if ($stored) {
                $item->checkpoint = $name;
                if (isset($context['remoteId'])) {
                    $item->sevdesk_id = (string) $context['remoteId'];
                }
            }

            return $stored;
        };
        $result = $this->corrections->create(
            $trustedRequest,
            $tax,
            $positions,
            true,
            $persistCheckpoint,
            self::readOnlyRecoveryRequired((string) ($item->checkpoint ?? '')),
        );
        $status = (string) ($result['status'] ?? 'failed');
        $code = (string) ($result['code'] ?? 'correction_failed');
        $message = (string) ($result['message'] ?? 'Der Korrektur-Voucher konnte nicht erstellt werden.');
        $remoteId = isset($result['remoteId']) ? (string) $result['remoteId'] : null;
        $context = is_array($result['context'] ?? null) ? $result['context'] : [];

        if (in_array($status, ['succeeded', 'skipped'], true)) {
            return JobOutcome::succeeded(
                $status === 'skipped'
                    ? 'Ein vorhandener Korrektur-Voucher wurde eindeutig wieder zugeordnet.'
                    : 'Der bestätigte negative Umsatz-Voucher wurde erstellt.',
                $remoteId,
                ['resultCode' => $code],
            );
        }
        if ($status === 'ambiguous' || self::truthy($context['outcomeUnknown'] ?? false)) {
            return JobOutcome::ambiguous(
                'Der Ausgang des Korrektur-Voucher-Aufrufs ist unklar. Vor einem weiteren Versuch wird der Marker abgeglichen.',
                (string) ($item->checkpoint ?? 'correction_voucher_write_requested'),
                $remoteId,
                self::intOrNull($context['httpStatus'] ?? null),
                self::stringOrNull($context['exceptionUuid'] ?? null),
                $code,
                $context,
            );
        }

        $httpStatus = self::intOrNull($context['httpStatus'] ?? null);
        $definiteWriteRejected = self::truthy($context['definiteWriteRejected'] ?? false);
        $resumeCheckpoint = $definiteWriteRejected
            ? 'queued'
            : (string) ($item->checkpoint ?? 'queued');
        if (in_array($httpStatus, [401, 403], true)) {
            $this->tripAuthenticationAlarm((int) $item->job_id);

            return JobOutcome::retry(
                'sevdesk hat die Authentifizierung abgelehnt; der Job wurde pausiert.',
                300,
                $httpStatus,
                self::stringOrNull($context['exceptionUuid'] ?? null),
                'api_authentication_failed',
                $resumeCheckpoint,
            );
        }
        if ($httpStatus === 429 && (int) ($item->attempts ?? 1) < 10) {
            return JobOutcome::retry(
                'sevdesk begrenzt derzeit die Anfragen; die markerbasierte Vorprüfung wird später wiederholt.',
                max(60, min(21_600, self::intOrNull($context['retryAfterSeconds'] ?? null) ?? 300)),
                429,
                self::stringOrNull($context['exceptionUuid'] ?? null),
                $code,
                $resumeCheckpoint,
            );
        }
        $safeRetry = $httpStatus === 408
            || ($httpStatus !== null && $httpStatus >= 500)
            || in_array((string) ($context['sevdeskCode'] ?? ''), ['transport_error', 'http_client_error'], true);
        if ($status === 'failed' && $safeRetry && (int) ($item->attempts ?? 1) < 4) {
            $delays = [300, 900, 3600];

            return JobOutcome::retry(
                $message,
                $delays[min(max(1, (int) $item->attempts) - 1, 2)],
                $httpStatus,
                self::stringOrNull($context['exceptionUuid'] ?? null),
                $code,
                $resumeCheckpoint,
            );
        }

        if (!$definiteWriteRejected && JobRepository::isRiskyCheckpoint((string) ($item->checkpoint ?? ''))) {
            return JobOutcome::ambiguous(
                'Die read-only Korrektur-Recovery blieb ohne beweiskräftiges Ergebnis. '
                    . 'Ein zweiter Korrektur-Write bleibt gesperrt.',
                (string) $item->checkpoint,
                $remoteId,
                $httpStatus,
                self::stringOrNull($context['exceptionUuid'] ?? null),
                $code,
                $context,
            );
        }

        return JobOutcome::permanentFailure(
            $message,
            $httpStatus,
            self::stringOrNull($context['exceptionUuid'] ?? null),
            $code,
            $resumeCheckpoint,
        );
    }

    public static function readOnlyRecoveryRequired(string $checkpoint): bool
    {
        return in_array(
            $checkpoint,
            self::READ_ONLY_RECOVERY_CHECKPOINTS,
            true,
        );
    }

    private static function preflightResumeCheckpoint(string $checkpoint): string
    {
        return self::readOnlyRecoveryRequired($checkpoint) ? $checkpoint : 'finished';
    }

    private static function preflightFailure(
        object $item,
        string $message,
        string $errorCode,
        ?int $httpStatus = null,
        ?string $exceptionUuid = null,
    ): JobOutcome {
        $checkpoint = (string) ($item->checkpoint ?? '');
        if (!self::readOnlyRecoveryRequired($checkpoint)) {
            return JobOutcome::permanentFailure(
                $message,
                $httpStatus,
                $exceptionUuid,
                $errorCode,
            );
        }

        return JobOutcome::ambiguous(
            $message . ' Da bereits ein Korrektur-Write ungeklärt ist, bleibt ein weiterer Write gesperrt.',
            $checkpoint,
            self::stringOrNull($item->sevdesk_id ?? null),
            $httpStatus,
            $exceptionUuid,
            $errorCode,
            ['preflightFailure' => true],
        );
    }

    private function tripAuthenticationAlarm(int $jobId): void
    {
        $safety = $this->config->tripAuthenticationSafetyGates();
        self::logAuthenticationSafetyFailure($safety);
        if ($jobId < 1) {
            return;
        }
        try {
            $this->jobs->pause($jobId);
        } catch (\Throwable $error) {
            if (function_exists('logActivity')) {
                logActivity('sevdesk correction alarm could not pause the current job: ' . get_class($error));
            }
        }
    }

    /** @param array{alarm:bool,reviewFallback:bool,syncDisabled:bool} $safety */
    private static function logAuthenticationSafetyFailure(array $safety): void
    {
        if (!function_exists('logActivity')) {
            return;
        }
        if (!$safety['alarm']) {
            logActivity('sevdesk correction authentication alarm used runtime-review fallback.');
        }
        if (!$safety['alarm'] && !$safety['reviewFallback']) {
            logActivity('sevdesk correction authentication claim gates could not be persisted.');
        }
        if (!$safety['syncDisabled']) {
            logActivity('sevdesk correction authentication alarm could not disable enqueueing.');
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 128) : null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
