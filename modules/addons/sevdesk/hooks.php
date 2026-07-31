<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;
use WHMCS\Module\Addon\SevDesk\Support\AdminAssets;
use WHMCS\Module\Addon\SevDesk\Support\AdminInvoiceControls;
use WHMCS\Module\Addon\SevDesk\Support\ClientDocumentPresenter;
use WHMCS\Module\Addon\SevDesk\Support\DocumentDeliveryContext;
use WHMCS\Module\Addon\SevDesk\Support\DirectDeliveryIntentContext;
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
    if (
        (string) $application->config->get(
            'invoice_lifecycle_mode',
            MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
        ) === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
        && !$application->config->bool('direct_invoice_canary_confirmed')
    ) {
        return false;
    }

    return true;
}

/** @return array{containerInvoiceId:int|null,targetInvoiceIds:list<int>} */
function sevdesk_mass_payment_hook_context(Application $application, int $invoiceId): array
{
    if ($invoiceId < 1) {
        return ['containerInvoiceId' => null, 'targetInvoiceIds' => []];
    }

    $context = $application->paymentStructure()->massPaymentContextForHook($invoiceId);
    $targets = $context['targetInvoiceIds'] ?? null;
    $containerInvoiceId = $context['containerInvoiceId'] ?? null;
    if (!is_array($targets)) {
        throw new RuntimeException('The confirmed WHMCS mass-payment hook context is invalid.');
    }
    $ids = [];
    foreach ($targets as $targetId) {
        if (
            (!is_int($targetId) && !is_string($targetId))
            || preg_match('/^[1-9]\d*$/', (string) $targetId) !== 1
            || (int) $targetId === $invoiceId
            || isset($ids[(int) $targetId])
        ) {
            throw new RuntimeException(
                'The confirmed WHMCS mass-payment target snapshot is invalid.',
            );
        }
        $ids[(int) $targetId] = true;
    }
    $targetIds = array_keys($ids);
    sort($targetIds, SORT_NUMERIC);

    if (
        $containerInvoiceId !== null
        && (
            (!is_int($containerInvoiceId) && !is_string($containerInvoiceId))
            || preg_match('/^[1-9]\d*$/', (string) $containerInvoiceId) !== 1
        )
    ) {
        throw new RuntimeException('The confirmed WHMCS mass-payment parent is invalid.');
    }
    $containerInvoiceId = $containerInvoiceId !== null ? (int) $containerInvoiceId : null;
    if (
        ($targetIds !== [] && $containerInvoiceId !== $invoiceId)
        || ($targetIds === [] && $containerInvoiceId === $invoiceId)
    ) {
        throw new RuntimeException('The confirmed WHMCS mass-payment graph is inconsistent.');
    }

    return [
        'containerInvoiceId' => $containerInvoiceId,
        'targetInvoiceIds' => $targetIds,
    ];
}

/** @return list<int> */
function sevdesk_mass_payment_target_ids(Application $application, int $invoiceId): array
{
    return sevdesk_mass_payment_hook_context($application, $invoiceId)['targetInvoiceIds'];
}

