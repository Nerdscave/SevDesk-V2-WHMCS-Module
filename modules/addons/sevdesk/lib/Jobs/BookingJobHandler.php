<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Jobs;

use JsonException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\BookingService;

/** Confirms exactly one previously previewed payment inside a leased job item. */
final class BookingJobHandler
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly JobRepository $jobs,
        private readonly Config $config,
        private readonly MappingRepository $mappings,
    ) {
    }

    /** @param callable(string, array<string, scalar|null>): bool $checkpoint */
    public function __invoke(object $item, callable $checkpoint): JobOutcome
    {
        try {
            $candidate = json_decode((string) ($item->candidate_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return JobOutcome::permanentFailure(
                'Die gespeicherte Buchungsvorschau ist ungültig.',
                errorCode: 'invalid_booking_candidate',
            );
        }
        if (!is_array($candidate)) {
            return JobOutcome::permanentFailure(
                'Die gespeicherte Buchungsvorschau fehlt.',
                errorCode: 'missing_booking_candidate',
            );
        }

        if (in_array((string) ($item->checkpoint ?? ''), ['booking_write_requested', 'booking_completed'], true)) {
            return $this->reconcile($candidate, $item);
        }

        $localFailure = $this->validateWhmcsPayment($candidate, $item);
        if ($localFailure !== null) {
            return $localFailure;
        }

        $mappingFailure = $this->validateCurrentMapping($candidate, $item);
        if ($mappingFailure !== null) {
            return $mappingFailure;
        }

        $persistCheckpoint = static function (string $name, array $context = []) use ($checkpoint, $item): bool {
            $stored = $checkpoint($name, $context);
            if ($stored) {
                $item->checkpoint = $name;
            }

            return $stored;
        };
        $result = $this->bookings->confirm($candidate, true, $persistCheckpoint);
        $status = (string) ($result['status'] ?? 'failed');
        $code = (string) ($result['code'] ?? 'booking_failed');
        $message = (string) ($result['message'] ?? 'Die Zahlung konnte nicht gebucht werden.');
        $context = is_array($result['context'] ?? null) ? $result['context'] : [];
        $voucherId = isset($candidate['voucherId']) ? (string) $candidate['voucherId'] : null;

        if ($status === 'succeeded') {
            return JobOutcome::succeeded('Die eindeutige sevdesk-Banktransaktion wurde gebucht.', $voucherId, [
                'resultCode' => $code,
                'transactionId' => (string) ($candidate['transactionId'] ?? ''),
            ]);
        }
        if ($status === 'ambiguous' || self::truthy($context['outcomeUnknown'] ?? false)) {
            return JobOutcome::ambiguous(
                'Der Ausgang des sevdesk-Buchungsaufrufs ist unklar. Bitte manuell prüfen.',
                (string) ($item->checkpoint ?? 'booking_write_requested'),
                $voucherId,
                self::intOrNull($context['httpStatus'] ?? null),
                self::stringOrNull($context['exceptionUuid'] ?? null),
                $code,
                $context,
            );
        }

        $httpStatus = self::intOrNull($context['httpStatus'] ?? null);
        if (in_array($httpStatus, [401, 403], true)) {
            $this->config->set('sync_enabled', '');
            $this->config->set('health_alarm', 'api_authentication_failed');
            $this->jobs->pause((int) $item->job_id);

            return JobOutcome::retry(
                'sevdesk hat die Authentifizierung abgelehnt; der Job wurde pausiert.',
                300,
                $httpStatus,
                self::stringOrNull($context['exceptionUuid'] ?? null),
                'api_authentication_failed',
            );
        }
        if ($httpStatus === 429 && (int) ($item->attempts ?? 1) < 10) {
            return JobOutcome::retry(
                'sevdesk begrenzt derzeit die Anfragen; die sichere Vorprüfung wird später wiederholt.',
                max(60, min(21_600, self::intOrNull($context['retryAfterSeconds'] ?? null) ?? 300)),
                429,
                self::stringOrNull($context['exceptionUuid'] ?? null),
                $code,
            );
        }
        $safeRetry = $httpStatus === 408
            || ($httpStatus !== null && $httpStatus >= 500)
            || in_array((string) ($context['sevdeskCode'] ?? ''), ['transport_error', 'http_client_error'], true);
        if ($status === 'failed' && $safeRetry && (int) ($item->attempts ?? 1) < 4) {
            $delays = [300, 900, 3600];

            return JobOutcome::retry(
                'Die Buchungsvorprüfung war vor dem Schreibvorgang nicht erreichbar und wird wiederholt.',
                $delays[min(max(1, (int) $item->attempts) - 1, 2)],
                $httpStatus,
                self::stringOrNull($context['exceptionUuid'] ?? null),
                $code,
            );
        }

        return JobOutcome::permanentFailure($message, $httpStatus, self::stringOrNull($context['exceptionUuid'] ?? null), $code);
    }

    /** @param array<string,mixed> $candidate */
    private function reconcile(array $candidate, object $item): JobOutcome
    {
        $result = $this->bookings->reconcile($candidate);
        $status = (string) ($result['status'] ?? 'ambiguous');
        $code = (string) ($result['code'] ?? 'booking_reconciliation_failed');
        $message = (string) ($result['message'] ?? 'Der Buchungsausgang konnte nicht eindeutig abgeglichen werden.');
        $context = is_array($result['context'] ?? null) ? $result['context'] : [];
        $voucherId = isset($candidate['voucherId']) ? (string) $candidate['voucherId'] : null;

        if ($status === 'succeeded') {
            return JobOutcome::succeeded('Die frühere Buchung wurde anhand des Remote-Zustands eindeutig bestätigt.', $voucherId, [
                'resultCode' => $code,
                'transactionId' => (string) ($candidate['transactionId'] ?? ''),
            ]);
        }
        if ($status === 'blocked' && $code === 'booking_not_applied') {
            return JobOutcome::permanentFailure(
                'Der frühere Aufruf wurde nachweislich nicht angewendet. Vor einem neuen Versuch ist eine neue Vorschau erforderlich.',
                errorCode: $code,
            );
        }

        $httpStatus = self::intOrNull($context['httpStatus'] ?? null);
        if (in_array($httpStatus, [401, 403], true)) {
            $this->config->set('sync_enabled', '');
            $this->config->set('health_alarm', 'api_authentication_failed');
            $this->jobs->pause((int) $item->job_id);
        }

        return JobOutcome::ambiguous(
            $message,
            (string) ($item->checkpoint ?? 'booking_write_requested'),
            $voucherId,
            $httpStatus,
            self::stringOrNull($context['exceptionUuid'] ?? null),
            $code,
            $context,
        );
    }

    /** @param array<string,mixed> $candidate */
    private function validateWhmcsPayment(array $candidate, object $item): ?JobOutcome
    {
        $accountId = (int) ($candidate['whmcsAccountId'] ?? 0);
        $invoiceId = (int) ($candidate['whmcsInvoiceId'] ?? 0);
        if ($accountId < 1 || $invoiceId < 1 || $invoiceId !== (int) ($item->invoice_id ?? 0)) {
            return JobOutcome::permanentFailure(
                'Die gespeicherte WHMCS-Zahlungsreferenz ist unvollständig.',
                errorCode: 'whmcs_payment_reference_missing',
            );
        }

        try {
            $account = Capsule::table('tblaccounts')->where('id', $accountId)->first();
            $invoice = Capsule::table('tblinvoices as invoice')
                ->leftJoin('tblclients as client', 'invoice.userid', '=', 'client.id')
                ->leftJoin('tblcurrencies as currency', 'client.currency', '=', 'currency.id')
                ->where('invoice.id', $invoiceId)
                ->first(['invoice.status', 'currency.code as currencycode']);
            $invoiceStatus = (string) ($invoice->status ?? '');
            $accountCurrency = $account === null
                ? ''
                : trim((string) Capsule::table('tblcurrencies')
                    ->where('id', (int) $account->currency)
                    ->value('code'));
            // WHMCS stores currency=0 for client-related transactions. In that
            // case (and for a stale currency ID), the invoice client's currency
            // is authoritative. A resolvable transaction currency still wins,
            // so an explicit mismatch remains blocked below.
            $currency = $accountCurrency !== ''
                ? $accountCurrency
                : trim((string) ($invoice->currencycode ?? ''));
            $hasRefund = $account !== null && Capsule::table('tblaccounts')
                ->where('refundid', $accountId)
                ->where('amountout', '>', 0)
                ->exists();
            $amountMatches = $account !== null
                && Decimal::toMinorUnits((string) $account->amountin)
                    === Decimal::toMinorUnits((string) ($candidate['amount'] ?? '0'));
        } catch (\Throwable) {
            return JobOutcome::permanentFailure(
                'Die WHMCS-Zahlung konnte vor der Buchung nicht sicher geprüft werden.',
                errorCode: 'whmcs_payment_revalidation_failed',
            );
        }

        if (
            $account === null
            || (int) $account->invoiceid !== $invoiceId
            || trim((string) $account->transid) !== trim((string) ($candidate['whmcsTransactionId'] ?? ''))
            || !$amountMatches
            || (float) $account->amountout > 0
            || (int) ($account->refundid ?? 0) > 0
            || $hasRefund
            || substr((string) $account->date, 0, 10) !== (string) ($candidate['bookingDate'] ?? '')
            || $currency === ''
            || strtoupper($currency) !== strtoupper((string) ($candidate['currency'] ?? ''))
            || !in_array($invoiceStatus, ['Paid', 'Unpaid'], true)
        ) {
            return JobOutcome::permanentFailure(
                'Die WHMCS-Zahlung hat sich seit der Vorschau geändert, wurde erstattet oder ist nicht mehr eindeutig.',
                errorCode: 'whmcs_payment_changed',
            );
        }

        return null;
    }

    /** @param array<string,mixed> $candidate */
    private function validateCurrentMapping(array $candidate, object $item): ?JobOutcome
    {
        $invoiceId = (int) ($candidate['whmcsInvoiceId'] ?? 0);
        $voucherId = trim((string) ($candidate['voucherId'] ?? ''));
        if ($invoiceId < 1 || $invoiceId !== (int) ($item->invoice_id ?? 0) || $voucherId === '') {
            return JobOutcome::permanentFailure(
                'Die gespeicherte WHMCS-zu-sevdesk-Zuordnung ist unvollständig.',
                errorCode: 'booking_mapping_reference_missing',
            );
        }

        try {
            $mapping = $this->mappings->findCompleteByInvoice($invoiceId);
        } catch (\Throwable) {
            return JobOutcome::permanentFailure(
                'Die aktuelle sevdesk-Zuordnung konnte vor der Buchung nicht sicher geprüft werden.',
                errorCode: 'booking_mapping_revalidation_failed',
            );
        }

        if ($mapping === null || trim((string) $mapping->sevdesk_id) !== $voucherId) {
            return JobOutcome::permanentFailure(
                'Die sevdesk-Zuordnung hat sich seit der Vorschau geändert. Bitte eine neue Vorschau erzeugen.',
                errorCode: 'booking_mapping_changed',
            );
        }

        return null;
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
