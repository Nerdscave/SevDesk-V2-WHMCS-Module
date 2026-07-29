<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Jobs;

use JsonException;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Repository\RelatedDocumentRepository;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\DunningService;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceItemExportPolicy;
use WHMCS\Module\Addon\SevDesk\Service\ReferenceData;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

/** Executes one isolated reminder, cancellation or late-fee accounting item. */
final class DunningJobHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly WhmcsGateway $whmcs,
        private readonly MappingRepository $mappings,
        private readonly RelatedDocumentRepository $relatedDocuments,
        private readonly JobRepository $jobs,
        private readonly ReferenceData $referenceData,
        private readonly DunningService $dunning,
    ) {
    }

    /** @param callable(string,array<string,scalar|null>):bool $checkpoint */
    public function __invoke(object $item, callable $checkpoint): JobOutcome
    {
        $invoiceId = (int) ($item->invoice_id ?? 0);
        if ($invoiceId < 1) {
            return JobOutcome::permanentFailure(
                'Der Zusatzdokument-Job enthält keine gültige Rechnungs-ID.',
                errorCode: 'invalid_invoice_id',
            );
        }
        try {
            $candidate = self::candidate($item);
            $persistCheckpoint = static function (
                string $name,
                array $context = [],
            ) use (
                $checkpoint,
                $item,
                &$candidate
): bool {
                $stored = $checkpoint($name, $context);
                if ($stored) {
                    $item->checkpoint = $name;
                    foreach ($context as $key => $value) {
                        $candidate[$key] = $value;
                    }
                    if (isset($context['remoteId'])) {
                        $item->sevdesk_id = (string) $context['remoteId'];
                    }
                }

                return $stored;
            };

            $mapping = $this->mappings->findCompleteByInvoiceAndType(
                $invoiceId,
                MappingRepository::DOCUMENT_TYPE_INVOICE,
            );
            if ($mapping === null) {
                return JobOutcome::retry(
                    'Die verifizierte sevDesk-Grundrechnung ist noch nicht verfügbar.',
                    120,
                    errorCode: 'primary_invoice_mapping_pending',
                    checkpoint: (string) ($item->checkpoint ?? 'queued'),
                );
            }
            $remoteId = trim((string) ($mapping->sevdesk_id ?? ''));
            $invoiceNumber = trim((string) ($mapping->document_number ?? ''));
            if (
                preg_match('/^[1-9]\d*$/', $remoteId) !== 1
                || $invoiceNumber === ''
            ) {
                return $this->localFailure(
                    $item,
                    'Die Grundrechnung besitzt keine beweiskräftige typisierte Zuordnung.',
                    'primary_invoice_mapping_invalid',
                );
            }

            $contract = $this->whmcs->invoiceExportContract($invoiceId);
            $snapshot = $contract['snapshot'];
            if (!$snapshot instanceof InvoiceSnapshot) {
                return $this->localFailure(
                    $item,
                    'Der WHMCS-Rechnungsvertrag ist ungültig.',
                    'invalid_whmcs_invoice_contract',
                );
            }
            $fingerprint = strtolower(trim((string) ($contract['fingerprint'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
                return $this->localFailure(
                    $item,
                    'Der WHMCS-Rechnungsvertrag besitzt keinen gültigen Fingerprint.',
                    'invalid_whmcs_invoice_fingerprint',
                );
            }
            $primaryFingerprint = strtolower(trim((string) (
                $contract['primaryFingerprint']
                ?? ''
            )));
            $issuedPrimaryFingerprint = strtolower(trim((string) (
                $candidate['primaryInvoiceContractFingerprint']
                ?? ''
            )));
            if (
                preg_match('/^[a-f0-9]{64}$/', $primaryFingerprint) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $issuedPrimaryFingerprint) !== 1
                || !hash_equals($issuedPrimaryFingerprint, $primaryFingerprint)
            ) {
                return $this->localFailure(
                    $item,
                    'Die ausgegebene Leistungsrechnung stimmt nicht mehr mit dem WHMCS-Vertrag überein.',
                    'issued_primary_contract_changed',
                );
            }
            $localDunningFingerprint = trim((string) (
                $candidate['localDunningFingerprint']
                ?? ''
            ));
            if (
                $localDunningFingerprint !== ''
                && !hash_equals(
                    $localDunningFingerprint,
                    $this->whmcs->localDunningFingerprint($invoiceId),
                )
            ) {
                return $this->localFailure(
                    $item,
                    'Fälligkeit, Betrag, Status oder Positionen änderten sich nach dem WHMCS-Hook.',
                    'local_dunning_contract_changed',
                );
            }
            $frozen = strtolower(trim((string) ($candidate['whmcsInvoiceContractFingerprint'] ?? '')));
            if ($frozen !== '' && !hash_equals($frozen, $fingerprint)) {
                return $this->localFailure(
                    $item,
                    'Die WHMCS-Rechnung änderte sich nach Einreihung des Zusatzdokuments.',
                    'whmcs_invoice_contract_changed',
                );
            }
            if ($frozen === '') {
                if (JobRepository::isRiskyCheckpoint((string) ($item->checkpoint ?? ''))) {
                    return JobOutcome::ambiguous(
                        'Nach einem möglichen sevDesk-Write fehlt der eingefrorene WHMCS-Vertrag.',
                        (string) $item->checkpoint,
                        isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                        errorCode: 'whmcs_invoice_contract_missing_after_write',
                    );
                }
                if (
                    !$persistCheckpoint(
                        (string) ($item->checkpoint ?? 'queued'),
                        ['whmcsInvoiceContractFingerprint' => $fingerprint],
                    )
                ) {
                    return JobOutcome::permanentFailure(
                        'Der WHMCS-Vertrag konnte nicht eingefroren werden.',
                        errorCode: 'whmcs_invoice_contract_checkpoint_failed',
                    );
                }
            }

            $contact = $this->whmcs->contactData($snapshot->clientId);
            $contactId = trim((string) ($contact->sevdeskContactId ?? ''));
            if (preg_match('/^[1-9]\d*$/', $contactId) !== 1) {
                return $this->localFailure(
                    $item,
                    'Der bestehende sevDesk-Kontaktlink fehlt oder ist ungültig.',
                    'sevdesk_contact_link_missing',
                );
            }
            $action = (string) ($item->action ?? '');
            $primaryMismatch = $this->dunning->primaryInvoiceMismatch(
                $remoteId,
                $contactId,
                $invoiceNumber,
                $snapshot->totalMinorUnits(),
                $action === 'create_invoice_reminder',
            );
            if ($primaryMismatch !== null) {
                return $this->localFailure(
                    $item,
                    'Die sevDesk-Grundrechnung entspricht nicht mehr dem eingefrorenen WHMCS-Vertrag.',
                    $primaryMismatch,
                );
            }
            $guardedCheckpoint = function (
                string $name,
                array $context = [],
            ) use (
                $action,
                $invoiceId,
                $candidate,
                $fingerprint,
                $persistCheckpoint,
            ): bool {
                if (str_ends_with($name, '_requested')) {
                    $this->assertFreshBeforeWrite(
                        $action,
                        $invoiceId,
                        $candidate,
                        $fingerprint,
                    );
                }

                return $persistCheckpoint($name, $context);
            };

            return match ($action) {
                'create_invoice_reminder' => $this->createReminder(
                    $item,
                    $candidate,
                    $mapping,
                    $snapshot,
                    $contract,
                    $contactId,
                    $contact->email,
                    $remoteId,
                    $fingerprint,
                    $guardedCheckpoint,
                ),
                'cancel_invoice' => $this->cancelInvoice(
                    $item,
                    $candidate,
                    $mapping,
                    $snapshot,
                    $contactId,
                    $contact->email,
                    $remoteId,
                    $fingerprint,
                    $guardedCheckpoint,
                ),
                'export_late_fee_voucher' => $this->exportLateFeeVoucher(
                    $item,
                    $candidate,
                    $snapshot,
                    $contract,
                    $contactId,
                    $remoteId,
                    $fingerprint,
                    $guardedCheckpoint,
                ),
                default => JobOutcome::permanentFailure(
                    'Unbekannte Zusatzdokument-Aktion.',
                    errorCode: 'unknown_dunning_action',
                ),
            };
        } catch (ApiException $exception) {
            return $this->apiFailure($item, $exception);
        } catch (Throwable) {
            return $this->localFailure(
                $item,
                'Die lokale Zusatzdokument-Prüfung ist fehlgeschlagen.',
                'dunning_local_failure',
            );
        }
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $contract
     * @param callable(string,array<string,scalar|null>):bool $checkpoint
     */
    private function createReminder(
        object $item,
        array $candidate,
        object $mapping,
        InvoiceSnapshot $snapshot,
        array $contract,
        string $contactId,
        string $email,
        string $remoteId,
        string $fingerprint,
        callable $checkpoint,
    ): JobOutcome {
        if (
            !$this->directDunningReady()
            || trim((string) ($mapping->document_authority ?? ''))
                !== MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
            || trim((string) ($mapping->invoice_lifecycle_mode ?? ''))
                !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
        ) {
            return $this->localFailure(
                $item,
                'Der sevDesk-Mahnversand ist für diesen Beleg nicht freigegeben.',
                'dunning_prerequisites_missing',
            );
        }
        if ((string) $contract['status'] !== 'Unpaid') {
            return $this->localFailure(
                $item,
                'Die Rechnung ist nicht mehr offen; die Mahnung bleibt gesperrt.',
                'invoice_not_unpaid',
            );
        }
        $financial = $this->whmcs->invoiceFinancialState($snapshot->invoiceId);
        if (
            $financial['creditMinor'] !== 0
            || $financial['amountInMinor'] !== 0
            || $financial['amountOutMinor'] !== 0
            || $financial['transactionCount'] !== 0
        ) {
            return $this->localFailure(
                $item,
                'Teilzahlung, Guthaben oder Rückzahlung erfordern vor der Mahnung eine manuelle Abstimmung.',
                'dunning_payment_split_required',
            );
        }
        if ($mapping->delivered_at === null) {
            return $this->localFailure(
                $item,
                'Eine Mahnung darf erst nach bewiesenem Versand der Grundrechnung erfolgen.',
                'primary_invoice_not_delivered',
            );
        }
        $level = self::positiveInt($candidate['dunningLevel'] ?? null);
        $deadline = trim((string) ($candidate['reminderDeadline'] ?? ''));
        if ($level === null || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $deadline) !== 1) {
            return $this->localFailure(
                $item,
                'Mahnstufe oder Mahnfrist fehlen im WHMCS-Hookvertrag.',
                'dunning_schedule_snapshot_invalid',
            );
        }
        $lateFee = is_array($contract['lateFee'] ?? null) ? $contract['lateFee'] : null;
        $lateFeeMinor = is_int($lateFee['amountMinor'] ?? null) ? $lateFee['amountMinor'] : 0;
        $result = $this->dunning->createReminder([
            'invoiceId' => $snapshot->invoiceId,
            'parentRemoteId' => $remoteId,
            'contactId' => $contactId,
            'dunningLevel' => $level,
            'serviceGrossMinor' => $snapshot->totalMinorUnits(),
            'lateFeeMinor' => $lateFeeMinor,
            'fingerprint' => $fingerprint,
            'reminderDeadline' => $deadline,
            'toEmail' => $email,
            'subject' => self::render(
                (string) $this->config->get('sevdesk_reminder_subject', ''),
                $snapshot->invoiceNumber,
            ),
            'text' => self::render(
                (string) $this->config->get('sevdesk_reminder_body', ''),
                $snapshot->invoiceNumber,
            ),
            'deliver' => true,
            'createAllowed' => !JobRepository::isRiskyCheckpoint(
                (string) ($item->checkpoint ?? ''),
            ),
            'expectedRemoteId' => self::optionalRemoteId($item->sevdesk_id ?? null),
            'resumeDeliverySafe' => (string) ($item->checkpoint ?? '')
                !== 'reminder_delivery_write_requested',
        ], $checkpoint);

        return $this->outcome($item, $result);
    }

    /**
     * @param array<string,mixed> $candidate
     * @param callable(string,array<string,scalar|null>):bool $checkpoint
     */
    private function cancelInvoice(
        object $item,
        array $candidate,
        object $mapping,
        InvoiceSnapshot $snapshot,
        string $contactId,
        string $email,
        string $remoteId,
        string $fingerprint,
        callable $checkpoint,
    ): JobOutcome {
        if (
            !$this->directCancellationReady()
            || trim((string) ($mapping->document_authority ?? ''))
                !== MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
            || trim((string) ($mapping->invoice_lifecycle_mode ?? ''))
                !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
        ) {
            return $this->localFailure(
                $item,
                'Der automatische sevDesk-Stornopfad ist für diesen Beleg nicht freigegeben.',
                'cancellation_prerequisites_missing',
            );
        }
        if ($this->whmcs->invoiceStatusForDelivery($snapshot->invoiceId) !== 'Cancelled') {
            return $this->localFailure(
                $item,
                'Nur eine weiterhin stornierte, unbezahlte WHMCS-Rechnung darf automatisch storniert werden.',
                'invoice_not_cancelled',
            );
        }
        $financial = $this->whmcs->invoiceFinancialState($snapshot->invoiceId);
        if (
            $financial['creditMinor'] !== 0
            || $financial['amountInMinor'] !== 0
            || $financial['amountOutMinor'] !== 0
            || $financial['transactionCount'] !== 0
        ) {
            return $this->localFailure(
                $item,
                'Eine Rechnung mit Zahlungsbewegung oder Guthaben wird nicht automatisch storniert.',
                'cancellation_payment_activity_blocked',
            );
        }
        if (
            (bool) ($mapping->is_e_invoice ?? false)
            && !$this->config->bool('e_invoice_cancellation_canary_confirmed')
        ) {
            return $this->localFailure(
                $item,
                'Für diese E-Rechnung fehlt der separate SR-PDF/XML-Canary.',
                'e_invoice_cancellation_canary_missing',
            );
        }
        if ($this->relatedDocuments->hasChargedReminder($snapshot->invoiceId)) {
            return $this->localFailure(
                $item,
                'Eine bereits erzeugte Mahnung mit Gebühr kann nicht automatisch '
                    . 'durch die Stornorechnung der verkürzten Grundrechnung ausgeglichen werden.',
                'cancellation_with_charged_reminder_requires_review',
            );
        }
        $deliver = $mapping->delivered_at !== null;
        $result = $this->dunning->cancelInvoice([
            'invoiceId' => $snapshot->invoiceId,
            'parentRemoteId' => $remoteId,
            'contactId' => $contactId,
            'serviceGrossMinor' => $snapshot->totalMinorUnits(),
            'fingerprint' => $fingerprint,
            'toEmail' => $email,
            'subject' => self::render(
                (string) $this->config->get('sevdesk_cancellation_subject', ''),
                $snapshot->invoiceNumber,
            ),
            'text' => self::render(
                (string) $this->config->get('sevdesk_cancellation_body', ''),
                $snapshot->invoiceNumber,
            ),
            'deliver' => $deliver,
            'isEInvoice' => (bool) ($mapping->is_e_invoice ?? false),
            'createAllowed' => !JobRepository::isRiskyCheckpoint(
                (string) ($item->checkpoint ?? ''),
            ),
            'expectedRemoteId' => self::optionalRemoteId($item->sevdesk_id ?? null),
            'resumeDeliverySafe' => (string) ($item->checkpoint ?? '')
                !== 'cancellation_delivery_write_requested',
        ], $checkpoint);

        return $this->outcome($item, $result);
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $contract
     * @param callable(string,array<string,scalar|null>):bool $checkpoint
     */
    private function exportLateFeeVoucher(
        object $item,
        array $candidate,
        InvoiceSnapshot $snapshot,
        array $contract,
        string $contactId,
        string $remoteId,
        string $fingerprint,
        callable $checkpoint,
    ): JobOutcome {
        $accountingSource = (string) $this->config->get(
            'late_fee_accounting_source',
            'rule22_voucher',
        );
        if (
            (string) $this->config->get(
                'late_fee_mode',
                InvoiceItemExportPolicy::LATE_FEE_MODE_BLOCKED,
            ) !== InvoiceItemExportPolicy::LATE_FEE_MODE_REMINDER_THEN_RULE22
            || !in_array($accountingSource, ['rule22_voucher', 'reminder'], true)
        ) {
            return $this->localFailure(
                $item,
                'Der mailfreie Rule-22-Gebührenpfad ist noch nicht vollständig freigegeben.',
                'late_fee_rule22_prerequisites_missing',
            );
        }
        if ((string) $contract['status'] !== 'Paid') {
            return $this->localFailure(
                $item,
                'Der Gebührenbeleg wird erst nach vollständiger WHMCS-Zahlung erzeugt.',
                'late_fee_requires_payment',
            );
        }
        $lateFee = is_array($contract['lateFee'] ?? null) ? $contract['lateFee'] : null;
        if (
            !is_int($lateFee['amountMinor'] ?? null)
            || $lateFee['amountMinor'] <= 0
            || !is_string($lateFee['fingerprint'] ?? null)
        ) {
            return $this->localFailure(
                $item,
                'Der eingefrorene Late-Fee-Nachweis fehlt.',
                'late_fee_snapshot_missing',
            );
        }
        $storedFeeFingerprint = trim((string) ($candidate['lateFeeFingerprint'] ?? ''));
        $storedFeeAmount = $candidate['lateFeeAmountMinor'] ?? null;
        if (
            $storedFeeFingerprint !== ''
            && (
                !hash_equals($storedFeeFingerprint, $lateFee['fingerprint'])
                || !is_int($storedFeeAmount)
                || $storedFeeAmount !== $lateFee['amountMinor']
            )
        ) {
            return $this->localFailure(
                $item,
                'Die Mahngebühr änderte sich nach dem Export der Grundrechnung.',
                'late_fee_snapshot_changed',
            );
        }
        if ($accountingSource === 'reminder') {
            if (!$this->config->bool('late_fee_reminder_accounting_canary_confirmed')) {
                return $this->localFailure(
                    $item,
                    'Die buchhalterische Wirkung der sevDesk-Mahngebühr wurde noch nicht im Canary bestätigt.',
                    'late_fee_reminder_accounting_canary_missing',
                );
            }
            $reminders = $this->relatedDocuments->forInvoice(
                $snapshot->invoiceId,
                RelatedDocumentRepository::ROLE_REMINDER,
            );
            $matchingReminder = false;
            foreach ($reminders as $reminder) {
                if ((int) ($reminder->amount_minor ?? 0) === $lateFee['amountMinor']) {
                    $matchingReminder = true;
                    break;
                }
            }
            if (!$matchingReminder) {
                return $this->localFailure(
                    $item,
                    'Die Gebührenbuchung über eine sevDesk-Mahnung ist freigegeben, aber keine exakt passende '
                        . 'geprüfte Mahnung ist zugeordnet.',
                    'late_fee_accounting_reminder_missing',
                );
            }

            return JobOutcome::skipped(
                'Die exakt geprüfte sevDesk-Mahnung bildet die Mahngebühr bereits ab; '
                    . 'ein zusätzlicher Rule-22-Beleg wäre doppelt.',
            );
        }
        if (!$this->config->bool('late_fee_rule22_canary_confirmed')) {
            return $this->localFailure(
                $item,
                'Der mailfreie Rule-22-Gebührenpfad ist noch nicht vollständig freigegeben.',
                'late_fee_rule22_canary_missing',
            );
        }
        $voucherDate = trim((string) ($candidate['lateFeeVoucherDate'] ?? ''));
        $currentPaymentDate = $this->whmcs->invoiceDatePaid($snapshot->invoiceId);
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $voucherDate) !== 1
            || $currentPaymentDate === null
            || !hash_equals($voucherDate, $currentPaymentDate)
        ) {
            return $this->localFailure(
                $item,
                'Das eingefrorene WHMCS-Zahlungsdatum für den Gebührenbeleg fehlt oder hat sich geändert.',
                'late_fee_payment_date_changed',
            );
        }
        $accountDatevId = trim((string) $this->config->get(
            'late_fee_rule22_account_datev_id',
            '',
        ));
        $result = $this->dunning->exportLateFeeVoucher([
            'invoiceId' => $snapshot->invoiceId,
            'parentRemoteId' => $remoteId,
            'contactId' => $contactId,
            'invoiceNumber' => $snapshot->invoiceNumber,
            'voucherDate' => $voucherDate,
            'lateFeeMinor' => $lateFee['amountMinor'],
            'fingerprint' => $lateFee['fingerprint'],
            'accountDatevId' => $accountDatevId,
            'receiptGuidance' => $this->referenceData->receiptGuidance(true),
            'createAllowed' => !JobRepository::isRiskyCheckpoint(
                (string) ($item->checkpoint ?? ''),
            ),
            'expectedRemoteId' => self::optionalRemoteId($item->sevdesk_id ?? null),
        ], $checkpoint);

        return $this->outcome($item, $result);
    }

    private function directDunningReady(): bool
    {
        return $this->config->get('invoice_lifecycle_mode')
                === DocumentTargetResolver::LIFECYCLE_ISSUE_ON_CREATION
            && $this->config->get('dunning_mode') === 'whmcs_schedule_sevdesk_delivery'
            && $this->config->bool('direct_invoice_canary_confirmed')
            && $this->config->bool('dunning_canary_confirmed');
    }

    private function directCancellationReady(): bool
    {
        return $this->config->get('invoice_lifecycle_mode')
                === DocumentTargetResolver::LIFECYCLE_ISSUE_ON_CREATION
            && $this->config->bool('direct_invoice_canary_confirmed')
            && $this->config->bool('cancellation_canary_confirmed');
    }

    /** @param array<string,mixed> $candidate */
    private function assertFreshBeforeWrite(
        string $action,
        int $invoiceId,
        array $candidate,
        string $expectedContractFingerprint,
    ): void {
        $currentContract = $this->whmcs->invoiceExportContract($invoiceId);
        $currentFingerprint = strtolower(trim((string) ($currentContract['fingerprint'] ?? '')));
        if (
            preg_match('/^[a-f0-9]{64}$/', $currentFingerprint) !== 1
            || !hash_equals($expectedContractFingerprint, $currentFingerprint)
        ) {
            throw new \RuntimeException('The WHMCS invoice contract changed before the remote write.');
        }
        $localFingerprint = trim((string) ($candidate['localDunningFingerprint'] ?? ''));
        if (
            $localFingerprint !== ''
            && !hash_equals($localFingerprint, $this->whmcs->localDunningFingerprint($invoiceId))
        ) {
            throw new \RuntimeException('The WHMCS dunning contract changed before the remote write.');
        }
        $status = (string) ($currentContract['status'] ?? '');
        if ($action === 'create_invoice_reminder') {
            if ($status !== 'Unpaid') {
                throw new \RuntimeException('The invoice is no longer unpaid.');
            }
            $financial = $this->whmcs->invoiceFinancialState($invoiceId);
            if (
                $financial['creditMinor'] !== 0
                || $financial['amountInMinor'] !== 0
                || $financial['amountOutMinor'] !== 0
                || $financial['transactionCount'] !== 0
            ) {
                throw new \RuntimeException('Payment activity blocks reminder delivery.');
            }
        } elseif ($action === 'cancel_invoice') {
            if ($this->whmcs->invoiceStatusForDelivery($invoiceId) !== 'Cancelled') {
                throw new \RuntimeException('The invoice is no longer cancelled.');
            }
            $financial = $this->whmcs->invoiceFinancialState($invoiceId);
            if (
                $financial['creditMinor'] !== 0
                || $financial['amountInMinor'] !== 0
                || $financial['amountOutMinor'] !== 0
                || $financial['transactionCount'] !== 0
            ) {
                throw new \RuntimeException('Payment activity blocks cancellation.');
            }
        } elseif ($action === 'export_late_fee_voucher') {
            if ($status !== 'Paid') {
                throw new \RuntimeException('The late-fee voucher requires a paid invoice.');
            }
            $storedPaymentDate = trim((string) ($candidate['lateFeeVoucherDate'] ?? ''));
            $currentPaymentDate = $this->whmcs->invoiceDatePaid($invoiceId);
            if (
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedPaymentDate) !== 1
                || $currentPaymentDate === null
                || !hash_equals($storedPaymentDate, $currentPaymentDate)
            ) {
                throw new \RuntimeException('The late-fee payment date changed before the remote write.');
            }
        }
    }

    /** @param array{status:string,code:string,message:string,remoteId?:string} $result */
    private function outcome(object $item, array $result): JobOutcome
    {
        $remoteId = isset($result['remoteId']) ? (string) $result['remoteId'] : null;
        if ($result['status'] === 'succeeded') {
            return JobOutcome::succeeded($result['message'], $remoteId, ['resultCode' => $result['code']]);
        }
        if ($result['status'] === 'ambiguous') {
            return JobOutcome::ambiguous(
                $result['message'],
                (string) ($item->checkpoint ?? 'write_requested'),
                $remoteId,
                errorCode: $result['code'],
            );
        }

        return $this->localFailure($item, $result['message'], $result['code']);
    }

    private function apiFailure(object $item, ApiException $exception): JobOutcome
    {
        $checkpoint = (string) ($item->checkpoint ?? 'queued');
        $safeCheckpoint = !$exception->outcomeUnknown
            ? self::checkpointBeforeDefinitiveRejection($checkpoint)
            : $checkpoint;
        if ($exception->isAuthenticationFailure()) {
            $this->tripAuthenticationAlarm((int) ($item->job_id ?? 0));

            return JobOutcome::retry(
                'sevDesk hat die Authentifizierung abgelehnt; alle Jobs wurden pausiert.',
                300,
                $exception->httpStatus,
                $exception->exceptionUuid,
                'api_authentication_failed',
                $safeCheckpoint,
            );
        }
        if ($exception->outcomeUnknown) {
            $this->markUnknownDelivery($item, $checkpoint);

            return JobOutcome::ambiguous(
                'Der Ausgang des sevDesk-Schreibvorgangs ist unklar; es folgt kein automatischer zweiter Write.',
                $checkpoint,
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                $exception->httpStatus,
                $exception->exceptionUuid,
                $exception->sevdeskCode ?? 'dunning_write_outcome_unknown',
            );
        }
        if (
            ($exception->isRateLimit() || $exception->httpStatus === 408
                || ($exception->httpStatus !== null && $exception->httpStatus >= 500))
            && (int) ($item->attempts ?? 1) < 4
        ) {
            return JobOutcome::retry(
                'Der sichere Zusatzdokument-Schritt wird mit Backoff wiederholt.',
                max(60, min(3600, $exception->retryAfterSeconds ?? 300)),
                $exception->httpStatus,
                $exception->exceptionUuid,
                $exception->sevdeskCode ?? 'dunning_api_retry',
                $safeCheckpoint,
            );
        }

        return JobOutcome::permanentFailure(
            'sevDesk hat den Zusatzdokument-Schritt eindeutig abgelehnt.',
            $exception->httpStatus,
            $exception->exceptionUuid,
            $exception->sevdeskCode ?? 'dunning_api_rejected',
            $safeCheckpoint,
        );
    }

    private function markUnknownDelivery(object $item, string $checkpoint): void
    {
        $role = match ($checkpoint) {
            'reminder_delivery_write_requested' => RelatedDocumentRepository::ROLE_REMINDER,
            'cancellation_delivery_write_requested' => RelatedDocumentRepository::ROLE_CANCELLATION,
            default => null,
        };
        $invoiceId = (int) ($item->invoice_id ?? 0);
        $remoteId = self::optionalRemoteId($item->sevdesk_id ?? null);
        if ($role === null || $invoiceId < 1 || $remoteId === null) {
            return;
        }
        $candidate = self::candidate($item);
        $level = $role === RelatedDocumentRepository::ROLE_REMINDER
            ? (self::positiveInt($candidate['dunningLevel'] ?? null) ?? 0)
            : 0;
        if ($role === RelatedDocumentRepository::ROLE_REMINDER && $level === 0) {
            return;
        }
        try {
            $this->relatedDocuments->markDeliveryAmbiguous(
                $invoiceId,
                $role,
                $level,
                $remoteId,
            );
        } catch (Throwable) {
            // The durable job checkpoint remains the authoritative uncertainty.
        }
    }

    private static function checkpointBeforeDefinitiveRejection(string $checkpoint): string
    {
        return match ($checkpoint) {
            'reminder_create_requested',
            'cancellation_write_requested',
            'late_fee_voucher_write_requested' => 'queued',
            'reminder_delivery_write_requested' => 'reminder_mapping_persisted',
            'cancellation_delivery_write_requested' => 'cancellation_mapping_persisted',
            default => $checkpoint,
        };
    }

    private function localFailure(object $item, string $message, string $code): JobOutcome
    {
        $checkpoint = (string) ($item->checkpoint ?? '');
        if (JobRepository::isRiskyCheckpoint($checkpoint)) {
            return JobOutcome::ambiguous(
                $message,
                $checkpoint,
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                errorCode: $code,
            );
        }

        return JobOutcome::permanentFailure($message, errorCode: $code);
    }

    private function tripAuthenticationAlarm(int $jobId): void
    {
        $this->config->tripAuthenticationSafetyGates();
        if ($jobId > 0) {
            try {
                $this->jobs->pause($jobId);
            } catch (Throwable) {
                // The tenant-wide gates are already durable.
            }
        }
    }

    /** @return array<string,mixed> */
    private static function candidate(object $item): array
    {
        $json = trim((string) ($item->candidate_json ?? ''));
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new \InvalidArgumentException('Invalid dunning candidate JSON.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private static function optionalRemoteId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }

    private static function render(string $template, string $invoiceNumber): string
    {
        return str_replace(['{invoice_number}', '{company_name}'], [$invoiceNumber, ''], $template);
    }
}