/** @param array<string, mixed> $vars */
function sevdesk_enqueue_invoice(array $vars, string $event): bool
{
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
        if (!Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return false;
        }
        $application = Application::instance();
        // InvoicePaidPreEmail and InvoicePaid share one Application instance.
        // Refresh here so an authentication alarm raised by a parallel worker
        // cannot be hidden by settings cached during the earlier mail hook.
        $application->config->refresh();
        $automaticEnqueueEnabled = sevdesk_automatic_enqueue_enabled($application);
        $authAlarmAuthorityPending = !$automaticEnqueueEnabled
            && in_array($event, ['InvoicePaid', 'InvoiceCreated', 'InvoiceCreatedEmailPending'], true)
            && $application->config->bool('module_active')
            && !$application->config->bool('sync_enabled')
            && !$application->config->bool(Config::RUNTIME_REVIEW_SETTING)
            && (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                === Config::RUNTIME_SIGNATURE
            && $application->config->bool('invoice_canary_confirmed')
            && (string) $application->config->get('export_mode', 'voucher_only') === 'invoice_only'
            && (string) $application->config->get('document_authority', 'whmcs') === 'sevdesk'
            && (
                $event === 'InvoicePaid'
                || (
                    $application->config->bool('direct_invoice_canary_confirmed')
                    && (string) $application->config->get(
                        'invoice_lifecycle_mode',
                        MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
                    ) === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
                )
            )
            && trim((string) $application->config->get('health_alarm', ''))
                === 'api_authentication_failed';
        if (!$automaticEnqueueEnabled && !$authAlarmAuthorityPending) {
            return false;
        }
        $mode = (string) $application->config->get('export_mode', 'voucher_only');
        $documentAuthority = (string) $application->config->get('document_authority', 'whmcs');
        $ossProfile = (string) $application->config->get('oss_profile', 'blocked');
        $euB2cMode = (string) $application->config->get('eu_b2c_mode', 'blocked');
        $eInvoiceMode = (string) $application->config->get('e_invoice_mode', 'off');
        $invoiceLifecycle = (string) $application->config->get(
            'invoice_lifecycle_mode',
            MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
        );
        $lateFeeMode = (string) $application->config->get('late_fee_mode', 'blocked');
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
        if ($mode === 'invoice_only') {
            if (
                $invoiceLifecycle === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
                && !in_array($event, ['InvoiceCreated', 'InvoiceCreatedEmailPending'], true)
            ) {
                return false;
            }
            if (
                $invoiceLifecycle !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
                && $event !== 'InvoicePaid'
            ) {
                return false;
            }
        }
        if ($mode === 'voucher_only' && (($event === 'InvoicePaid') === !$onlyPaid)) {
            return false;
        }
        if ($mode === 'invoice_for_oss' && $onlyPaid && $event !== 'InvoicePaid') {
            return false;
        }

        if ($invoiceId < 1) {
            return false;
        }
        $directDeliveryRequested = in_array($event, ['InvoiceCreated', 'InvoiceCreatedEmailPending'], true)
            && $invoiceLifecycle === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
            && DirectDeliveryIntentContext::isRequested($invoiceId);

        $massPaymentContext = $event === 'InvoicePaid'
            ? sevdesk_mass_payment_hook_context($application, $invoiceId)
            : ['containerInvoiceId' => null, 'targetInvoiceIds' => []];
        $massPaymentTargets = $massPaymentContext['targetInvoiceIds'];
        $massPaymentContainerInvoiceId = $massPaymentContext['containerInvoiceId'];
        // A pure Mass Pay invoice is only a payment container. Queue its
        // original revenue invoices directly and leave the container entirely
        // on the WHMCS presentation and mail path.
        $invoiceIds = $massPaymentTargets !== [] ? $massPaymentTargets : [$invoiceId];
        $items = [];
        foreach ($invoiceIds as $queuedInvoiceId) {
            if ($application->mappings->findCompleteByInvoice($queuedInvoiceId) !== null) {
                continue;
            }
            $candidate = [
                'trigger' => $event,
                'requestedExportMode' => $mode,
                'requestedDocumentAuthority' => $documentAuthority,
                'requestedInvoiceLifecycleMode' => $invoiceLifecycle,
                'requestedLateFeeMode' => $lateFeeMode,
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
                'delivery_requested' => $documentAuthority === 'sevdesk'
                    && (
                        ($event === 'InvoicePaid'
                            && $invoiceLifecycle !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION)
                        || $directDeliveryRequested
                    ),
                'directCreationConfirmed' => $event === 'InvoiceCreated',
            ];
            if (
                $massPaymentContainerInvoiceId !== null
                && $queuedInvoiceId !== $massPaymentContainerInvoiceId
            ) {
                $candidate['massPaymentContainerInvoiceId'] = $massPaymentContainerInvoiceId;
            }
            $items[] = [
                'invoice_id' => $queuedInvoiceId,
                'action' => 'export_document',
                'dedupe_key' => 'export_voucher:' . $queuedInvoiceId,
                'candidate' => $candidate,
            ];
        }
        if ($items === []) {
            if ($directDeliveryRequested && $event === 'InvoiceCreated') {
                DirectDeliveryIntentContext::acknowledge($invoiceId);
            }

            return true;
        }

        $application->jobs->create('automatic_export', $items, [
            'trigger' => $event,
            'mass_payment_container' => $massPaymentTargets !== [] ? $invoiceId : null,
        ]);
        if ($directDeliveryRequested && $event === 'InvoiceCreated') {
            DirectDeliveryIntentContext::acknowledge($invoiceId);
        }

        return true;
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk could not enqueue ' . $event
                . ' for invoice ' . max(0, $invoiceId)
                . ': ' . get_class($error),
            );
        }

        return false;
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
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
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
        $pureMassPaymentContainer = false;
        // Request-local and idempotent: no job, remote call or PDF operation is
        // allowed before WHMCS has completed the payment-email phase. The
        // authority guard deliberately survives review, authentication and
        // sync pauses; those states must not silently restore a WHMCS final PDF.
        if ($currentModeOwnsNewInvoice) {
            InvoiceEmailGuardContext::register($invoiceId);
            $pureMassPaymentContainer = sevdesk_mass_payment_target_ids(
                $application,
                $invoiceId,
            ) !== [];
        }
        $mapping = $application->mappings->findByInvoice($invoiceId);
        if ($mapping !== null) {
            // Existing mappings keep their frozen/legacy document authority;
            // a later global mode change must not reclassify their email.
            // Voucher and untyped legacy mappings are WHMCS-owned regardless
            // of any job context, so a failed context read must not retain the
            // global guard. A typed Invoice stays protected until its frozen
            // context positively proves WHMCS authority.
            if (($mapping->document_type ?? null) === MappingRepository::DOCUMENT_TYPE_INVOICE) {
                InvoiceEmailGuardContext::register($invoiceId);
            } else {
                InvoiceEmailGuardContext::discard($invoiceId);

                return;
            }
            $documentContext = $application->jobs->latestDocumentContextForInvoice(
                $invoiceId,
                true,
            );
            if (DocumentDeliveryContext::usesSevdeskInvoiceAuthority($documentContext, $mapping)) {
                InvoiceEmailGuardContext::register($invoiceId);
            } elseif (DocumentDeliveryContext::usesWhmcsInvoiceAuthority($documentContext, $mapping)) {
                InvoiceEmailGuardContext::confirmWhmcsAuthority($invoiceId);
            }
        } elseif ($pureMassPaymentContainer) {
            InvoiceEmailGuardContext::discard($invoiceId);
        }
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk could not prepare the paid Invoice email guard for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
    }
}

