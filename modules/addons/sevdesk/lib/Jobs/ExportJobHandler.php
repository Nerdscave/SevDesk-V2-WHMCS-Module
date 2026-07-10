<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Jobs;

use DateTimeImmutable;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\ContactResolution;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\ContactService;
use WHMCS\Module\Addon\SevDesk\Service\PdfRenderer;
use WHMCS\Module\Addon\SevDesk\Service\ReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

/** Executes one isolated invoice export item. */
final class ExportJobHandler
{
    /** @var \Closure(): TaxPolicy */
    private readonly \Closure $taxPolicy;

    /** @param callable(): TaxPolicy $taxPolicy */
    public function __construct(
        private readonly Config $config,
        private readonly WhmcsGateway $whmcs,
        private readonly MappingRepository $mappings,
        private readonly JobRepository $jobs,
        private readonly ContactService $contacts,
        private readonly PdfRenderer $pdf,
        private readonly VoucherExporter $exporter,
        private readonly ReconciliationService $reconciliation,
        callable $taxPolicy,
    ) {
        $this->taxPolicy = \Closure::fromCallable($taxPolicy);
    }

    /** @param callable(string, array<string, scalar|null>): bool $checkpoint */
    public function __invoke(object $item, callable $checkpoint): JobOutcome
    {
        $contactRecoveryOnly = self::contactRecoveryRequired((string) ($item->checkpoint ?? ''));
        $persistCheckpoint = static function (string $name, array $context = []) use ($checkpoint, $item): bool {
            $stored = $checkpoint($name, $context);
            if ($stored) {
                $item->checkpoint = $name;
                if (isset($context['remoteId']) && preg_match('/^\d+$/', (string) $context['remoteId']) === 1) {
                    $item->sevdesk_id = (string) $context['remoteId'];
                }
            }

            return $stored;
        };
        $invoiceId = (int) ($item->invoice_id ?? 0);
        if ($invoiceId < 1) {
            return JobOutcome::permanentFailure(
                'Die Jobposition enthält keine gültige WHMCS-Rechnungs-ID.',
                errorCode: 'invalid_invoice_id',
            );
        }

        $mapping = $this->mappings->findByInvoice($invoiceId);
        if ($mapping !== null && $mapping->sevdesk_id !== null && trim((string) $mapping->sevdesk_id) !== '') {
            return JobOutcome::skipped(
                'Die Rechnung ist bereits mit einem sevdesk-Beleg verknüpft.',
                (string) $mapping->sevdesk_id,
            );
        }
        if ($mapping !== null && (string) ($item->action ?? '') !== 'reconcile_voucher') {
            return JobOutcome::ambiguous(
                'Für diese Rechnung existiert eine alte NULL-Zuordnung. Vor einem Export ist eine Reconciliation erforderlich.',
                'ambiguous_legacy',
                errorCode: 'ambiguous_legacy',
            );
        }

        try {
            $reconciliationRequired = $this->requiresReconciliation($item);
            $rawInvoice = $this->whmcs->invoice($invoiceId);
            $status = (string) ($rawInvoice['status'] ?? '');
            $onlyPaid = $this->config->bool('import_only_paid', true);
            if (!$reconciliationRequired && !self::statusIsExportable($status, $onlyPaid)) {
                return JobOutcome::skipped(
                    $onlyPaid
                        ? 'Nach der aktuellen Einstellung werden nur bezahlte Rechnungen exportiert.'
                        : 'Nur bezahlte oder veröffentlichte offene Rechnungen werden exportiert.',
                );
            }
            if (!$this->isAfterConfiguredStart((string) ($rawInvoice['date'] ?? ''))) {
                return JobOutcome::skipped('Die Rechnung liegt vor dem konfigurierten Exportstichtag.');
            }

            $invoice = $this->whmcs->invoiceSnapshot($invoiceId);
            if (!$reconciliationRequired && $invoice->currency !== 'EUR') {
                return JobOutcome::permanentFailure(
                    'Fremdwährungsrechnungen benötigen bis zur separaten Freigabe eine manuelle Prüfung.',
                    errorCode: 'foreign_currency_requires_review',
                );
            }
            $contact = $this->whmcs->contactData($invoice->clientId);

            if ($reconciliationRequired) {
                $result = $this->reconciliation->reconcile(
                    $invoice,
                    $contact->sevdeskContactId,
                    isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                );

                return $this->toOutcome($result, $item, 'reconciled');
            }

            if (!$contactRecoveryOnly) {
                $persistCheckpoint('preflight_complete', ['invoiceId' => $invoiceId]);
            }
            $policy = ($this->taxPolicy)();
            $tax = $policy->decide(
                $contact->countryCode,
                $contact->taxExempt,
                $contact->vatNumber,
                $this->config->bool('smallBusinessOwner'),
                $this->whmcs->isAddFundsInvoice($invoiceId),
                $invoice->lineItems,
                $contact->isOrganisation(),
            );
            if (!$tax->allowed) {
                return JobOutcome::permanentFailure(
                    self::messageFor($tax->code, $tax->message),
                    errorCode: $tax->code,
                );
            }

            // Validate the local document before a contact can be created.
            $pdfContents = $this->pdf->render($invoiceId);
            if (!$contactRecoveryOnly) {
                $persistCheckpoint('pdf_validated', ['invoiceId' => $invoiceId]);
            }

            $contactResult = $this->contacts->resolve($contact, $persistCheckpoint, $contactRecoveryOnly);
            if ($contactResult->isFailure()) {
                return $this->failureResultToOutcome(
                    $contactResult->errorCode() ?? 'contact_failed',
                    $contactResult->errorMessage() ?? 'Der sevdesk-Kontakt konnte nicht aufgelöst werden.',
                    $contactResult->context(),
                    $item,
                );
            }

            $resolution = $contactResult->value();
            if (!$resolution instanceof ContactResolution) {
                return JobOutcome::permanentFailure(
                    'Die Kontaktauflösung lieferte ein ungültiges Ergebnis.',
                    errorCode: 'invalid_contact_resolution',
                );
            }

            $result = $this->exporter->export(
                $invoice,
                $resolution->contactId,
                $tax,
                $pdfContents,
                $persistCheckpoint,
                $this->creditTreatmentConfirmed($item),
            );

            return $this->toOutcome($result, $item, 'exported', [
                'contactSource' => $resolution->source,
                'warnings' => implode(',', $resolution->warnings),
            ]);
        } catch (ApiException $exception) {
            return $this->failureResultToOutcome(
                $exception->isAuthenticationFailure() ? 'api_authentication_failed' : 'api_request_failed',
                $exception->getMessage(),
                $exception->context(),
                $item,
            );
        } catch (Throwable $exception) {
            $checkpointName = (string) ($item->checkpoint ?? '');
            if (
                in_array($checkpointName, [
                'contact_write_requested',
                'voucher_write_requested',
                'voucher_created',
                'mapping_persisted',
                ], true)
            ) {
                return JobOutcome::ambiguous(
                    'Nach einem möglichen Remote-Schreibvorgang ist die lokale Verarbeitung fehlgeschlagen. Bitte zuerst abgleichen.',
                    $checkpointName,
                    isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                    errorCode: 'local_post_write_failed',
                );
            }

            return JobOutcome::permanentFailure(
                self::messageFor('local_preflight_failed', $exception->getMessage()),
                errorCode: 'local_preflight_failed',
            );
        }
    }

