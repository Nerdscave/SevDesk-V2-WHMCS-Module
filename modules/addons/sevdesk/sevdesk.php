<?php

declare(strict_types=1);

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Controller;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Support\ClientDocumentPresenter;
use WHMCS\Module\Addon\SevDesk\Support\DocumentDeliveryContext;

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/lib/Autoloader.php';

function sevdesk_acquire_runner_lock(): bool
{
    $lock = Capsule::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['whmcs_sevdesk_job_runner']);

    return isset($lock->acquired) && (int) $lock->acquired === 1;
}

function sevdesk_release_runner_lock(): void
{
    Capsule::selectOne('SELECT RELEASE_LOCK(?) AS released', ['whmcs_sevdesk_job_runner']);
}

/** @return array<string, mixed> */
function sevdesk_config(): array
{
    return [
        'name' => 'sevdesk Integration',
        'description' => 'Fortsetzbarer WHMCS→sevdesk Voucher-/Invoice-Export. '
            . 'Die betriebliche Konfiguration erfolgt ausschließlich auf der Modul-Seite „Einrichtung“.',
        'version' => '2.1.0-rc.2',
        'author' => 'Nerdscave',
        'language' => 'german',
        // WHMCS persists fields declared here without passing through the guarded
        // setup controller. Existing tbladdonmodules rows remain readable, but
        // all future operational changes must use the locked setup workflow.
        'fields' => [],
    ];
}

/** @return array{code:string,message:string} */
function sevdesk_migration_diagnostic(Throwable $error): array
{
    $known = [
        'Duplicate invoice mappings prevent creation of the unique index.' => [
            'code' => 'legacy_duplicate_invoice_mapping',
            'message' => 'Mehrere Altzuordnungen verwenden dieselbe WHMCS-Rechnung. '
                . 'Die Migration hat nichts bereinigt; der Bestand muss vor einem erneuten Versuch geprüft werden.',
        ],
        'Duplicate sevdesk mappings prevent creation of the unique index.' => [
            'code' => 'legacy_duplicate_remote_mapping',
            'message' => 'Mehrere Altzuordnungen verwenden dieselbe sevdesk-ID. Die Migration hat nichts bereinigt; der Bestand muss vor einem erneuten Versuch geprüft werden.',
        ],
        'The legacy invoice index has the expected name but is not a unique single-column index.' => [
            'code' => 'legacy_invoice_index_conflict',
            'message' => 'Der vorhandene Index für WHMCS-Rechnungen hat eine unerwartete Definition. Die Migration wurde ohne automatische Indexänderung abgebrochen.',
        ],
        'The legacy sevdesk index has the expected name but is not a unique single-column index.' => [
            'code' => 'legacy_remote_index_conflict',
            'message' => 'Der vorhandene Index für sevdesk-IDs hat eine unerwartete Definition. Die Migration wurde ohne automatische Indexänderung abgebrochen.',
        ],
    ];

    return $known[$error->getMessage()] ?? [
        'code' => 'migration_failed',
        'message' => 'Die additive Schema-Migration konnte nicht sicher abgeschlossen werden. '
            . 'Automatische Exporte bleiben deaktiviert; bitte den Fehler anhand der Referenz im Serverprotokoll prüfen.',
    ];
}

function sevdesk_log_failure(string $scope, Throwable $error, string $diagnosticCode = ''): string
{
    $reference = substr(hash('sha256', get_class($error) . '|' . microtime(true)), 0, 12);
    if (function_exists('logActivity')) {
        $code = $diagnosticCode === '' ? '' : ' (' . $diagnosticCode . ')';
        logActivity('sevdesk ' . $scope . ' failed [' . $reference . ']' . $code . ': ' . get_class($error));
    }

    return $reference;
}

/**
 * Runs the additive schema preparation and safely bootstraps an already active
 * vendor installation that did not execute this rewrite's activation callback.
 */
