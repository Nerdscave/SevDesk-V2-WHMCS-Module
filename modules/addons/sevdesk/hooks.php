<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Support\AdminAssets;
use WHMCS\Module\Addon\SevDesk\Support\AdminInvoiceControls;
use WHMCS\Module\Addon\SevDesk\Support\QuickExportGuard;

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/lib/Autoloader.php';

/** @param array<string, mixed> $vars */
function sevdesk_enqueue_invoice(array $vars, string $event): void
{
    try {
        if (!Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return;
        }
        $application = Application::instance();
        if (!$application->config->bool('module_active') || !$application->config->bool('sync_enabled')) {
            return;
        }
        if ($event === 'InvoicePaid' && !$application->config->bool('import_only_paid', true)) {
            return;
        }
        if ($event === 'InvoiceCreated' && $application->config->bool('import_only_paid', true)) {
            return;
        }

        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId < 1 || $application->mappings->findCompleteByInvoice($invoiceId) !== null) {
            return;
        }

        $application->jobs->create('automatic_export', [[
            'invoice_id' => $invoiceId,
            'action' => 'export_voucher',
            'dedupe_key' => 'export_voucher:' . $invoiceId,
        ]], ['trigger' => $event]);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk could not enqueue ' . $event . ': ' . get_class($error));
        }
    }
}

/** @param array<string, mixed> $vars */
function sevdesk_enqueue_review(array $vars, string $reason): void
{
    try {
        if (!Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return;
        }
        $application = Application::instance();
        if (!$application->config->bool('module_active')) {
            return;
        }
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId < 1) {
            return;
        }
        $application->jobs->create('accounting_review', [[
            'invoice_id' => $invoiceId,
            'action' => 'review_notice',
            'dedupe_key' => 'review:' . $reason . ':' . $invoiceId,
        ]], ['reason' => $reason]);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk could not enqueue accounting review: ' . get_class($error));
        }
    }
}

/** @param array<string, mixed> $vars */
function sevdesk_enqueue_transaction_review(array $vars): void
{
    try {
        if (!Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return;
        }
        $application = Application::instance();
        if (!$application->config->bool('module_active')) {
            return;
        }
        $transactionId = (int) ($vars['id'] ?? 0);
        $amountOut = (float) ($vars['amountout'] ?? 0);
        $refundId = (int) ($vars['refundid'] ?? 0);
        if ($transactionId < 1 || ($amountOut <= 0 && $refundId < 1)) {
            return;
        }
        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invocieid'] ?? 0);
        if ($invoiceId < 1) {
            $invoiceId = (int) Capsule::table('tblaccounts')->where('id', $transactionId)->value('invoiceid');
        }
        if ($invoiceId < 1) {
            return;
        }
        $reference = 'review_transaction:' . hash('sha256', (string) $transactionId);
        $application->jobs->create('accounting_review', [[
            'invoice_id' => $invoiceId,
            'action' => 'review_notice',
            'dedupe_key' => $reference,
            'transaction_reference' => $reference,
        ]], ['reason' => 'negative_transaction'], null);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk could not enqueue transaction review: ' . get_class($error));
        }
    }
}

add_hook('AdminAreaHeadOutput', 1, static function (): string {
    if (($_GET['module'] ?? null) !== 'sevdesk') {
        return '';
    }

    return AdminAssets::stylesheetMarkup();
});

add_hook('AdminAreaFooterOutput', 1, static function (): string {
    $output = AdminInvoiceControls::footerForms();
    if (($_GET['module'] ?? null) === 'sevdesk') {
        $output = AdminAssets::scriptMarkup() . $output;
    }

    return $output;
});

add_hook('InvoiceCreated', 1, static fn (array $vars) => sevdesk_enqueue_invoice($vars, 'InvoiceCreated'));
add_hook('InvoicePaid', 1, static fn (array $vars) => sevdesk_enqueue_invoice($vars, 'InvoicePaid'));
add_hook('InvoiceRefunded', 1, static fn (array $vars) => sevdesk_enqueue_review($vars, 'invoice_refunded'));
add_hook('InvoiceCancelled', 1, static fn (array $vars) => sevdesk_enqueue_review($vars, 'invoice_cancelled'));
add_hook('AddTransaction', 1, static fn (array $vars) => sevdesk_enqueue_transaction_review($vars));

add_hook('AfterCronJob', 1, static function (): void {
    try {
        if (!Capsule::schema()->hasTable(Migrator::ITEMS_TABLE)) {
            return;
        }
        $application = Application::instance();
        if (!$application->config->bool('module_active')) {
            return;
        }
        $application->config->set('runner_last_seen', (new DateTimeImmutable())->format(DATE_ATOM));
        $application->runner()->run(10, 50);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk cron runner failed safely: ' . get_class($error));
        }
    }
});

add_hook('AdminInvoicesControlsOutput', 1, static function (array $vars): string {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId < 1 || !Capsule::schema()->hasTable(Migrator::MAPPING_TABLE)) {
            return '';
        }
        $application = Application::instance();
        if (!$application->config->bool('module_active')) {
            return '';
        }
        $mapping = $application->mappings->findByInvoice($invoiceId);
        $remoteId = trim((string) ($mapping->sevdesk_id ?? ''));
        $hasLegacyMapping = $mapping !== null && $remoteId === '';
        $quickEligible = false;
        $invoice = $application->whmcs->invoiceForDryRun($invoiceId);
        if (
            $invoice !== null
            && Capsule::schema()->hasTable(Migrator::JOBS_TABLE)
            && Capsule::schema()->hasTable(Migrator::ITEMS_TABLE)
        ) {
            $invoiceItems = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId);
            $quickEligible = QuickExportGuard::blockReason(
                $invoice,
                $mapping,
                $application->config->bool('import_only_paid', true),
                (string) $application->config->get('import_after', '01-01-1999'),
                (clone $invoiceItems)->exists(),
                (clone $invoiceItems)->where('amount', '<', 0)->exists(),
            ) === null;
        }
        $token = $quickEligible && function_exists('generate_token')
            ? (string) generate_token('plain')
            : '';

        return AdminInvoiceControls::render(
            $invoiceId,
            $remoteId !== '' ? $remoteId : null,
            $hasLegacyMapping,
            $quickEligible,
            $token,
        );
    } catch (Throwable) {
        return '';
    }
});