    public static function statusIsExportable(string $status, bool $onlyPaid): bool
    {
        return $status === 'Paid' || (!$onlyPaid && $status === 'Unpaid');
    }

    public static function contactRecoveryRequired(string $checkpoint): bool
    {
        return in_array($checkpoint, ['contact_write_requested', 'contact_linked'], true);
    }

    /** @param array<string, scalar|null> $extra */
    private function toOutcome(ExportResult $result, object $item, string $successCode, array $extra = []): JobOutcome
    {
        if ($result->status === ExportResult::SUCCEEDED) {
            return JobOutcome::succeeded(
                $successCode === 'reconciled'
                    ? 'Die lokale Zuordnung wurde anhand des eindeutigen sevdesk-Belegs repariert.'
                    : 'Die Rechnung wurde erfolgreich an sevdesk übertragen.',
                $result->remoteId,
                array_filter(array_merge($extra, ['resultCode' => $result->code]), static fn (mixed $value): bool => $value !== ''),
            );
        }
        if ($result->status === ExportResult::SKIPPED) {
            return JobOutcome::skipped('Die Rechnung war bereits zugeordnet.', $result->remoteId);
        }
        if ($result->status === ExportResult::AMBIGUOUS) {
            return JobOutcome::ambiguous(
                self::messageFor($result->code, $result->message),
                (string) ($item->checkpoint ?? 'write_requested'),
                $result->remoteId,
                self::nullableInt($result->context['httpStatus'] ?? null),
                self::nullableString($result->context['exceptionUuid'] ?? null),
                $result->code,
                $result->context,
            );
        }

        return $this->failureResultToOutcome($result->code, $result->message, $result->context, $item);
    }

