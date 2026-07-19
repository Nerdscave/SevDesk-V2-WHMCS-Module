<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Controllers;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Health\HealthService;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Support\AdminInvoiceControls;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;
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
                $this->view->flash('danger', $error->getMessage(), 'Einstellungen nicht gespeichert');
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

        $accountOptions = [];
        $sevUsers = [];
        $unities = [];
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

        $this->render('setup.tpl', 'setup', [
            'settings' => $settings,
            'customFields' => $customFields,
            'accountOptions' => $accountOptions,
            'sevUsers' => $sevUsers,
            'unities' => $unities,
            'emailTemplates' => $emailTemplates,
            'proformaEnabled' => $this->application->whmcs->proformaInvoicingEnabled(),
            'themeAdapterInstalled' => $this->application->whmcs->themeAdapterManifestInstalled(),
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
                    $reason = QuickExportGuard::blockReason(
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
                        $jobId = $this->application->jobs->create('bulk_export', $allowed, [
                            'date_start' => $from->format('Y-m-d'),
                            'date_end' => $until->format('Y-m-d'),
                        ], $this->adminId());
                        $job = $this->application->jobs->findJob($jobId);
                        $this->view->flash('success', count($allowed) . ' Rechnungen wurden als fortsetzbarer Job eingereiht.', 'Job #' . $jobId . ' angelegt');
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
        if ($this->isPost()) {
            $this->csrf->assertPost();
            $mappingId = (int) ($_POST['mapping_id'] ?? 0);
            try {
                if (isset($_POST['inspect_legacy_type'])) {
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
                        ];
                    } else {
                        $this->handleLegacyMappingTypeFailure($inspection);
                    }
                } elseif (isset($_POST['confirm_legacy_type'])) {
                    $mapping = $this->legacyMappingContext($mappingId);
                    $result = $this->application->legacyMappingType()->confirm(
                        (int) $mapping->invoice_id,
                        (string) $mapping->invoicenum,
                        (string) $mapping->sevdesk_id,
                        (string) ($_POST['document_type'] ?? ''),
                    );
                    if (($result['status'] ?? '') === 'confirmed') {
                        if (function_exists('logActivity')) {
                            logActivity(
                                'sevdesk: legacy mapping type confirmed by admin ' . $this->adminId()
                                . '; invoice ' . (int) $mapping->invoice_id
                                . '; mapping ' . $mappingId
                                . '; type ' . (string) ($result['suggestedType'] ?? ''),
                            );
                        }
                        $this->view->flash(
                            'success',
                            'Der Belegtyp wurde nach erneuter Remote-Prüfung additiv bestätigt.',
                            'Legacy-Zuordnung typisiert',
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
        $base = $this->moduleLink . '&a=assignmentManager'
            . ($status !== '' ? '&status=' . rawurlencode($status) : '')
            . ($query !== '' ? '&q=' . rawurlencode($query) : '');

        $this->render('assignment_manager.tpl', 'assignmentManager', [
            'filters' => ['status' => $status, 'q' => $query],
            'mappings' => $result['items'],
            'typeInspection' => $typeInspection,
            'pagination' => $this->pagination($result['page'], $result['pages'], $base),
        ]);
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
                if (isset($_POST['confirm_email_retry'])) {
                    $ok = ($_POST['confirm_duplicate_delivery_risk'] ?? '') === 'yes'
                        && $this->application->jobs->confirmEmailRetry($itemId);
                } else {
                    $ok = isset($_POST['reconcile'])
                        ? $this->application->jobs->reconcile($itemId)
                        : $this->application->jobs->retry($itemId);
                }
                $this->view->flash(
                    $ok ? 'success' : 'warning',
                    $ok
                        ? 'Die Position wurde mit der erforderlichen Bestätigung erneut eingeplant.'
                        : 'Die Position konnte nicht erneut eingeplant werden; prüfen Sie Status und Warnbestätigung.',
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
                ], true);
            $item->can_confirm_email_retry = (string) $item->status === 'ambiguous'
                && (string) $item->action === 'export_document'
                && (string) $item->checkpoint === 'whmcs_email_write_requested';
            $item->can_reconcile = (string) $item->status === 'ambiguous'
                && !$item->can_confirm_email_retry;
            $item->recommendation = $item->can_confirm_email_retry
                ? 'Der frühere Provider-Übergang ist nicht lesend beweisbar. Ein erneuter Versand kann zu einer Doppelmail führen.'
                : ($item->can_reconcile
                    ? 'Zuerst anhand des WHMCS-Markers in sevdesk abgleichen.'
                    : 'Konfiguration oder Belegdaten korrigieren und danach erneut versuchen.');
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
                'invoice.id as existing_invoice_id',
                'invoice.invoicenum',
            ]);
        if (
            $mapping === null
            || (int) ($mapping->invoice_id ?? 0) < 1
            || (int) ($mapping->existing_invoice_id ?? 0) !== (int) $mapping->invoice_id
            || preg_match('/^[1-9]\d*$/', trim((string) ($mapping->sevdesk_id ?? ''))) !== 1
            || ($mapping->document_type ?? null) !== null
        ) {
            throw new RuntimeException(
                'Nur vollständige Legacy-Zuordnungen ohne bestätigten Dokumenttyp können geprüft werden.',
            );
        }

        return $mapping;
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

        $storedInvoiceId = (int) Capsule::table(Migrator::MAPPING_TABLE)
            ->where('id', $mappingId)
            ->value('invoice_id');
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

        $removed = $this->application->mappings->unlinkById($mappingId);
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
            'Nur die lokale Zuordnung wurde entfernt. Der sevdesk-Beleg blieb unverändert.',
            'Zuordnung aufgehoben',
        );
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
            'delivery_requested' => false,
        ];
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

        $deliveryChannel = trim((string) ($_POST['invoice_delivery_channel'] ?? 'sevdesk'));
        if (!in_array($deliveryChannel, ['sevdesk', 'whmcs_template'], true)) {
            throw new RuntimeException('Ungültiger Versandkanal.');
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
        if (
            $modeChanged
            && Capsule::table(Migrator::ITEMS_TABLE)
                ->whereIn('action', ['export_document', 'export_voucher', 'reconcile_voucher'])
                ->whereIn('status', ['pending', 'running', 'retry_wait', 'ambiguous'])
                ->exists()
        ) {
            throw new RuntimeException(
                'Exportmodus, Dokumenthoheit und Steuerprofile können erst geändert werden, wenn aktive und '
                    . 'ungeklärte Exportjobs abgeschlossen oder bereinigt sind.',
            );
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
        $this->application->config->set('invoice_sev_user_id', $sevUserId);
        $this->application->config->set('invoice_unity_id', $unityId);
        $this->application->config->set('invoice_delivery_channel', $deliveryChannel);
        $this->application->config->set('whmcs_invoice_email_template', $emailTemplate);
        $this->application->config->set('sevdesk_email_subject', $emailSubject);
        $this->application->config->set('sevdesk_email_body', $emailBody);
        $this->application->config->set('theme_adapter_confirmed', $themeAdapterConfirmed);
        $this->application->config->set('import_only_paid', isset($_POST['import_only_paid']));
        $this->application->config->set('smallBusinessOwner', isset($_POST['smallBusinessOwner']));
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
        $proposed = [
            'export_mode' => (string) ($_POST['export_mode'] ?? 'voucher_only'),
            'document_authority' => (string) ($_POST['document_authority'] ?? 'whmcs'),
            'oss_profile' => (string) ($_POST['oss_profile'] ?? 'blocked'),
            'invoice_canary_confirmed' => isset($_POST['invoice_canary_confirmed']) ? 'on' : '',
            'invoice_sev_user_id' => trim((string) ($_POST['invoice_sev_user_id'] ?? '')),
            'invoice_unity_id' => trim((string) ($_POST['invoice_unity_id'] ?? '')),
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
        $invoiceItems = [];
        foreach (
            Capsule::table('tblinvoiceitems')
                ->whereIn('invoiceid', $invoiceIds)
                ->orderBy('id')
                ->get(['invoiceid', 'type', 'description', 'amount', 'taxed']) as $item
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
        $invoiceTaxPolicy = $invoiceCapable ? $this->application->invoiceTaxPolicy() : null;
        $voucherTaxPolicy = $exportMode === DocumentTargetResolver::MODE_VOUCHER_ONLY
            ? $this->application->taxPolicy()
            : null;
        $taxType = strtolower((string) ($GLOBALS['CONFIG']['TaxType'] ?? 'Exclusive'));
        $net = $taxType !== 'inclusive';
        $configuredStart = DateTimeImmutable::createFromFormat(
            '!d-m-Y',
            (string) $this->application->config->get('import_after', '01-01-1999'),
        );
        $result = [];
        foreach ($rows as $invoice) {
            $invoiceId = (int) $invoice->id;
            $mapping = $mappings[$invoiceId] ?? null;
            $country = strtoupper((string) ($invoice->country ?? ''));
            $taxExempt = in_array(strtolower((string) ($invoice->taxexempt ?? '')), ['1', 'true', 'yes', 'on'], true);
            $exportable = true;
            $reason = '';
            $reasonCode = '';
            if ($mapping !== null && $mapping->sevdesk_id !== null) {
                $exportable = false;
                $reason = 'Bereits zugeordnet';
            } elseif ($mapping !== null) {
                $exportable = false;
                $reason = 'Alte NULL-Zuordnung: Reconciliation erforderlich';
            } elseif ((float) $invoice->credit > 0) {
                $exportable = false;
                $reason = 'Angewendetes Guthaben benötigt Einzelprüfung';
                $reasonCode = 'credit_applied_requires_review';
            } elseif ((float) $invoice->total <= 0) {
                $exportable = false;
                $reason = 'Null- oder Negativbetrag benötigt Einzelprüfung';
            } elseif (strtoupper(trim((string) ($invoice->currencycode ?? ''))) !== 'EUR') {
                $exportable = false;
                $reason = 'Fremdwährung benötigt bis zur separaten Freigabe eine manuelle Prüfung';
                $reasonCode = 'foreign_currency_requires_review';
            } elseif ($this->application->config->bool('import_only_paid', true) && (string) $invoice->status !== 'Paid') {
                $exportable = false;
                $reason = 'Nach der aktuellen Einstellung werden nur bezahlte Rechnungen exportiert';
            } elseif ($configuredStart instanceof DateTimeImmutable && (string) $invoice->date < $configuredStart->format('Y-m-d')) {
                $exportable = false;
                $reason = 'Rechnung liegt vor dem konfigurierten Exportstichtag';
            }

            $lines = [];
            $types = [];
            $taxRate = (float) ($invoice->taxrate ?? 0) + (float) ($invoice->taxrate2 ?? 0);
            if ($exportable && (float) ($invoice->taxrate ?? 0) > 0 && (float) ($invoice->taxrate2 ?? 0) > 0) {
                $exportable = false;
                $reason = 'Zwei gleichzeitig angewendete WHMCS-Steuern benötigen eine manuelle Positionsprüfung';
            }
            foreach ($invoiceItems[$invoiceId] ?? [] as $item) {
                $type = strtolower(trim((string) $item->type));
                if ($type !== '') {
                    $types[] = $type;
                }
                try {
                    $lines[] = new LineItem(
                        (string) $item->description,
                        (string) $item->amount,
                        self::truthy($item->taxed ?? false) ? $this->decimal($taxRate) : '0',
                        $net,
                    );
                } catch (\InvalidArgumentException) {
                    $exportable = false;
                    $reason = 'Mindestens eine Rechnungsposition enthält ungültige Betrags- oder Steuerdaten';
                }
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
            if ($exportable) {
                try {
                    $snapshot = new InvoiceSnapshot(
                        $invoiceId,
                        (int) $invoice->userid,
                        trim((string) $invoice->invoicenum) !== '' ? (string) $invoice->invoicenum : (string) $invoiceId,
                        new DateTimeImmutable((string) $invoice->date),
                        (string) $invoice->currencycode,
                        (string) $invoice->total,
                        (string) $invoice->credit,
                        $lines,
                    );
                    if (abs($snapshot->lineGrossMinorUnits() - $snapshot->totalMinorUnits()) > 1) {
                        $exportable = false;
                        $reason = 'Positionssumme und WHMCS-Rechnungsbetrag weichen um mehr als 0,01 ab';
                    }
                } catch (\Throwable) {
                    $exportable = false;
                    $reason = 'Die WHMCS-Rechnung konnte nicht konsistent normalisiert werden';
                }
            }
            if ($exportable) {
                $arguments = [
                    $country,
                    $taxExempt,
                    trim((string) ($invoice->tax_id ?? '')) ?: null,
                    $this->application->config->bool('smallBusinessOwner'),
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
                    trim((string) $invoice->invoicenum) !== '',
                );
                if (
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
            $result[] = [
                'id' => (int) $invoice->id,
                'invoice_id' => (int) $invoice->id,
                'invoicenum' => (string) $invoice->invoicenum,
                'client_name' => $clientName,
                'countrycode' => $country,
                'date' => (string) $invoice->date,
                'datepaid' => (string) $invoice->datepaid,
                'total' => number_format((float) $invoice->total, 2, ',', '.'),
                'gross_formatted' => number_format((float) $invoice->total, 2, ',', '.'),
                'credit_formatted' => number_format((float) $invoice->credit, 2, ',', '.'),
                'payable_formatted' => number_format(max(0, (float) $invoice->total - (float) $invoice->credit), 2, ',', '.'),
                'status' => (string) $invoice->status,
                'mapped' => $mapping !== null && $mapping->sevdesk_id !== null,
                'sevdesk_id' => $mapping->sevdesk_id ?? null,
                'eligible' => $exportable,
                'exportable' => $exportable,
                'reason' => $reason,
                'reason_code' => $reasonCode,
                'credit_voucher_confirmation_allowed' => $reasonCode === 'credit_applied_requires_review'
                    && $this->application->config->get('export_mode')
                        === DocumentTargetResolver::MODE_VOUCHER_ONLY,
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
            $item->document_authority = trim((string) ($candidate['documentAuthority']
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