/**
 * Blocks the initial WHMCS Invoice PDF before InvoiceCreated queues the direct
 * sevDesk document. The attempted email is remembered only in this request.
 *
 * @param array<string,mixed> $vars
 */
function sevdesk_prepare_created_invoice_email_guard(array $vars): void
{
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
        if ($invoiceId < 1) {
            return;
        }
        $application = Application::instance();
        if (
            !$application->config->bool('module_active')
            || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
            || (string) $application->config->get('export_mode', 'voucher_only') !== 'invoice_only'
            || (string) $application->config->get('document_authority', 'whmcs') !== 'sevdesk'
            || (string) $application->config->get(
                'invoice_lifecycle_mode',
                MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
            ) !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
        ) {
            return;
        }
        InvoiceEmailGuardContext::register($invoiceId);
        DirectDeliveryIntentContext::prepare($invoiceId);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk could not prepare the created Invoice email guard for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
    }
}

/** @param array<string, mixed> $vars */
function sevdesk_enqueue_review(array $vars, string $reason, bool $retainForIssuedDirectInvoice = false): void
{
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
        if (!Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return;
        }
        $application = Application::instance();
        $automaticEnabled = sevdesk_automatic_enqueue_enabled($application);
        $issuedDirectInvoice = false;
        if ($retainForIssuedDirectInvoice && $invoiceId > 0) {
            $mapping = $application->mappings->findCompleteByInvoiceAndType(
                $invoiceId,
                MappingRepository::DOCUMENT_TYPE_INVOICE,
            );
            $issuedDirectInvoice = $mapping !== null
                && trim((string) ($mapping->document_authority ?? ''))
                    === MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
                && trim((string) ($mapping->invoice_lifecycle_mode ?? ''))
                    === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION;
        }
        if (!$automaticEnabled && !$issuedDirectInvoice) {
            return;
        }
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
            logActivity(
                'sevdesk could not enqueue accounting review for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
    }
}