    /** @param array<string, scalar|null> $context */
    private function failureResultToOutcome(string $code, string $message, array $context, object $item): JobOutcome
    {
        $httpStatus = self::nullableInt($context['httpStatus'] ?? null);
        $uuid = self::nullableString($context['exceptionUuid'] ?? null);
        $attempts = max(1, (int) ($item->attempts ?? 1));

        if (self::truthy($context['ambiguous'] ?? false) || str_ends_with($code, '_ambiguous')) {
            return JobOutcome::ambiguous(
                self::messageFor($code, $message),
                (string) ($item->checkpoint ?? 'write_requested'),
                null,
                $httpStatus,
                $uuid,
                $code,
                $context,
            );
        }

        if (in_array($httpStatus, [401, 403], true) || $code === 'api_authentication_failed') {
            $this->config->set('sync_enabled', '');
            $this->config->set('health_alarm', 'api_authentication_failed');
            $this->jobs->pause((int) $item->job_id);

            return JobOutcome::retry(
                'sevdesk hat die Authentifizierung abgelehnt. Der Job und die automatische Synchronisation wurden pausiert.',
                300,
                $httpStatus,
                $uuid,
                'api_authentication_failed',
            );
        }

        if ($httpStatus === 429 && $attempts < 10) {
            $delay = max(60, min(21_600, self::nullableInt($context['retryAfterSeconds'] ?? null) ?? 300));

            return JobOutcome::retry(
                'sevdesk begrenzt derzeit die Anfragen. Der Beleg wird später erneut versucht.',
                $delay,
                429,
                $uuid,
                $code,
            );
        }

        $safeRetry = $httpStatus === 408
            || ($httpStatus !== null && $httpStatus >= 500)
            || in_array(self::nullableString($context['sevdeskCode'] ?? null), ['transport_error', 'http_client_error'], true)
            || in_array($code, ['api_request_failed', 'contact_search_failed', 'contact_verification_failed'], true);
        if ($safeRetry && $attempts < 4) {
            $delays = [300, 900, 3600];

            return JobOutcome::retry(
                'Die sichere Vorstufe konnte nicht abgeschlossen werden. Ein automatischer Wiederholungsversuch wurde eingeplant.',
                $delays[min($attempts - 1, 2)],
                $httpStatus,
                $uuid,
                $code,
            );
        }

        return JobOutcome::permanentFailure(
            self::messageFor($code, $message),
            $httpStatus,
            $uuid,
            $code,
        );
    }

    private function requiresReconciliation(object $item): bool
    {
        return (isset($item->sevdesk_id) && trim((string) $item->sevdesk_id) !== '')
            || in_array((string) ($item->checkpoint ?? ''), [
                'voucher_write_requested',
                'voucher_created',
                'mapping_persisted',
            ], true)
            || (string) ($item->action ?? '') === 'reconcile_voucher';
    }

    private function creditTreatmentConfirmed(object $item): bool
    {
        try {
            $candidate = json_decode((string) ($item->candidate_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($candidate)
            && ($candidate['credit_treatment'] ?? null) === 'full_gross_voucher';
    }

    private function isAfterConfiguredStart(string $invoiceDate): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $invoiceDate);
        if (!$date instanceof DateTimeImmutable) {
            return false;
        }

        $configured = (string) $this->config->get('import_after', '01-01-1999');
        $start = DateTimeImmutable::createFromFormat('!d-m-Y', $configured)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d', $configured);

        return !$start instanceof DateTimeImmutable || $date >= $start;
    }

    private static function messageFor(string $code, string $fallback): string
    {
        return [
            'unsupported_oss' => 'EU-B2C kann als sevdesk-Voucher nicht mit OSS-Steuerregeln exportiert werden.',
            'missing_vat_id' => 'Für EU-B2B fehlen Steuerbefreiung oder USt-ID.',
            'eu_b2b_organisation_required' => 'Für EU-B2B fehlt eine in WHMCS hinterlegte Organisation.',
            'unconfirmed_tax_profile' => 'Das benötigte Steuerprofil wurde noch nicht ausdrücklich bestätigt.',
            'eu_b2b_tax_rate_mismatch' => 'Die steuerbefreite EU-B2B-Rechnung enthält dennoch einen positiven USt-Satz.',
            'unsupported_domestic_tax_exempt' => 'Für einen steuerbefreiten deutschen Kunden fehlt ein ausdrücklich bestätigtes Steuerprofil.',
            'credit_applied_requires_review' => 'Auf diese Rechnung wurde WHMCS-Guthaben angewendet; sie benötigt eine Einzelprüfung.',
            'non_positive_total_requires_review' => 'Null- oder Negativbeträge werden nicht automatisch exportiert.',
            'invoice_total_mismatch' => 'Positionssumme und WHMCS-Rechnungsbetrag weichen voneinander ab.',
            'foreign_currency_requires_review' => 'Fremdwährungsrechnungen benötigen eine separate fachliche Freigabe.',
            'receipt_guidance_not_validated' => 'Konto und Steuerregel wurden von sevdesk Receipt Guidance nicht bestätigt.',
            'contact_conflict' => 'Mehrere sevdesk-Kontakte tragen dieselbe WHMCS-Kundennummer.',
            'reconciliation_no_match' => 'Es wurde kein eindeutig passender sevdesk-Beleg gefunden. Bitte manuell prüfen.',
            'reconciliation_multiple_matches' => 'Es wurden mehrere passende sevdesk-Belege gefunden. Bitte manuell prüfen.',
            'invalid_invoice_pdf' => 'WHMCS hat kein gültiges Rechnungs-PDF erzeugt.',
            'local_preflight_failed' => 'Die lokale Vorprüfung ist fehlgeschlagen: ' . mb_substr($fallback, 0, 500),
        ][$code] ?? $fallback;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 128) : null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1
            || (is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true));
    }
}