function sevdesk_prepare_runtime(?string $previousVersion = null): void
{
    $config = new Config();
    $stored = $config->stored();
    $rewriteMarkerWasEstablished = array_key_exists('module_active', $stored);
    $rewriteSignatureWasEstablished = ($stored[Config::RUNTIME_SIGNATURE_SETTING] ?? '')
        === Config::RUNTIME_SIGNATURE;
    $legacyVersionWasReported = $previousVersion !== null
        && version_compare($previousVersion, '2.0.0', '<');

    // A missing rewrite signature is intentionally fail-closed, including the
    // one-time 2.0 -> 2.1 transition. Schema/settings fingerprints cannot prove
    // which same-named addon most recently owned the installation.
    $requiresInventoryReview = !$rewriteMarkerWasEstablished
        || !$rewriteSignatureWasEstablished
        || $legacyVersionWasReported;
    if ($requiresInventoryReview) {
        $config->quarantineRuntimeOrFail();
    }

    try {
        $rewriteSchemaWasEstablished = Migrator::runtimeSchemaReady();
    } catch (Throwable $error) {
        $config->quarantineRuntime();
        throw $error;
    }
    $rewriteRuntimeWasEstablished = $rewriteMarkerWasEstablished
        && $rewriteSignatureWasEstablished
        && $rewriteSchemaWasEstablished
        && !$legacyVersionWasReported;
    if ($rewriteRuntimeWasEstablished) {
        return;
    }
    $config->quarantineRuntimeOrFail();

    if (!sevdesk_acquire_runner_lock()) {
        throw new RuntimeException('The sevdesk job runner is active; migration remains quarantined.');
    }
    try {
        Migrator::up();
        Migrator::assertRuntimeSchema();
        $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);

        // Vendor versions are not product-family markers. Missing rewrite
        // signature/schema state is always the fail-closed replacement signal.
        $config->set('module_active', 'on');
        if ($previousVersion !== null) {
            $config->set('upgraded_from_version', $previousVersion);
        }
    } catch (Throwable $error) {
        $config->quarantineRuntime();
        throw $error;
    } finally {
        sevdesk_release_runner_lock();
    }
}

/** @return array{status:string,description:string} */
function sevdesk_activate(): array
{
    try {
        $config = new Config();
        $config->quarantineRuntimeOrFail();
        if (!sevdesk_acquire_runner_lock()) {
            throw new RuntimeException('The sevdesk job runner is active; activation remains quarantined.');
        }
        try {
            Migrator::up();
            Migrator::assertRuntimeSchema();
            $config->set(Config::RUNTIME_SIGNATURE_SETTING, Config::RUNTIME_SIGNATURE);
            $config->set('module_active', 'on');
        } finally {
            sevdesk_release_runner_lock();
        }

        return [
            'status' => 'success',
            'description' => 'Schema installiert. Die Synchronisation bleibt bis zur Einrichtung und Canary-Prüfung deaktiviert.',
        ];
    } catch (Throwable $error) {
        try {
            $config->quarantineRuntime();
        } catch (Throwable) {
            // The sanitized migration error remains the only public result.
        }
        $diagnostic = sevdesk_migration_diagnostic($error);
        $reference = sevdesk_log_failure('activation migration', $error, $diagnostic['code']);

        return [
            'status' => 'error',
            'description' => $diagnostic['message'] . ' Code: ' . $diagnostic['code'] . '; Referenz: ' . $reference,
        ];
    }
}