/** @param array<string,mixed> $vars */
function sevdesk_enqueue_invoice_reminder(array $vars): void
{
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
        if (
            $invoiceId < 1
            || !defined(Migrator::class . '::RELATED_DOCUMENTS_TABLE')
            || !Capsule::schema()->hasTable(Migrator::RELATED_DOCUMENTS_TABLE)
        ) {
            return;
        }
        $application = Application::instance();
        if (
            !sevdesk_automatic_enqueue_enabled($application)
            || (string) $application->config->get(
                'invoice_lifecycle_mode',
                MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
            ) !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
            || (string) $application->config->get('dunning_mode', 'off')
                !== 'whmcs_schedule_sevdesk_delivery'
            || !$application->config->bool('dunning_canary_confirmed')
        ) {
            return;
        }
        $level = sevdesk_dunning_level($vars);
        if ($level === null) {
            sevdesk_enqueue_review($vars, 'unknown_invoice_payment_reminder_level');

            return;
        }
        $mapping = $application->mappings->findCompleteByInvoiceAndType(
            $invoiceId,
            MappingRepository::DOCUMENT_TYPE_INVOICE,
        );
        if (
            $mapping === null
            || trim((string) ($mapping->document_authority ?? ''))
                !== MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
            || trim((string) ($mapping->invoice_lifecycle_mode ?? ''))
                !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
        ) {
            sevdesk_enqueue_review($vars, 'invoice_reminder_without_direct_mapping');

            return;
        }
        $fingerprint = $application->whmcs->localDunningFingerprint($invoiceId);
        $primaryFingerprint = $application->jobs->issuedPrimaryContractFingerprint($invoiceId);
        if ($primaryFingerprint === null) {
            sevdesk_enqueue_review($vars, 'issued_primary_contract_missing');

            return;
        }
        $application->jobs->create('invoice_dunning', [[
            'invoice_id' => $invoiceId,
            'action' => 'create_invoice_reminder',
            'dedupe_key' => 'invoice_reminder:' . $invoiceId . ':' . $level . ':' . $fingerprint,
            'candidate' => [
                'dunningLevel' => $level,
                'reminderDeadline' => $application->whmcs->reminderDeadline($invoiceId, $level),
                'localDunningFingerprint' => $fingerprint,
                'primaryInvoiceContractFingerprint' => $primaryFingerprint,
                'delivery_requested' => true,
            ],
        ]], [
            'trigger' => 'InvoicePaymentReminder',
            'dunning_level' => $level,
        ]);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk could not enqueue InvoicePaymentReminder for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
    }
}

/**
 * Direct-mode invoices already own their primary sevDesk mapping when payment
 * arrives. A separated LateFee therefore needs its own paid-only job instead
 * of another primary export.
 *
 * @param array<string,mixed> $vars
 */
function sevdesk_enqueue_paid_late_fee(array $vars): void
{
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
        if (
            $invoiceId < 1
            || !defined(Migrator::class . '::RELATED_DOCUMENTS_TABLE')
            || !Capsule::schema()->hasTable(Migrator::RELATED_DOCUMENTS_TABLE)
        ) {
            return;
        }
        $application = Application::instance();
        if (
            !sevdesk_automatic_enqueue_enabled($application)
            || (string) $application->config->get('export_mode', 'voucher_only') !== 'invoice_only'
            || (string) $application->config->get(
                'invoice_lifecycle_mode',
                MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
            ) !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
            || (string) $application->config->get('late_fee_mode', 'blocked')
                !== 'reminder_then_rule22'
        ) {
            return;
        }
        $contract = $application->whmcs->invoiceExportContract($invoiceId);
        $lateFee = is_array($contract['lateFee'] ?? null) ? $contract['lateFee'] : null;
        $fingerprint = strtolower(trim((string) ($lateFee['fingerprint'] ?? '')));
        $amountMinor = $lateFee['amountMinor'] ?? null;
        if (
            $lateFee === null
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
            || !is_int($amountMinor)
            || $amountMinor <= 0
        ) {
            return;
        }
        $voucherDate = $application->whmcs->invoiceDatePaid($invoiceId);
        if ($voucherDate === null) {
            sevdesk_enqueue_review($vars, 'late_fee_payment_date_missing');

            return;
        }
        $primaryFingerprint = $application->jobs->issuedPrimaryContractFingerprint($invoiceId);
        if ($primaryFingerprint === null) {
            sevdesk_enqueue_review($vars, 'issued_primary_contract_missing');

            return;
        }
        $application->jobs->create('late_fee_accounting', [[
            'invoice_id' => $invoiceId,
            'action' => 'export_late_fee_voucher',
            'dedupe_key' => 'late_fee_voucher:' . $invoiceId . ':' . $fingerprint,
            'candidate' => [
                'whmcsInvoiceContractFingerprint' => (string) $contract['fingerprint'],
                'primaryInvoiceContractFingerprint' => $primaryFingerprint,
                'lateFeeFingerprint' => $fingerprint,
                'lateFeeAmountMinor' => $amountMinor,
                'lateFeeVoucherDate' => $voucherDate,
                'localDunningFingerprint' => $application->whmcs->localDunningFingerprint($invoiceId),
                'historicalBackfill' => false,
                'delivery_requested' => false,
            ],
        ]], [
            'trigger' => 'InvoicePaid',
            'mail_free' => true,
        ]);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk could not enqueue paid LateFee for invoice '
                    . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
    }
}

