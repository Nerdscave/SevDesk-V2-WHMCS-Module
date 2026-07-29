<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;

/** Builds the small, theme-independent client-area adapter contract. */
final class ClientDocumentPresenter
{
    /** @var list<string> */
    private const FAILURE_STATUSES = [
        'succeeded',
        'skipped',
        'permanent_failed',
        'ambiguous',
        'cancelled',
    ];

    /**
     * @return array{authority:string,state:string,invoiceNumber:string,downloadUrl:string}
     */
    public static function present(
        string $invoiceStatus,
        string $fallbackNumber,
        ?object $mapping,
        ?string $latestJobStatus,
        string $downloadUrl,
        string $invoiceLifecycleMode = MappingRepository::LIFECYCLE_AFTER_PAYMENT_PROFORMA,
    ): array {
        $invoiceNumber = trim((string) ($mapping->document_number ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = trim($fallbackNumber);
        }

        $ready = self::isDeliverableInvoiceMapping($invoiceStatus, $mapping);
        $state = $ready ? 'ready' : 'proforma';
        if (
            !$ready
            && (
                strcasecmp(trim($invoiceStatus), 'Paid') === 0
                || (
                    strcasecmp(trim($invoiceStatus), 'Unpaid') === 0
                    && $invoiceLifecycleMode === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION
                )
            )
        ) {
            $state = match (true) {
                in_array(trim((string) $latestJobStatus), self::FAILURE_STATUSES, true) => 'failure',
                default => 'pending',
            };
        }

        return [
            'authority' => 'sevdesk',
            'state' => $state,
            'invoiceNumber' => $invoiceNumber,
            'downloadUrl' => $ready ? $downloadUrl : '',
        ];
    }

    public static function isReadyInvoiceMapping(?object $mapping): bool
    {
        if ($mapping === null) {
            return false;
        }

        return ($mapping->document_type ?? null) === MappingRepository::DOCUMENT_TYPE_INVOICE
            && preg_match('/^[1-9]\d*$/', trim((string) ($mapping->sevdesk_id ?? ''))) === 1
            && trim((string) ($mapping->document_ready_at ?? '')) !== ''
            && preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string) ($mapping->pdf_sha256 ?? '')))) === 1;
    }

    public static function isDeliverableInvoiceMapping(string $invoiceStatus, ?object $mapping): bool
    {
        if (!self::isReadyInvoiceMapping($mapping)) {
            return false;
        }
        $status = strtolower(trim($invoiceStatus));
        if (in_array($status, ['paid', 'refunded'], true)) {
            return true;
        }

        return in_array($status, ['unpaid', 'cancelled'], true)
            && trim((string) ($mapping->document_authority ?? ''))
                === MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
            && trim((string) ($mapping->invoice_lifecycle_mode ?? ''))
                === MappingRepository::LIFECYCLE_ISSUE_ON_CREATION;
    }
}
