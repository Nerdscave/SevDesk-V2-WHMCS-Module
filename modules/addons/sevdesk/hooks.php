<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Support\AdminAssets;
use WHMCS\Module\Addon\SevDesk\Support\AdminInvoiceControls;
use WHMCS\Module\Addon\SevDesk\Support\ClientDocumentPresenter;
use WHMCS\Module\Addon\SevDesk\Support\DocumentDeliveryContext;
use WHMCS\Module\Addon\SevDesk\Support\EmailAttachmentContext;
use WHMCS\Module\Addon\SevDesk\Support\InvoiceEmailGuardContext;
use WHMCS\Module\Addon\SevDesk\Support\QuickExportGuard;

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/lib/Autoloader.php';

/**
 * Event-driven enqueueing stays disabled during setup and canary runs, while
 * the runner remains available for explicitly created admin jobs.
 */
function sevdesk_automatic_enqueue_enabled(Application $application): bool
{
    if (
        !$application->config->bool('module_active')
        || !$application->config->bool('sync_enabled')
        || $application->config->bool(Config::RUNTIME_REVIEW_SETTING)
        || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
            !== Config::RUNTIME_SIGNATURE
    ) {
        return false;
    }

    $mode = (string) $application->config->get('export_mode', 'voucher_only');
    if ($mode !== 'voucher_only' && !$application->config->bool('invoice_canary_confirmed')) {
        return false;
    }
    if (
        (string) $application->config->get('e_invoice_mode', 'off') !== 'off'
        && !$application->config->bool('e_invoice_canary_confirmed')
    ) {
        return false;
    }

    return true;
}

/** @param array<string, mixed> $vars */
function sevdesk_enqueue_invoice(array $vars, string $event): void
{
    try {
        if (!Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return;
        }
        $application = Application::instance();
        // InvoicePaidPreEmail and InvoicePaid share one Application instance.
        // Refresh here so an authentication alarm raised by a parallel worker
        // cannot be hidden by settings cached during the earlier mail hook.
        $application->config->refresh();
        $automaticEnqueueEnabled = sevdesk_automatic_enqueue_enabled($application);
        $authAlarmAuthorityPending = !$automaticEnqueueEnabled
            && $event === 'InvoicePaid'
            && $application->config->bool('module_active')
            && !$application->config->bool('sync_enabled')
            && !$application->config->bool(Config::RUNTIME_REVIEW_SETTING)
            && (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                === Config::RUNTIME_SIGNATURE
            && $application->config->bool('invoice_canary_confirmed')
            && (string) $application->config->get('export_mode', 'voucher_only') === 'invoice_only'
            && (string) $application->config->get('document_authority', 'whmcs') === 'sevdesk'
            && trim((string) $application->config->get('health_alarm', ''))
                === 'api_authentication_failed';
        if (!$automaticEnqueueEnabled && !$authAlarmAuthorityPending) {
            return;
        }
        $mode = (string) $application->config->get('export_mode', 'voucher_only');
        $documentAuthority = (string) $application->config->get('document_authority', 'whmcs');
        $ossProfile = (string) $application->config->get('oss_profile', 'blocked');
        $euB2cMode = (string) $application->config->get('eu_b2c_mode', 'blocked');
        $eInvoiceMode = (string) $application->config->get('e_invoice_mode', 'off');
        $storedEInvoiceActiveFrom = (string) $application->config->get('e_invoice_active_from', '');
        $eInvoiceActiveFrom = DateTimeImmutable::createFromFormat('!d-m-Y', $storedEInvoiceActiveFrom);
        $requestedEInvoiceActiveFrom = $eInvoiceActiveFrom instanceof DateTimeImmutable
            && $eInvoiceActiveFrom->format('d-m-Y') === $storedEInvoiceActiveFrom
                ? $eInvoiceActiveFrom->format('Y-m-d')
                : '';
        $deliveryChannel = $documentAuthority === 'sevdesk'
            ? (string) $application->config->get('invoice_delivery_channel', 'sevdesk')
            : null;
        $onlyPaid = $application->config->bool('import_only_paid', true);
        if ($mode === 'invoice_only' && $event !== 'InvoicePaid') {
            return;
        }
        if ($mode === 'voucher_only' && (($event === 'InvoicePaid') === !$onlyPaid)) {
            return;
        }
        if ($mode === 'invoice_for_oss' && $onlyPaid && $event !== 'InvoicePaid') {
            return;
        }

        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId < 1 || $application->mappings->findCompleteByInvoice($invoiceId) !== null) {
            return;
        }

        $application->jobs->create('automatic_export', [[
            'invoice_id' => $invoiceId,
            'action' => 'export_document',
            'dedupe_key' => 'export_voucher:' . $invoiceId,
            'candidate' => [
                'trigger' => $event,
                'requestedExportMode' => $mode,
                'requestedDocumentAuthority' => $documentAuthority,
                'requestedOssProfile' => $ossProfile,
                'requestedEuB2cMode' => $euB2cMode,
                'requestedDeliveryChannel' => $deliveryChannel,
                'requestedEInvoiceMode' => $eInvoiceMode,
                'requestedEInvoiceClientFieldId' => $application->config->int(
                    'e_invoice_client_field_id',
                ),
                'requestedEInvoicePaymentMethodId' => trim((string) $application->config->get(
                    'e_invoice_payment_method_id',
                    '',
                )),
                'requestedEInvoiceActiveFrom' => $requestedEInvoiceActiveFrom,
                'requestedEInvoiceCanaryConfirmed' => $application->config->bool(
                    'e_invoice_canary_confirmed',
                ),
                'requestedEInvoiceSevUserId' => trim((string) $application->config->get(
                    'invoice_sev_user_id',
                    '',
                )),
                'requestedEInvoiceUnityId' => trim((string) $application->config->get(
                    'invoice_unity_id',
                    '',
                )),
                'delivery_requested' => $event === 'InvoicePaid'
                    && $documentAuthority === 'sevdesk',
            ],
        ]], ['trigger' => $event]);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk could not enqueue ' . $event . ': ' . get_class($error));
        }
    }
}