/** @param array<string,mixed> $vars */
function sevdesk_dunning_level(array $vars): ?int
{
    $raw = strtolower(trim((string) (
        $vars['type']
        ?? $vars['remindertype']
        ?? $vars['level']
        ?? ''
    )));
    if (preg_match('/^[1-3]$/', $raw) === 1) {
        return (int) $raw;
    }
    foreach (
        [
        1 => ['first', '1st', 'firstoverdue', 'first overdue'],
        2 => ['second', '2nd', 'secondoverdue', 'second overdue'],
        3 => ['third', '3rd', 'thirdoverdue', 'third overdue'],
        ] as $level => $markers
    ) {
        foreach ($markers as $marker) {
            if (str_contains($raw, $marker)) {
                return $level;
            }
        }
    }

    return null;
}

/** @param array<string,mixed> $vars */
function sevdesk_handle_invoice_cancellation(array $vars): void
{
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
        if ($invoiceId < 1 || !Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return;
        }
        $application = Application::instance();
        $mapping = $application->mappings->findByInvoice($invoiceId);
        if ($mapping === null) {
            $application->jobs->cancelUnstartedPrimaryExport($invoiceId);

            return;
        }
        if (
            trim((string) ($mapping->document_type ?? ''))
                !== MappingRepository::DOCUMENT_TYPE_INVOICE
            || trim((string) ($mapping->document_authority ?? ''))
                !== MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
            || trim((string) ($mapping->invoice_lifecycle_mode ?? ''))
                !== MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
        ) {
            sevdesk_enqueue_review($vars, 'invoice_cancelled');

            return;
        }
        if (trim((string) ($mapping->document_ready_at ?? '')) === '') {
            sevdesk_enqueue_review($vars, 'unpublished_sevdesk_draft_cleanup_required');

            return;
        }
        if (
            !sevdesk_automatic_enqueue_enabled($application)
            || !$application->config->bool('cancellation_canary_confirmed')
        ) {
            sevdesk_enqueue_review($vars, 'invoice_cancellation_canary_missing', true);

            return;
        }
        $fingerprint = $application->whmcs->localDunningFingerprint($invoiceId);
        $primaryFingerprint = $application->jobs->issuedPrimaryContractFingerprint($invoiceId);
        if ($primaryFingerprint === null) {
            sevdesk_enqueue_review($vars, 'issued_primary_contract_missing');

            return;
        }
        $application->jobs->create('invoice_cancellation', [[
            'invoice_id' => $invoiceId,
            'action' => 'cancel_invoice',
            'dedupe_key' => 'cancel_invoice:' . $invoiceId . ':' . $fingerprint,
            'candidate' => [
                'localDunningFingerprint' => $fingerprint,
                'primaryInvoiceContractFingerprint' => $primaryFingerprint,
                'delivery_requested' => $mapping->delivered_at !== null,
            ],
        ]], ['trigger' => 'InvoiceCancelled']);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk could not handle InvoiceCancelled for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
    }
}

/** @param array<string,mixed> $vars */
function sevdesk_freeze_related_document_actions(array $vars, string $reason): void
{
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
        if ($invoiceId < 1 || !Capsule::schema()->hasTable(Migrator::ITEMS_TABLE)) {
            return;
        }
        Application::instance()->jobs->freezeRelatedDocumentActions($invoiceId, $reason);
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk could not freeze related document actions for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
    }
}

