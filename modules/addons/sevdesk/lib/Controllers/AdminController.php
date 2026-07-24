<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Controllers;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\WhmcsInvoiceItem;
use WHMCS\Module\Addon\SevDesk\Health\HealthService;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceItemNormalizer;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsPaymentStructureService;
use WHMCS\Module\Addon\SevDesk\Support\AdminInvoiceControls;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;
use WHMCS\Module\Addon\SevDesk\Support\DocumentDeliveryContext;
use WHMCS\Module\Addon\SevDesk\Support\QuickExportGuard;
use WHMCS\Module\Addon\SevDesk\View;

final class AdminController
{
    public function __construct(
        private readonly Application $application,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly string $moduleLink,
    ) {
    }

    public function index(): void
    {
        $mapping = $this->application->mappings->counts();
        $jobs = $this->application->jobs->statusCounts();
        $health = (new HealthService($this->application))->run(false);
        $unmapped = Capsule::table('tblinvoices as invoice')
            ->leftJoin(Migrator::MAPPING_TABLE . ' as mapping', 'invoice.id', '=', 'mapping.invoice_id')
            ->whereNull('mapping.id')
            ->whereIn('invoice.status', $this->application->config->bool('import_only_paid', true) ? ['Paid'] : ['Paid', 'Unpaid'])
            ->count();

        $this->render('index.tpl', 'index', [
            'stats' => [
                'mapped' => $mapping['complete'],
                'unmapped' => $unmapped,
                'running' => $jobs['running'],
                'pending' => $jobs['pending'],
                'failed' => $jobs['failed'],
                'ambiguous' => $jobs['ambiguous'] + $mapping['ambiguous'],
                'health_status' => $health['stats']['health_status'],
            ],
            'healthChecks' => array_slice($health['checks'], 0, 5),
            'jobs' => $this->application->jobs->recent(10),
        ]);
    }

    public function setup(): void
    {
        $saveFailed = false;
        if ($this->isPost()) {
            $this->csrf->assertPost();
            try {
                $this->saveSetup();
                $this->view->flash('success', 'Die Einstellungen wurden gespeichert. Das Speichern selbst hat keinen Export gestartet.', 'Konfiguration aktualisiert');
            } catch (Throwable $error) {
                $saveFailed = true;
                $this->flashSetupFailure($error);
            }
        }

        $settings = $this->application->config->all();
        $storedToken = trim((string) ($settings['sevdesk_api_key'] ?? ''));
        $settings['sevdesk_api_key_placeholder'] = $storedToken === ''
            ? 'Noch kein Token gespeichert'
            : 'Token gespeichert – leer lassen zum Beibehalten';
        unset($settings['sevdesk_api_key']);
        $start = DateTimeImmutable::createFromFormat('!d-m-Y', (string) ($settings['import_after'] ?? ''));
        $settings['import_after_iso'] = $start instanceof DateTimeImmutable ? $start->format('Y-m-d') : (string) ($settings['import_after'] ?? '');
        $eInvoiceStart = DateTimeImmutable::createFromFormat(
            '!d-m-Y',
            (string) ($settings['e_invoice_active_from'] ?? ''),
        );
        $settings['e_invoice_active_from_iso'] = $eInvoiceStart instanceof DateTimeImmutable
            ? $eInvoiceStart->format('Y-m-d')
            : '';
        $storedSmallBusinessUntil = trim((string) ($settings['small_business_until'] ?? ''));
        try {
            $smallBusinessUntil = Config::parseSmallBusinessUntil($storedSmallBusinessUntil);
            $settings['small_business_until_iso'] = $smallBusinessUntil?->format('Y-m-d') ?? '';
            $settings['small_business_until_invalid'] = false;
        } catch (RuntimeException) {
            $settings['small_business_until_iso'] = '';
            $settings['small_business_until_invalid'] = true;
        }

        $accountOptions = [];
        $sevUsers = [];
        $unities = [];
        $paymentMethods = [];
        if ($storedToken !== '' && !$saveFailed) {
            try {
                $accountOptions = $this->application->referenceData()->revenueAccounts();
                $sevUsers = $this->application->referenceData()->sevUsers();
                $unities = $this->application->referenceData()->unities();
            } catch (Throwable $error) {
                $this->view->flash(
                    'warning',
                    'Gespeicherte Referenzen bleiben erhalten, aber die sevdesk-Referenzdaten waren nicht vollständig erreichbar.',
                    'sevdesk nicht erreichbar',
                );
            }
            try {
                $paymentMethods = $this->application->referenceData()->paymentMethods();
            } catch (Throwable) {
                if (($settings['e_invoice_mode'] ?? 'off') !== 'off') {
                    $this->view->flash(
                        'warning',
                        'Die gespeicherte Zahlungsmethode bleibt erhalten, konnte aber nicht read-only geprüft werden.',
                        'E-Rechnungsreferenz nicht erreichbar',
                    );
                }
            }
        }

        $emailTemplates = [];
        try {
            $emailTemplates = $this->application->whmcs->activeCustomInvoiceTemplates();
        } catch (Throwable) {
            $this->view->flash(
                'warning',
                'Aktive benutzerdefinierte Invoice-Mailvorlagen konnten nicht gelesen werden.',
                'WHMCS-Mailvorlagen nicht verfügbar',
            );
        }

        $customFields = [];
        foreach (Capsule::table('tblcustomfields')->where('type', 'client')->orderBy('id')->get() as $field) {
            $customFields[] = ['id' => (int) $field->id, 'label' => (string) $field->fieldname];
        }
        $eInvoiceClientFields = $this->application->whmcs->eInvoiceOptInFields();
        $transitionInventory = $this->transitionInventory();

        $this->render('setup.tpl', 'setup', [
            'settings' => $settings,
            'customFields' => $customFields,
            'accountOptions' => $accountOptions,
            'sevUsers' => $sevUsers,
            'unities' => $unities,
            'paymentMethods' => $paymentMethods,
            'eInvoiceClientFields' => $eInvoiceClientFields,
            'emailTemplates' => $emailTemplates,
            'whmcsTemplateDeliverySupported' => $this->application->whmcs
                ->supportsEmailPreSendAttachments(),
            'proformaEnabled' => $this->application->whmcs->proformaInvoicingEnabled(),
            'themeAdapterInstalled' => $this->application->whmcs->themeAdapterManifestInstalled(),
            'transitionInventory' => $transitionInventory,
        ]);
    }

    public function singleImport(): void
    {
        $job = null;
        $preflight = null;
        $invoiceId = max(0, (int) ($_POST['invoiceid'] ?? $_GET['invoiceid'] ?? 0));
        if ($this->isPost()) {
            $this->csrf->assertPost();
            if ($invoiceId < 1 || !Capsule::table('tblinvoices')->where('id', $invoiceId)->exists()) {
                $this->view->flash('danger', 'Die angegebene WHMCS-Rechnung wurde nicht gefunden.', 'Einzelexport nicht gestartet');
            } else {
                try {
                    $row = $this->application->whmcs->invoiceForDryRun($invoiceId);
                    if ($row === null) {
                        throw new RuntimeException('Entwürfe und anderweitig gesperrte Rechnungen sind nicht exportierbar.');
                    }
                    $preflight = $this->decorateDryRun([$row])[0] ?? null;
                    $creditConfirmed = isset($_POST['confirm_credit_export'])
                        && (string) ($_POST['credit_treatment_confirmed'] ?? '') === '1'
                        && ($preflight['reason_code'] ?? '') === 'credit_applied_requires_review'
                        && ($preflight['credit_voucher_confirmation_allowed'] ?? false) === true
                        && $this->application->config->get('export_mode')
                            === DocumentTargetResolver::MODE_VOUCHER_ONLY;
                    $confirmed = isset($_POST['confirm_export']) || isset($_POST['confirm_credit_export']);
                    if (
                        $confirmed
                        && is_array($preflight)
                        && ($preflight['exportable'] || $creditConfirmed)
                    ) {
                        $this->assertJobMutationAllowed();
                        $candidate = $this->requestedExportContext();
                        if ($creditConfirmed) {
                            $candidate['credit_treatment'] = 'full_gross_voucher';
                        }
                        $item = [
                            'invoice_id' => $invoiceId,
                            'action' => 'export_document',
                            'dedupe_key' => 'export_voucher:' . $invoiceId,
                            'candidate' => $candidate,
                        ];
                        $jobId = $this->application->jobs->create(
                            'single_export',
                            [$item],
                            ['invoice_id' => $invoiceId],
                            $this->adminId(),
                        );
                        $job = $this->application->jobs->findJob($jobId);
                        $this->view->flash('success', 'Der Beleg wurde sicher eingereiht. Die Verarbeitung läuft unabhängig vom Browser.', 'Job #' . $jobId . ' angelegt');
                    } elseif (!is_array($preflight) || (!$preflight['exportable'] && $confirmed)) {
                        $this->view->flash('warning', (string) ($preflight['reason'] ?? 'Die Rechnung ist nicht exportierbar.'), 'Vorprüfung blockiert den Export');
                    }
                } catch (RuntimeException $error) {
                    $this->view->flash('danger', $error->getMessage(), 'Vorprüfung fehlgeschlagen');
                } catch (Throwable) {
                    $this->view->flash('danger', 'Die read-only Vorprüfung konnte nicht abgeschlossen werden. Es wurde kein Job angelegt.', 'Vorprüfung fehlgeschlagen');
                }
            }
        }

        $this->render('single_import.tpl', 'singleImport', [
            'filters' => ['invoiceid' => $invoiceId > 0 ? $invoiceId : ''],
            'preflight' => $preflight,
            'job' => $job,
        ]);
    }

