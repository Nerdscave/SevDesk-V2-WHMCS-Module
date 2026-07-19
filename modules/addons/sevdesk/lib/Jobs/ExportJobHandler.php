<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Jobs;

use Closure;
use DateTimeImmutable;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\ContactResolution;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\ContactService;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\EInvoiceEligibilityService;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceExporter;
use WHMCS\Module\Addon\SevDesk\Service\InvoicePdf;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\PdfRenderer;
use WHMCS\Module\Addon\SevDesk\Service\ReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;
use WHMCS\Module\Addon\SevDesk\Support\EmailAttachmentContext;

/** Executes one isolated, document-aware invoice export item. */
final class ExportJobHandler
{
    /** @var Closure(): TaxPolicy */
    private readonly Closure $taxPolicy;

    /** @var Closure(): DocumentTargetResolver */
    private readonly Closure $targetResolver;

    /**
     * The first nine arguments intentionally keep the 2.0 Voucher constructor
     * contract. Invoice collaborators are appended so old tests and operators
     * upgrading with queued Voucher jobs retain the established path.
     *
     * @param callable(): TaxPolicy $taxPolicy
     * @param null|callable(): DocumentTargetResolver $targetResolver
     */
    public function __construct(
        private readonly Config $config,
        private readonly WhmcsGateway $whmcs,
        private readonly MappingRepository $mappings,
        private readonly JobRepository $jobs,
        private readonly ContactService $contacts,
        private readonly PdfRenderer $pdf,
        private readonly VoucherExporter $voucherExporter,
        private readonly ReconciliationService $voucherReconciliation,
        callable $taxPolicy,
        private readonly ?InvoiceExporter $invoiceExporter = null,
        private readonly ?InvoiceReconciliationService $invoiceReconciliation = null,
        private readonly ?InvoicePdf $invoicePdf = null,
        ?callable $targetResolver = null,
        private readonly ?EInvoiceEligibilityService $eInvoiceEligibility = null,
    ) {
        $this->taxPolicy = Closure::fromCallable($taxPolicy);
        $this->targetResolver = Closure::fromCallable($targetResolver ?? static fn (): DocumentTargetResolver =>
            new DocumentTargetResolver(
                (string) $config->get('export_mode', DocumentTargetResolver::MODE_VOUCHER_ONLY),
                (string) $config->get('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS),
                (string) $config->get('oss_profile', DocumentTargetResolver::OSS_BLOCKED),
            ));
    }

    /** @param callable(string, array<string, scalar|null>): bool $checkpoint */
    public function __invoke(object $item, callable $checkpoint): JobOutcome
    {
        $candidate = self::candidate($item);
        $contactRecoveryOnly = self::contactRecoveryRequired((string) ($item->checkpoint ?? ''));
        $persistCheckpoint = static function (
            string $name,
            array $context = []
        ) use (
            $checkpoint,
            $item,
            &$candidate,
        ): bool {
            $stored = $checkpoint($name, $context);
            if (!$stored) {
                return false;
            }

            $item->checkpoint = $name;
            foreach ($context as $key => $value) {
                $candidate[$key] = $value;
            }
            if (isset($context['remoteId']) && preg_match('/^\d+$/', (string) $context['remoteId']) === 1) {
                $item->sevdesk_id = (string) $context['remoteId'];
            }

            return true;
        };

        $invoiceId = (int) ($item->invoice_id ?? 0);
        if ($invoiceId < 1) {
            return JobOutcome::permanentFailure(
                'Die Jobposition enthält keine gültige WHMCS-Rechnungs-ID.',
                errorCode: 'invalid_invoice_id',
            );
        }

        try {
            $rawInvoice = null;
            $contact = null;
            $resolution = null;

            // A possibly executed Contact POST outranks mappings and ordinary
            // business terminals. Recovery searches and relinks, but never
            // creates a second contact.
            if ($contactRecoveryOnly) {
                $clientId = self::contactRecoveryClientId($candidate);
                if ($clientId < 1) {
                    $rawInvoice = $this->whmcs->invoice($invoiceId);
                    $clientId = (int) ($rawInvoice['userid'] ?? 0);
                }
                $contact = $this->whmcs->contactData($clientId);
                $contactResult = $this->contacts->resolve($contact, $persistCheckpoint, true);
                if ($contactResult->isFailure()) {
                    return $this->contactRecoveryFailureToOutcome(
                        $contactResult->errorCode() ?? 'contact_failed',
                        $contactResult->errorMessage() ?? 'Der sevdesk-Kontakt konnte nicht aufgelöst werden.',
                        $contactResult->context(),
                        $item,
                    );
                }
                $resolution = $contactResult->value();
                if (!$resolution instanceof ContactResolution) {
                    return JobOutcome::ambiguous(
                        'Die lesende Kontakt-Recovery lieferte kein eindeutiges Ergebnis.',
                        (string) ($item->checkpoint ?? 'contact_write_requested'),
                        errorCode: 'invalid_contact_recovery_result',
                    );
                }
            }

            $mapping = $this->mappings->findByInvoice($invoiceId);
            $completeRemoteId = self::mappingRemoteId($mapping);
            $mappingType = trim((string) ($mapping->document_type ?? ''));
            $invoiceContinuation = self::isInvoiceContinuation($item, $candidate, $mappingType);

            if ($completeRemoteId !== null && $mappingType === '') {
                return JobOutcome::ambiguous(
                    'Die bestehende Zuordnung hat noch keinen bestätigten Belegtyp. Bitte zuerst read-only prüfen und bestätigen.',
                    'legacy_document_type_confirmation_required',
                    $completeRemoteId,
                    errorCode: 'mapping_document_type_unknown',
                );
            }
            if (
                (string) ($item->action ?? '') === 'export_document'
                && (string) ($item->checkpoint ?? '') === 'whmcs_email_handed_off'
            ) {
                return $this->finishHandedOffRecovery(
                    $candidate,
                    $invoiceId,
                    trim((string) ($mapping->document_number ?? '')),
                    $completeRemoteId,
                    $mappingType,
                );
            }
            if (
                $completeRemoteId !== null
                && !($mappingType === MappingRepository::DOCUMENT_TYPE_INVOICE && $invoiceContinuation)
            ) {
                return JobOutcome::skipped(
                    'Die Rechnung ist bereits mit einem typisierten sevdesk-Beleg verknüpft.',
                    $completeRemoteId,
                );
            }
            if (
                $mapping !== null
                && $completeRemoteId === null
                && !$this->allowsIncompleteMappingRecovery($item, $candidate)
            ) {
                return JobOutcome::ambiguous(
                    'Für diese Rechnung existiert eine alte NULL-Zuordnung. Vor einem Export ist eine Reconciliation erforderlich.',
                    'ambiguous_legacy',
                    errorCode: 'ambiguous_legacy',
                );
            }

            $voucherRecovery = $this->requiresVoucherReconciliation($item, $candidate);
            $rawInvoice ??= $this->whmcs->invoice($invoiceId);
            $status = (string) ($rawInvoice['status'] ?? '');
            if (!$voucherRecovery && !in_array($status, ['Paid', 'Unpaid'], true)) {
                if (
                    self::candidateSelectsInvoice($item, $candidate)
                    && JobRepository::isRiskyCheckpoint((string) ($item->checkpoint ?? ''))
                ) {
                    return JobOutcome::ambiguous(
                        'Der WHMCS-Zahlungsstatus änderte sich nach einem möglichen Invoice-Write. '
                            . 'Der Remote-Zustand muss manuell abgeglichen werden.',
                        (string) ($item->checkpoint ?? 'invoice_write_requested'),
                        isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                        errorCode: 'invoice_payment_state_changed_after_write',
                    );
                }

                return JobOutcome::skipped('Nur bezahlte oder veröffentlichte offene Rechnungen werden exportiert.');
            }
            if (
                !$voucherRecovery
                && !$invoiceContinuation
                && !$this->isAfterConfiguredStart((string) ($rawInvoice['date'] ?? ''))
            ) {
                return JobOutcome::skipped('Die Rechnung liegt vor dem konfigurierten Exportstichtag.');
            }

            $invoice = $this->whmcs->invoiceSnapshot($invoiceId);
            $creditTreatmentConfirmed = $this->creditTreatmentConfirmed($candidate);
            if (!$voucherRecovery) {
                // These checks are document-independent and deliberately happen
                // before Receipt Guidance, contact lookups, PDF rendering or any
                // remote accounting write.
                $documentValidation = $this->voucherExporter->validateInvoiceDocument(
                    $invoice,
                    $creditTreatmentConfirmed,
                );
                if ($documentValidation !== null) {
                    return $this->toOutcome($documentValidation, $item, 'exported');
                }
            }

            if ($contact !== null && $contact->whmcsClientId !== $invoice->clientId) {
                return JobOutcome::ambiguous(
                    'Die Rechnung wurde nach einem möglichen Kontakt-Write einem anderen Kunden zugeordnet.',
                    (string) ($item->checkpoint ?? 'contact_linked'),
                    errorCode: 'invoice_client_changed_after_contact_write',
                );
            }
            $contact ??= $this->whmcs->contactData($invoice->clientId);

            if ($voucherRecovery) {
                $result = $this->voucherReconciliation->reconcile(
                    $invoice,
                    $contact->sevdeskContactId,
                    isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                );

                return $this->toOutcome($result, $item, 'reconciled');
            }

            $tax = $this->taxDecision($invoiceId, $invoice, $contact, $item, $candidate);
            $target = $this->frozenOrNewTarget(
                $item,
                $candidate,
                $tax,
                $status,
                trim((string) ($rawInvoice['invoicenum'] ?? '')),
                $persistCheckpoint,
            );
            if (!$target instanceof DocumentTargetDecision) {
                return $target;
            }

            if (!$target->allowed) {
                if (in_array($target->code, ['invoice_requires_payment', 'invoice_number_not_final'], true)) {
                    return JobOutcome::skipped(self::messageFor($target->code, $target->message));
                }

                return JobOutcome::permanentFailure(
                    self::messageFor($target->code, $target->message),
                    errorCode: $target->code,
                );
            }
            if ($target->taxRuleId !== $tax->taxRuleId) {
                return JobOutcome::ambiguous(
                    'Die Steuerentscheidung unterscheidet sich vom eingefrorenen Exportziel. Bitte neu prüfen.',
                    (string) ($item->checkpoint ?? 'document_type_selected'),
                    $completeRemoteId,
                    errorCode: 'frozen_target_tax_rule_changed',
                );
            }

            if ($target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE && $status !== 'Paid') {
                if (JobRepository::isRiskyCheckpoint((string) ($item->checkpoint ?? ''))) {
                    return JobOutcome::ambiguous(
                        'Die Rechnung ist nach einem möglichen Invoice-Write nicht mehr vollständig bezahlt. '
                            . 'Vor jeder Fortsetzung ist ein manueller Remote-Abgleich erforderlich.',
                        (string) ($item->checkpoint ?? 'invoice_write_requested'),
                        $completeRemoteId,
                        errorCode: 'invoice_payment_state_changed_after_write',
                    );
                }

                return JobOutcome::skipped(
                    'Invoice-Ziele werden ausschließlich für aktuell vollständig bezahlte WHMCS-Rechnungen verarbeitet.',
                );
            }

            $runtimePreflight = $this->targetRuntimePreflight($target, $candidate, $item);
            if ($runtimePreflight !== null) {
                return $runtimePreflight;
            }

            if ($target->documentType === DocumentTargetDecision::DOCUMENT_VOUCHER) {
                return $this->exportVoucher(
                    $item,
                    $invoice,
                    $contact,
                    $resolution,
                    $tax,
                    $status,
                    $persistCheckpoint,
                    $creditTreatmentConfirmed,
                );
            }

            return $this->exportInvoice(
                $item,
                $candidate,
                $mapping,
                $invoice,
                $contact,
                $resolution,
                $tax,
                $target,
                $persistCheckpoint,
            );
        } catch (ApiException $exception) {
            return $this->failureResultToOutcome(
                $exception->isAuthenticationFailure() ? 'api_authentication_failed' : 'api_request_failed',
                $exception->getMessage(),
                $exception->context(),
                $item,
            );
        } catch (Throwable $exception) {
            $checkpointName = (string) ($item->checkpoint ?? '');
            if (JobRepository::isWriteOutcomeUnknownCheckpoint($checkpointName)) {
                return JobOutcome::ambiguous(
                    'Nach einem möglichen Schreibvorgang ist die lokale Verarbeitung fehlgeschlagen. Bitte zuerst read-only abgleichen.',
                    $checkpointName,
                    isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                    errorCode: 'local_post_write_failed',
                );
            }
            if (JobRepository::isVerifiedSideEffectCheckpoint($checkpointName)) {
                if ((int) ($item->attempts ?? 1) < 4) {
                    return JobOutcome::retry(
                        'Die lokale Fortsetzung nach einem verifizierten Remote-Schritt ist fehlgeschlagen und wird erneut versucht.',
                        300,
                        errorCode: 'local_post_write_resume_failed',
                        checkpoint: $checkpointName,
                    );
                }

                return JobOutcome::ambiguous(
                    'Der Remote-Schritt ist bestätigt, aber die lokale Fortsetzung blieb wiederholt fehlerhaft. '
                        . 'Der Beleg muss vor einem weiteren Versuch geprüft werden.',
                    $checkpointName,
                    isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                    errorCode: 'local_post_write_resume_failed',
                );
            }

            return JobOutcome::permanentFailure(
                self::messageFor('local_preflight_failed', $exception->getMessage()),
                errorCode: 'local_preflight_failed',
            );
        }
    }

    /** @param callable(string, array<string, scalar|null>): bool $checkpoint */
    private function exportVoucher(
        object $item,
        InvoiceSnapshot $invoice,
        ContactData $contact,
        ?ContactResolution $resolution,
        TaxDecision $tax,
        string $status,
        callable $checkpoint,
        bool $creditTreatmentConfirmed,
    ): JobOutcome {
        if (!self::statusIsExportable($status, $this->config->bool('import_only_paid', true))) {
            return JobOutcome::skipped(
                $this->config->bool('import_only_paid', true)
                    ? 'Nach der aktuellen Einstellung werden nur bezahlte Rechnungen exportiert.'
                    : 'Nur bezahlte oder veröffentlichte offene Rechnungen werden exportiert.',
            );
        }

        $taxValidation = $this->voucherExporter->validateTaxDecision($invoice, $tax);
        if ($taxValidation !== null) {
            return $this->toOutcome($taxValidation, $item, 'exported');
        }
        if (
            !$checkpoint('preflight_complete', [
                'invoiceId' => $invoice->invoiceId,
                'documentType' => DocumentTargetDecision::DOCUMENT_VOUCHER,
            ])
        ) {
            return JobOutcome::permanentFailure(
                'Der Voucher-Preflight konnte nicht zuverlässig gespeichert werden.',
                errorCode: 'voucher_preflight_checkpoint_failed',
            );
        }

        $pdfContents = $this->pdf->render($invoice->invoiceId);
        if (!$checkpoint('pdf_validated', ['invoiceId' => $invoice->invoiceId])) {
            return JobOutcome::permanentFailure(
                'Die lokale PDF-Prüfung konnte nicht zuverlässig gespeichert werden.',
                errorCode: 'voucher_pdf_checkpoint_failed',
            );
        }

        $resolution ??= $this->resolveContact($contact, $checkpoint, false, $item);
        if (!$resolution instanceof ContactResolution) {
            return $resolution;
        }

        $result = $this->voucherExporter->export(
            $invoice,
            $resolution->contactId,
            $tax,
            $pdfContents,
            $checkpoint,
            $creditTreatmentConfirmed,
        );

        return $this->toOutcome($result, $item, 'exported', [
            'documentType' => DocumentTargetDecision::DOCUMENT_VOUCHER,
            'contactSource' => $resolution->source,
            'warnings' => implode(',', $resolution->warnings),
        ]);
    }

    /** @param array<string,mixed> $candidate @param callable(string,array<string,scalar|null>):bool $checkpoint */
    private function exportInvoice(
        object $item,
        array $candidate,
        ?object $mapping,
        InvoiceSnapshot $invoice,
        ContactData $contact,
        ?ContactResolution $resolution,
        TaxDecision $tax,
        DocumentTargetDecision $target,
        callable $checkpoint,
    ): JobOutcome {
        if ($this->invoiceExporter === null || $this->invoiceReconciliation === null || $this->invoicePdf === null) {
            return JobOutcome::permanentFailure(
                'Die Invoice-Komponenten sind in dieser Installation nicht verfügbar.',
                errorCode: 'invoice_components_unavailable',
            );
        }

        $references = self::invoiceReferences($candidate);
        if ($references === null) {
            return JobOutcome::ambiguous(
                'Der eingefrorene Invoice-Snapshot enthält keinen gültigen SevUser oder keine gültige Unity.',
                (string) ($item->checkpoint ?? 'document_type_selected'),
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                errorCode: 'invoice_reference_snapshot_missing',
            );
        }
        $invoiceExporter = $this->invoiceExporter->withReferences(
            $references['sevUserId'],
            $references['unityId'],
        );
        $invoiceReconciliation = $this->invoiceReconciliation->withReferences(
            $references['sevUserId'],
            $references['unityId'],
        );

        $mappingRemoteId = self::mappingRemoteId($mapping);
        if ($mappingRemoteId !== null) {
            $mappingType = trim((string) ($mapping->document_type ?? ''));
            if ($mappingType !== MappingRepository::DOCUMENT_TYPE_INVOICE) {
                return JobOutcome::ambiguous(
                    'Der eingefrorene Invoice-Job trifft auf einen anderen Mapping-Typ.',
                    (string) ($item->checkpoint ?? 'document_type_selected'),
                    $mappingRemoteId,
                    errorCode: 'mapping_document_type_changed',
                );
            }
            $mappingNumber = trim((string) ($mapping->document_number ?? ''));
            if ($mappingNumber !== '' && $mappingNumber !== $invoice->invoiceNumber) {
                return JobOutcome::ambiguous(
                    'Die gemappte Dokumentnummer stimmt nicht mehr mit WHMCS überein.',
                    (string) ($item->checkpoint ?? 'mapping_persisted'),
                    $mappingRemoteId,
                    errorCode: 'mapping_document_number_changed',
                );
            }
        }

        $currentCheckpoint = (string) ($item->checkpoint ?? '');
        if ($mappingRemoteId === null && self::invoicePostMappingWriteStarted($currentCheckpoint)) {
            return JobOutcome::ambiguous(
                'Nach einem späteren Invoice-Write fehlt die lokale Zuordnung. Ein erneuter Create ist gesperrt; '
                    . 'Dokument und Mapping müssen manuell abgeglichen werden.',
                $currentCheckpoint,
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                errorCode: 'invoice_mapping_missing_after_write',
            );
        }

        $writeStarted = self::invoiceCreateWriteStarted($currentCheckpoint);
        $contactRecoveryOnly = $writeStarted || $mappingRemoteId !== null;
        $contactCheckpoint = $contactRecoveryOnly
            ? static fn (): bool => true
            : $checkpoint;
        $resolution ??= $this->resolveContact(
            $contact,
            $contactCheckpoint,
            $contactRecoveryOnly,
            $item,
        );
        if (!$resolution instanceof ContactResolution) {
            return $resolution;
        }

        $eInvoiceContext = null;
        $requestedEInvoiceMode = self::truthy($candidate['historicalBackfill'] ?? false)
            ? EInvoiceEligibilityService::MODE_OFF
            : trim((string) ($candidate['targetEInvoiceMode']
                ?? $candidate['requestedEInvoiceMode']
                ?? EInvoiceEligibilityService::MODE_OFF));
        if (
            $this->eInvoiceEligibility === null
            && ($requestedEInvoiceMode !== EInvoiceEligibilityService::MODE_OFF
                || self::truthy($candidate['targetIsEInvoice'] ?? false))
        ) {
            return JobOutcome::permanentFailure(
                'Die E-Rechnungsprüfung ist in dieser Installation nicht verfügbar.',
                errorCode: 'e_invoice_components_unavailable',
                checkpoint: (string) ($item->checkpoint ?? 'document_type_selected'),
            );
        }
        if ($this->eInvoiceEligibility !== null) {
            $eInvoiceDecision = $this->eInvoiceEligibility->decide(
                $invoice,
                $contact,
                $resolution->contactId,
                $tax,
                $target,
                $candidate,
                !$writeStarted && $mappingRemoteId === null,
            );
            if ($eInvoiceDecision->isFailure()) {
                return $this->eInvoiceFailureOutcome(
                    $item,
                    $eInvoiceDecision->errorCode() ?? 'e_invoice_selection_failed',
                    $eInvoiceDecision->errorMessage() ?? 'Die E-Rechnungsprüfung ist fehlgeschlagen.',
                );
            }
            $selected = $eInvoiceDecision->valueOrNull();
            if ($selected !== null && !$selected instanceof EInvoiceContext) {
                return JobOutcome::permanentFailure(
                    'Die E-Rechnungsprüfung lieferte keinen gültigen Kontext.',
                    errorCode: 'e_invoice_context_invalid',
                    checkpoint: (string) ($item->checkpoint ?? 'document_type_selected'),
                );
            }
            $eInvoiceContext = $selected;
        }
        if (!array_key_exists('targetIsEInvoice', $candidate)) {
            $eInvoiceTarget = self::eInvoiceTargetSnapshot($eInvoiceContext, $requestedEInvoiceMode);
            if ($writeStarted) {
                if ($requestedEInvoiceMode !== EInvoiceEligibilityService::MODE_OFF) {
                    return JobOutcome::ambiguous(
                        'Nach einem Invoice-Write fehlt die eingefrorene E-Rechnungsentscheidung. '
                            . 'Mit dem aktuellen Profil darf der Altjob nicht neu interpretiert werden.',
                        (string) ($item->checkpoint ?? 'invoice_write_requested'),
                        isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                        errorCode: 'e_invoice_snapshot_missing_after_write',
                    );
                }
            } elseif (!$checkpoint('e_invoice_target_selected', $eInvoiceTarget)) {
                    return JobOutcome::permanentFailure(
                        'Die unveränderliche E-Rechnungsentscheidung konnte nicht gespeichert werden.',
                        errorCode: 'e_invoice_target_checkpoint_failed',
                        checkpoint: (string) ($item->checkpoint ?? 'document_type_selected'),
                    );
            }
            $candidate = array_replace($candidate, $eInvoiceTarget);
        }

        $remoteId = $mappingRemoteId;
        $documentResult = null;
        if ($remoteId === null && $writeStarted) {
            $reconciled = $invoiceReconciliation->reconcile(
                $invoice,
                $resolution->contactId,
                $tax,
                $contact->countryCode,
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                $checkpoint,
                $eInvoiceContext,
            );
            if ($reconciled->status !== ExportResult::SUCCEEDED && $reconciled->status !== ExportResult::SKIPPED) {
                return $this->toOutcome($reconciled, $item, 'reconciled');
            }
            $documentResult = $reconciled;
            $remoteId = $reconciled->remoteId;
        } elseif ($remoteId === null) {
            if (self::truthy($candidate['historicalBackfill'] ?? false)) {
                $duplicateRisk = $invoiceReconciliation->historicalDuplicateRisk(
                    $invoice,
                    $resolution->contactId,
                    $tax,
                    $contact->countryCode,
                );
                if ($duplicateRisk->status !== ExportResult::SUCCEEDED) {
                    return $this->toOutcome($duplicateRisk, $item, 'duplicate_guard');
                }
            }
            $created = $invoiceExporter->export(
                $invoice,
                $resolution->contactId,
                $tax,
                $contact->countryCode,
                $target,
                $checkpoint,
                $eInvoiceContext,
            );
            if ($created->status !== ExportResult::SUCCEEDED) {
                return $this->toOutcome($created, $item, 'exported');
            }
            $documentResult = $created;
            $remoteId = $created->remoteId;
        }

        if ($remoteId === null || preg_match('/^[1-9]\d*$/', $remoteId) !== 1) {
            return JobOutcome::ambiguous(
                'Die Invoice-ID konnte nach Erstellung oder Reconciliation nicht eindeutig bestimmt werden.',
                (string) ($item->checkpoint ?? 'invoice_created'),
                errorCode: 'invoice_remote_id_missing',
            );
        }

        if ($eInvoiceContext !== null) {
            $xmlSha256 = strtolower(trim((string) ($documentResult?->context['xmlSha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $xmlSha256) !== 1) {
                $xmlSha256 = self::mappingXmlHash($this->mappings->findByInvoice($invoice->invoiceId)) ?? '';
            }
            if (preg_match('/^[a-f0-9]{64}$/', $xmlSha256) !== 1) {
                return JobOutcome::ambiguous(
                    'Die native E-Rechnung wurde angelegt, aber der verifizierte XML-Hash fehlt. '
                        . 'Öffnen, Versand und PDF-Auslieferung bleiben bis zum read-only Abgleich gesperrt.',
                    (string) ($item->checkpoint ?? 'mapping_persisted'),
                    $remoteId,
                    errorCode: 'e_invoice_xml_snapshot_missing',
                );
            }
            try {
                $eInvoiceContext = $eInvoiceContext->withExpectedXmlSha256($xmlSha256);
            } catch (\InvalidArgumentException) {
                return JobOutcome::ambiguous(
                    'Der verifizierte XML-Hash der nativen E-Rechnung widerspricht dem eingefrorenen Recovery-Snapshot.',
                    (string) ($item->checkpoint ?? 'mapping_persisted'),
                    $remoteId,
                    errorCode: 'e_invoice_xml_snapshot_changed',
                );
            }
        }

        if ($target->documentAuthority === DocumentTargetResolver::AUTHORITY_WHMCS) {
            $opened = $this->ensureInvoiceOpened(
                $item,
                $invoice,
                $remoteId,
                $tax,
                $resolution->contactId,
                $contact->countryCode,
                $checkpoint,
                $invoiceExporter,
                $eInvoiceContext,
            );
            if ($opened !== null) {
                return $opened;
            }
            $this->mappings->enrichDocumentMetadata(
                $invoice->invoiceId,
                $remoteId,
                MappingRepository::DOCUMENT_TYPE_INVOICE,
                $invoice->invoiceNumber,
                new DateTimeImmutable(),
                isEInvoice: $eInvoiceContext !== null,
                xmlSha256: self::mappingXmlHash($this->mappings->findByInvoice($invoice->invoiceId)),
            );

            return JobOutcome::succeeded(
                'Die sevdesk-Invoice wurde verifiziert und geöffnet; WHMCS bleibt Dokumenthoheit.',
                $remoteId,
                [
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentAuthority' => DocumentTargetResolver::AUTHORITY_WHMCS,
                    'deliveryState' => 'not_requested',
                    ...$this->mappingEInvoiceOutcome($invoice->invoiceId),
                ],
            );
        }

        return $this->completeSevdeskAuthority(
            $item,
            $candidate,
            $invoice,
            $contact,
            $resolution->contactId,
            $tax,
            $remoteId,
            $checkpoint,
            $invoiceExporter,
            $eInvoiceContext,
        );
    }

    /** @param callable(string,array<string,scalar|null>):bool $checkpoint */
    private function ensureInvoiceOpened(
        object $item,
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $tax,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        callable $checkpoint,
        InvoiceExporter $invoiceExporter,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ?JobOutcome {
        $current = (string) ($item->checkpoint ?? '');
        if (in_array($current, ['whmcs_email_write_requested', 'whmcs_email_handed_off'], true)) {
            return null;
        }
        if ($current === 'invoice_opened' && $eInvoiceContext === null) {
            return null;
        }

        $result = in_array($current, ['invoice_open_write_requested', 'invoice_opened'], true)
            ? $invoiceExporter->reconcileOpened(
                $invoice,
                $remoteId,
                $tax,
                $sevdeskContactId,
                $deliveryCountryCode,
                $checkpoint,
                $eInvoiceContext,
            )
            : $invoiceExporter->openForWhmcsAuthority(
                $invoice,
                $remoteId,
                $tax,
                $sevdeskContactId,
                $deliveryCountryCode,
                $checkpoint,
                $eInvoiceContext,
            );
        if ($result->status !== ExportResult::SUCCEEDED) {
            return $this->toOutcome($result, $item, 'exported');
        }

        return null;
    }

    /** @param array<string,mixed> $candidate @param callable(string,array<string,scalar|null>):bool $checkpoint */
    private function completeSevdeskAuthority(
        object $item,
        array $candidate,
        InvoiceSnapshot $invoice,
        ContactData $contact,
        string $sevdeskContactId,
        TaxDecision $tax,
        string $remoteId,
        callable $checkpoint,
        InvoiceExporter $invoiceExporter,
        ?EInvoiceContext $eInvoiceContext = null,
    ): JobOutcome {
        $deliveryRequested = self::truthy($candidate['delivery_requested'] ?? false);
        $channel = (string) ($candidate['targetDeliveryChannel']
            ?? $this->config->get('invoice_delivery_channel', 'sevdesk'));
        if (!in_array($channel, ['sevdesk', 'whmcs_template'], true)) {
            return JobOutcome::permanentFailure(
                'Der eingefrorene Invoice-Versandkanal ist ungültig.',
                errorCode: 'invalid_invoice_delivery_channel',
                checkpoint: (string) ($item->checkpoint ?? 'mapping_persisted'),
            );
        }

        $current = (string) ($item->checkpoint ?? '');
        if ($current === 'whmcs_email_write_requested' && !self::truthy($candidate['emailRetryConfirmed'] ?? false)) {
            return JobOutcome::ambiguous(
                'Die Übergabe an WHMCS kann nicht beweiskräftig gelesen werden. Eine Wiederholung braucht eine ausdrückliche Doppelversand-Warnbestätigung.',
                'whmcs_email_write_requested',
                $remoteId,
                errorCode: 'whmcs_email_outcome_unknown',
            );
        }

        $template = '';
        if ($channel === 'whmcs_template' && $deliveryRequested) {
            $template = trim((string) $this->config->get('whmcs_invoice_email_template', ''));
            if (!$this->whmcs->isActiveCustomInvoiceTemplate($template)) {
                return JobOutcome::permanentFailure(
                    'Die konfigurierte benutzerdefinierte WHMCS-Invoice-Mailvorlage ist nicht aktiv.',
                    errorCode: 'invoice_email_template_unavailable',
                    checkpoint: $current !== '' ? $current : 'mapping_persisted',
                );
            }
        }

        if ($channel === 'sevdesk' && $deliveryRequested) {
            if (
                $current === 'invoice_delivery_write_requested'
                || ($current === 'invoice_delivered' && $eInvoiceContext !== null)
            ) {
                $delivered = $invoiceExporter->reconcileDelivered(
                    $invoice,
                    $remoteId,
                    $tax,
                    $sevdeskContactId,
                    $contact->countryCode,
                    $checkpoint,
                    $eInvoiceContext,
                );
            } elseif ($current === 'invoice_delivered' && $eInvoiceContext === null) {
                $delivered = ExportResult::succeeded($invoice->invoiceId, $remoteId);
            } else {
                $delivered = $invoiceExporter->deliverViaSevdesk(
                    $invoice,
                    $remoteId,
                    $tax,
                    $sevdeskContactId,
                    $contact->countryCode,
                    $contact->email,
                    $this->deliveryText('sevdesk_email_subject', $invoice, $contact),
                    $this->deliveryText('sevdesk_email_body', $invoice, $contact),
                    $checkpoint,
                    $eInvoiceContext,
                );
            }
            if ($delivered->status !== ExportResult::SUCCEEDED) {
                return $this->toOutcome($delivered, $item, 'exported');
            }

            $pdf = $this->invoicePdf?->fetch($remoteId);
            if (!is_array($pdf)) {
                throw new \RuntimeException('The final sevdesk Invoice PDF is unavailable.');
            }
            $this->mappings->enrichDocumentMetadata(
                $invoice->invoiceId,
                $remoteId,
                MappingRepository::DOCUMENT_TYPE_INVOICE,
                $invoice->invoiceNumber,
                new DateTimeImmutable(),
                new DateTimeImmutable(),
                $pdf['sha256'],
                $eInvoiceContext !== null,
                self::mappingXmlHash($this->mappings->findByInvoice($invoice->invoiceId)),
            );

            return JobOutcome::succeeded(
                'Die sevdesk-Invoice wurde erstellt, verifiziert und über sevdesk versendet.',
                $remoteId,
                [
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentAuthority' => DocumentTargetResolver::AUTHORITY_SEVDESK,
                    'deliveryState' => 'delivered',
                    ...$this->mappingEInvoiceOutcome($invoice->invoiceId),
                ],
            );
        }

        $opened = $this->ensureInvoiceOpened(
            $item,
            $invoice,
            $remoteId,
            $tax,
            $sevdeskContactId,
            $contact->countryCode,
            $checkpoint,
            $invoiceExporter,
            $eInvoiceContext,
        );
        if ($opened !== null) {
            return $opened;
        }
        $pdf = $this->invoicePdf?->fetch($remoteId);
        if (!is_array($pdf)) {
            throw new \RuntimeException('The final sevdesk Invoice PDF is unavailable.');
        }
        $this->mappings->enrichDocumentMetadata(
            $invoice->invoiceId,
            $remoteId,
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            $invoice->invoiceNumber,
            new DateTimeImmutable(),
            null,
            $pdf['sha256'],
            $eInvoiceContext !== null,
            self::mappingXmlHash($this->mappings->findByInvoice($invoice->invoiceId)),
        );

        if (!$deliveryRequested) {
            return JobOutcome::succeeded(
                'Die sevdesk-Invoice ist downloadbereit; für diesen Bulk-/Historienlauf wurde kein Versand ausgelöst.',
                $remoteId,
                [
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentAuthority' => DocumentTargetResolver::AUTHORITY_SEVDESK,
                    'deliveryState' => 'ready_not_delivered',
                    ...$this->mappingEInvoiceOutcome($invoice->invoiceId),
                ],
            );
        }

        $token = EmailAttachmentContext::register(
            $invoice->invoiceId,
            $template,
            $pdf['filename'],
            $pdf['contents'],
        );
        if (
            !$checkpoint('whmcs_email_write_requested', [
            'invoiceId' => $invoice->invoiceId,
            'remoteId' => $remoteId,
            'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
            'emailRetryConfirmed' => false,
            ])
        ) {
            EmailAttachmentContext::discard($token);

            return JobOutcome::permanentFailure(
                'Der WHMCS-Mail-Checkpoint konnte nicht gespeichert werden.',
                errorCode: 'checkpoint_persist_failed',
                checkpoint: (string) ($item->checkpoint ?? 'invoice_opened'),
            );
        }

        try {
            $this->whmcs->sendInvoiceEmail(
                $invoice->invoiceId,
                $template,
                ['sevdesk_attachment_token' => $token],
            );
        } catch (Throwable) {
            EmailAttachmentContext::discard($token);

            return JobOutcome::ambiguous(
                'Der Ausgang der WHMCS-Mailübergabe ist unklar. Nicht ohne Warnbestätigung erneut senden.',
                'whmcs_email_write_requested',
                $remoteId,
                errorCode: 'whmcs_email_handoff_ambiguous',
            );
        }
        EmailAttachmentContext::discard($token);

        if (
            !$checkpoint('whmcs_email_handed_off', [
            'invoiceId' => $invoice->invoiceId,
            'remoteId' => $remoteId,
            'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
            ])
        ) {
            return JobOutcome::ambiguous(
                'WHMCS hat die Mail angenommen, aber der Abschluss-Checkpoint konnte nicht gespeichert werden.',
                'whmcs_email_write_requested',
                $remoteId,
                errorCode: 'whmcs_email_checkpoint_ambiguous',
            );
        }

        $this->mappings->enrichDocumentMetadata(
            $invoice->invoiceId,
            $remoteId,
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            $invoice->invoiceNumber,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            $pdf['sha256'],
            $eInvoiceContext !== null,
            self::mappingXmlHash($this->mappings->findByInvoice($invoice->invoiceId)),
        );

        return JobOutcome::succeeded(
            'Die sevdesk-Invoice-PDF wurde an den konfigurierten WHMCS-Mailprovider übergeben.',
            $remoteId,
            [
                'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                'documentAuthority' => DocumentTargetResolver::AUTHORITY_SEVDESK,
                'deliveryState' => 'handed_off',
                ...$this->mappingEInvoiceOutcome($invoice->invoiceId),
            ],
        );
    }

    /** @param array<string,mixed> $candidate */
    private function finishHandedOffRecovery(
        array $candidate,
        int $invoiceId,
        string $mappingNumber,
        ?string $remoteId,
        string $mappingType,
    ): JobOutcome {
        $selectedNumber = trim((string) ($candidate['selectedInvoiceNumber'] ?? ''));
        if (
            $remoteId === null
            || $mappingType !== MappingRepository::DOCUMENT_TYPE_INVOICE
            || !self::truthy($candidate['targetAllowed'] ?? false)
            || (string) ($candidate['targetDocumentType'] ?? '') !== DocumentTargetDecision::DOCUMENT_INVOICE
            || (string) ($candidate['targetDocumentAuthority'] ?? '') !== DocumentTargetResolver::AUTHORITY_SEVDESK
            || (string) ($candidate['targetDeliveryChannel'] ?? '') !== 'whmcs_template'
            || !self::truthy($candidate['delivery_requested'] ?? false)
            || $selectedNumber === ''
            || $mappingNumber !== $selectedNumber
        ) {
            return JobOutcome::ambiguous(
                'Der gespeicherte Mail-Handoff passt nicht zum typisierten Mapping und eingefrorenen Versandziel.',
                'whmcs_email_handed_off',
                $remoteId,
                errorCode: 'whmcs_email_checkpoint_context_mismatch',
            );
        }

        // The provider handoff is already proven by the durable checkpoint.
        // Recovery may only finish local metadata; another SendEmail call
        // could duplicate the customer message.
        $now = new DateTimeImmutable();
        $this->mappings->enrichDocumentMetadata(
            $invoiceId,
            $remoteId,
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            $selectedNumber,
            $now,
            $now,
        );

        return JobOutcome::succeeded(
            'Die bereits bestätigte WHMCS-Mailübergabe wurde lokal abgeschlossen.',
            $remoteId,
            [
                'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                'documentAuthority' => DocumentTargetResolver::AUTHORITY_SEVDESK,
                'deliveryState' => 'handed_off',
                ...$this->mappingEInvoiceOutcome($invoiceId),
            ],
        );
    }

    /** @return array<string,scalar|null> */
    private static function eInvoiceTargetSnapshot(
        ?EInvoiceContext $context,
        string $requestedMode,
    ): array {
        if ($context === null) {
            return [
                'targetIsEInvoice' => false,
                'targetEInvoiceMode' => $requestedMode,
                'targetEInvoiceContactId' => null,
                'targetEInvoicePaymentMethodId' => null,
                'targetEInvoiceUnityId' => null,
                'targetEInvoiceCountryId' => null,
                'targetEInvoiceAddressHash' => null,
            ];
        }

        return [
            'targetIsEInvoice' => true,
            'targetEInvoiceMode' => EInvoiceEligibilityService::MODE_ZUGFERD_DOMESTIC_B2B,
            'targetEInvoiceContactId' => $context->contactId,
            'targetEInvoicePaymentMethodId' => $context->paymentMethodId,
            'targetEInvoiceUnityId' => $context->unityId,
            'targetEInvoiceCountryId' => $context->countryId,
            'targetEInvoiceAddressHash' => $context->expectedAddressHash,
        ];
    }

    private function eInvoiceFailureOutcome(object $item, string $code, string $message): JobOutcome
    {
        $checkpoint = (string) ($item->checkpoint ?? 'document_type_selected');
        if ($this->documentWriteStarted($checkpoint) || JobRepository::isRiskyCheckpoint($checkpoint)) {
            return JobOutcome::ambiguous(
                $message,
                $checkpoint,
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                errorCode: $code,
            );
        }

        return JobOutcome::permanentFailure($message, errorCode: $code, checkpoint: $checkpoint);
    }

    /** @return array{isEInvoice:bool,xmlSha256:?string} */
    private function mappingEInvoiceOutcome(int $invoiceId): array
    {
        $mapping = $this->mappings->findByInvoice($invoiceId);

        return [
            'isEInvoice' => $mapping !== null && self::truthy($mapping->is_e_invoice ?? false),
            'xmlSha256' => self::mappingXmlHash($mapping),
        ];
    }

    private static function mappingXmlHash(?object $mapping): ?string
    {
        $hash = strtolower(trim((string) ($mapping->xml_sha256 ?? '')));

        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }

    /** @param callable(string,array<string,scalar|null>):bool $checkpoint */
    private function resolveContact(
        ContactData $contact,
        callable $checkpoint,
        bool $recoveryOnly,
        object $item,
    ): ContactResolution|JobOutcome {
        $contactResult = $this->contacts->resolve($contact, $checkpoint, $recoveryOnly);
        if ($contactResult->isFailure()) {
            $code = $contactResult->errorCode() ?? 'contact_failed';
            $message = $contactResult->errorMessage() ?? 'Der sevdesk-Kontakt konnte nicht aufgelöst werden.';

            return $recoveryOnly
                ? $this->contactRecoveryFailureToOutcome($code, $message, $contactResult->context(), $item)
                : $this->failureResultToOutcome($code, $message, $contactResult->context(), $item);
        }

        $resolution = $contactResult->value();
        if (!$resolution instanceof ContactResolution) {
            return JobOutcome::permanentFailure(
                'Die Kontaktauflösung lieferte ein ungültiges Ergebnis.',
                errorCode: 'invalid_contact_resolution',
            );
        }

        return $resolution;
    }

    /** @param array<string,mixed> $candidate */
    private function taxDecision(
        int $invoiceId,
        InvoiceSnapshot $invoice,
        ContactData $contact,
        object $item,
        array $candidate,
    ): TaxDecision {
        $arguments = [
            $contact->countryCode,
            $contact->taxExempt,
            $contact->vatNumber,
            $this->config->bool('smallBusinessOwner'),
            $this->whmcs->isAddFundsInvoice($invoiceId),
            $invoice->lineItems,
            $contact->isOrganisation(),
        ];
        $mode = self::documentContextValue(
            $candidate,
            'targetExportMode',
            'requestedExportMode',
            (string) $this->config->get('export_mode', DocumentTargetResolver::MODE_VOUCHER_ONLY),
        );
        $ossProfile = self::documentContextValue(
            $candidate,
            'targetOssProfile',
            'requestedOssProfile',
            (string) $this->config->get('oss_profile', DocumentTargetResolver::OSS_BLOCKED),
        );
        $exportDocument = (string) ($item->action ?? '') === 'export_document';
        $euB2cMode = self::documentEuB2cMode(
            $candidate,
            $exportDocument
                ? null
                : (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED),
        );

        if (
            $exportDocument
            && in_array($mode, [
                DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
                DocumentTargetResolver::MODE_INVOICE_ONLY,
            ], true)
        ) {
            $invoicePolicy = new TaxPolicy(
                $this->config->taxProfiles(),
                $euB2cMode,
                null,
                $ossProfile,
            );
            $invoiceTax = $invoicePolicy->decideInvoice(...$arguments);
            if (
                $mode === DocumentTargetResolver::MODE_INVOICE_ONLY
                || ($invoiceTax->allowed && $invoiceTax->taxRuleId === '19')
                || (!$invoiceTax->allowed && $invoiceTax->profile === 'eu_b2c')
            ) {
                return $invoiceTax;
            }
        }

        return ($this->taxPolicy)()->decide(...$arguments);
    }

    /** @param array<string,mixed> $candidate */
    private static function documentContextValue(
        array $candidate,
        string $frozenKey,
        string $requestedKey,
        string $fallback,
    ): string {
        foreach ([$frozenKey, $requestedKey] as $key) {
            if (is_string($candidate[$key] ?? null) && trim((string) $candidate[$key]) !== '') {
                return trim((string) $candidate[$key]);
            }
        }

        return $fallback;
    }

    /** @param array<string,mixed> $candidate */
    private static function documentEuB2cMode(array $candidate, ?string $fallback = null): string
    {
        $frozenKeys = [
            'targetAllowed',
            'targetDocumentType',
            'targetDocumentAuthority',
            'targetExportMode',
            'targetOssProfile',
            'targetEuB2cMode',
        ];
        $requestedKeys = [
            'requestedExportMode',
            'requestedDocumentAuthority',
            'requestedOssProfile',
            'requestedEuB2cMode',
        ];
        $hasFrozenContext = array_intersect($frozenKeys, array_keys($candidate)) !== [];
        $hasRequestedContext = array_intersect($requestedKeys, array_keys($candidate)) !== [];
        $value = match (true) {
            $hasFrozenContext => $candidate['targetEuB2cMode'] ?? null,
            $hasRequestedContext => $candidate['requestedEuB2cMode'] ?? null,
            default => $fallback,
        };
        if (
            !is_string($value)
            || !in_array(trim($value), [TaxPolicy::EU_B2C_BLOCKED, TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED], true)
        ) {
            throw new \InvalidArgumentException('The document EU B2C context is incomplete or invalid.');
        }

        return trim($value);
    }

    /** @param array<string,mixed> $candidate @param callable(string,array<string,scalar|null>):bool $checkpoint */
    private function frozenOrNewTarget(
        object $item,
        array $candidate,
        TaxDecision $tax,
        string $invoiceStatus,
        string $finalInvoiceNumber,
        callable $checkpoint,
    ): DocumentTargetDecision|JobOutcome {
        if (array_key_exists('targetAllowed', $candidate)) {
            try {
                $target = DocumentTargetDecision::fromArray([
                    'allowed' => $candidate['targetAllowed'],
                    'documentType' => $candidate['targetDocumentType'] ?? null,
                    'documentAuthority' => $candidate['targetDocumentAuthority'] ?? '',
                    'exportMode' => $candidate['targetExportMode'] ?? '',
                    'ossProfile' => $candidate['targetOssProfile'] ?? '',
                    'taxRuleId' => $candidate['targetTaxRuleId'] ?? null,
                    'code' => $candidate['targetCode'] ?? '',
                    'message' => $candidate['targetMessage'] ?? '',
                ]);
            } catch (\InvalidArgumentException) {
                return JobOutcome::ambiguous(
                    'Das eingefrorene Dokumentziel ist beschädigt und darf nicht neu entschieden werden.',
                    (string) ($item->checkpoint ?? 'document_type_selected'),
                    errorCode: 'frozen_document_target_invalid',
                );
            }

            if (
                $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                && (string) ($candidate['selectedInvoiceNumber'] ?? '') !== $finalInvoiceNumber
            ) {
                return JobOutcome::ambiguous(
                    'Die finale WHMCS-Rechnungsnummer hat sich nach der Zielauswahl geändert.',
                    (string) ($item->checkpoint ?? 'document_type_selected'),
                    errorCode: 'frozen_invoice_number_changed',
                );
            }

            if ($target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE) {
                $references = self::invoiceReferences($candidate);
                if ($references === null) {
                    $currentCheckpoint = (string) ($item->checkpoint ?? '');
                    if ($currentCheckpoint !== 'document_type_selected') {
                        return JobOutcome::ambiguous(
                            'Der begonnene Invoice-Job enthält keinen eingefrorenen SevUser-/Unity-Snapshot. '
                                . 'Recovery mit den aktuellen Einstellungen wäre nicht beweiskräftig.',
                            $currentCheckpoint !== '' ? $currentCheckpoint : 'document_type_selected',
                            isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                            errorCode: 'invoice_reference_snapshot_missing',
                        );
                    }

                    $references = self::configuredInvoiceReferences($this->config);
                    if (
                        $references === null
                        || !$checkpoint('document_type_selected', [
                            'targetSevUserId' => $references['sevUserId'],
                            'targetUnityId' => $references['unityId'],
                        ])
                    ) {
                        return JobOutcome::permanentFailure(
                            'SevUser und Unity konnten vor dem ersten Invoice-Write nicht sicher eingefroren werden.',
                            errorCode: 'invoice_reference_snapshot_failed',
                            checkpoint: 'document_type_selected',
                        );
                    }
                }
            }

            return $target;
        }

        $action = (string) ($item->action ?? '');
        if (in_array($action, ['export_voucher', 'reconcile_voucher'], true)) {
            $target = $tax->allowed && $tax->taxRuleId !== null
                ? DocumentTargetDecision::select(
                    DocumentTargetDecision::DOCUMENT_VOUCHER,
                    DocumentTargetResolver::AUTHORITY_WHMCS,
                    DocumentTargetResolver::MODE_VOUCHER_ONLY,
                    DocumentTargetResolver::OSS_BLOCKED,
                    $tax->taxRuleId,
                    'legacy_voucher_job',
                    'The queued legacy action remains on the Voucher path.',
                )
                : DocumentTargetDecision::block(
                    DocumentTargetResolver::AUTHORITY_WHMCS,
                    DocumentTargetResolver::MODE_VOUCHER_ONLY,
                    DocumentTargetResolver::OSS_BLOCKED,
                    $tax->taxRuleId,
                    $tax->code,
                    $tax->message,
                );
        } else {
            try {
                $resolver = self::requestedTargetResolver($candidate) ?? ($this->targetResolver)();
            } catch (\InvalidArgumentException $exception) {
                $profileConflict = $exception->getMessage() === 'conflicting_eu_b2c_profiles';
                return JobOutcome::permanentFailure(
                    $profileConflict
                        ? 'Die Rule-19-OSS-Freigabe widerspricht der alten EU-B2C-Inlandsfreigabe.'
                        : 'Der beim Einreihen gespeicherte Modus-/Hoheitskontext ist unvollständig oder ungültig.',
                    errorCode: $profileConflict
                        ? 'conflicting_eu_b2c_profiles'
                        : 'requested_document_context_invalid',
                );
            }
            $target = $resolver->resolve(
                $tax,
                $invoiceStatus === 'Paid',
                $finalInvoiceNumber !== '',
            );
            if (
                $target->allowed
                && $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                && !$this->config->bool('invoice_canary_confirmed')
            ) {
                $target = DocumentTargetDecision::block(
                    $target->documentAuthority,
                    $target->exportMode,
                    $target->ossProfile,
                    $target->taxRuleId,
                    'invoice_canary_not_confirmed',
                    'The documented sevdesk tenant canary has not been explicitly confirmed.',
                );
            }
        }

        if (
            !$target->allowed
            && $target->code === 'invoice_requires_payment'
            && $target->exportMode === DocumentTargetResolver::MODE_INVOICE_FOR_OSS
        ) {
            if (!$checkpoint('invoice_payment_pending', ['invoicePaymentPending' => true])) {
                return JobOutcome::permanentFailure(
                    'Der wartende Invoice-Status konnte nicht gespeichert werden.',
                    errorCode: 'invoice_payment_pending_checkpoint_failed',
                );
            }

            return $target;
        }

        $snapshot = $target->toArray();
        $invoiceReferences = $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
            ? self::configuredInvoiceReferences($this->config)
            : null;
        if ($target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE && $invoiceReferences === null) {
            return JobOutcome::permanentFailure(
                'Invoice-Ziele benötigen vor dem ersten Remote-Write einen gültigen SevUser und eine gültige Unity.',
                errorCode: 'invoice_reference_snapshot_failed',
            );
        }
        if (
            !$checkpoint('document_type_selected', [
            'targetAllowed' => $snapshot['allowed'],
            'targetDocumentType' => $snapshot['documentType'],
            'targetDocumentAuthority' => $snapshot['documentAuthority'],
            'targetExportMode' => $snapshot['exportMode'],
            'targetOssProfile' => $snapshot['ossProfile'],
            'targetEuB2cMode' => self::documentEuB2cMode(
                $candidate,
                (string) ($item->action ?? '') === 'export_document'
                    ? null
                    : (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED),
            ),
            'targetTaxRuleId' => $snapshot['taxRuleId'],
            'targetCode' => $snapshot['code'],
            'targetMessage' => $snapshot['message'],
            'selectedInvoiceNumber' => $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                ? $finalInvoiceNumber
                : null,
            'targetDeliveryChannel' => $target->documentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK
                ? (string) ($candidate['requestedDeliveryChannel']
                    ?? $this->config->get('invoice_delivery_channel', 'sevdesk'))
                : null,
            'targetSevUserId' => $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                ? $invoiceReferences['sevUserId']
                : null,
            'targetUnityId' => $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                ? $invoiceReferences['unityId']
                : null,
            'targetEInvoiceMode' => $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                ? (self::truthy($candidate['historicalBackfill'] ?? false)
                    ? EInvoiceEligibilityService::MODE_OFF
                    : (string) ($candidate['requestedEInvoiceMode'] ?? EInvoiceEligibilityService::MODE_OFF))
                : EInvoiceEligibilityService::MODE_OFF,
            ])
        ) {
            return JobOutcome::permanentFailure(
                'Das unveränderliche Dokumentziel konnte nicht gespeichert werden.',
                errorCode: 'document_target_checkpoint_failed',
            );
        }

        return $target;
    }

    /** @param array<string,mixed> $candidate */
    private function targetRuntimePreflight(
        DocumentTargetDecision $target,
        array $candidate,
        object $item,
    ): ?JobOutcome {
        if ((string) ($item->checkpoint ?? '') === 'whmcs_email_handed_off') {
            return null;
        }

        if (
            !$this->documentWriteStarted((string) ($item->checkpoint ?? ''))
            && !$this->frozenContextMatchesCurrentConfiguration($target, $candidate)
        ) {
            return JobOutcome::permanentFailure(
                'Der eingefrorene Exportkontext gehört zu einer früheren Modulkonfiguration. '
                    . 'Dieser sichere Vor-Write-Fall darf nur nach neuer Vorschau als export_document eingereiht werden.',
                errorCode: 'stale_export_context_requeue_required',
                checkpoint: (string) ($item->checkpoint ?? 'document_type_selected'),
            );
        }

        $legacyEuB2c = (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED);
        $targetLegacyEuB2c = self::documentEuB2cMode(
            $candidate,
            (string) ($item->action ?? '') === 'export_document' ? null : $legacyEuB2c,
        );
        $currentOssProfile = (string) $this->config->get(
            'oss_profile',
            DocumentTargetResolver::OSS_BLOCKED,
        );
        if (
            in_array(TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED, [$legacyEuB2c, $targetLegacyEuB2c], true)
            && (
                $currentOssProfile === DocumentTargetResolver::OSS_RULE_19_CONFIRMED
                || $target->ossProfile === DocumentTargetResolver::OSS_RULE_19_CONFIRMED
            )
        ) {
            return $this->runtimePreflightFailure(
                $item,
                'Die Rule-19-OSS-Freigabe widerspricht der alten EU-B2C-Inlandsfreigabe. Der Worker bleibt gesperrt.',
                'conflicting_eu_b2c_profiles',
            );
        }
        $deliveryChannel = $target->documentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK
            ? trim((string) ($candidate['targetDeliveryChannel'] ?? ''))
            : null;
        if (
            !DocumentTargetResolver::contextValuesAreValid(
                $target->exportMode,
                $target->documentAuthority,
                $target->ossProfile,
                $targetLegacyEuB2c,
                $deliveryChannel,
            )
        ) {
            return $this->runtimePreflightFailure(
                $item,
                'Der eingefrorene Modus-/Hoheitskontext ist ungültig. Der Worker bleibt gesperrt.',
                'requested_document_context_invalid',
            );
        }
        if ($target->documentType !== DocumentTargetDecision::DOCUMENT_INVOICE) {
            return null;
        }
        if (
            !$this->config->bool('invoice_canary_confirmed')
            || self::invoiceReferences($candidate) === null
        ) {
            return $this->runtimePreflightFailure(
                $item,
                'Canary, SevUser und Standard-Unity müssen unmittelbar vor jedem Invoice-Schreibpfad gültig sein.',
                'invoice_runtime_prerequisites_missing',
            );
        }

        $eInvoiceMode = trim((string) ($candidate['targetEInvoiceMode']
            ?? $candidate['requestedEInvoiceMode']
            ?? EInvoiceEligibilityService::MODE_OFF));
        $eInvoiceDecisionPending = !array_key_exists('targetIsEInvoice', $candidate);
        $eInvoiceSelected = self::truthy($candidate['targetIsEInvoice'] ?? false);
        if (
            !self::truthy($candidate['historicalBackfill'] ?? false)
            && $eInvoiceMode === EInvoiceEligibilityService::MODE_ZUGFERD_DOMESTIC_B2B
            && ($eInvoiceDecisionPending || $eInvoiceSelected)
            && (
                $target->exportMode !== DocumentTargetResolver::MODE_INVOICE_ONLY
                || $target->documentAuthority !== DocumentTargetResolver::AUTHORITY_SEVDESK
                || !$this->config->bool('e_invoice_canary_confirmed')
                || !self::truthy($candidate['requestedEInvoiceCanaryConfirmed'] ?? false)
            )
        ) {
            return $this->runtimePreflightFailure(
                $item,
                'Das native E-Rechnungsprofil ist für diesen eingefrorenen Zielkontext nicht vollständig freigegeben.',
                'e_invoice_runtime_prerequisites_missing',
            );
        }

        if ($target->documentAuthority !== DocumentTargetResolver::AUTHORITY_SEVDESK) {
            return null;
        }

        $channel = $deliveryChannel ?? '';
        $authorityReady = $this->whmcs->proformaInvoicingEnabled()
            && $this->whmcs->themeAdapterManifestInstalled()
            && $this->config->bool('theme_adapter_confirmed')
            && in_array($channel, ['sevdesk', 'whmcs_template'], true);
        if ($authorityReady && $channel === 'whmcs_template') {
            $authorityReady = $this->whmcs->isActiveCustomInvoiceTemplate(
                (string) $this->config->get('whmcs_invoice_email_template', ''),
            );
        }
        if ($authorityReady && $channel === 'sevdesk') {
            $authorityReady = self::validDeliveryText(
                (string) $this->config->get('sevdesk_email_subject', ''),
                (string) $this->config->get('sevdesk_email_body', ''),
            );
        }
        if (!$authorityReady) {
            return $this->runtimePreflightFailure(
                $item,
                'Proforma, Theme-Adapter und eingefrorener Versandkanal wurden vor dem Invoice-Write nicht bestätigt.',
                'sevdesk_authority_prerequisites_missing',
            );
        }

        return null;
    }

    /** @param array<string,mixed> $candidate */
    private function frozenContextMatchesCurrentConfiguration(
        DocumentTargetDecision $target,
        array $candidate,
    ): bool {
        $currentAuthority = (string) $this->config->get(
            'document_authority',
            DocumentTargetResolver::AUTHORITY_WHMCS,
        );
        $currentDeliveryChannel = $currentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK
            ? (string) $this->config->get('invoice_delivery_channel', 'sevdesk')
            : null;
        $targetDeliveryChannel = $target->documentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK
            ? trim((string) ($candidate['targetDeliveryChannel'] ?? ''))
            : null;
        $targetEInvoiceMode = trim((string) ($candidate['targetEInvoiceMode']
            ?? $candidate['requestedEInvoiceMode']
            ?? 'off'));

        return $target->exportMode === (string) $this->config->get(
            'export_mode',
            DocumentTargetResolver::MODE_VOUCHER_ONLY,
        )
            && $target->documentAuthority === $currentAuthority
            && $target->ossProfile === (string) $this->config->get(
                'oss_profile',
                DocumentTargetResolver::OSS_BLOCKED,
            )
            && self::documentEuB2cMode($candidate, TaxPolicy::EU_B2C_BLOCKED)
                === (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED)
            && $targetDeliveryChannel === $currentDeliveryChannel
            && (
                self::truthy($candidate['historicalBackfill'] ?? false)
                || $targetEInvoiceMode === (string) $this->config->get('e_invoice_mode', 'off')
            );
    }

    private function documentWriteStarted(string $checkpoint): bool
    {
        return in_array($checkpoint, [
            'voucher_write_requested',
            'voucher_created',
            'mapping_persisted',
            'invoice_write_requested',
            'invoice_created',
            'invoice_xml_verified',
            'invoice_open_write_requested',
            'invoice_opened',
            'invoice_delivery_write_requested',
            'invoice_delivered',
            'whmcs_email_write_requested',
            'whmcs_email_handed_off',
        ], true);
    }

    private function runtimePreflightFailure(object $item, string $message, string $code): JobOutcome
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

    private static function validDeliveryText(string $subject, string $body): bool
    {
        if ($subject === '' || $body === '' || mb_strlen($subject) > 200 || mb_strlen($body) > 5000) {
            return false;
        }
        foreach ([$subject, $body] as $value) {
            preg_match_all('/\{[A-Za-z0-9_]+\}/', $value, $matches);
            foreach ($matches[0] as $placeholder) {
                if (!in_array($placeholder, ['{invoice_number}', '{company_name}'], true)) {
                    return false;
                }
            }
            $withoutAllowed = str_replace(['{invoice_number}', '{company_name}'], '', $value);
            if (str_contains($withoutAllowed, '{') || str_contains($withoutAllowed, '}')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $candidate
     * @return null|array{sevUserId:string,unityId:string}
     */
    private static function invoiceReferences(array $candidate): ?array
    {
        $sevUserId = trim((string) ($candidate['targetSevUserId'] ?? ''));
        $unityId = trim((string) ($candidate['targetUnityId'] ?? ''));
        if (
            preg_match('/^[1-9]\d*$/', $sevUserId) !== 1
            || preg_match('/^[1-9]\d*$/', $unityId) !== 1
        ) {
            return null;
        }

        return ['sevUserId' => $sevUserId, 'unityId' => $unityId];
    }

    /** @return null|array{sevUserId:string,unityId:string} */
    private static function configuredInvoiceReferences(Config $config): ?array
    {
        return self::invoiceReferences([
            'targetSevUserId' => $config->get('invoice_sev_user_id', ''),
            'targetUnityId' => $config->get('invoice_unity_id', ''),
        ]);
    }

    /** @param array<string,mixed> $candidate */
    private static function requestedTargetResolver(array $candidate): ?DocumentTargetResolver
    {
        $keys = [
            'requestedExportMode',
            'requestedDocumentAuthority',
            'requestedOssProfile',
            'requestedEuB2cMode',
        ];
        $hasSnapshot = false;
        foreach ($keys as $key) {
            $hasSnapshot = $hasSnapshot || array_key_exists($key, $candidate);
        }
        if (!$hasSnapshot) {
            return null;
        }

        foreach ($keys as $key) {
            if (!is_string($candidate[$key] ?? null) || trim((string) $candidate[$key]) === '') {
                throw new \InvalidArgumentException('Incomplete requested document context.');
            }
        }
        $mode = trim((string) $candidate['requestedExportMode']);
        $authority = trim((string) $candidate['requestedDocumentAuthority']);
        $ossProfile = trim((string) $candidate['requestedOssProfile']);
        $euB2cMode = trim((string) $candidate['requestedEuB2cMode']);
        $deliveryChannel = $authority === DocumentTargetResolver::AUTHORITY_SEVDESK
            ? trim((string) ($candidate['requestedDeliveryChannel'] ?? ''))
            : null;
        $validationError = DocumentTargetResolver::contextValidationError(
            $mode,
            $authority,
            $ossProfile,
            $euB2cMode,
            $deliveryChannel,
        );
        if ($validationError !== null) {
            throw new \InvalidArgumentException($validationError);
        }

        return new DocumentTargetResolver(
            $mode,
            $authority,
            $ossProfile,
        );
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
                array_filter(
                    array_merge($result->context, $extra, ['resultCode' => $result->code]),
                    static fn (mixed $value): bool => $value !== '',
                ),
            );
        }
        if ($result->status === ExportResult::SKIPPED) {
            return JobOutcome::skipped('Die Rechnung war bereits zugeordnet.', $result->remoteId);
        }
        if ($result->status === ExportResult::AMBIGUOUS) {
            $httpStatus = self::nullableInt($result->context['httpStatus'] ?? null);
            if (in_array($httpStatus, [401, 403], true)) {
                $this->tripAuthenticationAlarm((int) ($item->job_id ?? 0));
            }

            return JobOutcome::ambiguous(
                self::messageFor($result->code, $result->message),
                (string) ($item->checkpoint ?? 'write_requested'),
                $result->remoteId,
                $httpStatus,
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
        $authenticationFailure = in_array($httpStatus, [401, 403], true)
            || $code === 'api_authentication_failed';
        if ($authenticationFailure) {
            $this->tripAuthenticationAlarm((int) ($item->job_id ?? 0));
        }

        if (self::truthy($context['outcomeUnknown'] ?? $context['ambiguous'] ?? false) || str_ends_with($code, '_ambiguous')) {
            return JobOutcome::ambiguous(
                self::messageFor($code, $message),
                (string) ($item->checkpoint ?? 'write_requested'),
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                $httpStatus,
                $uuid,
                $code,
                $context,
            );
        }

        $checkpoint = (string) ($item->checkpoint ?? '');
        $definiteWriteRejected = self::truthy($context['definiteWriteRejected'] ?? false);
        $safeCheckpoint = $definiteWriteRejected
            ? self::checkpointBeforeRejectedWrite($checkpoint)
            : null;
        $resumeCheckpoint = $safeCheckpoint ?? ($checkpoint !== '' ? $checkpoint : 'finished');
        if ($authenticationFailure) {
            return JobOutcome::retry(
                'sevdesk hat die Authentifizierung abgelehnt. Der Job und die automatische Synchronisation wurden pausiert.',
                300,
                $httpStatus,
                $uuid,
                'api_authentication_failed',
                $resumeCheckpoint,
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
                $resumeCheckpoint,
            );
        }

        if (
            $definiteWriteRejected
            && $httpStatus !== null
            && $httpStatus >= 400
            && $httpStatus < 500
        ) {
            return JobOutcome::permanentFailure(
                self::messageFor($code, $message),
                $httpStatus,
                $uuid,
                $code,
                $resumeCheckpoint,
            );
        }

        $safeRetry = $httpStatus === 408
            || ($httpStatus !== null && $httpStatus >= 500)
            || in_array(self::nullableString($context['sevdeskCode'] ?? null), ['transport_error', 'http_client_error'], true)
            || in_array($code, [
                'api_request_failed',
                'contact_search_failed',
                'contact_verification_failed',
                'invoice_reconciliation_lookup_failed',
                'historical_duplicate_guard_lookup_failed',
            ], true);
        if ($safeRetry && $attempts < 4) {
            $delays = [300, 900, 3600];

            return JobOutcome::retry(
                'Die sichere Lese- oder Vorstufe konnte nicht abgeschlossen werden. Ein Wiederholungsversuch wurde eingeplant.',
                $delays[min($attempts - 1, 2)],
                $httpStatus,
                $uuid,
                $code,
                $resumeCheckpoint,
            );
        }

        if (JobRepository::isRiskyCheckpoint($checkpoint)) {
            $createRecovery = self::invoiceCreateWriteStarted($checkpoint);

            return JobOutcome::ambiguous(
                $createRecovery
                    ? 'Die lesende Invoice-Recovery blieb nach einem möglichen Create ohne beweiskräftiges Ergebnis. '
                        . 'Ein zweiter Create ist gesperrt.'
                    : 'Die lesende Recovery blieb nach einem möglichen Remote-Write ohne beweiskräftiges Ergebnis. '
                        . 'Der riskante Checkpoint bleibt gesperrt.',
                $checkpoint,
                isset($item->sevdesk_id) ? (string) $item->sevdesk_id : null,
                $httpStatus,
                $uuid,
                $code,
                $context,
            );
        }

        return JobOutcome::permanentFailure(
            self::messageFor($code, $message),
            $httpStatus,
            $uuid,
            $code,
            $resumeCheckpoint,
        );
    }

    private static function checkpointBeforeRejectedWrite(string $checkpoint): ?string
    {
        return match ($checkpoint) {
            'contact_write_requested', 'voucher_write_requested', 'invoice_write_requested' => 'document_type_selected',
            'invoice_open_write_requested' => 'mapping_persisted',
            'invoice_delivery_write_requested' => 'invoice_opened',
            default => null,
        };
    }

    /** @param array<string, scalar|null> $context */
    private function contactRecoveryFailureToOutcome(
        string $code,
        string $message,
        array $context,
        object $item,
    ): JobOutcome {
        $outcome = $this->failureResultToOutcome($code, $message, $context, $item);
        if ($outcome->status === 'retry_wait') {
            return JobOutcome::retry(
                $outcome->message,
                max(60, $outcome->retryAfterSeconds ?? 300),
                $outcome->httpStatus,
                $outcome->exceptionUuid,
                $outcome->errorCode,
                (string) ($item->checkpoint ?? 'contact_write_requested'),
            );
        }
        if ($outcome->status !== 'permanent_failed') {
            return $outcome;
        }

        return JobOutcome::ambiguous(
            'Die lesende Kontakt-Recovery blieb ohne beweiskräftiges Ergebnis. Bitte manuell abgleichen.',
            (string) ($item->checkpoint ?? 'contact_write_requested'),
            null,
            $outcome->httpStatus,
            $outcome->exceptionUuid,
            $code,
            $context,
        );
    }

    /** @param array<string,mixed> $candidate */
    private function requiresVoucherReconciliation(object $item, array $candidate): bool
    {
        if ((string) ($candidate['targetDocumentType'] ?? '') === DocumentTargetDecision::DOCUMENT_INVOICE) {
            return false;
        }

        return (string) ($item->action ?? '') === 'reconcile_voucher'
            || ((string) ($item->action ?? '') === 'export_voucher'
                && (isset($item->sevdesk_id) || in_array((string) ($item->checkpoint ?? ''), [
                    'voucher_write_requested',
                    'voucher_created',
                    'mapping_persisted',
                ], true)))
            || ((string) ($candidate['targetDocumentType'] ?? '') === DocumentTargetDecision::DOCUMENT_VOUCHER
                && in_array((string) ($item->checkpoint ?? ''), [
                    'voucher_write_requested',
                    'voucher_created',
                    'mapping_persisted',
                ], true));
    }

    /** @param array<string,mixed> $candidate */
    private function allowsIncompleteMappingRecovery(object $item, array $candidate): bool
    {
        return $this->requiresVoucherReconciliation($item, $candidate)
            || (
                self::isInvoiceContinuation($item, $candidate, '')
                && self::invoiceCreateWriteStarted((string) ($item->checkpoint ?? ''))
            );
    }

    /** @param array<string,mixed> $candidate */
    private static function isInvoiceContinuation(object $item, array $candidate, string $mappingType): bool
    {
        $checkpoint = (string) ($item->checkpoint ?? '');
        if (
            (string) ($candidate['targetDocumentType'] ?? '') !== DocumentTargetDecision::DOCUMENT_INVOICE
            || (string) ($item->action ?? '') !== 'export_document'
            || !self::invoiceContinuationCheckpoint($checkpoint)
        ) {
            return false;
        }

        return $mappingType === '' || $mappingType === MappingRepository::DOCUMENT_TYPE_INVOICE;
    }

    private static function invoiceContinuationCheckpoint(string $checkpoint): bool
    {
        return self::invoiceCreateWriteStarted($checkpoint)
            || self::invoicePostMappingWriteStarted($checkpoint);
    }

    private static function invoiceCreateWriteStarted(string $checkpoint): bool
    {
        return in_array($checkpoint, [
            'invoice_write_requested',
            'invoice_created',
            'invoice_xml_verified',
            'mapping_persisted',
        ], true);
    }

    private static function invoicePostMappingWriteStarted(string $checkpoint): bool
    {
        return in_array($checkpoint, [
            'invoice_open_write_requested',
            'invoice_opened',
            'invoice_delivery_write_requested',
            'invoice_delivered',
            'whmcs_email_write_requested',
            'whmcs_email_handed_off',
        ], true);
    }

    /** @param array<string,mixed> $candidate */
    private static function candidateSelectsInvoice(object $item, array $candidate): bool
    {
        return (string) ($item->action ?? '') === 'export_document'
            && (string) ($candidate['targetDocumentType'] ?? '') === DocumentTargetDecision::DOCUMENT_INVOICE;
    }

    private function tripAuthenticationAlarm(int $jobId): void
    {
        $safety = $this->config->tripAuthenticationSafetyGates();
        self::logAuthenticationSafetyFailure($safety, 'export');
        if ($jobId < 1) {
            return;
        }
        try {
            $this->jobs->pause($jobId);
        } catch (Throwable $error) {
            if (function_exists('logActivity')) {
                logActivity('sevdesk authentication alarm could not pause the current job: ' . get_class($error));
            }
        }
    }

    /** @param array{alarm:bool,reviewFallback:bool,syncDisabled:bool} $safety */
    private static function logAuthenticationSafetyFailure(array $safety, string $scope): void
    {
        if (!function_exists('logActivity')) {
            return;
        }
        if (!$safety['alarm']) {
            logActivity('sevdesk ' . $scope . ' authentication alarm used runtime-review fallback.');
        }
        if (!$safety['alarm'] && !$safety['reviewFallback']) {
            logActivity('sevdesk ' . $scope . ' authentication claim gates could not be persisted.');
        }
        if (!$safety['syncDisabled']) {
            logActivity('sevdesk ' . $scope . ' authentication alarm could not disable enqueueing.');
        }
    }

    /** @param array<string,mixed> $candidate */
    private function creditTreatmentConfirmed(array $candidate): bool
    {
        return ($candidate['credit_treatment'] ?? null) === 'full_gross_voucher';
    }

    /** @param array<string,mixed> $candidate */
    private static function contactRecoveryClientId(array $candidate): int
    {
        return max(0, (int) ($candidate['whmcsClientId'] ?? 0));
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

    private function deliveryText(string $setting, InvoiceSnapshot $invoice, ContactData $contact): string
    {
        return strtr((string) $this->config->get($setting, ''), [
            '{invoice_number}' => $invoice->invoiceNumber,
            '{company_name}' => $contact->displayName(),
        ]);
    }

    private static function mappingRemoteId(?object $mapping): ?string
    {
        $remoteId = trim((string) ($mapping->sevdesk_id ?? ''));

        return preg_match('/^[1-9]\d*$/', $remoteId) === 1 ? $remoteId : null;
    }

    /** @return array<string,mixed> */
    private static function candidate(object $item): array
    {
        try {
            $candidate = json_decode((string) ($item->candidate_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($candidate) ? $candidate : [];
    }

    private static function messageFor(string $code, string $fallback): string
    {
        return [
            'unsupported_oss' => 'EU-B2C braucht im bestätigten OSS-Profil eine sevdesk-Invoice; Voucher bleiben dafür gesperrt.',
            'unsupported_oss_rule' => 'Die OSS-Steuerregeln 18 und 20 sind in dieser Version nicht freigegeben.',
            'oss_requires_invoice_mode' => 'Rule 19 benötigt invoice_for_oss oder invoice_only.',
            'oss_profile_not_confirmed' => 'Rule 19 benötigt die ausdrückliche Bestätigung ausschließlich digitaler Leistungen.',
            'invoice_canary_not_confirmed' => 'Der sevDesk-Testmandanten-Canary ist noch nicht ausdrücklich bestätigt; Invoice-Schreibzugriffe bleiben gesperrt.',
            'invoice_requires_payment' => 'Invoice-Ziele werden erst nach vollständiger WHMCS-Zahlung exportiert.',
            'invoice_number_not_final' => 'Für den Invoice-Export fehlt eine finale WHMCS-Rechnungsnummer.',
            'contact_creation_not_confirmed' => 'Die Neuanlage eines sevdesk-Kontakts ist nicht freigegeben. Die exakte Kundennummernsuche blieb ohne Treffer.',
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
            'invoice_reconciliation_no_match' => 'Es wurde keine exakt passende sevdesk-Invoice gefunden. Bitte manuell prüfen.',
            'invoice_reconciliation_multiple_matches' => 'Es wurden mehrere exakt passende sevdesk-Invoices gefunden. Bitte manuell prüfen.',
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