/** @param array<string, mixed> $vars */
function sevdesk_enqueue_transaction_review(array $vars): void
{
    $transactionId = (int) ($vars['id'] ?? 0);
    $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invocieid'] ?? 0);
    try {
        if (!Capsule::schema()->hasTable(Migrator::JOBS_TABLE)) {
            return;
        }
        $application = Application::instance();
        if (!sevdesk_automatic_enqueue_enabled($application)) {
            return;
        }
        $amountOut = (float) ($vars['amountout'] ?? 0);
        $refundId = (int) ($vars['refundid'] ?? 0);
        if ($transactionId < 1 || ($amountOut <= 0 && $refundId < 1)) {
            return;
        }
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
            logActivity(
                'sevdesk could not enqueue transaction review for transaction '
                . max(0, $transactionId) . ' and invoice ' . max(0, $invoiceId)
                . ': ' . get_class($error),
            );
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
    $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invoiceId'] ?? $_GET['id'] ?? 0);
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

        $invoiceNumber = WhmcsGateway::effectiveInvoiceNumber(
            $invoiceId,
            (string) ($invoice->invoicenum ?? ''),
        );
        $webRoot = rtrim((string) ($vars['WEB_ROOT'] ?? ''), '/');
        $downloadUrl = ($webRoot === '' ? '' : $webRoot . '/')
            . 'index.php?m=sevdesk&a=download&id=' . rawurlencode((string) $invoiceId);
        $relatedDocuments = [];
        if (
            defined(Migrator::class . '::RELATED_DOCUMENTS_TABLE')
            && Capsule::schema()->hasTable(Migrator::RELATED_DOCUMENTS_TABLE)
        ) {
            foreach ($application->relatedDocuments->forInvoice($invoiceId) as $related) {
                $role = trim((string) ($related->role ?? ''));
                if (
                    !in_array($role, ['reminder', 'cancellation', 'late_fee_voucher'], true)
                    || (
                        $role !== 'late_fee_voucher'
                        && trim((string) ($related->document_ready_at ?? '')) === ''
                    )
                ) {
                    continue;
                }
                $level = (int) ($related->dunning_level ?? 0);
                $download = '';
                if ($role !== 'late_fee_voucher') {
                    $download = ($webRoot === '' ? '' : $webRoot . '/')
                        . 'index.php?m=sevdesk&a=downloadRelated&id='
                        . rawurlencode((string) $invoiceId)
                        . '&role=' . rawurlencode($role)
                        . '&level=' . rawurlencode((string) $level);
                }
                $relatedDocuments[] = [
                    'role' => $role,
                    'dunningLevel' => $level,
                    'documentNumber' => trim((string) ($related->document_number ?? '')),
                    'delivered' => trim((string) ($related->delivered_at ?? '')) !== '',
                    'downloadUrl' => $download,
                ];
            }
        }

        return [
            'sevdeskDocument' => ClientDocumentPresenter::present(
                (string) ($invoice->status ?? ''),
                $invoiceNumber,
                $mapping,
                $documentContext['itemStatus'] ?? null,
                $downloadUrl,
                trim((string) (
                    $mapping->invoice_lifecycle_mode
                    ?? $documentContext['invoiceLifecycleMode']
                    ?? MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA
                )),
            ),
            'sevdeskRelatedDocuments' => $relatedDocuments,
        ];
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk client invoice adapter failed safely for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
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
        $hasConfirmedWhmcsAuthority = InvoiceEmailGuardContext::hasConfirmedWhmcsAuthority($invoiceId);
        if ($hasActiveAttachmentContext || $hasPaidInvoiceGuard) {
            $guardApplies = true;
        }

        $application = Application::instance();
        if (!$application->config->bool('module_active')) {
            return $guardApplies ? ['abortsend' => true] : [];
        }

        $isInvoiceTemplate = Capsule::table('tblemailtemplates')
            ->whereRaw('LOWER(type) = ?', ['invoice'])
            ->where('name', $template)
            ->exists();
        if (!$isInvoiceTemplate) {
            return $hasActiveAttachmentContext ? ['abortsend' => true] : [];
        }
        $directConfiguration = (string) $application->config->get(
            'export_mode',
            'voucher_only',
        ) === 'invoice_only'
            && (string) $application->config->get('document_authority', 'whmcs') === 'sevdesk'
            && (string) $application->config->get(
                'invoice_lifecycle_mode',
                MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
            ) === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION;
        $preparedCreationMail = DirectDeliveryIntentContext::isPrepared($invoiceId);
        $directNewInvoice = $directConfiguration
            && ($preparedCreationMail || strcasecmp($template, 'Invoice Created') === 0);
        if ($directNewInvoice) {
            // InvoiceCreated is documented to run after the initial email. For
            // autogen there is no guaranteed InvoiceCreationPreEmail hook.
            // Persist the delivery intent before suppressing the Core mail;
            // InvoiceCreated later hits the same dedupe key and cannot create a
            // second accounting action. This performs local database work only.
            InvoiceEmailGuardContext::register($invoiceId);
            if ($preparedCreationMail) {
                DirectDeliveryIntentContext::confirm($invoiceId);
            } else {
                DirectDeliveryIntentContext::request($invoiceId);
            }
            sevdesk_enqueue_invoice(['invoiceid' => $invoiceId], 'InvoiceCreatedEmailPending');
            $hasPaidInvoiceGuard = true;
            $guardApplies = true;
        }
        if (
            (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
                !== Config::RUNTIME_SIGNATURE
        ) {
            return $guardApplies ? ['abortsend' => true] : [];
        }
        $mapping = $application->mappings->findByInvoice($invoiceId);
        if ($mapping === null && $hasPaidInvoiceGuard) {
            if (!$directNewInvoice) {
                DirectDeliveryIntentContext::confirm($invoiceId);
            }
        }
        $typedInvoiceMapping = $mapping !== null
            && ($mapping->document_type ?? null) === MappingRepository::DOCUMENT_TYPE_INVOICE;
        if ($typedInvoiceMapping && !$hasConfirmedWhmcsAuthority) {
            // Set this before the context query. If that local read fails, an
            // Invoice with unknown authority must remain blocked.
            $guardApplies = true;
        }
        $documentContext = $application->jobs->latestDocumentContextForInvoice(
            $invoiceId,
            $mapping !== null,
        );
        if (DocumentDeliveryContext::usesWhmcsInvoiceAuthority($documentContext, $mapping)) {
            InvoiceEmailGuardContext::confirmWhmcsAuthority($invoiceId);

            return $hasActiveAttachmentContext ? ['abortsend' => true] : [];
        }
        if ($typedInvoiceMapping) {
            $guardApplies = true;
        }
        if (
            !$typedInvoiceMapping
            && !DocumentDeliveryContext::usesSevdeskInvoiceAuthority($documentContext, $mapping)
        ) {
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
    try {
        if (($_GET['module'] ?? null) !== 'sevdesk') {
            return '';
        }

        return AdminAssets::stylesheetMarkup();
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk admin stylesheet hook failed safely: ' . get_class($error));
        }

        return '';
    }
});

add_hook('AdminAreaFooterOutput', 1, static function (): string {
    try {
        $output = AdminInvoiceControls::footerForms();
        if (($_GET['module'] ?? null) === 'sevdesk') {
            $output = AdminAssets::scriptMarkup() . $output;
        }

        return $output;
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity('sevdesk admin footer hook failed safely: ' . get_class($error));
        }

        return '';
    }
});

