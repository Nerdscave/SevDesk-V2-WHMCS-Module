<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

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
        $mapping = $application->mappings->findCompleteByInvoice($invoiceId);
        if ($mapping !== null) {
            $remoteId = rawurlencode((string) $mapping->sevdesk_id);

            return '<a target="_blank" rel="noopener" href="https://my.sevdesk.de/#/ex/detail/id/' . $remoteId
                . '" class="btn btn-default"><i class="fas fa-up-right-from-square"></i> sevdesk-Beleg</a>';
        }

        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if ($status === 'Draft') {
            return '';
        }
        $token = function_exists('generate_token') ? (string) generate_token('plain') : '';

        return '<form method="post" action="addonmodules.php?module=sevdesk&amp;a=singleImport" style="display:inline-block">'
            . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
            . '<button type="submit" name="import" value="1" class="btn btn-default">'
            . '<i class="fas fa-arrow-right-arrow-left"></i> Zu sevdesk einreihen</button></form>';
    } catch (Throwable) {
        return '';
    }
});