    /**
     * Queue one saved invoice without running sevdesk work in the browser request.
     *
     * The compact invoice-page action is deliberately narrower than the normal
     * single-export flow. Anything already known to require judgement is sent
     * back to that preflight instead of being silently accepted here.
     */
    public function quickExport(): void
    {
        $this->csrf->assertPost();
        $invoiceId = (int) ($_POST['invoiceid'] ?? 0);
        if ($invoiceId < 1) {
            throw new RuntimeException('Die Schnellaktion enthält keine gültige Rechnungs-ID.');
        }

        $notice = 'failed';
        $jobId = null;
        try {
            $this->assertJobMutationAllowed();
            $invoiceExists = Capsule::table('tblinvoices')->where('id', $invoiceId)->exists();
            if (!$invoiceExists) {
                $notice = 'not_found';
            } else {
                $invoice = $this->application->whmcs->invoiceForDryRun($invoiceId);
                $mapping = $this->application->mappings->findByInvoice($invoiceId);
                if ($invoice === null) {
                    $notice = 'blocked';
                } else {
                    $invoiceItems = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId);
                    $hasMassPaymentReference = (clone $invoiceItems)
                        ->whereRaw('LOWER(TRIM(type)) = ?', ['invoice'])
                        ->exists();
                    $reason = $hasMassPaymentReference
                        ? WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW
                        : QuickExportGuard::blockReason(
                            $invoice,
                            $mapping,
                            $this->application->config->bool('import_only_paid', true),
                            (string) $this->application->config->get('import_after', '01-01-1999'),
                            (clone $invoiceItems)->exists(),
                            (clone $invoiceItems)->where('amount', '<', 0)->exists(),
                        );
                    if ($reason === QuickExportGuard::ALREADY_MAPPED) {
                        $notice = 'already_mapped';
                    } elseif ($reason === QuickExportGuard::AMBIGUOUS_LEGACY) {
                        $notice = 'legacy_mapping';
                    } elseif ($reason !== null) {
                        $notice = 'blocked';
                    } else {
                        $jobId = $this->application->jobs->create('single_export', [[
                            'invoice_id' => $invoiceId,
                            'action' => 'export_document',
                            'dedupe_key' => 'export_voucher:' . $invoiceId,
                            'candidate' => $this->requestedExportContext(),
                        ]], [
                            'invoice_id' => $invoiceId,
                            'source' => 'admin_invoice_quick_export',
                        ], $this->adminId());
                        // Once create() returns, the transaction is committed.
                        // A diagnostic read failure must not turn that durable
                        // result into a false "nothing was queued" notice.
                        $notice = 'queued';
                        try {
                            $item = $this->application->jobs->items($jobId)[0] ?? null;
                            if ($item !== null && (string) $item->status === 'skipped') {
                                $notice = 'already_active';
                            }
                        } catch (Throwable $readError) {
                            if (function_exists('logActivity')) {
                                logActivity(
                                    'sevdesk quick export job ' . $jobId
                                    . ' was committed, but its status read failed: ' . get_class($readError),
                                );
                            }
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            if (function_exists('logActivity')) {
                logActivity(
                    'sevdesk quick export failed safely for invoice '
                    . $invoiceId . ': ' . get_class($error),
                );
            }
            $notice = 'failed';
            $jobId = null;
        }

        AdminInvoiceControls::storeNotice(
            $invoiceId,
            $notice,
            $notice === 'queued' ? $jobId : null,
        );
        $this->redirectToInvoice($invoiceId);
    }

    public function massImport(): void
    {
        $filters = [
            'date_start' => (string) ($_POST['date_start'] ?? ''),
            'date_end' => (string) ($_POST['date_end'] ?? ''),
            'submitted' => $this->isPost(),
        ];
        $invoices = [];
        $job = null;
        if ($this->isPost()) {
            $this->csrf->assertPost();
            try {
                [$from, $until] = $this->dateRange($filters['date_start'], $filters['date_end']);
                $rows = $this->application->whmcs->invoicesBetween(
                    $from,
                    $until,
                    $this->application->config->bool('import_only_paid', true),
                    751,
                );
                if (count($rows) > 750) {
                    throw new RuntimeException(
                        'Der Zeitraum enthält mehr als 750 Rechnungen. Bitte den Zeitraum verkleinern; '
                        . 'die Vorschau wurde nicht abgeschnitten und kein Job angelegt.',
                    );
                }
                $invoices = $this->decorateDryRun($rows);

                if (isset($_POST['import'])) {
                    $this->assertJobMutationAllowed();
                    $selected = array_values(array_unique(array_filter(
                        array_map('intval', (array) ($_POST['invoice_ids'] ?? [])),
                        static fn (int $id): bool => $id > 0,
                    )));
                    $allowed = [];
                    $requestedContext = $this->requestedExportContext();
                    // A confirmed range export is a historical backfill. It is
                    // always mail-free and never upgrades old invoices to
                    // ZUGFeRD, even if that profile is enabled for new bills.
                    $requestedContext['historicalBackfill'] = true;
                    $requestedContext['delivery_requested'] = false;
                    $requestedContext['requestedEInvoiceMode'] = 'off';
                    $requestedContext['requestedEInvoiceCanaryConfirmed'] = false;
                    foreach ($invoices as $invoice) {
                        if ($invoice['exportable'] && in_array($invoice['id'], $selected, true)) {
                            $allowed[] = [
                                'invoice_id' => $invoice['id'],
                                'action' => 'export_document',
                                'dedupe_key' => 'export_voucher:' . $invoice['id'],
                                'candidate' => $requestedContext,
                            ];
                        }
                    }
                    if ($allowed === []) {
                        $this->view->flash('warning', 'Es wurden keine zulässigen Rechnungen ausgewählt.', 'Kein Job angelegt');
                    } else {
                        $jobId = $this->application->jobs->create('historical_backfill', $allowed, [
                            'date_start' => $from->format('Y-m-d'),
                            'date_end' => $until->format('Y-m-d'),
                            'mail_free' => true,
                            'e_invoice' => false,
                        ], $this->adminId());
                        $job = $this->application->jobs->findJob($jobId);
                        $queuedCount = (int) Capsule::table(Migrator::ITEMS_TABLE)
                            ->where('job_id', $jobId)
                            ->where('status', 'pending')
                            ->count();
                        $ownerCount = (int) Capsule::table(Migrator::ITEMS_TABLE)
                            ->where('job_id', $jobId)
                            ->where('status', 'skipped')
                            ->count();
                        $this->view->flash(
                            $ownerCount === 0 ? 'success' : 'warning',
                            $queuedCount . ' Rechnungen wurden mailfrei als Altbestandsjob eingereiht.'
                                . ($ownerCount > 0
                                    ? ' ' . $ownerCount . ' Rechnungen hatten inzwischen bereits einen aktiven Exportjob und wurden nicht übernommen.'
                                    : ''),
                            'Job #' . $jobId . ' angelegt',
                        );
                    }
                }
            } catch (RuntimeException $error) {
                $invoices = [];
                $this->view->flash('danger', $error->getMessage(), 'Vorprüfung fehlgeschlagen');
            } catch (Throwable) {
                $invoices = [];
                $this->view->flash(
                    'danger',
                    'Der read-only Dry-Run konnte nicht vollständig ausgeführt werden. Es wurde kein Exportjob angelegt.',
                    'Vorprüfung fehlgeschlagen',
                );
            }
        }

        $this->render('mass_import.tpl', 'massImport', [
            'filters' => $filters,
            'invoices' => $invoices,
            'job' => $job,
        ]);
    }

    public function jobs(): void
    {
        $status = trim((string) ($_GET['status'] ?? ''));
        $query = trim((string) ($_GET['q'] ?? ''));
        $jobs = $this->application->jobs->recent(250);
        $jobs = array_values(array_filter($jobs, static function (object $job) use ($status, $query): bool {
            if ($status !== '' && (string) $job->status !== $status) {
                return false;
            }
            if ($query !== '' && !str_contains((string) $job->id, $query) && !str_contains((string) $job->type, $query)) {
                return false;
            }

            return true;
        }));

        $this->render('jobs.tpl', 'jobs', [
            'filters' => ['status' => $status, 'q' => $query],
            'jobs' => $jobs,
            'stats' => $this->application->jobs->statusCounts(),
        ]);
    }

    public function jobDetail(): void
    {
        $jobId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($this->isPost()) {
            $this->csrf->assertPost();
            if (isset($_POST['pause'])) {
                $this->assertModuleActiveForJobSafetyAction();
                $this->application->jobs->pause($jobId);
                $this->view->flash('warning', 'Neue Positionen werden nicht beansprucht; ein bereits laufender API-Aufruf darf sauber enden.', 'Job pausiert');
            } elseif (isset($_POST['resume'])) {
                $this->assertJobMutationAllowed();
                if ($this->application->jobs->resume($jobId)) {
                    $this->view->flash('success', 'Offene Positionen dürfen wieder verarbeitet werden.', 'Job fortgesetzt');
                } else {
                    $this->view->flash('warning', 'Der Job war nicht pausiert oder wurde nicht gefunden.', 'Keine Änderung');
                }
            } elseif (isset($_POST['cancel'])) {
                $this->assertModuleActiveForJobSafetyAction();
                $this->application->jobs->cancel($jobId);
                $this->view->flash('warning', 'Noch nicht gestartete Positionen wurden abgebrochen.', 'Abbruch angefordert');
            }
        }

        $job = $this->application->jobs->findJob($jobId);
        if ($job === null) {
            $this->notFound();
            return;
        }
        $status = trim((string) ($_GET['status'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $itemPage = $this->application->jobs->paginatedItems($jobId, $status, $page);
        $itemPage['items'] = $this->decorateJobDocumentFields($itemPage['items']);
        $job->status_url = $this->moduleLink . '&a=jobStatus&id=' . $jobId;

        $this->render('job_detail.tpl', 'jobDetail', [
            'job' => $job,
            'items' => $itemPage['items'],
            'pagination' => $this->pagination(
                $itemPage['page'],
                $itemPage['pages'],
                $this->moduleLink . '&a=jobDetail&id=' . $jobId . ($status !== '' ? '&status=' . rawurlencode($status) : ''),
            ),
        ]);
    }

    public function jobStatus(): void
    {
        $job = $this->application->jobs->findJob((int) ($_GET['id'] ?? 0));
        $this->startDirectResponse('application/json; charset=utf-8');
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
        }
        if ($job === null) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
            exit;
        }

        echo json_encode(['job' => [
            'id' => (int) $job->id,
            'status' => (string) $job->status,
            'total_items' => (int) $job->total_items,
            'processed_items' => (int) $job->processed_items,
            'progress_percent' => (int) $job->progress_percent,
            'succeeded_items' => (int) $job->succeeded_items,
            'skipped_items' => (int) $job->skipped_items,
            'failed_items' => (int) $job->failed_items,
            'ambiguous_items' => (int) $job->ambiguous_items,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
        ]], JSON_THROW_ON_ERROR);
        exit;
    }

    public function jobCsv(): void
    {
        $jobId = (int) ($_GET['id'] ?? 0);
        if ($this->application->jobs->findJob($jobId) === null) {
            $this->notFound();
            return;
        }
        $this->startDirectResponse('text/csv; charset=utf-8');
        if (!headers_sent()) {
            header('Content-Disposition: attachment; filename="sevdesk-job-' . $jobId . '.csv"');
            header('Cache-Control: no-store, private');
        }
        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new RuntimeException('CSV-Ausgabe konnte nicht geöffnet werden.');
        }
        fputcsv($stream, self::safeCsvRow([
            'item_id', 'invoice_id', 'invoice_number', 'action', 'checkpoint', 'status', 'attempts',
            'document_type', 'document_authority', 'tax_rule', 'delivery_state', 'sevdesk_id',
            'error_code', 'http_status', 'exception_uuid', 'message', 'updated_at',
        ]));
        foreach ($this->decorateJobDocumentFields($this->application->jobs->items($jobId)) as $item) {
            fputcsv($stream, self::safeCsvRow([
                $item->id, $item->invoice_id, $item->invoicenum, $item->action, $item->checkpoint,
                $item->status, $item->attempts, $item->document_type, $item->document_authority,
                $item->tax_rule, $item->delivery_state, $item->sevdesk_id, $item->error_code,
                $item->http_status, $item->exception_uuid, $item->message, $item->updated_at,
            ]));
        }
        fclose($stream);
        exit;
    }

    public function assignmentManager(): void
    {
        $typeInspection = null;
        $batchTypeInspections = [];
        if ($this->isPost()) {
            $this->csrf->assertPost();
            $mappingId = (int) ($_POST['mapping_id'] ?? 0);
            try {
                if (isset($_POST['inspect_legacy_types_batch'])) {
                    $batchTypeInspections = $this->inspectLegacyMappingsBatch(
                        $this->submittedLegacyBatchIds(),
                    );
                } elseif (isset($_POST['confirm_legacy_types_batch'])) {
                    $this->confirmLegacyMappingsBatch();
                } elseif (isset($_POST['inspect_legacy_type'])) {
                    $mapping = $this->legacyMappingContext($mappingId);
                    $inspection = $this->application->legacyMappingType()->inspect(
                        (int) $mapping->invoice_id,
                        (string) $mapping->invoicenum,
                        (string) $mapping->sevdesk_id,
                    );
                    if (($inspection['status'] ?? '') === 'suggested') {
                        $typeInspection = $inspection + [
                            'mappingId' => $mappingId,
                            'invoiceId' => (int) $mapping->invoice_id,
                            'remoteId' => (string) $mapping->sevdesk_id,
                            'invoiceNumber' => (string) $mapping->invoicenum,
                            'invoicePaid' => self::legacyInvoiceIsPaid($mapping),
                            'frozenDocumentType' => (string) $mapping->frozen_document_type,
                            'frozenDocumentAuthority' => (string) $mapping->frozen_document_authority,
                        ];
                    } else {
                        $this->handleLegacyMappingTypeFailure($inspection);
                    }
                } elseif (isset($_POST['confirm_legacy_type'])) {
                    $submittedDocumentType = $_POST['document_type'] ?? null;
                    $submittedDocumentAuthority = $_POST['document_authority'] ?? null;
                    if (
                        !is_string($submittedDocumentType)
                        || !in_array($submittedDocumentType, ['voucher', 'invoice'], true)
                    ) {
                        throw new RuntimeException('Der bestätigte Belegtyp ist ungültig.');
                    }
                    if (
                        !is_string($submittedDocumentAuthority)
                        || !in_array($submittedDocumentAuthority, ['whmcs', 'sevdesk'], true)
                        || ($submittedDocumentType === 'voucher' && $submittedDocumentAuthority !== 'whmcs')
                    ) {
                        throw new RuntimeException('Die bestätigte Dokumenthoheit ist ungültig.');
                    }
                    if (
                        $submittedDocumentAuthority === 'sevdesk'
                        && !$this->application->legacySevdeskAuthorityReady()
                    ) {
                        throw new RuntimeException(
                            'Für sevdesk-Hoheit fehlen Proforma, Theme-Adapter oder Versandvoraussetzungen.',
                        );
                    }
                    $mapping = $this->legacyMappingContext($mappingId);
                    self::assertLegacyAuthorityStatus($mapping, $submittedDocumentAuthority);
                    self::assertLegacyFrozenDocument(
                        $mapping,
                        $submittedDocumentType,
                        $submittedDocumentAuthority,
                    );
                    $result = $this->application->legacyMappingType()->confirm(
                        (int) $mapping->invoice_id,
                        (string) $mapping->invoicenum,
                        (string) $mapping->sevdesk_id,
                        $submittedDocumentType,
                        $submittedDocumentAuthority,
                    );
                    if (($result['status'] ?? '') === 'confirmed') {
                        if (function_exists('logActivity')) {
                            logActivity(
                                'sevdesk: legacy mapping type confirmed by admin ' . $this->adminId()
                                . '; invoice ' . (int) $mapping->invoice_id
                                . '; mapping ' . $mappingId
                                . '; type ' . (string) ($result['suggestedType'] ?? '')
                                . '; authority ' . $submittedDocumentAuthority,
                            );
                        }
                        $this->view->flash(
                            'success',
                            'Belegtyp und Dokumenthoheit wurden nach erneuter Remote-Prüfung additiv bestätigt.',
                            'Legacy-Zuordnung bestätigt',
                        );
                    } else {
                        $this->handleLegacyMappingTypeFailure($result);
                    }
                } elseif (isset($_POST['delete'])) {
                    $this->deleteMapping($mappingId, (int) ($_POST['invoiceid'] ?? 0));
                }
            } catch (Throwable) {
                $this->view->flash(
                    'danger',
                    'Die Legacy-Zuordnung konnte nicht sicher geprüft oder aktualisiert werden.',
                    'Zuordnungsprüfung fehlgeschlagen',
                );
            }
        }

        $page = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
        $status = trim((string) ($_GET['status'] ?? $_POST['filter_status'] ?? ''));
        $query = trim((string) ($_GET['q'] ?? $_POST['filter_q'] ?? ''));
        $result = $this->application->mappings->paginate($page, 100, $query, $status);
        $legacyBatchIds = [];
        foreach ($result['items'] as $mapping) {
            if (
                ($mapping->invoice_exists ?? false) !== false
                && trim((string) ($mapping->sevdesk_id ?? '')) !== ''
                && trim((string) ($mapping->stored_document_authority ?? '')) === ''
            ) {
                $legacyBatchIds[] = (int) ($mapping->mapping_id ?? $mapping->id ?? 0);
            }
        }
        $legacyBatchIds = array_values(array_filter($legacyBatchIds, static fn (int $id): bool => $id > 0));
        $base = $this->moduleLink . '&a=assignmentManager'
            . ($status !== '' ? '&status=' . rawurlencode($status) : '')
            . ($query !== '' ? '&q=' . rawurlencode($query) : '');

        $this->render('assignment_manager.tpl', 'assignmentManager', [
            'filters' => ['status' => $status, 'q' => $query],
            'mappings' => $result['items'],
            'typeInspection' => $typeInspection,
            'batchTypeInspections' => $batchTypeInspections,
            'batchTypeEligibleCount' => count(array_filter(
                $batchTypeInspections,
                static fn (array $inspection): bool => ($inspection['batchEligible'] ?? false) === true,
            )),
            'legacyBatchIds' => implode(',', array_slice($legacyBatchIds, 0, 25)),
            'legacySevdeskAuthorityReady' => $this->application->legacySevdeskAuthorityReady(),
            'pagination' => $this->pagination($result['page'], $result['pages'], $base),
        ]);
    }

    /** @return list<int> */
    private function submittedLegacyBatchIds(): array
    {
        $raw = trim((string) ($_POST['batch_mapping_ids'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $value) {
            $id = (int) trim($value);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_slice(array_map('intval', array_keys($ids)), 0, 25);
    }

    /**
     * @param list<int> $mappingIds
     * @return list<array<string,mixed>>
     */
    private function inspectLegacyMappingsBatch(array $mappingIds): array
    {
        if ($mappingIds === []) {
            throw new RuntimeException('Für die Sammelprüfung wurden keine Legacy-Zuordnungen ausgewählt.');
        }
        $inspections = [];
        foreach ($mappingIds as $mappingId) {
            try {
                $mapping = $this->legacyMappingContext($mappingId);
                $result = $this->application->legacyMappingType()->inspect(
                    (int) $mapping->invoice_id,
                    (string) $mapping->invoicenum,
                    (string) $mapping->sevdesk_id,
                );
                if (($result['code'] ?? '') === 'api_authentication_failed') {
                    $this->handleLegacyMappingTypeFailure($result);
                    break;
                }
                $markerEvidence = ($result['context']['markerEvidence'] ?? false) === true;
                $inspections[] = [
                    'mappingId' => $mappingId,
                    'invoiceId' => (int) $mapping->invoice_id,
                    'invoiceNumber' => (string) $mapping->invoicenum,
                    'status' => (string) ($result['status'] ?? 'failed'),
                    'code' => (string) ($result['code'] ?? 'legacy_mapping_type_check_failed'),
                    'suggestedType' => (string) ($result['suggestedType'] ?? ''),
                    'markerEvidence' => $markerEvidence,
                    'deliveryReady' => ($result['context']['deliveryReady'] ?? false) === true,
                    'invoicePaid' => self::legacyInvoiceIsPaid($mapping),
                    'frozenDocumentType' => (string) $mapping->frozen_document_type,
                    'frozenDocumentAuthority' => (string) $mapping->frozen_document_authority,
                    'batchEligible' => ($result['status'] ?? '') === 'suggested'
                        && $markerEvidence
                        && self::legacyFrozenDocumentMatches(
                            $mapping,
                            (string) ($result['suggestedType'] ?? ''),
                        ),
                    'message' => self::legacyBatchResultMessage($result, $markerEvidence),
                ];
            } catch (Throwable) {
                $inspections[] = [
                    'mappingId' => $mappingId,
                    'invoiceId' => 0,
                    'invoiceNumber' => '',
                    'status' => 'failed',
                    'code' => 'legacy_mapping_type_check_failed',
                    'suggestedType' => '',
                    'markerEvidence' => false,
                    'deliveryReady' => false,
                    'invoicePaid' => false,
                    'frozenDocumentType' => '',
                    'frozenDocumentAuthority' => '',
                    'batchEligible' => false,
                    'message' => 'Die Zuordnung konnte nicht eindeutig read-only geprüft werden.',
                ];
            }
        }

        return $inspections;
    }

    private function confirmLegacyMappingsBatch(): void
    {
        $submitted = $_POST['batch_confirmations'] ?? null;
        $submittedAuthorities = $_POST['batch_authorities'] ?? null;
        if (!is_array($submitted) || $submitted === []) {
            throw new RuntimeException('Es wurden keine markerbestätigten Legacy-Typen ausgewählt.');
        }
        $confirmed = 0;
        $blocked = 0;
        foreach (array_slice($submitted, 0, 25, true) as $mappingKey => $submittedDocumentType) {
            if (
                (
                    !is_int($mappingKey)
                    && preg_match('/^[1-9]\d*$/', $mappingKey) !== 1
                )
                || !is_string($submittedDocumentType)
                || !in_array($submittedDocumentType, ['voucher', 'invoice'], true)
            ) {
                $blocked++;
                continue;
            }
            $mappingId = (int) $mappingKey;
            $documentType = $submittedDocumentType;
            $documentAuthority = is_array($submittedAuthorities)
                ? ($submittedAuthorities[$mappingKey] ?? null)
                : null;
            if (
                !is_string($documentAuthority)
                || !in_array($documentAuthority, ['whmcs', 'sevdesk'], true)
                || ($documentType === 'voucher' && $documentAuthority !== 'whmcs')
                || (
                    $documentAuthority === 'sevdesk'
                    && !$this->application->legacySevdeskAuthorityReady()
                )
            ) {
                $blocked++;
                continue;
            }
            try {
                $mapping = $this->legacyMappingContext($mappingId);
                self::assertLegacyAuthorityStatus($mapping, $documentAuthority);
                self::assertLegacyFrozenDocument($mapping, $documentType, $documentAuthority);
                $inspection = $this->application->legacyMappingType()->inspect(
                    (int) $mapping->invoice_id,
                    (string) $mapping->invoicenum,
                    (string) $mapping->sevdesk_id,
                );
                if (($inspection['code'] ?? '') === 'api_authentication_failed') {
                    $this->handleLegacyMappingTypeFailure($inspection);
                    $blocked++;
                    break;
                }
                if (
                    ($inspection['status'] ?? '') !== 'suggested'
                    || ($inspection['context']['markerEvidence'] ?? false) !== true
                    || ($inspection['suggestedType'] ?? null) !== $documentType
                ) {
                    $blocked++;
                    continue;
                }
                $result = $this->application->legacyMappingType()->confirm(
                    (int) $mapping->invoice_id,
                    (string) $mapping->invoicenum,
                    (string) $mapping->sevdesk_id,
                    $documentType,
                    $documentAuthority,
                );
                if (($result['status'] ?? '') === 'confirmed') {
                    $confirmed++;
                    if (function_exists('logActivity')) {
                        logActivity(
                            'sevdesk: marker-backed legacy mapping type confirmed by admin '
                                . $this->adminId() . '; invoice ' . (int) $mapping->invoice_id
                                . '; mapping ' . $mappingId
                                . '; authority ' . $documentAuthority,
                        );
                    }
                } else {
                    $blocked++;
                }
            } catch (Throwable) {
                $blocked++;
            }
        }

        $this->view->flash(
            $blocked === 0 ? 'success' : 'warning',
            sprintf('%d Zuordnungen bestätigt, %d wegen geänderter oder unklarer Nachweise nicht übernommen.', $confirmed, $blocked),
            'Legacy-Sammelbestätigung abgeschlossen',
        );
    }

    /** @param array<string,mixed> $result */
    private static function legacyBatchResultMessage(array $result, bool $markerEvidence): string
    {
        if (($result['status'] ?? '') === 'suggested') {
            return $markerEvidence
                ? 'Dokumentnummer und Rewrite-Marker stimmen eindeutig überein.'
                : 'Die Dokumentnummer passt, aber der Rewrite-Marker fehlt. Dieser Beleg bleibt ein Einzelfall.';
        }

        return match ((string) ($result['code'] ?? '')) {
            'legacy_mapping_type_collision' => 'Dieselbe ID ist typübergreifend belegt; keine Sammelbestätigung möglich.',
            'legacy_mapping_type_no_match' => 'Kein Remote-Typ stimmt widerspruchsfrei überein.',
            default => 'Die read-only Prüfung war nicht eindeutig oder nicht erreichbar.',
        };
    }

    public function bookingAssistant(): void
    {
        $isPost = $this->isPost();
        $filters = [
            'date_start' => (string) ($_POST['date_start'] ?? $_GET['date_start'] ?? ''),
            'date_end' => (string) ($_POST['date_end'] ?? $_GET['date_end'] ?? ''),
            'submitted' => $isPost || isset($_GET['page']),
        ];
        $candidates = [];
        $job = null;
        $paymentTotal = 0;
        $pagination = $this->pagination(1, 1, $this->moduleLink . '&a=bookingAssistant');
        $previewRequested = ($isPost && isset($_POST['preview']))
            || (!$isPost && isset($_GET['page']));
        if ($isPost) {
            $this->csrf->assertPost();
        }
        if ($previewRequested) {
            try {
                $previewStarted = microtime(true);
                [$from, $until] = $this->dateRange($filters['date_start'], $filters['date_end']);
                $page = $isPost ? 1 : max(1, (int) ($_GET['page'] ?? 1));
                $paymentPage = $this->application->whmcs->bookingPaymentsBetween($from, $until, $page, 10);
                $rows = $paymentPage['items'];
                $paymentTotal = $paymentPage['total'];
                $paginationBase = $this->moduleLink . '&a=bookingAssistant'
                    . '&date_start=' . rawurlencode($filters['date_start'])
                    . '&date_end=' . rawurlencode($filters['date_end']);
                $pagination = $this->pagination(
                    $paymentPage['page'],
                    $paymentPage['pages'],
                    $paginationBase,
                );
                $sessionCandidates = [];
                foreach ($rows as $row) {
                    if (microtime(true) - $previewStarted > 15) {
                        $this->view->flash(
                            'warning',
                            'Die Vorschau wurde nach dem sicheren Zeitbudget beendet. Bereits angezeigte Treffer '
                            . 'sind vollständig; bitte diese Seite erneut laden.',
                            'Zeitbudget erreicht',
                        );
                        break;
                    }

                    $invoiceId = (int) $row->invoice_id;
                    $transaction = [
                        'id' => (int) $row->whmcs_account_id,
                        'transid' => (string) $row->transaction_id,
                        'amountin' => (string) $row->amountin,
                        'amountout' => (string) $row->amountout,
                        'date' => (string) $row->transaction_date,
                        'gateway' => (string) $row->gateway,
                        'refundid' => (int) $row->refundid,
                        'currency' => (int) $row->transaction_currency_id,
                    ];
                    $reference = trim((string) $transaction['transid']);
                    $amountIn = (string) $transaction['amountin'];
                    $currency = strtoupper(trim((string) ($row->transaction_currency ?? '')));
                    if ($currency === '') {
                        $currency = strtoupper(trim((string) ($row->invoice_currency ?? '')));
                    }
                    $whmcsPayment = $this->verifiedWhmcsPayment($invoiceId, $transaction);
                    if ($whmcsPayment === null) {
                        $candidates[] = [
                            'id' => hash('sha256', $invoiceId . '|' . $reference . '|local-conflict'),
                            'invoice_id' => $invoiceId,
                            'invoicenum' => (string) $row->invoicenum,
                            'sevdesk_id' => (string) $row->sevdesk_id,
                            'transaction_id' => $reference,
                            'sevdesk_transaction_id' => '',
                            'gateway' => (string) $transaction['gateway'],
                            'amount' => $amountIn,
                            'amount_formatted' => number_format((float) $amountIn, 2, ',', '.') . ' ' . $currency,
                            'status' => 'blocked',
                            'bookable' => false,
                            'reason' => 'whmcs_payment_not_unique',
                            'message' => 'Die WHMCS-Zahlung ist nicht mehr eindeutig oder wurde bereits erstattet.',
                        ];
                        continue;
                    }
                    $preview = $this->application->bookings()->preview([
                        'kind' => 'payment',
                        'documentType' => (string) ($row->document_type ?? ''),
                        'whmcsTransactionId' => $reference,
                        'voucherId' => (string) $row->sevdesk_id,
                        'amount' => $amountIn,
                        'currency' => $currency,
                        'bookingDate' => substr((string) $transaction['date'], 0, 10),
                    ]);
                    if (!self::bookingPreviewNeedsAttention($preview)) {
                        continue;
                    }
                    $confirmation = is_array($preview['confirmation'] ?? null) ? $preview['confirmation'] : null;
                    if ($confirmation !== null) {
                        $confirmation['whmcsAccountId'] = (int) $whmcsPayment->id;
                        $confirmation['whmcsInvoiceId'] = $invoiceId;
                    }
                    $candidateId = (string) ($confirmation['reference'] ?? hash('sha256', $invoiceId . '|' . $reference));
                    $bookable = ($preview['status'] ?? '') === 'ready' && $confirmation !== null;
                    $candidates[] = [
                        'id' => $candidateId,
                        'invoice_id' => $invoiceId,
                        'invoicenum' => (string) $row->invoicenum,
                        'sevdesk_id' => (string) $row->sevdesk_id,
                        'transaction_id' => $reference,
                        'sevdesk_transaction_id' => (string) ($confirmation['transactionId'] ?? ''),
                        'gateway' => (string) $transaction['gateway'],
                        'amount' => $amountIn,
                        'amount_formatted' => number_format((float) $amountIn, 2, ',', '.') . ' ' . $currency,
                        'status' => (string) ($preview['status'] ?? 'failed'),
                        'bookable' => $bookable,
                        'reason' => (string) ($preview['code'] ?? 'booking_preview_failed'),
                        'message' => (string) ($preview['message'] ?? 'Keine eindeutige Zuordnung.'),
                    ];
                    if ($bookable) {
                        $sessionCandidates[$candidateId] = [
                            'invoice_id' => $invoiceId,
                            'confirmation' => $confirmation,
                        ];
                    }
                }
                $_SESSION['sevdesk_booking_candidates'] = $sessionCandidates;
            } catch (Throwable) {
                $_SESSION['sevdesk_booking_candidates'] = [];
                $this->view->flash('danger', 'Die read-only Buchungsvorschau konnte nicht vollständig erstellt werden.', 'Vorschau fehlgeschlagen');
            }
        } elseif ($isPost && isset($_POST['import'])) {
            $this->assertJobMutationAllowed();
            $stored = is_array($_SESSION['sevdesk_booking_candidates'] ?? null)
                ? $_SESSION['sevdesk_booking_candidates']
                : [];
            $selected = array_values(array_unique(array_map('strval', (array) ($_POST['candidate_ids'] ?? []))));
            $items = [];
            foreach ($selected as $candidateId) {
                $storedCandidate = $stored[$candidateId] ?? null;
                if (!is_array($storedCandidate) || !is_array($storedCandidate['confirmation'] ?? null)) {
                    continue;
                }
                $confirmation = $storedCandidate['confirmation'];
                $transactionKey = 'book_payment:' . hash(
                    'sha256',
                    'whmcs-account:' . (int) ($confirmation['whmcsAccountId'] ?? 0),
                );
                $items[] = [
                    'invoice_id' => (int) $storedCandidate['invoice_id'],
                    'action' => 'book_payment',
                    'dedupe_key' => $transactionKey,
                    'transaction_reference' => $transactionKey,
                    'candidate' => $confirmation,
                ];
            }
            if ($items === []) {
                $this->view->flash('warning', 'Keine weiterhin gültige Vorschau wurde ausgewählt. Bitte neu suchen.', 'Kein Buchungsjob angelegt');
            } else {
                $jobId = $this->application->jobs->create('payment_booking', $items, [
                    'date_start' => $filters['date_start'],
                    'date_end' => $filters['date_end'],
                ], $this->adminId());
                $job = $this->application->jobs->findJob($jobId);
                unset($_SESSION['sevdesk_booking_candidates']);
                $this->view->flash('success', count($items) . ' bestätigte Zahlungen wurden als sicherer Job eingereiht.', 'Job #' . $jobId . ' angelegt');
            }
        }
        $this->render('booking_assistant.tpl', 'bookingAssistant', [
            'filters' => $filters,
            'candidates' => $candidates,
            'job' => $job,
            'paymentTotal' => $paymentTotal,
            'pagination' => $pagination,
        ]);
    }

    public function corrections(): void
    {
        $createdJob = null;
        if ($this->isPost()) {
            $this->csrf->assertPost();
            $this->assertJobMutationAllowed();
            if (isset($_POST['create_correction'])) {
                try {
                    $createdJob = $this->createCorrectionJob();
                    $this->view->flash(
                        'success',
                        'Der bestätigte Korrektur-Voucher wurde als eigener Job eingereiht. Es wurde noch nichts festgeschrieben.',
                        'Job #' . $createdJob->id . ' angelegt',
                    );
                } catch (Throwable $error) {
                    $this->view->flash('danger', $error->getMessage(), 'Korrektur nicht eingeplant');
                }
            } else {
                $itemId = (int) ($_POST['item_id'] ?? 0);
                if (isset($_POST['requeue_current_mode'])) {
                    $newJobId = ($_POST['confirm_mail_free_requeue'] ?? '') === 'yes'
                        ? $this->application->jobs->requeueExportDocument(
                            $itemId,
                            $this->requestedExportContext(),
                            $this->adminId(),
                        )
                        : null;
                    $ok = $newJobId !== null;
                } elseif (isset($_POST['confirm_email_retry'])) {
                    $ok = ($_POST['confirm_duplicate_delivery_risk'] ?? '') === 'yes'
                        && $this->application->jobs->confirmEmailRetry($itemId);
                } else {
                    $ok = isset($_POST['reconcile'])
                        ? $this->application->jobs->reconcile($itemId)
                        : $this->application->jobs->retry($itemId);
                }
                $this->view->flash(
                    $ok ? 'success' : 'warning',
                    $ok && isset($newJobId)
                        ? 'Ein neuer mailfreier Exportjob wurde im aktuell bestätigten Modus angelegt. Der alte Job bleibt als Nachweis erhalten.'
                        : ($ok
                        ? 'Die Position wurde mit der erforderlichen Bestätigung erneut eingeplant.'
                        : 'Die Position konnte nicht erneut eingeplant werden; prüfen Sie Status und Warnbestätigung.'),
                    $ok ? 'Aktion eingeplant' : 'Keine Änderung'
                );
            }
        }

        $jobId = (int) ($_GET['job_id'] ?? 0);
        $status = trim((string) ($_GET['status'] ?? ''));
        $query = trim((string) ($_GET['q'] ?? ''));
        $items = $this->application->jobs->reviewItems($jobId > 0 ? $jobId : null, $status, $query);
        foreach ($items as $item) {
            $item->can_retry = (string) $item->status === 'permanent_failed'
                && !in_array((string) ($item->error_code ?? ''), [
                    'booking_not_applied',
                    'manual_review_required',
                    'stale_export_context_requeue_required',
                ], true);
            $item->can_requeue_current_mode = (string) $item->status === 'permanent_failed'
                && (string) ($item->error_code ?? '') === 'stale_export_context_requeue_required';
            $item->can_confirm_email_retry = (string) $item->status === 'ambiguous'
                && (string) $item->action === 'export_document'
                && (string) $item->checkpoint === 'whmcs_email_write_requested';
            $item->can_reconcile = (string) $item->status === 'ambiguous'
                && !$item->can_confirm_email_retry;
            $item->recommendation = $item->can_confirm_email_retry
                ? 'Der frühere Provider-Übergang ist nicht lesend beweisbar. Ein erneuter Versand kann zu einer Doppelmail führen.'
                : ($item->can_requeue_current_mode
                    ? 'Der alte Belegpfad bleibt eingefroren. Nach Prüfung kann ein neuer, mailfreier Job im aktuellen Modus angelegt werden.'
                : ($item->can_reconcile
                    ? 'Zuerst anhand des WHMCS-Markers in sevdesk abgleichen.'
                    : 'Konfiguration oder Belegdaten korrigieren und danach erneut versuchen.'));
        }
        $counts = $this->application->jobs->statusCounts();
        $refundTransactions = [];
        foreach (
            Capsule::table('tblaccounts as account')
            ->join('tblinvoices as invoice', 'account.invoiceid', '=', 'invoice.id')
            ->where('account.amountout', '>', 0)
            ->orderByDesc('account.id')
            ->limit(100)
            ->get([
                'account.id', 'account.invoiceid', 'account.date', 'account.amountout',
                'account.transid', 'account.refundid', 'account.description', 'account.gateway',
                'invoice.invoicenum',
            ]) as $refund
        ) {
            if (!$this->application->whmcs->isVerifiedRefundTransaction($refund)) {
                continue;
            }
            $refundTransactions[] = [
                'id' => (int) $refund->id,
                'invoice_id' => (int) $refund->invoiceid,
                'invoicenum' => (string) $refund->invoicenum,
                'date' => (string) $refund->date,
                'amount' => number_format((float) $refund->amountout, 2, ',', '.'),
                'transid_short' => mb_substr((string) $refund->transid, 0, 24),
            ];
        }

        $this->render('corrections.tpl', 'corrections', [
            'filters' => ['job_id' => $jobId ?: '', 'status' => $status, 'q' => $query],
            'items' => $items,
            'stats' => [
                'ambiguous' => $counts['ambiguous'],
                'failed' => $counts['failed'],
                'incomplete' => $this->application->mappings->counts()['ambiguous'],
                'retryable' => count(array_filter($items, static fn (object $item): bool => $item->can_retry)),
            ],
            'createdJob' => $createdJob,
            'refundTransactions' => $refundTransactions,
        ]);
    }

    public function health(): void
    {
        if ($this->isPost()) {
            $this->csrf->assertPost();
        }
        $health = (new HealthService($this->application))->run(true);
        $this->render('health.tpl', 'health', [
            'healthChecks' => $health['checks'],
            'stats' => $health['stats'],
        ]);
    }

    public function notFound(): void
    {
        http_response_code(404);
        $this->render('error.tpl', 'error', [
            'error' => [
                'title' => 'Seite nicht gefunden',
                'message' => 'Die angeforderte Modulroute existiert nicht.',
                'reference' => 'HTTP 404',
            ],
        ]);
    }

    private function legacyMappingContext(int $mappingId): object
    {
        if ($mappingId < 1) {
            throw new RuntimeException('Eine gültige Mapping-ID ist erforderlich.');
        }
        $mapping = Capsule::table(Migrator::MAPPING_TABLE . ' as mapping')
            ->leftJoin('tblinvoices as invoice', 'mapping.invoice_id', '=', 'invoice.id')
            ->where('mapping.id', $mappingId)
            ->first([
                'mapping.id',
                'mapping.invoice_id',
                'mapping.sevdesk_id',
                'mapping.document_type',
                'mapping.document_authority',
                'invoice.id as existing_invoice_id',
                'invoice.invoicenum',
                'invoice.status as invoice_status',
            ]);
        if (
            $mapping === null
            || (int) ($mapping->invoice_id ?? 0) < 1
            || (int) ($mapping->existing_invoice_id ?? 0) !== (int) $mapping->invoice_id
            || preg_match('/^[1-9]\d*$/', trim((string) ($mapping->sevdesk_id ?? ''))) !== 1
            || trim((string) ($mapping->document_authority ?? '')) !== ''
            || (
                trim((string) ($mapping->document_type ?? '')) !== ''
                && !in_array((string) $mapping->document_type, ['voucher', 'invoice'], true)
            )
        ) {
            throw new RuntimeException(
                'Nur vollständige Legacy-Zuordnungen ohne bestätigte Dokumenthoheit können geprüft werden.',
            );
        }
        $frozenDocument = DocumentDeliveryContext::frozenConfirmedDocument(
            $this->application->jobs->latestDocumentContextForInvoice((int) $mapping->invoice_id, true),
        );
        $mapping->frozen_document_type = $frozenDocument['documentType'] ?? '';
        $mapping->frozen_document_authority = $frozenDocument['documentAuthority'] ?? '';

        return $mapping;
    }

    private static function legacyInvoiceIsPaid(object $mapping): bool
    {
        return strcasecmp(trim((string) ($mapping->invoice_status ?? '')), 'Paid') === 0;
    }

    private static function assertLegacyAuthorityStatus(object $mapping, string $documentAuthority): void
    {
        if (
            $documentAuthority === MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
            && !self::legacyInvoiceIsPaid($mapping)
        ) {
            throw new RuntimeException(
                'sevdesk-Hoheit kann erst nach vollständiger Zahlung der WHMCS-Rechnung bestätigt werden.',
            );
        }
    }

    private static function legacyFrozenDocumentMatches(object $mapping, string $documentType): bool
    {
        $frozenType = trim((string) ($mapping->frozen_document_type ?? ''));

        return $frozenType === '' || $frozenType === $documentType;
    }

    private static function assertLegacyFrozenDocument(
        object $mapping,
        string $documentType,
        string $documentAuthority,
    ): void {
        $frozenType = trim((string) ($mapping->frozen_document_type ?? ''));
        $frozenAuthority = trim((string) ($mapping->frozen_document_authority ?? ''));
        if (
            ($frozenType !== '' && $frozenType !== $documentType)
            || ($frozenAuthority !== '' && $frozenAuthority !== $documentAuthority)
        ) {
            throw new RuntimeException(
                'Typ und Hoheit müssen der bereits eingefrorenen Dokumententscheidung entsprechen.',
            );
        }
    }

    /** @param array<string,mixed> $result */
    private function handleLegacyMappingTypeFailure(array $result): void
    {
        $code = (string) ($result['code'] ?? 'legacy_mapping_type_check_failed');
        if ($code === 'api_authentication_failed') {
            $safety = $this->application->config->tripAuthenticationSafetyGates();
            if (
                function_exists('logActivity')
                && (!$safety['alarm'] || !$safety['syncDisabled'])
            ) {
                logActivity('sevdesk legacy mapping authentication safety gates required fallback.');
            }
        }
        $message = match ($code) {
            'legacy_mapping_type_collision' =>
                'Die gespeicherte ID passt gleichzeitig zu Voucher und Invoice. Es wurde kein Typ übernommen.',
            'legacy_mapping_type_no_match' =>
                'Weder Voucher noch Invoice stimmen bei Marker und WHMCS-Referenz vollständig überein.',
            'legacy_mapping_confirmation_changed' =>
                'Das Ergebnis der erneuten Remote-Prüfung weicht von der Bestätigung ab.',
            'api_authentication_failed' =>
                'sevdesk hat die Zugangsdaten abgelehnt; die Synchronisation wurde vorsorglich deaktiviert.',
            default => 'Die read-only Typprüfung war nicht eindeutig oder nicht erreichbar.',
        };
        $this->view->flash(
            ($result['status'] ?? '') === 'failed' ? 'danger' : 'warning',
            $message,
            'Belegtyp nicht bestätigt',
        );
    }

    private function deleteMapping(int $mappingId, int $invoiceId): void
    {
        if ($mappingId < 1) {
            $this->view->flash(
                'danger',
                'Die lokale Zuordnungs-ID fehlt. Es wurde nichts verändert.',
                'Entkopplung abgebrochen',
            );
            return;
        }

        $storedMapping = Capsule::table(Migrator::MAPPING_TABLE)
            ->where('id', $mappingId)
            ->first(['invoice_id', 'sevdesk_id', 'document_type']);
        $storedInvoiceId = (int) ($storedMapping->invoice_id ?? 0);
        if ($storedInvoiceId < 1) {
            $this->view->flash(
                'danger',
                'Die angeforderte Zuordnung existiert nicht mehr oder ist unvollständig.',
                'Entkopplung abgebrochen',
            );
            return;
        }
        if ($invoiceId > 0 && $invoiceId !== $storedInvoiceId) {
            $this->view->flash(
                'danger',
                'Zuordnungs-ID und Rechnungs-ID widersprechen sich. Es wurde nichts verändert.',
                'Entkopplung abgebrochen',
            );
            return;
        }
        $invoiceId = $storedInvoiceId;
        $activeAccountingItem = Capsule::table(Migrator::ITEMS_TABLE)
            ->where('invoice_id', $invoiceId)
            ->whereIn('action', [
                'export_voucher',
                'export_document',
                'reconcile_voucher',
                'book_payment',
                'correction_voucher',
            ])
            ->whereIn('status', ['pending', 'running', 'retry_wait', 'ambiguous'])
            ->exists();
        if ($activeAccountingItem) {
            $this->view->flash(
                'danger',
                'Für diese Rechnung läuft ein Export oder ein ungeklärter Remote-Write. Die Zuordnung bleibt geschützt.',
                'Entkopplung blockiert',
            );
            return;
        }

        $remoteId = trim((string) ($storedMapping->sevdesk_id ?? ''));
        $documentType = ($storedMapping->document_type ?? null) === null
            ? null
            : trim((string) $storedMapping->document_type);
        $remoteMissingConfirmed = false;
        if ($remoteId !== '') {
            if (preg_match('/^[1-9]\d*$/', $remoteId) !== 1) {
                $this->view->flash(
                    'danger',
                    'Die gespeicherte sevdesk-ID ist nicht sicher prüfbar. Die Zuordnung bleibt erhalten.',
                    'Entkopplung blockiert',
                );
                return;
            }
            try {
                $remoteMissingConfirmed = $this->remoteDocumentDefinitelyMissing('Voucher', $remoteId)
                    && $this->remoteDocumentDefinitelyMissing('Invoice', $remoteId);
            } catch (Throwable) {
                $this->view->flash(
                    'danger',
                    'Der Remote-Bestand konnte nicht eindeutig read-only geprüft werden. Die Zuordnung bleibt erhalten.',
                    'Entkopplung blockiert',
                );
                return;
            }
            if (!$remoteMissingConfirmed) {
                $this->view->flash(
                    'danger',
                    'Unter der gespeicherten ID ist weiterhin ein sevdesk-Beleg vorhanden oder der fehlende Bestand '
                        . 'ist nicht eindeutig nachgewiesen. Die Zuordnung bleibt geschützt.',
                    'Entkopplung blockiert',
                );
                return;
            }
        }

        $removed = $this->application->mappings->unlinkById(
            $mappingId,
            $remoteMissingConfirmed,
            $remoteId !== '' ? $remoteId : null,
            $documentType,
        );
        if (!$removed) {
            return;
        }
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk: local mapping detached by admin ' . $this->adminId()
                . '; invoice ' . $invoiceId
                . '; mapping ' . $mappingId,
            );
        }
        $this->view->flash(
            'warning',
            $remoteId === ''
                ? 'Die unvollständige lokale Reservierung wurde entfernt; eine Remote-ID war nicht gespeichert.'
                : 'Die lokale Zuordnung wurde erst entfernt, nachdem Voucher und Invoice unter dieser ID nicht gefunden wurden.',
            'Zuordnung aufgehoben',
        );
    }

    /** Definitive absence is intentionally narrower than normal read recovery. */
    private function remoteDocumentDefinitelyMissing(string $resource, string $remoteId): bool
    {
        try {
            $this->application->client()->get('/' . $resource . '/' . rawurlencode($remoteId));

            return false;
        } catch (ApiException $error) {
            if ($error->isAuthenticationFailure()) {
                $this->application->config->tripAuthenticationSafetyGates();
            }
            // sevdesk documents 400 for a missing by-ID Voucher or Invoice;
            // accept 404 as a compatible conventional response as well.
            if (in_array($error->httpStatus, [400, 404], true)) {
                return true;
            }

            throw $error;
        }
    }

    /**
     * Freeze the operator-selected document context at every admin enqueue.
     * Manual and historical exports deliberately never request delivery.
     *
     * @return array<string, scalar|null>
     */
    private function requestedExportContext(): array
    {
        $authority = (string) $this->application->config->get(
            'document_authority',
            DocumentTargetResolver::AUTHORITY_WHMCS,
        );
        $storedEInvoiceActiveFrom = (string) $this->application->config->get('e_invoice_active_from', '');
        $eInvoiceActiveFrom = DateTimeImmutable::createFromFormat(
            '!d-m-Y',
            $storedEInvoiceActiveFrom,
        );
        $requestedEInvoiceActiveFrom = $eInvoiceActiveFrom instanceof DateTimeImmutable
            && $eInvoiceActiveFrom->format('d-m-Y') === $storedEInvoiceActiveFrom
                ? $eInvoiceActiveFrom->format('Y-m-d')
                : '';

        return [
            'requestedExportMode' => (string) $this->application->config->get(
                'export_mode',
                DocumentTargetResolver::MODE_VOUCHER_ONLY,
            ),
            'requestedDocumentAuthority' => $authority,
            'requestedOssProfile' => (string) $this->application->config->get(
                'oss_profile',
                DocumentTargetResolver::OSS_BLOCKED,
            ),
            'requestedEuB2cMode' => (string) $this->application->config->get('eu_b2c_mode', 'blocked'),
            'requestedDeliveryChannel' => $authority === DocumentTargetResolver::AUTHORITY_SEVDESK
                ? (string) $this->application->config->get('invoice_delivery_channel', 'sevdesk')
                : null,
            'requestedEInvoiceMode' => (string) $this->application->config->get('e_invoice_mode', 'off'),
            'requestedEInvoiceClientFieldId' => $this->application->config->int('e_invoice_client_field_id'),
            'requestedEInvoicePaymentMethodId' => trim((string) $this->application->config->get(
                'e_invoice_payment_method_id',
                '',
            )),
            'requestedEInvoiceActiveFrom' => $requestedEInvoiceActiveFrom,
            'requestedEInvoiceCanaryConfirmed' => $this->application->config->bool(
                'e_invoice_canary_confirmed',
            ),
            'requestedEInvoiceSevUserId' => trim((string) $this->application->config->get(
                'invoice_sev_user_id',
                '',
            )),
            'requestedEInvoiceUnityId' => trim((string) $this->application->config->get(
                'invoice_unity_id',
                '',
            )),
            'delivery_requested' => false,
        ];
    }

    /**
     * Build the local, read-only transition snapshot shown before document
     * mode changes. It contains only counters and technical high-water marks,
     * never invoice content or customer data.
     *
     * @return array<string,int|string>
     */
    private function transitionInventory(): array
    {
        $exportActions = ['export_document', 'export_voucher', 'reconcile_voucher'];
        $complete = static fn ($query) => $query
            ->whereNotNull('mapping.sevdesk_id')
            ->whereRaw("TRIM(mapping.sevdesk_id) <> ''");

        $typedVoucher = (int) $complete(Capsule::table(Migrator::MAPPING_TABLE . ' as mapping'))
            ->where('mapping.document_type', 'voucher')
            ->count();
        $typedInvoice = (int) $complete(Capsule::table(Migrator::MAPPING_TABLE . ' as mapping'))
            ->where('mapping.document_type', 'invoice')
            ->count();
        $untypedComplete = (int) $complete(Capsule::table(Migrator::MAPPING_TABLE . ' as mapping'))
            ->whereNull('mapping.document_type')
            ->count();
        $nullRemote = (int) Capsule::table(Migrator::MAPPING_TABLE . ' as mapping')
            ->where(static function ($query): void {
                $query->whereNull('mapping.sevdesk_id')->orWhereRaw("TRIM(mapping.sevdesk_id) = ''");
            })
            ->count();
        $orphans = (int) Capsule::table(Migrator::MAPPING_TABLE . ' as mapping')
            ->leftJoin('tblinvoices as invoice', 'mapping.invoice_id', '=', 'invoice.id')
            ->whereNull('invoice.id')
            ->count();

        $exportItems = static fn () => Capsule::table(Migrator::ITEMS_TABLE . ' as item')
            ->whereIn('item.action', $exportActions);
        $activeJobs = (int) $exportItems()
            ->whereIn('item.status', ['pending', 'running', 'retry_wait'])
            ->distinct()
            ->count('item.job_id');
        $ambiguousJobs = (int) $exportItems()
            ->where('item.status', 'ambiguous')
            ->distinct()
            ->count('item.job_id');
        $failedJobs = (int) $exportItems()
            ->where('item.status', 'permanent_failed')
            ->distinct()
            ->count('item.job_id');
        $possibleRemoteDuplicates = (int) $exportItems()
            ->whereNotNull('item.invoice_id')
            ->where(static function ($query): void {
                $query->where('item.status', 'ambiguous')
                    ->orWhere(static function ($failed): void {
                        $failed->whereIn('item.status', ['permanent_failed', 'cancelled'])
                            ->whereIn('item.checkpoint', JobRepository::riskyCheckpoints());
                    });
            })
            ->distinct()
            ->count('item.invoice_id');

        $importAfter = DateTimeImmutable::createFromFormat(
            '!d-m-Y',
            (string) $this->application->config->get('import_after', '01-01-1999'),
        );
        $paidUnmappedQuery = Capsule::table('tblinvoices as invoice')
            ->leftJoin(Migrator::MAPPING_TABLE . ' as mapping', 'invoice.id', '=', 'mapping.invoice_id')
            ->where('invoice.status', 'Paid')
            ->whereNull('mapping.id');
        if ($importAfter instanceof DateTimeImmutable) {
            $paidUnmappedQuery->where('invoice.date', '>=', $importAfter->format('Y-m-d'));
        }

        $inventory = [
            'typed_vouchers' => $typedVoucher,
            'typed_invoices' => $typedInvoice,
            'untyped_complete' => $untypedComplete,
            'null_remote_mappings' => $nullRemote,
            'orphan_mappings' => $orphans,
            'active_export_jobs' => $activeJobs,
            'ambiguous_export_jobs' => $ambiguousJobs,
            'failed_export_jobs' => $failedJobs,
            'paid_unmapped' => (int) $paidUnmappedQuery->count(),
            'possible_remote_duplicates' => $possibleRemoteDuplicates,
            'mapping_high_water' => (int) (Capsule::table(Migrator::MAPPING_TABLE)->max('id') ?? 0),
            'item_high_water' => (int) (Capsule::table(Migrator::ITEMS_TABLE)->max('id') ?? 0),
            'invoice_high_water' => (int) (Capsule::table('tblinvoices')->max('id') ?? 0),
        ];
        $protectedProfile = [];
        $protectedSettings = [
            'export_mode',
            'document_authority',
            'oss_profile',
            'eu_b2c_mode',
            'invoice_canary_confirmed',
            'small_business_invoice_canary_confirmed',
            'invoice_discount_canary_confirmed',
            'invoice_sev_user_id',
            'invoice_unity_id',
            'e_invoice_mode',
            'e_invoice_client_field_id',
            'e_invoice_payment_method_id',
            'e_invoice_active_from',
            'e_invoice_canary_confirmed',
            'invoice_delivery_channel',
            'smallBusinessOwner',
            'small_business_until',
            'small_business_confirmed',
            'accountingTypeSmallBusinessOwner',
            'taxRuleSmallBusinessOwner',
        ];
        foreach ($protectedSettings as $setting) {
            $protectedProfile[$setting] = (string) $this->application->config->get($setting, '');
        }
        $inventory['protected_profile_fingerprint'] = hash(
            'sha256',
            json_encode($protectedProfile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $inventory['fingerprint'] = hash(
            'sha256',
            json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        return $inventory;
    }

    private function saveSetup(): void
    {
        $lock = Capsule::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['whmcs_sevdesk_job_runner']);
        if (!isset($lock->acquired) || (int) $lock->acquired !== 1) {
            throw new RuntimeException('Ein Worker ist gerade aktiv. Bitte die Einrichtung nach dessen Abschluss erneut speichern.');
        }

        try {
            // This is a purely local lease transition. It cannot invoke a
            // handler and remains safe while replacement inventory is
            // quarantined. Without it, a crashed old worker could leave an
            // expired `running` row that permanently deadlocks setup review.
            $this->application->jobs->recoverExpiredLeasesForSafety();
            $activeQuery = Capsule::table(Migrator::ITEMS_TABLE . ' as item')
                ->join(Migrator::JOBS_TABLE . ' as job', 'item.job_id', '=', 'job.id')
                ->whereIn('item.status', ['pending', 'running', 'retry_wait']);
            $unsafeActiveItems = (clone $activeQuery)
                ->where(static function ($query): void {
                    $query->where('item.status', 'running')
                        ->orWhere('job.status', '!=', 'paused');
                })
                ->exists();
            if ($unsafeActiveItems) {
                throw new RuntimeException(
                    'Steuer- und Kontoeinstellungen können nicht geändert werden, solange aktive Jobs laufen. '
                    . 'Bitte Jobs zuerst pausieren, abschließen oder abbrechen.',
                );
            }
            $pausedItems = (clone $activeQuery)->where('job.status', 'paused')->exists();
            if ($pausedItems && $this->operationalSettingsChanged()) {
                throw new RuntimeException(
                    'Bei pausierten Jobs dürfen nur API-Token, Diagnoseprotokoll und Synchronisationsschalter '
                    . 'geändert werden. Für Steuer- oder Kontenänderungen müssen die Jobs zuerst abgebrochen werden.',
                );
            }
            // Hooks do not participate in the runner advisory lock. Commit the
            // safety gate before validation so they cannot enqueue against a
            // half-validated setup; every other setting is persisted atomically.
            $this->application->config->set('sync_enabled', '');
            Capsule::connection()->transaction(function (): void {
                $this->saveSetupWhileLocked();
            });
        } finally {
            try {
                Capsule::selectOne('SELECT RELEASE_LOCK(?) AS released', ['whmcs_sevdesk_job_runner']);
            } finally {
                // Config may have cached values written inside a transaction
                // that subsequently rolled back. The response must always read
                // the durable post-rollback/post-commit state.
                $this->application->config->refresh();
            }
        }
    }

    private function flashSetupFailure(Throwable $error): void
    {
        if (get_class($error) === RuntimeException::class) {
            $message = $error->getMessage();
        } elseif ($error instanceof ApiException) {
            $message = 'Die sevdesk-Prüfung konnte nicht abgeschlossen werden'
                . ($error->httpStatus !== null ? ' (HTTP ' . $error->httpStatus . ')' : '')
                . '.';
        } else {
            $reference = substr(
                hash('sha256', get_class($error) . '|' . microtime(true)),
                0,
                12,
            );
            if (function_exists('logActivity')) {
                try {
                    logActivity(
                        'sevdesk setup failed [' . $reference . ']: ' . get_class($error),
                    );
                } catch (Throwable) {
                    // The sanitized setup response must not depend on logging.
                }
            }
            $message = 'Die Einstellungen konnten aufgrund eines internen Fehlers nicht gespeichert werden. '
                . 'Es wurden keine Fehlerdetails ausgegeben. Referenz: ' . $reference;
        }

        $this->view->flash('danger', $message, 'Einstellungen nicht gespeichert');
    }

    private function saveSetupWhileLocked(): void
    {
        // Runtime settings are locked before any remote validation. This follows
        // the global settings -> job -> item order and serializes setup against
        // quarantine, deactivation, auth alarms and new worker claims.
        $runtimeGates = $this->application->config->lockRuntimeGates();
        $runtimeReviewRequired = $runtimeGates['runtimeReviewRequired'];
        $submittedQuarantineToken = (string) ($_POST['runtime_quarantine_token'] ?? '');
        if (
            $runtimeReviewRequired
            && (string) ($_POST['runtime_review_confirmed'] ?? '') !== '1'
        ) {
            throw new RuntimeException(
                'Der übernommene Bestand muss vor der Freigabe ausdrücklich geprüft und bestätigt werden.',
            );
        }
        if (
            $runtimeReviewRequired
            && !hash_equals($runtimeGates['quarantineToken'], $submittedQuarantineToken)
        ) {
            throw new RuntimeException(
                'Die Laufzeitquarantäne wurde seit dem Öffnen der Einrichtung erneuert. '
                    . 'Bitte die Seite neu laden und den aktuellen Bestand erneut prüfen.',
            );
        }
        if (
            $runtimeReviewRequired
            && $runtimeGates['runtimeSignature'] !== Config::RUNTIME_SIGNATURE
        ) {
            throw new RuntimeException(
                'Die lokale Laufzeitprüfung ist nicht vollständig. Bitte zuerst Migration und Schemafehler klären.',
            );
        }

        $token = trim((string) ($_POST['sevdesk_api_key'] ?? ''));
        $tokenChanged = $token !== '';
        if ($token !== '') {
            if (strlen($token) > 512 || preg_match('/[\x00-\x20\x7F]/', $token) === 1) {
                throw new RuntimeException('Der API-Token enthält ungültige Zeichen.');
            }
            $this->application->config->set('sevdesk_api_key', $token);
        }

        $date = $this->parseIsoDate((string) ($_POST['import_after'] ?? ''));
        if ($date === null) {
            throw new RuntimeException('Bitte einen gültigen Exportstichtag wählen.');
        }
        $smallBusinessOwner = isset($_POST['smallBusinessOwner']);
        $smallBusinessUntilInput = trim((string) ($_POST['small_business_until'] ?? ''));
        $smallBusinessUntil = $smallBusinessUntilInput === ''
            ? null
            : $this->parseIsoDate($smallBusinessUntilInput);
        if ($smallBusinessUntilInput !== '' && !($smallBusinessUntil instanceof DateTimeImmutable)) {
            throw new RuntimeException('Bitte einen gültigen Kleinunternehmer-Stichtag wählen.');
        }
        $customFieldId = (int) ($_POST['custom_field_id'] ?? 0);
        if ($customFieldId < 1 || !Capsule::table('tblcustomfields')->where('id', $customFieldId)->where('type', 'client')->exists()) {
            throw new RuntimeException('Das gewählte WHMCS-Kundenfeld existiert nicht.');
        }
        $customerNumberContactCreationConfirmed = isset($_POST['customer_number_contact_creation_confirmed']);

        $exportMode = trim((string) ($_POST['export_mode'] ?? 'voucher_only'));
        if (!in_array($exportMode, ['voucher_only', 'invoice_for_oss', 'invoice_only'], true)) {
            throw new RuntimeException('Ungültiger Exportmodus.');
        }
        $documentAuthority = trim((string) ($_POST['document_authority'] ?? 'whmcs'));
        if (!in_array($documentAuthority, ['whmcs', 'sevdesk'], true)) {
            throw new RuntimeException('Ungültige Dokumenthoheit.');
        }
        $enableSync = isset($_POST['sync_enabled']);
        if ($documentAuthority === 'sevdesk' && $exportMode !== 'invoice_only') {
            throw new RuntimeException('sevdesk-Dokumenthoheit ist ausschließlich mit „Invoice only“ zulässig.');
        }
        if ($documentAuthority === 'sevdesk' && !$enableSync) {
            throw new RuntimeException(
                'sevdesk-Dokumenthoheit benötigt die automatische Einreihung neuer Rechnungen. '
                    . 'Bitte „Neue Rechnungen automatisch als Exportjob einreihen“ aktivieren.',
            );
        }

        $ossProfile = trim((string) ($_POST['oss_profile'] ?? 'blocked'));
        if (!in_array($ossProfile, ['blocked', 'rule19_digital_services_confirmed'], true)) {
            throw new RuntimeException('Ungültiges OSS-Profil.');
        }
        if (
            $ossProfile === 'rule19_digital_services_confirmed'
            && (string) ($_POST['oss_profile_acknowledged'] ?? '') !== '1'
        ) {
            throw new RuntimeException(
                'Rule 19 darf nur nach ausdrücklicher Bestätigung ausschließlich elektronischer/digitaler Leistungen '
                    . 'freigegeben werden.',
            );
        }
        if ($ossProfile !== 'blocked' && $exportMode === 'voucher_only') {
            throw new RuntimeException('Das bestätigte OSS-Profil benötigt einen Invoice-fähigen Exportmodus.');
        }

        $invoiceCanaryConfirmed = isset($_POST['invoice_canary_confirmed']);
        $smallBusinessInvoiceCanaryConfirmed = isset(
            $_POST['small_business_invoice_canary_confirmed'],
        );
        $invoiceDiscountCanaryConfirmed = isset($_POST['invoice_discount_canary_confirmed']);
        $sevUserId = trim((string) ($_POST['invoice_sev_user_id'] ?? ''));
        $unityId = trim((string) ($_POST['invoice_unity_id'] ?? ''));
        if ($exportMode !== 'voucher_only') {
            if (!$invoiceCanaryConfirmed) {
                throw new RuntimeException('Invoice-Modi bleiben gesperrt, bis der dokumentierte Testmandanten-Canary bestätigt ist.');
            }
            if (preg_match('/^[1-9]\d*$/', $sevUserId) !== 1 || preg_match('/^[1-9]\d*$/', $unityId) !== 1) {
                throw new RuntimeException('Invoice-Modi benötigen einen gültigen SevUser und eine Standard-Unity.');
            }
        }
        if (
            $invoiceDiscountCanaryConfirmed
            && !$smallBusinessInvoiceCanaryConfirmed
        ) {
            throw new RuntimeException(
                'Der Invoice-Rabatt-Canary setzt zuerst den allgemeinen Rule-11-Invoice-Canary voraus.',
            );
        }
        if (
            $smallBusinessInvoiceCanaryConfirmed
            && !TaxPolicy::guidanceSupportsInvoiceRuleEleven(
                $this->application->referenceData()->receiptGuidance(true),
            )
        ) {
            throw new RuntimeException(
                'Der aktuelle sevdesk-Mandant bietet in Receipt Guidance kein REVENUE-Konto '
                    . 'für Rule 11 mit 0 % an. Rule-11-Invoices bleiben gesperrt.',
            );
        }

        $eInvoiceMode = trim((string) ($_POST['e_invoice_mode'] ?? 'off'));
        if (!in_array($eInvoiceMode, ['off', 'zugferd_domestic_b2b'], true)) {
            throw new RuntimeException('Ungültiger E-Rechnungsmodus.');
        }
        $eInvoiceClientFieldId = (int) ($_POST['e_invoice_client_field_id'] ?? 0);
        $eInvoicePaymentMethodId = trim((string) ($_POST['e_invoice_payment_method_id'] ?? ''));
        $eInvoiceActiveFromInput = trim((string) ($_POST['e_invoice_active_from'] ?? ''));
        $eInvoiceActiveFrom = $eInvoiceActiveFromInput === ''
            ? null
            : $this->parseIsoDate($eInvoiceActiveFromInput);
        $eInvoiceCanaryConfirmed = isset($_POST['e_invoice_canary_confirmed']);
        if ($eInvoicePaymentMethodId !== '' && preg_match('/^[1-9]\d*$/', $eInvoicePaymentMethodId) !== 1) {
            throw new RuntimeException('Die sevdesk-Zahlungsmethode muss eine gültige numerische ID sein.');
        }
        if ($eInvoiceActiveFromInput !== '' && !($eInvoiceActiveFrom instanceof DateTimeImmutable)) {
            throw new RuntimeException('Bitte ein gültiges Aktivierungsdatum für E-Rechnungen wählen.');
        }
        if (
            $eInvoiceClientFieldId > 0
            && !$this->application->whmcs->isEInvoiceOptInField($eInvoiceClientFieldId)
        ) {
            throw new RuntimeException(
                'Das E-Rechnungs-Kundenfeld muss ein vorhandenes, nur für Administratoren sichtbares Tickbox-Feld sein.',
            );
        }
        if ($eInvoiceMode === 'zugferd_domestic_b2b') {
            if (!class_exists(\XMLReader::class)) {
                throw new RuntimeException('ZUGFeRD benötigt die PHP-Erweiterung XMLReader.');
            }
            if ($exportMode !== 'invoice_only' || $documentAuthority !== 'sevdesk') {
                throw new RuntimeException(
                    'ZUGFeRD ist ausschließlich mit „Invoice only“ und sevdesk-Dokumenthoheit zulässig.',
                );
            }
            if (!$eInvoiceCanaryConfirmed) {
                throw new RuntimeException(
                    'ZUGFeRD bleibt gesperrt, bis der separate E-Rechnungs-Canary bestätigt ist.',
                );
            }
            if (
                $eInvoiceClientFieldId < 1
                || !$this->application->whmcs->isEInvoiceOptInField($eInvoiceClientFieldId)
            ) {
                throw new RuntimeException(
                    'ZUGFeRD benötigt ein vorhandenes, nur für Administratoren sichtbares Kunden-Tickbox-Feld.',
                );
            }
            if (
                preg_match('/^[1-9]\d*$/', $eInvoicePaymentMethodId) !== 1
                || !($eInvoiceActiveFrom instanceof DateTimeImmutable)
            ) {
                throw new RuntimeException(
                    'ZUGFeRD benötigt eine sevdesk-Zahlungsmethode und ein Aktivierungsdatum.',
                );
            }
            if ((string) ($_POST['e_invoice_profile_acknowledged'] ?? '') !== '1') {
                throw new RuntimeException(
                    'Die Grenzen des deutschen B2B-ZUGFeRD-Profils müssen ausdrücklich bestätigt werden.',
                );
            }
        }

        $deliveryChannel = trim((string) ($_POST['invoice_delivery_channel'] ?? 'sevdesk'));
        if (!in_array($deliveryChannel, ['sevdesk', 'whmcs_template'], true)) {
            throw new RuntimeException('Ungültiger Versandkanal.');
        }
        if (
            $documentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK
            &&
            $deliveryChannel === 'whmcs_template'
            && !$this->application->whmcs->supportsEmailPreSendAttachments()
        ) {
            throw new RuntimeException(
                'WHMCS 8.13 unterstützt keine Binäranhänge aus EmailPreSend. '
                    . 'Bitte den Versandkanal „sevdesk sendViaEmail“ wählen.',
            );
        }
        $emailTemplate = trim((string) ($_POST['whmcs_invoice_email_template'] ?? ''));
        $emailSubject = trim((string) ($_POST['sevdesk_email_subject']
            ?? $this->application->config->get('sevdesk_email_subject', 'Ihre Rechnung {invoice_number}')));
        $emailBody = trim((string) ($_POST['sevdesk_email_body']
            ?? $this->application->config->get('sevdesk_email_body', 'Ihre Rechnung {invoice_number}')));

        $themeAdapterConfirmed = isset($_POST['theme_adapter_confirmed']);
        if ($documentAuthority === 'sevdesk') {
            if (!$this->application->whmcs->proformaInvoicingEnabled()) {
                throw new RuntimeException('Vor sevdesk-Dokumenthoheit muss WHMCS „Enable Proforma Invoicing“ aktiv sein.');
            }
            if (
                !$themeAdapterConfirmed
                || !$this->application->whmcs->themeAdapterManifestInstalled()
            ) {
                throw new RuntimeException(
                    'sevdesk-Dokumenthoheit benötigt den installierten Twenty-One-Adapter und die ausdrückliche Bestätigung.',
                );
            }
            if (
                $deliveryChannel === 'whmcs_template'
                && !$this->application->whmcs->isActiveCustomInvoiceTemplate($emailTemplate)
            ) {
                throw new RuntimeException('Bitte eine aktive benutzerdefinierte Invoice-Mailvorlage auswählen.');
            }
            if ($deliveryChannel === 'sevdesk') {
                self::validateDeliveryText($emailSubject, $emailBody);
            }
        }

        $mode = (string) ($_POST['eu_b2c_mode'] ?? 'blocked');
        if (!in_array($mode, ['blocked', 'domestic_confirmed'], true)) {
            throw new RuntimeException('Ungültiger EU-B2C-Modus.');
        }
        if ($mode === 'domestic_confirmed' && (string) ($_POST['eu_b2c_acknowledged'] ?? '') !== '1') {
            throw new RuntimeException('Die deutsche Besteuerung für EU-B2C muss ausdrücklich bestätigt werden.');
        }
        if ($ossProfile === 'rule19_digital_services_confirmed' && $mode !== 'blocked') {
            throw new RuntimeException(
                'Das Rule-19-OSS-Profil und die bisherige deutsche EU-B2C-Besteuerung dürfen nicht gleichzeitig '
                    . 'aktiv sein. Bitte EU-Privatkunden auf „Blockieren“ stellen.',
            );
        }
        if (
            !DocumentTargetResolver::contextValuesAreValid(
                $exportMode,
                $documentAuthority,
                $ossProfile,
                $mode,
                $documentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK ? $deliveryChannel : null,
            )
        ) {
            throw new RuntimeException('Exportmodus, Dokumenthoheit, OSS-Profil und Versandkanal sind nicht kompatibel.');
        }

        $modeChanged = $exportMode !== (string) $this->application->config->get('export_mode', 'voucher_only')
            || $documentAuthority !== (string) $this->application->config->get('document_authority', 'whmcs')
            || $ossProfile !== (string) $this->application->config->get('oss_profile', 'blocked')
            || $mode !== (string) $this->application->config->get('eu_b2c_mode', 'blocked');
        $documentProfileChanged = $modeChanged
            || $eInvoiceMode !== (string) $this->application->config->get('e_invoice_mode', 'off')
            || ($eInvoiceClientFieldId > 0 ? (string) $eInvoiceClientFieldId : '')
                !== (string) $this->application->config->get('e_invoice_client_field_id', '')
            || $eInvoicePaymentMethodId
                !== (string) $this->application->config->get('e_invoice_payment_method_id', '')
            || ($eInvoiceActiveFrom?->format('d-m-Y') ?? '')
                !== (string) $this->application->config->get('e_invoice_active_from', '')
            || ($eInvoiceCanaryConfirmed ? 'on' : '')
                !== (string) $this->application->config->get('e_invoice_canary_confirmed', '')
            || ($smallBusinessInvoiceCanaryConfirmed ? 'on' : '')
                !== (string) $this->application->config->get(
                    'small_business_invoice_canary_confirmed',
                    '',
                )
            || ($invoiceDiscountCanaryConfirmed ? 'on' : '')
                !== (string) $this->application->config->get('invoice_discount_canary_confirmed', '')
            || ($smallBusinessOwner ? 'on' : '')
                !== (string) $this->application->config->get('smallBusinessOwner', '')
            || ($smallBusinessUntil?->format('d-m-Y') ?? '')
                !== (string) $this->application->config->get('small_business_until', '')
            || (isset($_POST['small_business_confirmed']) ? 'on' : '')
                !== (string) $this->application->config->get('small_business_confirmed', '')
            || trim((string) ($_POST['accountingTypeSmallBusinessOwner'] ?? ''))
                !== (string) $this->application->config->get('accountingTypeSmallBusinessOwner', '')
            || trim((string) ($_POST['taxRuleSmallBusinessOwner'] ?? ''))
                !== (string) $this->application->config->get('taxRuleSmallBusinessOwner', '');
        if (
            $documentProfileChanged
            && Capsule::table(Migrator::ITEMS_TABLE)
                ->whereIn('action', ['export_document', 'export_voucher', 'reconcile_voucher'])
                ->where(static function ($query): void {
                    $query->whereIn('status', ['pending', 'running', 'retry_wait', 'ambiguous'])
                        ->orWhere(static function ($riskyTerminal): void {
                            $riskyTerminal
                                ->whereIn('status', ['permanent_failed', 'cancelled'])
                                ->whereIn('checkpoint', JobRepository::riskyCheckpoints());
                        });
                })
                ->exists()
        ) {
            throw new RuntimeException(
                'Exportmodus, Dokumenthoheit und Steuerprofile können erst geändert werden, wenn aktive und '
                    . 'ungeklärte Exportjobs abgeschlossen oder bereinigt sind.',
            );
        }
        if ($documentProfileChanged) {
            $inventory = $this->transitionInventory();
            $submittedFingerprint = trim((string) ($_POST['transition_inventory_fingerprint'] ?? ''));
            if (
                (string) ($_POST['transition_inventory_confirmed'] ?? '') !== '1'
                || $submittedFingerprint === ''
                || !hash_equals((string) $inventory['fingerprint'], $submittedFingerprint)
            ) {
                throw new RuntimeException(
                    'Vor einer Änderung an Dokumentmodus, Hoheit, OSS-, E-Rechnungs- oder Kleinunternehmerprofil '
                        . 'sowie an den Rule-11-Invoice-Gates muss die aktuelle '
                        . 'Übergangsinventur auf dieser Seite geprüft und ausdrücklich bestätigt werden.',
                );
            }
        }

        $numericSettings = [
            'accountingTypeGeneral', 'accountingTypeInterCommunityBusiness',
            'accountingTypeInterCommunityConsumer', 'accountingTypeThirdPartyCountry',
            'accountingTypeCredit', 'accountingTypeSmallBusinessOwner',
            'taxRuleGeneral', 'taxRuleInterCommunityBusiness', 'taxRuleInterCommunityConsumer',
            'taxRuleThirdPartyCountry', 'taxRuleCredit', 'taxRuleSmallBusinessOwner',
        ];
        foreach ($numericSettings as $setting) {
            $value = trim((string) ($_POST[$setting] ?? ''));
            if ($value !== '' && preg_match('/^\d+$/', $value) !== 1) {
                throw new RuntimeException('Konto- und TaxRule-IDs müssen numerisch sein.');
            }
            $this->application->config->set($setting, $value);
        }

        $this->application->config->set('import_after', $date->format('d-m-Y'));
        $this->application->config->set('custom_field_id', $customFieldId);
        $this->application->config->set(
            'customer_number_contact_creation_confirmed',
            $customerNumberContactCreationConfirmed,
        );
        $this->application->config->set('export_mode', $exportMode);
        $this->application->config->set('document_authority', $documentAuthority);
        $this->application->config->set('oss_profile', $ossProfile);
        $this->application->config->set('invoice_canary_confirmed', $invoiceCanaryConfirmed);
        $this->application->config->set(
            'small_business_invoice_canary_confirmed',
            $smallBusinessInvoiceCanaryConfirmed,
        );
        $this->application->config->set(
            'invoice_discount_canary_confirmed',
            $invoiceDiscountCanaryConfirmed,
        );
        $this->application->config->set('invoice_sev_user_id', $sevUserId);
        $this->application->config->set('invoice_unity_id', $unityId);
        $this->application->config->set('e_invoice_mode', $eInvoiceMode);
        $this->application->config->set('e_invoice_client_field_id', $eInvoiceClientFieldId ?: '');
        $this->application->config->set('e_invoice_payment_method_id', $eInvoicePaymentMethodId);
        $this->application->config->set(
            'e_invoice_active_from',
            $eInvoiceActiveFrom?->format('d-m-Y') ?? '',
        );
        $this->application->config->set('e_invoice_canary_confirmed', $eInvoiceCanaryConfirmed);
        $this->application->config->set('invoice_delivery_channel', $deliveryChannel);
        $this->application->config->set('whmcs_invoice_email_template', $emailTemplate);
        $this->application->config->set('sevdesk_email_subject', $emailSubject);
        $this->application->config->set('sevdesk_email_body', $emailBody);
        $this->application->config->set('theme_adapter_confirmed', $themeAdapterConfirmed);
        $this->application->config->set('import_only_paid', isset($_POST['import_only_paid']));
        $this->application->config->set('smallBusinessOwner', $smallBusinessOwner);
        $this->application->config->set(
            'small_business_until',
            $smallBusinessUntil?->format('d-m-Y') ?? '',
        );
        $this->application->config->set('eu_b2b_goods_confirmed', isset($_POST['eu_b2b_goods_confirmed']));
        $this->application->config->set('eu_b2c_mode', $mode);
        $this->application->config->set('third_country_confirmed', isset($_POST['third_country_confirmed']));
        $this->application->config->set('add_funds_confirmed', isset($_POST['add_funds_confirmed']));
        $this->application->config->set('small_business_confirmed', isset($_POST['small_business_confirmed']));
        $this->application->config->set('debug_logging', isset($_POST['debug_logging']));

        if ($exportMode !== 'voucher_only') {
            if (trim((string) $this->application->config->get('sevdesk_api_key', '')) === '') {
                throw new RuntimeException('Invoice-Modi benötigen einen gespeicherten API-Token.');
            }
            if ($this->application->referenceData()->bookkeepingVersion() !== '2.0') {
                throw new RuntimeException('Invoice-Modi benötigen einen sevdesk-Mandanten mit Systemversion 2.0.');
            }
            if ($exportMode !== DocumentTargetResolver::MODE_INVOICE_ONLY) {
                $this->application->referenceData()->receiptGuidance(true);
            }
            if (
                !$this->application->referenceData()->hasSevUser($sevUserId)
                || !$this->application->referenceData()->hasUnity($unityId)
            ) {
                throw new RuntimeException('SevUser oder Unity wurde im aktuellen sevdesk-Mandanten nicht gefunden.');
            }
            if (
                $eInvoiceMode === 'zugferd_domestic_b2b'
                && !$this->application->referenceData()->hasPaymentMethod($eInvoicePaymentMethodId)
            ) {
                throw new RuntimeException(
                    'Die gewählte E-Rechnungs-Zahlungsmethode wurde im aktuellen sevdesk-Mandanten nicht gefunden.',
                );
            }
            $this->application->config->set('health_alarm', '');
        }
        if (($tokenChanged || $runtimeReviewRequired) && !$enableSync && $exportMode === 'voucher_only') {
            if (trim((string) $this->application->config->get('sevdesk_api_key', '')) === '') {
                throw new RuntimeException(
                    'Die Bestandsprüfung benötigt einen gespeicherten sevdesk-API-Token.',
                );
            }
            if ($this->application->referenceData()->bookkeepingVersion() !== '2.0') {
                throw new RuntimeException('Der neue API-Token gehört nicht zu einem sevdesk-Mandanten mit Systemversion 2.0.');
            }
            // A successful version read alone does not prove that the API user
            // may access the accounting endpoints that previously returned 403.
            $this->application->referenceData()->receiptGuidance(true);
            $this->application->config->set('health_alarm', '');
        }
        if ($enableSync) {
            if (trim((string) $this->application->config->get('sevdesk_api_key', '')) === '') {
                throw new RuntimeException('Vor Aktivierung der Hooks muss ein API-Token gespeichert sein.');
            }
            if ($this->application->referenceData()->bookkeepingVersion() !== '2.0') {
                throw new RuntimeException('Die automatische Synchronisation benötigt sevdesk-Systemversion 2.0.');
            }
            $invoiceOnly = $exportMode === DocumentTargetResolver::MODE_INVOICE_ONLY;
            if (!$invoiceOnly) {
                $this->application->referenceData()->receiptGuidance(true);
            }
            $policy = $invoiceOnly
                ? $this->application->invoiceTaxPolicy()
                : $this->application->taxPolicy();
            $setupLine = [new LineItem('Setup validation', '1.00', '19', true)];
            $decision = $invoiceOnly
                ? $policy->decideInvoice('DE', false, null, false, false, $setupLine)
                : $policy->decide('DE', false, null, false, false, $setupLine);
            if (!$decision->allowed || (!$invoiceOnly && !$decision->guidanceValidated)) {
                throw new RuntimeException(
                    $invoiceOnly
                        ? 'Das deutsche Invoice-Steuerprofil wurde nicht freigegeben: ' . $decision->message
                        : 'Das deutsche Steuerprofil wurde von Receipt Guidance nicht bestätigt: ' . $decision->message,
                );
            }
            if ($ossProfile === DocumentTargetResolver::OSS_RULE_19_CONFIRMED) {
                $ossDecision = $this->application->invoiceTaxPolicy()->decideInvoice(
                    'BE',
                    false,
                    null,
                    false,
                    false,
                    [new LineItem('Setup validation', '1.00', '21', true)],
                );
                if (!$ossDecision->allowed || $ossDecision->taxRuleId !== '19') {
                    throw new RuntimeException(
                        'Das bestätigte Rule-19-Profil ist nicht Invoice-fähig: ' . $ossDecision->message,
                    );
                }
            }
            if ($this->application->config->bool('eu_b2b_goods_confirmed')) {
                $goodsLine = [new LineItem('Setup validation', '1.00', '0', true)];
                $euGoodsDecision = $invoiceOnly
                    ? $policy->decideInvoice('BE', true, 'BE0123456789', false, false, $goodsLine, true)
                    : $policy->decide('BE', true, 'BE0123456789', false, false, $goodsLine, true);
                if (!$euGoodsDecision->allowed || (!$invoiceOnly && !$euGoodsDecision->guidanceValidated)) {
                    throw new RuntimeException(
                        ($invoiceOnly
                            ? 'Das bestätigte EU-Warenlieferungsprofil ist nicht Invoice-fähig: '
                            : 'Das bestätigte EU-Warenlieferungsprofil wurde von Receipt Guidance nicht bestätigt: ')
                        . $euGoodsDecision->message,
                    );
                }
            }
            $this->application->config->set('health_alarm', '');
        }
        if ($runtimeReviewRequired) {
            if (!$this->application->config->clearRuntimeReviewIfUnchanged($submittedQuarantineToken)) {
                throw new RuntimeException(
                    'Während der Prüfung wurde eine neue Laufzeitquarantäne gesetzt. '
                        . 'Die Freigabe wurde nicht gespeichert; bitte den Bestand erneut prüfen.',
                );
            }
        }
        $this->application->config->set('sync_enabled', $enableSync);
    }

    private function operationalSettingsChanged(): bool
    {
        $date = $this->parseIsoDate((string) ($_POST['import_after'] ?? ''));
        $smallBusinessUntilInput = trim((string) ($_POST['small_business_until'] ?? ''));
        $smallBusinessUntil = $smallBusinessUntilInput === ''
            ? ''
            : ($this->parseIsoDate($smallBusinessUntilInput)?->format('d-m-Y') ?? '__invalid__');
        $proposed = [
            'export_mode' => (string) ($_POST['export_mode'] ?? 'voucher_only'),
            'document_authority' => (string) ($_POST['document_authority'] ?? 'whmcs'),
            'oss_profile' => (string) ($_POST['oss_profile'] ?? 'blocked'),
            'invoice_canary_confirmed' => isset($_POST['invoice_canary_confirmed']) ? 'on' : '',
            'small_business_invoice_canary_confirmed' => isset(
                $_POST['small_business_invoice_canary_confirmed'],
            ) ? 'on' : '',
            'invoice_discount_canary_confirmed' => isset($_POST['invoice_discount_canary_confirmed'])
                ? 'on'
                : '',
            'invoice_sev_user_id' => trim((string) ($_POST['invoice_sev_user_id'] ?? '')),
            'invoice_unity_id' => trim((string) ($_POST['invoice_unity_id'] ?? '')),
            'e_invoice_mode' => trim((string) ($_POST['e_invoice_mode'] ?? 'off')),
            'e_invoice_client_field_id' => (int) ($_POST['e_invoice_client_field_id'] ?? 0) > 0
                ? (string) (int) $_POST['e_invoice_client_field_id']
                : '',
            'e_invoice_payment_method_id' => trim((string) ($_POST['e_invoice_payment_method_id'] ?? '')),
            'e_invoice_active_from' => ($this->parseIsoDate((string) ($_POST['e_invoice_active_from'] ?? '')))
                ?->format('d-m-Y') ?? '',
            'e_invoice_canary_confirmed' => isset($_POST['e_invoice_canary_confirmed']) ? 'on' : '',
            'invoice_delivery_channel' => (string) ($_POST['invoice_delivery_channel'] ?? 'sevdesk'),
            'whmcs_invoice_email_template' => trim((string) ($_POST['whmcs_invoice_email_template'] ?? '')),
            'sevdesk_email_subject' => trim((string) ($_POST['sevdesk_email_subject']
                ?? $this->application->config->get('sevdesk_email_subject', ''))),
            'sevdesk_email_body' => trim((string) ($_POST['sevdesk_email_body']
                ?? $this->application->config->get('sevdesk_email_body', ''))),
            'theme_adapter_confirmed' => isset($_POST['theme_adapter_confirmed']) ? 'on' : '',
            'import_after' => $date?->format('d-m-Y') ?? '',
            'import_only_paid' => isset($_POST['import_only_paid']) ? 'on' : '',
            'custom_field_id' => (string) (int) ($_POST['custom_field_id'] ?? 0),
            'customer_number_contact_creation_confirmed' => isset(
                $_POST['customer_number_contact_creation_confirmed']
            ) ? 'on' : '',
            'smallBusinessOwner' => isset($_POST['smallBusinessOwner']) ? 'on' : '',
            'small_business_until' => $smallBusinessUntil,
            'eu_b2b_goods_confirmed' => isset($_POST['eu_b2b_goods_confirmed']) ? 'on' : '',
            'eu_b2c_mode' => (string) ($_POST['eu_b2c_mode'] ?? 'blocked'),
            'third_country_confirmed' => isset($_POST['third_country_confirmed']) ? 'on' : '',
            'add_funds_confirmed' => isset($_POST['add_funds_confirmed']) ? 'on' : '',
            'small_business_confirmed' => isset($_POST['small_business_confirmed']) ? 'on' : '',
        ];
        foreach (
            [
            'accountingTypeGeneral', 'accountingTypeInterCommunityBusiness',
            'accountingTypeInterCommunityConsumer', 'accountingTypeThirdPartyCountry',
            'accountingTypeCredit', 'accountingTypeSmallBusinessOwner',
            'taxRuleGeneral', 'taxRuleInterCommunityBusiness', 'taxRuleInterCommunityConsumer',
            'taxRuleThirdPartyCountry', 'taxRuleCredit', 'taxRuleSmallBusinessOwner',
            ] as $setting
        ) {
            $proposed[$setting] = trim((string) ($_POST[$setting] ?? ''));
        }
        foreach ($proposed as $setting => $value) {
            if ((string) $this->application->config->get($setting, '') !== $value) {
                return true;
            }
        }

        return false;
    }

    private function assertJobMutationAllowed(): void
    {
        $this->assertModuleActiveForJobSafetyAction();
        if ($this->application->config->bool(Config::RUNTIME_REVIEW_SETTING)) {
            throw new RuntimeException(
                'Der übernommene Modulbestand befindet sich in Quarantäne. Prüfen und bestätigen Sie zuerst '
                    . 'Token, Kontaktfeld, Konten, Steuerprofile und offene Jobs in der Einrichtung.',
            );
        }
    }

    private function assertModuleActiveForJobSafetyAction(): void
    {
        if (!$this->application->config->bool('module_active')) {
            throw new RuntimeException(
                'Das sevdesk-Modul ist deaktiviert. Setup, Health und read-only Ansichten bleiben verfügbar, '
                    . 'aber Jobänderungen sind gesperrt.',
            );
        }
    }

    /**
     * @param list<mixed> $cells
     * @return list<string>
     */
    private static function safeCsvRow(array $cells): array
    {
        return array_map(static function (mixed $value): string {
            $cell = $value === null ? '' : (string) $value;
            if (preg_match('/^[\x00-\x20]*[=+\-@\t\r\n]/', $cell) === 1) {
                return "'" . $cell;
            }

            return $cell;
        }, $cells);
    }

    private function createCorrectionJob(): object
    {
        if ((string) ($_POST['correction_confirmed'] ?? '') !== '1') {
            throw new RuntimeException('Der negative Korrektur-Voucher muss für diesen Einzelfall ausdrücklich bestätigt werden.');
        }
        if ((string) ($_POST['correction_refund_confirmed'] ?? '') !== '1') {
            throw new RuntimeException('Bitte bestätigen, dass die ausgewählte negative Transaktion eine echte Erstattung und kein Chargeback ist.');
        }
        $invoiceId = (int) ($_POST['correction_invoice_id'] ?? 0);
        $accountId = (int) ($_POST['refund_account_id'] ?? 0);
        $transaction = Capsule::table('tblaccounts')->where('id', $accountId)->first();
        if (
            $invoiceId < 1
            || $transaction === null
            || (int) $transaction->invoiceid !== $invoiceId
            || !$this->application->whmcs->isVerifiedRefundTransaction($transaction)
        ) {
            throw new RuntimeException('Die ausgewählte WHMCS-Rückzahlung wurde nicht gefunden oder gehört nicht zur Rechnung.');
        }
        $mapping = $this->application->mappings->findCompleteByInvoice($invoiceId);
        if ($mapping === null) {
            throw new RuntimeException('Die Originalrechnung besitzt keine vollständige sevdesk-Zuordnung.');
        }

        $invoice = $this->application->whmcs->invoiceSnapshot($invoiceId);
        $transactionCurrency = (string) Capsule::table('tblcurrencies')->where('id', (int) $transaction->currency)->value('code');
        if ($transactionCurrency !== '' && strtoupper($transactionCurrency) !== $invoice->currency) {
            throw new RuntimeException('Rückzahlung und Originalrechnung verwenden unterschiedliche Währungen. Dieser Fall benötigt eine manuelle Aufteilung.');
        }
        $contact = $this->application->whmcs->contactData($invoice->clientId);
        if ($contact->sevdeskContactId === null || preg_match('/^\d+$/', $contact->sevdeskContactId) !== 1) {
            throw new RuntimeException('Die Originalrechnung besitzt keine gültige sevdesk-Kontakt-ID.');
        }

        $positions = [];
        $positionLines = [];
        $json = trim((string) ($_POST['correction_positions_json'] ?? ''));
        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Die Positionsaufteilung ist kein gültiges JSON.');
            }
            if (!is_array($decoded) || $decoded === [] || count($decoded) > 50) {
                throw new RuntimeException('Die Positionsaufteilung muss zwischen einer und 50 Positionen enthalten.');
            }
            foreach ($decoded as $position) {
                if (!is_array($position)) {
                    throw new RuntimeException('Jede Korrekturposition muss ein JSON-Objekt sein.');
                }
                $line = new LineItem(
                    (string) ($position['description'] ?? 'Erstattung'),
                    (string) ($position['amount'] ?? ''),
                    (string) ($position['taxRate'] ?? ''),
                    filter_var($position['net'] ?? false, FILTER_VALIDATE_BOOL),
                );
                $positionLines[] = $line;
                $positions[] = [
                    'description' => $line->description,
                    'amount' => $line->amount,
                    'taxRate' => $line->taxRate,
                    'net' => $line->net,
                ];
            }
        } else {
            $rates = array_values(array_unique(array_map(
                static fn (\WHMCS\Module\Addon\SevDesk\Domain\LineItem $line): string => $line->taxRate,
                $invoice->lineItems,
            )));
            if (count($rates) !== 1) {
                throw new RuntimeException('Bei mehreren Steuersätzen ist eine explizite JSON-Positionsaufteilung erforderlich.');
            }
            $line = new LineItem(
                'Erstattung zu Rechnung ' . $invoice->invoiceNumber,
                (string) $transaction->amountout,
                $rates[0],
                false,
            );
            $positionLines[] = $line;
            $positions[] = [
                'description' => $line->description,
                'amount' => $line->amount,
                'taxRate' => $line->taxRate,
                'net' => $line->net,
            ];
        }
        self::assertCorrectionPositionsMatchRefund($positionLines, (string) $transaction->amountout);

        // The immutable WHMCS row ID is sufficient for dedupe and recovery.
        // Do not persist the gateway's raw transaction reference unnecessarily.
        $transactionReference = 'WHMCS-ACCOUNT:' . $accountId;
        $request = [
            'kind' => 'refund',
            'whmcsRefundTransactionId' => $transactionReference,
            'invoiceId' => $invoiceId,
            'invoiceNumber' => $invoice->invoiceNumber,
            'originalVoucherId' => (string) $mapping->sevdesk_id,
            'contactId' => $contact->sevdeskContactId,
            'refundAmount' => (string) $transaction->amountout,
            'currency' => $invoice->currency,
            'voucherDate' => substr((string) $transaction->date, 0, 10),
        ];
        $dedupeReference = CorrectionService::dedupeReference($transactionReference);
        $jobId = $this->application->jobs->create('refund_correction', [[
            'invoice_id' => $invoiceId,
            'action' => 'correction_voucher',
            'dedupe_key' => $dedupeReference,
            'transaction_reference' => $dedupeReference,
            'candidate' => [
                'whmcsAccountId' => $accountId,
                'request' => $request,
                'positions' => $positions,
            ],
        ]], [
            'invoice_id' => $invoiceId,
            'refund_account_id' => $accountId,
            'manual_confirmation' => true,
        ], $this->adminId());

        return $this->application->jobs->findJob($jobId)
            ?? throw new RuntimeException('Der Korrekturjob konnte nicht gelesen werden.');
    }

    /**
     * @param list<object> $rows
     * @return list<array<string,mixed>>
     */
    private function decorateDryRun(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $invoiceIds = array_map(static fn (object $invoice): int => (int) $invoice->id, $rows);
        $mappings = [];
        foreach (Capsule::table(Migrator::MAPPING_TABLE)->whereIn('invoice_id', $invoiceIds)->get() as $mapping) {
            $mappings[(int) $mapping->invoice_id] = $mapping;
        }
        $activeExportOwners = [];
        foreach (
            Capsule::table(Migrator::ITEMS_TABLE)
                ->whereIn('invoice_id', $invoiceIds)
                ->whereIn('action', ['export_document', 'export_voucher', 'reconcile_voucher'])
                ->whereIn('status', [
                    'pending', 'running', 'retry_wait', 'ambiguous', 'permanent_failed', 'cancelled',
                ])
                ->get(['invoice_id', 'status', 'checkpoint']) as $owner
        ) {
            if (
                !in_array((string) $owner->status, ['permanent_failed', 'cancelled'], true)
                || JobRepository::isRiskyCheckpoint((string) ($owner->checkpoint ?? ''))
            ) {
                $activeExportOwners[(int) $owner->invoice_id] = in_array(
                    (string) $owner->status,
                    ['permanent_failed', 'cancelled'],
                    true,
                )
                    ? 'unresolved_export_history'
                    : 'active_export_owner';
            }
        }
        $invoiceItems = [];
        foreach (
            Capsule::table('tblinvoiceitems')
                ->whereIn('invoiceid', $invoiceIds)
                ->orderBy('id')
                ->get(['invoiceid', 'type', 'relid', 'description', 'amount', 'taxed']) as $item
        ) {
            $invoiceItems[(int) $item->invoiceid][] = $item;
        }
        // Voucher-capable previews share one read-only Guidance lookup. Invoice
        // only uses the separate Invoice classification and never requires a
        // Voucher accountDatev merely to render the preview.
        $exportMode = (string) $this->application->config->get(
            'export_mode',
            DocumentTargetResolver::MODE_VOUCHER_ONLY,
        );
        $invoiceOnly = $exportMode === DocumentTargetResolver::MODE_INVOICE_ONLY;
        $invoiceCapable = in_array($exportMode, [
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::MODE_INVOICE_ONLY,
        ], true);
        $invoiceTaxPolicy = $invoiceCapable ? $this->application->invoiceTaxPolicy(true) : null;
        $voucherTaxPolicy = $exportMode === DocumentTargetResolver::MODE_VOUCHER_ONLY
            ? $this->application->taxPolicy()
            : null;
        $taxType = strtolower((string) ($GLOBALS['CONFIG']['TaxType'] ?? 'Exclusive'));
        $taxTypeSupported = in_array($taxType, ['exclusive', 'inclusive'], true);
        $net = $taxType === 'exclusive';
        $configuredStart = DateTimeImmutable::createFromFormat(
            '!d-m-Y',
            (string) $this->application->config->get('import_after', '01-01-1999'),
        );
        $result = [];
        foreach ($rows as $invoice) {
            $invoiceId = (int) $invoice->id;
            $effectiveInvoiceNumber = WhmcsGateway::effectiveInvoiceNumber(
                $invoiceId,
                (string) ($invoice->invoicenum ?? ''),
            );
            $mapping = $mappings[$invoiceId] ?? null;
            $mappingRemoteId = $mapping === null ? '' : trim((string) ($mapping->sevdesk_id ?? ''));
            $country = strtoupper((string) ($invoice->country ?? ''));
            $taxExempt = in_array(strtolower((string) ($invoice->taxexempt ?? '')), ['1', 'true', 'yes', 'on'], true);
            $sourceItems = $invoiceItems[$invoiceId] ?? [];
            $sourceTypes = array_values(array_unique(array_filter(array_map(
                static fn (object $item): string => strtolower(trim((string) ($item->type ?? ''))),
                $sourceItems,
            ))));
            $hasInvoiceReferenceItems = in_array('invoice', $sourceTypes, true);
            $requiresPaymentStructure = $hasInvoiceReferenceItems
                || (float) ($invoice->credit ?? 0) > 0;
            $massPaymentExact = false;
            $ordinaryVoucherCreditReview = false;
            $paymentStructureCode = '';
            $exportable = true;
            $reason = '';
            $reasonCode = '';
            if (!$taxTypeSupported) {
                $exportable = false;
                $reason = 'Der konfigurierte WHMCS-Steuermodus wird nicht unterstützt';
                $reasonCode = 'unsupported_whmcs_tax_type';
            } elseif ($mapping !== null && $mappingRemoteId !== '') {
                $exportable = false;
                $reason = 'Bereits zugeordnet';
            } elseif ($mapping !== null) {
                $exportable = false;
                $reason = 'Alte NULL-Zuordnung: Reconciliation erforderlich';
            } elseif (isset($activeExportOwners[$invoiceId])) {
                $exportable = false;
                $reasonCode = $activeExportOwners[$invoiceId];
                $reason = $reasonCode === 'unresolved_export_history'
                    ? 'Ein älterer Export endete nach einem möglichen Remote-Write und muss zuerst geklärt werden'
                    : 'Ein aktiver oder ungeklärter Exportjob besitzt diese Rechnung bereits';
            }
            if ($exportable && $requiresPaymentStructure) {
                $paymentStructure = $this->application->paymentStructure()->classify($invoiceId);
                $paymentStructureCode = (string) ($paymentStructure['code'] ?? '');
                if ($paymentStructureCode === WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET) {
                    $massPaymentExact = true;
                } elseif ($paymentStructureCode === WhmcsPaymentStructureService::CONTAINER_NOT_REVENUE) {
                    $exportable = false;
                    $reason = 'Reine Sammelzahlungsrechnung; die verknüpften Originalrechnungen sind die Umsatzbelege';
                    $reasonCode = WhmcsPaymentStructureService::CONTAINER_NOT_REVENUE;
                } elseif ($paymentStructureCode !== WhmcsPaymentStructureService::ORDINARY_INVOICE) {
                    $paymentStructureReason = (string) (
                        $paymentStructure['context']['reasonCode']
                        ?? $paymentStructureCode
                    );
                    $exportable = false;
                    if (
                        self::ordinaryVoucherCreditReviewAllowed(
                            $exportMode,
                            $hasInvoiceReferenceItems,
                            $paymentStructure,
                            $paymentStructureReason,
                        )
                    ) {
                        $ordinaryVoucherCreditReview = true;
                        $reason = 'Angewendetes Guthaben benötigt Einzelprüfung';
                        $reasonCode = 'credit_applied_requires_review';
                    } else {
                        $reason = 'Guthaben oder Sammelzahlung sind strukturell nicht vollständig beweisbar';
                        $reasonCode = $paymentStructureReason;
                    }
                }
            }
            if ($exportable && (float) $invoice->credit > 0 && !$massPaymentExact) {
                $exportable = false;
                $reason = 'Angewendetes Guthaben benötigt Einzelprüfung';
                $reasonCode = 'credit_applied_requires_review';
            }
            $rawDocumentGross = '0.00';
            $directCashMinor = 0;
            try {
                $rawDocumentGross = WhmcsGateway::documentGrossTotal(
                    (string) ($invoice->subtotal ?? ''),
                    (string) ($invoice->tax ?? ''),
                    (string) ($invoice->tax2 ?? ''),
                    (string) ($invoice->total ?? ''),
                    (string) ($invoice->credit ?? ''),
                );
                $directCashMinor = Decimal::toMinorUnits((string) $invoice->total);
            } catch (\InvalidArgumentException) {
                if ($exportable) {
                    $exportable = false;
                    $reason = 'Die WHMCS-Rechnung erfüllt nicht subtotal + tax + tax2 = total + credit';
                    $reasonCode = 'invoice_total_mismatch';
                }
            }
            if ($exportable && Decimal::toMinorUnits($rawDocumentGross) <= 0) {
                $exportable = false;
                $reason = 'Null- oder Negativbetrag benötigt Einzelprüfung';
            } elseif ($exportable && strtoupper(trim((string) ($invoice->currencycode ?? ''))) !== 'EUR') {
                $exportable = false;
                $reason = 'Fremdwährung benötigt bis zur separaten Freigabe eine manuelle Prüfung';
                $reasonCode = 'foreign_currency_requires_review';
            } elseif (
                $exportable
                && $this->application->config->bool('import_only_paid', true)
                && (string) $invoice->status !== 'Paid'
            ) {
                $exportable = false;
                $reason = 'Nach der aktuellen Einstellung werden nur bezahlte Rechnungen exportiert';
            } elseif (
                $exportable
                && $configuredStart instanceof DateTimeImmutable
                && (string) $invoice->date < $configuredStart->format('Y-m-d')
            ) {
                $exportable = false;
                $reason = 'Rechnung liegt vor dem konfigurierten Exportstichtag';
            }

            $lines = [];
            $discounts = [];
            $types = [];
            $taxRate = (float) ($invoice->taxrate ?? 0) + (float) ($invoice->taxrate2 ?? 0);
            if ($exportable && (float) ($invoice->taxrate ?? 0) > 0 && (float) ($invoice->taxrate2 ?? 0) > 0) {
                $exportable = false;
                $reason = 'Zwei gleichzeitig angewendete WHMCS-Steuern benötigen eine manuelle Positionsprüfung';
            }
            $hasPromoOrNegativeItem = false;
            try {
                foreach ($sourceItems as $item) {
                    $type = strtolower(trim((string) $item->type));
                    if ($type !== '') {
                        $types[] = $type;
                    }
                    if (
                        $type === 'promohosting'
                        || Decimal::toMinorUnits((string) $item->amount) < 0
                    ) {
                        $hasPromoOrNegativeItem = true;
                    }
                }

                if ($hasPromoOrNegativeItem) {
                    $structuredItems = [];
                    foreach ($sourceItems as $item) {
                        $structuredItems[] = WhmcsInvoiceItem::fromWhmcs([
                            'type' => (string) $item->type,
                            'relid' => $item->relid,
                            'description' => (string) $item->description,
                            'amount' => (string) $item->amount,
                            'taxed' => $item->taxed,
                        ], $this->decimal($taxRate), $net ? 'Exclusive' : 'Inclusive');
                    }
                    $normalization = (new InvoiceItemNormalizer())->normalize($structuredItems);
                    if (!$normalization->allowed) {
                        $exportable = false;
                        $reason = $normalization->message;
                        $reasonCode = $normalization->code;
                    } else {
                        $lines = $normalization->lines;
                        $discounts = $normalization->discounts;
                    }
                } else {
                    foreach ($sourceItems as $item) {
                        $lines[] = new LineItem(
                            (string) $item->description,
                            (string) $item->amount,
                            self::truthy($item->taxed ?? false) ? $this->decimal($taxRate) : '0',
                            $net,
                        );
                    }
                }
            } catch (\InvalidArgumentException) {
                $exportable = false;
                $reason = 'Mindestens eine Rechnungsposition enthält ungültige Betrags-, Steuer- oder Referenzdaten';
                $reasonCode = 'invalid_invoice_item';
            }
            $types = array_values(array_unique($types));
            $addFunds = in_array('addfunds', $types, true);
            if ($exportable && $addFunds && count($types) > 1) {
                $exportable = false;
                $reason = 'AddFunds und normale Positionen dürfen nicht denselben Voucher verwenden';
            }
            if ($exportable && $lines === []) {
                $exportable = false;
                $reason = 'Die Rechnung enthält keine exportierbaren Positionen';
            }

            $decision = null;
            $target = null;
            $snapshot = null;
            $smallBusinessApplies = false;
            if ($exportable) {
                try {
                    $snapshot = new InvoiceSnapshot(
                        $invoiceId,
                        (int) $invoice->userid,
                        $effectiveInvoiceNumber,
                        new DateTimeImmutable((string) $invoice->date),
                        (string) $invoice->currencycode,
                        $rawDocumentGross,
                        (string) $invoice->credit,
                        $lines,
                        $discounts,
                    );
                    if (
                        abs(
                            $snapshot->calculatedDocumentGrossMinorUnits()
                            - $snapshot->totalMinorUnits(),
                        ) > 1
                    ) {
                        $exportable = false;
                        $reason = 'Positionssumme und WHMCS-Rechnungsbetrag weichen um mehr als 0,01 ab';
                    }
                } catch (\Throwable) {
                    $exportable = false;
                    $reason = 'Die WHMCS-Rechnung konnte nicht konsistent normalisiert werden';
                }
            }
            if ($exportable && $snapshot instanceof InvoiceSnapshot) {
                try {
                    $smallBusinessApplies = $this->application->config->smallBusinessAppliesOn(
                        $snapshot->invoiceDate,
                    );
                } catch (RuntimeException) {
                    $exportable = false;
                    $reason = 'Der gespeicherte Kleinunternehmer-Stichtag ist ungültig';
                    $reasonCode = 'small_business_period_invalid';
                }
            } elseif ($exportable) {
                $exportable = false;
                $reason = 'Die WHMCS-Rechnung konnte nicht konsistent normalisiert werden';
            }
            if ($exportable) {
                $arguments = [
                    $country,
                    $taxExempt,
                    trim((string) ($invoice->tax_id ?? '')) ?: null,
                    $smallBusinessApplies,
                    $addFunds,
                    $lines,
                    trim((string) ($invoice->companyname ?? '')) !== '',
                ];
                if ($invoiceOnly) {
                    if ($invoiceTaxPolicy === null) {
                        throw new \LogicException('Invoice tax policy is unavailable.');
                    }
                    $decision = $invoiceTaxPolicy->decideInvoice(...$arguments);
                } elseif ($exportMode === DocumentTargetResolver::MODE_INVOICE_FOR_OSS) {
                    if ($invoiceTaxPolicy === null) {
                        throw new \LogicException('Invoice tax policy is unavailable.');
                    }
                    $invoiceDecision = $invoiceTaxPolicy->decideInvoice(...$arguments);
                    if (
                        ($invoiceDecision->allowed && $invoiceDecision->taxRuleId === '19')
                        || (!$invoiceDecision->allowed && $invoiceDecision->profile === 'eu_b2c')
                    ) {
                        $decision = $invoiceDecision;
                    } else {
                        $voucherTaxPolicy ??= $this->application->taxPolicy();
                        $decision = $voucherTaxPolicy->decide(...$arguments);
                    }
                } else {
                    $voucherTaxPolicy ??= $this->application->taxPolicy();
                    $decision = $voucherTaxPolicy->decide(...$arguments);
                }

                $target = $this->application->documentTargetResolver()->resolve(
                    $decision,
                    (string) $invoice->status === 'Paid',
                    $effectiveInvoiceNumber !== '',
                );
                if (
                    $target->allowed
                    && $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                    && $snapshot instanceof InvoiceSnapshot
                    && $snapshot->calculatedDocumentGrossMinorUnits() !== $snapshot->totalMinorUnits()
                ) {
                    $exportable = false;
                    $reason = 'Für Invoices müssen Positionssumme und WHMCS-Rechnungsbetrag centgenau übereinstimmen';
                    $reasonCode = 'invoice_total_mismatch';
                } elseif (
                    $target->allowed
                    && $target->documentType !== DocumentTargetDecision::DOCUMENT_INVOICE
                    && $snapshot instanceof InvoiceSnapshot
                    && $snapshot->discounts !== []
                ) {
                    $exportable = false;
                    $reason = 'Strukturelle PromoHosting-Rabatte sind nur im Modus „Invoice only“ freigegeben';
                    $reasonCode = 'voucher_discount_not_supported';
                } elseif (
                    $target->allowed
                    && $snapshot instanceof InvoiceSnapshot
                    && $snapshot->discounts !== []
                    && (
                        $decision->taxRuleId !== '11'
                        || array_filter(
                            $snapshot->lineItems,
                            static fn (LineItem $line): bool =>
                                Decimal::toMinorUnits($line->taxRate) !== 0,
                        ) !== []
                    )
                ) {
                    $exportable = false;
                    $reason = 'PromoHosting-Rabatte sind zunächst nur für Rule 11 mit 0 % freigegeben';
                    $reasonCode = 'invoice_discount_tax_rule_not_supported';
                } elseif (
                    $target->allowed
                    && $snapshot instanceof InvoiceSnapshot
                    && $snapshot->discounts !== []
                    && !$this->application->config->bool('invoice_discount_canary_confirmed')
                ) {
                    $exportable = false;
                    $reason = 'Der separate sevdesk-Canary für feste Invoice-Rabatte ist noch nicht bestätigt';
                    $reasonCode = 'invoice_discount_canary_not_confirmed';
                } elseif (
                    $target->allowed
                    && $target->documentType === DocumentTargetDecision::DOCUMENT_INVOICE
                    && !$this->application->config->bool('invoice_canary_confirmed')
                ) {
                    $exportable = false;
                    $reason = 'Der externe sevDesk-Testmandanten-Canary ist noch nicht bestätigt';
                    $reasonCode = 'invoice_canary_not_confirmed';
                } elseif (!$target->allowed) {
                    $exportable = false;
                    $reason = $this->dryRunTaxReason($target->code, $target->message);
                    $reasonCode = $target->code;
                } elseif (
                    $target->documentType === DocumentTargetDecision::DOCUMENT_VOUCHER
                    && !$decision->guidanceValidated
                ) {
                    $exportable = false;
                    $reason = $this->dryRunTaxReason('receipt_guidance_not_validated');
                    $reasonCode = 'receipt_guidance_not_validated';
                }
            }
            $clientName = trim((string) ($invoice->companyname ?? ''));
            if ($clientName === '') {
                $clientName = trim((string) ($invoice->firstname ?? '') . ' ' . (string) ($invoice->lastname ?? ''));
            }
            $documentAuthority = $target instanceof DocumentTargetDecision
                ? $target->documentAuthority
                : (string) $this->application->config->get('document_authority', 'whmcs');
            $documentGross = $rawDocumentGross;
            $result[] = [
                'id' => (int) $invoice->id,
                'invoice_id' => (int) $invoice->id,
                'invoicenum' => $effectiveInvoiceNumber,
                'client_name' => $clientName,
                'countrycode' => $country,
                'date' => (string) $invoice->date,
                'datepaid' => (string) $invoice->datepaid,
                'total' => number_format((float) $documentGross, 2, ',', '.'),
                'gross_formatted' => number_format((float) $documentGross, 2, ',', '.'),
                'credit_formatted' => number_format((float) $invoice->credit, 2, ',', '.'),
                'payable_formatted' => number_format(
                    (float) Decimal::fromMinorUnits($directCashMinor),
                    2,
                    ',',
                    '.',
                ),
                'status' => (string) $invoice->status,
                'mapped' => $mappingRemoteId !== '',
                'sevdesk_id' => $mappingRemoteId !== '' ? $mappingRemoteId : null,
                'eligible' => $exportable,
                'exportable' => $exportable,
                'reason' => $reason,
                'reason_code' => $reasonCode,
                'payment_structure' => $paymentStructureCode !== '' ? $paymentStructureCode : null,
                'payment_booking_note' => $massPaymentExact
                    ? 'Umsatzbeleg freigegeben; die Sammelzahlung bleibt in sevdesk manuell zuzuordnen'
                        . (
                            (string) $this->application->config->get('e_invoice_mode', 'off') !== 'off'
                                ? '. ZUGFeRD mit angewendetem Guthaben bleibt gesperrt'
                                : ''
                        )
                    : null,
                'credit_voucher_confirmation_allowed' => $ordinaryVoucherCreditReview,
                'tax_profile' => $decision?->profile,
                'tax_rule' => $decision?->taxRuleId,
                'account_datev' => $decision?->accountDatevId,
                'document_type' => $target?->documentType,
                'document_authority' => $documentAuthority,
                'delivery_state' => $documentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK
                    ? 'Dieser Admin-/Backfill-Export wird ohne automatische Mail bereitgestellt'
                    : 'Kein Kundenversand aus sevdesk',
                'help_url' => $decision?->code === 'unsupported_oss' ? 'https://api.sevdesk.de/' : null,
            ];
        }

        return $result;
    }

    /**
     * The legacy full-gross Voucher confirmation is deliberately narrower than
     * the mass-payment classifier. It is available only when no WHMCS Invoice
     * item references this document and the structural read proves that no
     * mass-payment parent exists.
     *
     * @param array<string,mixed> $paymentStructure
     */
    private static function ordinaryVoucherCreditReviewAllowed(
        string $exportMode,
        bool $hasInvoiceReferenceItems,
        array $paymentStructure,
        string $reasonCode,
    ): bool {
        $context = is_array($paymentStructure['context'] ?? null)
            ? $paymentStructure['context']
            : [];

        return $exportMode === DocumentTargetResolver::MODE_VOUCHER_ONLY
            && !$hasInvoiceReferenceItems
            && ($paymentStructure['code'] ?? null) === WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW
            && $reasonCode === 'mass_payment_parent_missing'
            && array_key_exists('parentInvoiceId', $paymentStructure)
            && $paymentStructure['parentInvoiceId'] === null
            && ($context['referencingParentCount'] ?? null) === 0
            && is_int($context['invoiceCreditMinor'] ?? null)
            && $context['invoiceCreditMinor'] > 0;
    }

    private function dryRunTaxReason(string $code, string $fallback = ''): string
    {
        return [
            'unsupported_oss' => 'EU-B2C / OSS ist für sevdesk-Voucher gesperrt',
            'unsupported_oss_rule' => 'OSS Rules 18 und 20 sind in dieser Version nicht freigegeben',
            'oss_profile_not_confirmed' => 'Rule 19 benötigt die ausdrückliche Bestätigung ausschließlich digitaler Leistungen',
            'oss_requires_invoice_mode' => 'Rule 19 benötigt invoice_for_oss oder invoice_only',
            'invoice_requires_payment' => 'Invoice-Ziele werden ausschließlich nach vollständiger Zahlung exportiert',
            'invoice_number_not_final' => 'Für den Invoice-Export fehlt die finale WHMCS-Rechnungsnummer',
            'invalid_document_authority_mode' => 'sevDesk-Dokumenthoheit ist ausschließlich mit invoice_only zulässig',
            'receipt_guidance_not_validated' => 'Konto und TaxRule wurden für den Voucher nicht durch Receipt Guidance bestätigt',
            'missing_vat_id' => 'EU-B2B benötigt Steuerbefreiung und eine USt-ID',
            'eu_b2b_organisation_required' => 'EU-B2B benötigt zusätzlich eine in WHMCS hinterlegte Organisation',
            'eu_b2b_tax_rate_mismatch' => 'Eine steuerbefreite EU-B2B-Rechnung darf keinen positiven USt-Satz enthalten',
            'unsupported_domestic_tax_exempt' => 'Ein steuerbefreiter deutscher Kunde benötigt ein eigenes bestätigtes Steuerprofil',
            'unsupported_tax_rate' => 'Receipt Guidance erlaubt mindestens einen Steuersatz nicht',
            'unsupported_receipt_guidance' => 'Konto und TaxRule werden von Receipt Guidance nicht angeboten',
            'unconfirmed_tax_profile' => 'Das benötigte Steuerprofil wurde noch nicht ausdrücklich bestätigt',
            'incomplete_tax_profile' => 'Für diesen Steuerfall fehlen Konto oder TaxRule',
            'small_business_invoice_canary_not_confirmed' => 'Rule-11-Invoices bleiben bis zum eigenen sevDesk-Mandanten-Canary gesperrt',
            'invoice_rule11_tenant_scope_unsupported' => 'Receipt Guidance bietet kein REVENUE-Konto für Rule 11 mit 0 % an',
        ][$code] ?? ($fallback !== '' ? $fallback : 'Steuerprofil oder Receipt Guidance blockiert diesen Beleg (' . $code . ')');
    }

    /**
     * @param array<int, object> $items
     * @return array<int, object>
     */
    private function decorateJobDocumentFields(array $items): array
    {
        foreach ($items as $item) {
            try {
                $decoded = json_decode((string) ($item->candidate_json ?? ''), true, 32, JSON_THROW_ON_ERROR);
                $candidate = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $candidate = [];
            }

            $item->document_type = trim((string) ($item->mapping_document_type
                ?? $candidate['documentType']
                ?? $candidate['targetDocumentType']
                ?? ''));
            $item->document_authority = trim((string) ($item->mapping_document_authority
                ?? $candidate['documentAuthority']
                ?? $candidate['targetDocumentAuthority']
                ?? ''));
            $item->tax_rule = trim((string) ($candidate['targetTaxRuleId'] ?? ''));
            $item->delivery_state = match (true) {
                trim((string) ($item->delivered_at ?? '')) !== '' => 'delivered',
                trim((string) ($item->document_ready_at ?? '')) !== '' => 'ready',
                trim((string) ($candidate['deliveryState'] ?? '')) !== '' => (string) $candidate['deliveryState'],
                in_array((string) ($item->checkpoint ?? ''), [
                    'invoice_delivery_write_requested',
                    'whmcs_email_write_requested',
                ], true) => 'outcome_unknown',
                default => 'not_delivered',
            };
        }

        return $items;
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function validateDeliveryText(string $subject, string $body): void
    {
        if ($subject === '' || $body === '' || mb_strlen($subject) > 200 || mb_strlen($body) > 5000) {
            throw new RuntimeException('Betreff und Nachrichtentext für den sevdesk-Versand sind erforderlich und zu lang begrenzt.');
        }
        foreach ([$subject, $body] as $value) {
            preg_match_all('/\{[A-Za-z0-9_]+\}/', $value, $matches);
            foreach ($matches[0] as $placeholder) {
                if (!in_array($placeholder, ['{invoice_number}', '{company_name}'], true)) {
                    throw new RuntimeException('Im Versandtext ist der Platzhalter „' . $placeholder . '“ nicht erlaubt.');
                }
            }
            $withoutAllowed = str_replace(['{invoice_number}', '{company_name}'], '', $value);
            if (str_contains($withoutAllowed, '{') || str_contains($withoutAllowed, '}')) {
                throw new RuntimeException('Der Versandtext enthält eine ungültige Platzhalter-Syntax.');
            }
        }
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1
            || (is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true));
    }

    /** @return array{DateTimeImmutable,DateTimeImmutable} */
    private function dateRange(string $from, string $until): array
    {
        $start = $this->parseIsoDate($from);
        $end = $this->parseIsoDate($until);
        if ($start === null || $end === null || $start > $end) {
            throw new RuntimeException('Bitte einen gültigen Zeitraum wählen.');
        }
        if ($end > $start->modify('+3 years')) {
            throw new RuntimeException('Ein Sammelexport darf höchstens drei Jahre umfassen.');
        }

        return [$start, $end];
    }

    private function parseIsoDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @param array<string,mixed> $preview */
    private static function bookingPreviewNeedsAttention(array $preview): bool
    {
        return !in_array(
            (string) ($preview['code'] ?? ''),
            ['voucher_already_paid', 'invoice_already_paid'],
            true,
        );
    }

    /** @param array<string,mixed> $transaction */
    private function verifiedWhmcsPayment(int $invoiceId, array $transaction): ?object
    {
        $accountId = (int) ($transaction['id'] ?? 0);
        if ($accountId < 1) {
            return null;
        }
        $account = Capsule::table('tblaccounts')->where('id', $accountId)->first();
        if (
            $account === null
            || (int) $account->invoiceid !== $invoiceId
            || trim((string) $account->transid) !== trim((string) ($transaction['transid'] ?? ''))
            || (float) $account->amountin <= 0
            || (float) $account->amountout > 0
            || abs((float) $account->amountin - (float) ($transaction['amountin'] ?? 0)) > 0.005
            || (int) ($account->refundid ?? 0) > 0
        ) {
            return null;
        }

        $hasRefund = Capsule::table('tblaccounts')
            ->where('refundid', $accountId)
            ->where('amountout', '>', 0)
            ->exists();

        return $hasRefund ? null : $account;
    }

    /** @param list<LineItem> $positions */
    private static function assertCorrectionPositionsMatchRefund(array $positions, string $refundAmount): void
    {
        $grossMinor = 0;
        $netMode = null;
        foreach ($positions as $position) {
            if (Decimal::toMinorUnits($position->amount) <= 0) {
                throw new RuntimeException('Korrekturpositionen müssen positive Beträge verwenden.');
            }
            if ($netMode !== null && $netMode !== $position->net) {
                throw new RuntimeException('Korrekturpositionen müssen einheitlich als Netto oder Brutto angegeben werden.');
            }
            $netMode = $position->net;
            $grossMinor += $position->grossMinorUnits();
        }

        if (abs($grossMinor - Decimal::toMinorUnits($refundAmount)) > 1) {
            throw new RuntimeException(
                'Die Bruttosumme der Korrekturpositionen stimmt nicht mit dem Erstattungsbetrag überein.',
            );
        }
    }

    private function startDirectResponse(string $contentType): void
    {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
        }
    }

    private function redirectToInvoice(int $invoiceId): never
    {
        $location = 'invoices.php?action=edit&id=' . $invoiceId;
        if (ob_get_level() > 0) {
            ob_clean();
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Location: ' . $location, true, 303);
            exit;
        }

        // WHMCS normally buffers addon output, but keep a fixed-destination
        // fallback for installations whose admin template already sent headers.
        $escapedLocation = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
        echo '<meta http-equiv="refresh" content="0;url=' . $escapedLocation . '">'
            . '<p><a href="' . $escapedLocation . '">Zur Rechnung zurückkehren</a></p>';
        exit;
    }

    /** @return array{page:int,total_pages:int,previous_url:?string,next_url:?string} */
    private function pagination(int $page, int $pages, string $baseUrl): array
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return [
            'page' => $page,
            'total_pages' => $pages,
            'previous_url' => $page > 1 ? $baseUrl . $separator . 'page=' . ($page - 1) : null,
            'next_url' => $page < $pages ? $baseUrl . $separator . 'page=' . ($page + 1) : null,
        ];
    }

    /** @param array<string,mixed> $variables */
    private function render(string $template, string $activeRoute, array $variables): void
    {
        $this->view->render($template, array_merge([
            'moduleLink' => $this->moduleLink,
            'activeRoute' => $activeRoute,
        ], $variables));
    }

    private function adminId(): ?int
    {
        $id = (int) ($_SESSION['adminid'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }
}