add_hook('InvoiceCreationPreEmail', 1, static fn (array $vars) => sevdesk_prepare_created_invoice_email_guard($vars));
add_hook('InvoiceCreated', 1, static fn (array $vars) => sevdesk_enqueue_invoice($vars, 'InvoiceCreated'));
add_hook('InvoicePaidPreEmail', 1, static fn (array $vars) => sevdesk_prepare_paid_invoice_email_guard($vars));
add_hook('InvoicePaid', 1, static function (array $vars): void {
    sevdesk_enqueue_invoice($vars, 'InvoicePaid');
    sevdesk_enqueue_paid_late_fee($vars);
});
add_hook('InvoicePaymentReminder', 1, static fn (array $vars) => sevdesk_enqueue_invoice_reminder($vars));
add_hook('AddInvoiceLateFee', 1, static fn (array $vars) =>
    sevdesk_freeze_related_document_actions($vars, 'late_fee_contract_changed'));
add_hook('UpdateInvoiceTotal', 1, static function (array $vars): void {
    sevdesk_freeze_related_document_actions($vars, 'invoice_total_changed');
    sevdesk_enqueue_review($vars, 'issued_invoice_total_changed', true);
});
add_hook('InvoiceRefunded', 1, static fn (array $vars) =>
    sevdesk_enqueue_review($vars, 'invoice_refunded', true));
add_hook('InvoiceCancelled', 1, static fn (array $vars) => sevdesk_handle_invoice_cancellation($vars));
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
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    try {
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
    } catch (Throwable $error) {
        if (function_exists('logActivity')) {
            logActivity(
                'sevdesk admin invoice controls failed safely for invoice '
                . max(0, $invoiceId) . ': ' . get_class($error),
            );
        }
        return '';
    }
});