/** @param array<string, mixed> $vars */
function sevdesk_upgrade(array $vars): void
{
    $previousVersion = (string) ($vars['version'] ?? '0.0.0');
    try {
        sevdesk_prepare_runtime($previousVersion);
    } catch (Throwable $error) {
        $diagnostic = sevdesk_migration_diagnostic($error);
        $reference = sevdesk_log_failure('upgrade migration', $error, $diagnostic['code']);
        throw new RuntimeException(
            'sevdesk migration failed [' . $diagnostic['code'] . ']; reference ' . $reference,
            0,
            $error,
        );
    }
    if (version_compare($previousVersion, '2.1.0', '<')) {
        // Additive defaults preserve the established WHMCS + voucher_only
        // behaviour. Invoice writes remain impossible until the operator has
        // completed and explicitly attested the external tenant canary.
        (new Config())->ensureDefaults();
    }
}

/** @return array{status:string,description:string} */
function sevdesk_deactivate(): array
{
    $config = new Config();
    $moduleDisabled = false;
    $syncDisabled = false;
    try {
        $config->set('module_active', '');
        $moduleDisabled = true;
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk deactivation could not persist claim-blocking gate: ' . get_class($error));
        }
    }
    try {
        $config->set('sync_enabled', '');
        $syncDisabled = true;
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk deactivation could not clear enqueueing gate: ' . get_class($error));
        }
    }

    if (!$moduleDisabled) {
        return [
            'status' => 'error',
            'description' => 'Die Modullaufzeit konnte nicht sicher deaktiviert werden. '
                . 'Bitte Cron und Modulzugriff bis zur Klärung extern stoppen.',
        ];
    }

    return [
        'status' => 'success',
        'description' => $syncDisabled
            ? 'Hooks wurden deaktiviert. Zuordnungen, Jobs und Prüfdaten bleiben vollständig erhalten.'
            : 'Die Modullaufzeit wurde deaktiviert; der zusätzliche Sync-Schalter konnte nicht gespeichert werden. '
                . 'Zuordnungen, Jobs und Prüfdaten bleiben vollständig erhalten.',
    ];
}

/** @param array<string, mixed> $vars */
function sevdesk_sidebar(array $vars): string
{
    $moduleLink = htmlspecialchars((string) ($vars['modulelink'] ?? 'addonmodules.php?module=sevdesk'), ENT_QUOTES, 'UTF-8');
    $items = [
        '' => 'Übersicht',
        'setup' => 'Einrichtung',
        'singleImport' => 'Einzelexport',
        'massImport' => 'Sammelexport',
        'jobs' => 'Jobs & Klärfälle',
        'assignmentManager' => 'Zuordnungen',
        'bookingAssistant' => 'Buchungsassistent',
        'corrections' => 'Korrektur-Voucher',
        'health' => 'Systemzustand',
    ];
    $links = '';
    foreach ($items as $action => $label) {
        $href = $moduleLink . ($action === '' ? '' : '&amp;a=' . rawurlencode($action));
        $links .= '<li><a href="' . $href . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }

    return '<div class="sidebar-header"><i class="fas fa-file-invoice"></i> sevdesk Modul</div>'
        . '<ul class="menu">' . $links . '</ul>';
}