/**
 * Marks the first paid-invoice email for local suppression before WHMCS builds
 * it. The later InvoicePaid hook still owns job creation and delivery intent.
 *
 * @param array<string, mixed> $vars
 */
function sevdesk_prepare_paid_invoice_email_guard(array $vars): void
{
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId < 1) {
            return;
        }

        $application = Application::instance();
        if (
            !$application->config->bool('module_active')
            || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
        ) {
            return;
        }

        $currentModeOwnsNewInvoice =
            (string) $application->config->get('export_mode', 'voucher_only') === 'invoice_only'
            && (string) $application->config->get('document_authority', 'whmcs') === 'sevdesk';
        // Request-local and idempotent: no job, remote call or PDF operation is
        // allowed before WHMCS has completed the payment-email phase. The
        // authority guard deliberately survives review, authentication and
        // sync pauses; those states must not silently restore a WHMCS final PDF.
        if ($currentModeOwnsNewInvoice) {
            InvoiceEmailGuardContext::register($invoiceId);
        }
        $mapping = $application->mappings->findByInvoice($invoiceId);
        if ($mapping !== null) {
            // Existing mappings keep their frozen/legacy document authority;
            // a later global mode change must not reclassify their email. A
            // proven sevdesk-owned Invoice keeps the fail-closed guard so a
            // later local read failure cannot leak WHMCS' final document.
            $documentContext = $application->jobs->latestDocumentContextForInvoice(
                $invoiceId,
                true,
            );
            if (DocumentDeliveryContext::usesSevdeskInvoiceAuthority($documentContext, $mapping)) {
                InvoiceEmailGuardContext::register($invoiceId);
            } else {
                InvoiceEmailGuardContext::discard($invoiceId);
            }
        }
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk could not prepare the paid Invoice email guard: ' . get_class($error));
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
        if (!sevdesk_automatic_enqueue_enabled($application)) {
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
        if (!sevdesk_automatic_enqueue_enabled($application)) {
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

/**
 * Exposes only local, immutable presentation state to the installed theme
 * adapter. No sevdesk request may occur while a customer invoice is rendered.
 *
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function sevdesk_client_invoice_variables(array $vars): array
{
    try {
        if (!Capsule::schema()->hasTable(Migrator::MAPPING_TABLE)) {
            return [];
        }

        $application = Application::instance();
        if (
            !$application->config->bool('module_active')
            || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
        ) {
            return [];
        }

        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invoiceId'] ?? $_GET['id'] ?? 0);
        if ($invoiceId < 1) {
            return [];
        }

        $invoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->select(['id', 'invoicenum', 'status'])
            ->first();
        if ($invoice === null) {
            return [];
        }

        $mapping = $application->mappings->findByInvoice($invoiceId);
        $documentContext = $application->jobs->latestDocumentContextForInvoice(
            $invoiceId,
            $mapping !== null,
        );
        if (!DocumentDeliveryContext::usesSevdeskInvoiceAuthority($documentContext, $mapping)) {
            return [];
        }

        $invoiceNumber = trim((string) ($invoice->invoicenum ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = (string) $invoiceId;
        }
        $webRoot = rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/');
        $downloadUrl = ($webRoot === '' ? '' : $webRoot . '/')
            . 'index.php?m=sevdesk&a=download&id=' . rawurlencode((string) $invoiceId);

        return [
            'sevdeskDocument' => ClientDocumentPresenter::present(
                (string) ($invoice->status ?? ''),
                $invoiceNumber,
                $mapping,
                $documentContext['itemStatus'] ?? null,
                $downloadUrl,
            ),
        ];
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk client invoice adapter failed safely: ' . get_class($error));
        }

        return [];
    }
}

/**
 * Adds the one-request PDF attachment prepared by the worker and suppresses
 * every other Invoice template for the same sevdesk-owned document. This hook
 * only reads WHMCS state and the in-memory attachment context.
 *
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function sevdesk_email_pre_send(array $vars): array
{
    $guardApplies = false;
    try {
        $invoiceId = (int) ($vars['relid'] ?? 0);
        $template = trim((string) ($vars['messagename'] ?? ''));
        if ($invoiceId < 1 || $template === '') {
            return [];
        }
        $hasActiveAttachmentContext = EmailAttachmentContext::hasActiveContext($invoiceId, $template);
        $hasPaidInvoiceGuard = InvoiceEmailGuardContext::appliesTo($invoiceId);
        if ($hasActiveAttachmentContext || $hasPaidInvoiceGuard) {
            $guardApplies = true;
        }

        $application = Application::instance();
        if (
            !$application->config->bool('module_active')
            || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
        ) {
            return $guardApplies ? ['abortsend' => true] : [];
        }

        $isInvoiceTemplate = Capsule::table('tblemailtemplates')
            ->whereRaw('LOWER(type) = ?', ['invoice'])
            ->where('name', $template)
            ->exists();
        if (!$isInvoiceTemplate) {
            return $hasActiveAttachmentContext ? ['abortsend' => true] : [];
        }
        $mapping = $application->mappings->findByInvoice($invoiceId);
        $documentContext = $application->jobs->latestDocumentContextForInvoice(
            $invoiceId,
            $mapping !== null,
        );
        if (!DocumentDeliveryContext::usesSevdeskInvoiceAuthority($documentContext, $mapping)) {
            return $guardApplies ? ['abortsend' => true] : [];
        }

        $invoiceStatus = (string) Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('status');
        if ($mapping === null && strcasecmp(trim($invoiceStatus), 'Paid') !== 0) {
            return $guardApplies ? ['abortsend' => true] : [];
        }
        $guardApplies = true;

        $mergeFields = is_array($vars['mergefields'] ?? null) ? $vars['mergefields'] : [];
        $attachmentToken = (string) ($mergeFields['sevdesk_attachment_token'] ?? '');

        if (
            $application->whmcs->isActiveCustomInvoiceTemplate($template)
            && $hasActiveAttachmentContext
        ) {
            $attachment = EmailAttachmentContext::consume($attachmentToken, $invoiceId, $template);
            if ($attachment !== null) {
                return ['attachments' => [$attachment]];
            }
        }

        return ['abortsend' => true];
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk Invoice email guard failed safely: ' . get_class($error));
        }

        return $guardApplies ? ['abortsend' => true] : [];
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
add_hook('InvoicePaidPreEmail', 1, static fn (array $vars) => sevdesk_prepare_paid_invoice_email_guard($vars));
add_hook('InvoicePaid', 1, static fn (array $vars) => sevdesk_enqueue_invoice($vars, 'InvoicePaid'));
add_hook('InvoiceRefunded', 1, static fn (array $vars) => sevdesk_enqueue_review($vars, 'invoice_refunded'));
add_hook('InvoiceCancelled', 1, static fn (array $vars) => sevdesk_enqueue_review($vars, 'invoice_cancelled'));
add_hook('AddTransaction', 1, static fn (array $vars) => sevdesk_enqueue_transaction_review($vars));
add_hook('ClientAreaPageViewInvoice', 1, static fn (array $vars): array => sevdesk_client_invoice_variables($vars));
add_hook('EmailPreSend', 1, static fn (array $vars): array => sevdesk_email_pre_send($vars));

add_hook('AfterCronJob', 1, static function (): void {
    try {
        if (!Capsule::schema()->hasTable(Migrator::ITEMS_TABLE)) {
            return;
        }
        $application = Application::instance();
        if (
            !$application->config->bool('module_active')
            || $application->config->bool(Config::RUNTIME_REVIEW_SETTING)
            || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
        ) {
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
        if (
            !$application->config->bool('module_active')
            || $application->config->bool(Config::RUNTIME_REVIEW_SETTING)
            || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
        ) {
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