/** @param array<string, mixed> $vars */
function sevdesk_output(array $vars): void
{
    try {
        // WHMCS normally invokes sevdesk_upgrade() on a config-version change.
        // This identical local-only bootstrap also covers a replaced active
        // addon whose vendor happened to report the same version string.
        sevdesk_prepare_runtime();
    } catch (Throwable $error) {
        $diagnostic = sevdesk_migration_diagnostic($error);
        $reference = sevdesk_log_failure('admin migration', $error, $diagnostic['code']);
        echo '<div class="alert alert-danger"><strong>sevdesk-Migrationsfehler:</strong> '
            . htmlspecialchars($diagnostic['message'], ENT_QUOTES, 'UTF-8')
            . ' Code: ' . htmlspecialchars($diagnostic['code'], ENT_QUOTES, 'UTF-8') . '; '
            . 'Referenz: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8')
            . '</div>';

        return;
    }

    try {
        (new Controller())->dispatch((string) ($_GET['a'] ?? 'index'), $vars);
    } catch (Throwable $error) {
        $reference = sevdesk_log_failure('admin output', $error);
        echo '<div class="alert alert-danger"><strong>sevdesk-Modulfehler:</strong> Die Aktion konnte sicher nicht abgeschlossen werden. '
            . 'Referenz: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

/**
 * The only public client-area action is an authenticated proxy keyed by the
 * local WHMCS invoice ID. A caller can never supply a sevdesk object ID.
 *
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function sevdesk_clientarea(array $vars): array
{
    $page = static function (string $message, int $status = 200): array {
        if ($status !== 200) {
            http_response_code($status);
        }

        return [
            'pagetitle' => 'Rechnungsdokument',
            'breadcrumb' => ['index.php?m=sevdesk' => 'Rechnungsdokument'],
            'templatefile' => 'client_document',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => ['message' => $message],
        ];
    };

    if ((string) ($_GET['a'] ?? '') !== 'download') {
        return $page('Bitte öffnen Sie das Rechnungsdokument über die zugehörige Rechnung.');
    }

    $requestedInvoiceId = trim((string) ($_GET['id'] ?? ''));
    $validatedInvoiceId = filter_var(
        $requestedInvoiceId,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]],
    );
    if (preg_match('/^[1-9]\d*$/', $requestedInvoiceId) !== 1 || $validatedInvoiceId === false) {
        return $page('Das Rechnungsdokument ist nicht verfügbar.', 404);
    }
    $invoiceId = $validatedInvoiceId;

    $application = null;
    try {
        $currentUser = new CurrentUser();
        $client = $currentUser->client();
        if ($client === null || (int) $client->id < 1) {
            return $page('Das Rechnungsdokument ist nicht verfügbar.', 404);
        }

        $application = Application::instance();
        if (
            !$application->config->bool('module_active')
            || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
            || $application->whmcs->invoiceOwnerId($invoiceId) !== (int) $client->id
        ) {
            return $page('Das Rechnungsdokument ist nicht verfügbar.', 404);
        }

        $mapping = $application->mappings->findByInvoice($invoiceId);
        $documentContext = $application->jobs->latestDocumentContextForInvoice(
            $invoiceId,
            $mapping !== null,
        );
        if (!DocumentDeliveryContext::usesSevdeskInvoiceAuthority($documentContext, $mapping)) {
            return $page('Das Rechnungsdokument ist nicht verfügbar.', 404);
        }
        if (!ClientDocumentPresenter::isReadyInvoiceMapping($mapping)) {
            return $page('Das Rechnungsdokument ist noch nicht verfügbar.', 409);
        }

        $expectedHash = strtolower(trim((string) $mapping->pdf_sha256));
        $pdf = $application->invoicePdf()->fetch((string) $mapping->sevdesk_id);
        if (!hash_equals($expectedHash, $pdf['sha256'])) {
            throw new RuntimeException('The final sevdesk Invoice PDF changed after verification.');
        }

        if (headers_sent()) {
            throw new RuntimeException('The Invoice PDF response headers were already sent.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $pdf['filename'] . '"');
        header('Content-Length: ' . strlen($pdf['contents']));
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo $pdf['contents'];
        exit;
    } catch (Throwable $error) {
        if ($error instanceof ApiException && $error->isAuthenticationFailure() && $application !== null) {
            $safety = $application->config->tripAuthenticationSafetyGates();
            if (function_exists('logActivity') && (!$safety['alarm'] || !$safety['syncDisabled'])) {
                logActivity('sevdesk client PDF authentication safety gates required fallback.');
            }
        }
        $reference = substr(hash('sha256', get_class($error) . '|' . microtime(true)), 0, 12);
        if (function_exists('logActivity')) {
            logActivity('sevdesk client PDF proxy failed [' . $reference . ']: ' . get_class($error));
        }

        return $page('Das Rechnungsdokument konnte nicht sicher bereitgestellt werden. Referenz: ' . $reference, 503);
    }
}
