<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;

/** Decides whether one local invoice belongs to the sevdesk delivery surface. */
final class DocumentDeliveryContext
{
    /**
     * @param array<string, mixed>|null $context
     * @return null|array{documentType:string,documentAuthority:string}
     */
    public static function frozenConfirmedDocument(?array $context): ?array
    {
        if (
            $context === null
            || ($context['source'] ?? null) !== 'frozen'
            || ($context['allowed'] ?? null) !== true
        ) {
            return null;
        }

        $documentType = trim((string) ($context['documentType'] ?? ''));
        $documentAuthority = trim((string) ($context['documentAuthority'] ?? ''));
        if (
            !in_array($documentType, ['voucher', 'invoice'], true)
            || !in_array($documentAuthority, ['whmcs', 'sevdesk'], true)
            || ($documentType === 'voucher' && $documentAuthority !== 'whmcs')
        ) {
            return null;
        }

        return compact('documentType', 'documentAuthority');
    }

    /**
     * @param null|array{
     *     itemId:int,
     *     itemStatus:string,
     *     checkpoint:string,
     *     source:'frozen'|'requested',
     *     allowed:?bool,
     *     documentType:?string,
     *     documentAuthority:string,
     *     exportMode:string,
     *     ossProfile:string,
     *     euB2cMode:string,
     *     deliveryChannel:?string
     * } $context
     */
    public static function usesSevdeskInvoiceAuthority(?array $context, ?object $mapping): bool
    {
        if ($mapping !== null) {
            return self::mappedInvoiceUsesAuthority($mapping, $context, 'sevdesk');
        }
        if (
            $context === null
            || $context['documentAuthority'] !== 'sevdesk'
            || $context['exportMode'] !== 'invoice_only'
        ) {
            return false;
        }

        if ($context['source'] === 'requested') {
            return $context['documentType'] === 'invoice';
        }

        if (in_array($context['itemStatus'], ['succeeded', 'skipped'], true)) {
            // A completed Invoice export owns the delivery surface only through
            // its still-present typed mapping. This also makes an explicit admin
            // unlink fall back to WHMCS instead of showing a permanent pending state.
            return false;
        }

        return ($context['allowed'] === true && $context['documentType'] === 'invoice')
            || ($context['allowed'] === false && $context['documentType'] === null);
    }

    /** @param array<string, mixed>|null $context */
    public static function usesWhmcsInvoiceAuthority(?array $context, ?object $mapping): bool
    {
        return $mapping !== null
            && self::mappedInvoiceUsesAuthority($mapping, $context, 'whmcs');
    }

    /**
     * The mapping is authoritative once the additive column is populated.
     * A frozen job context remains a compatibility fallback for Invoices that
     * were created by an earlier RC. Conflicting durable and frozen evidence
     * is never resolved by preference; both delivery surfaces remain blocked.
     *
     * @param array<string, mixed>|null $context
     */
    private static function mappedInvoiceUsesAuthority(
        object $mapping,
        ?array $context,
        string $requestedAuthority,
    ): bool {
        if (($mapping->document_type ?? null) !== MappingRepository::DOCUMENT_TYPE_INVOICE) {
            return false;
        }

        $storedAuthority = trim((string) ($mapping->document_authority ?? ''));
        if (!in_array($storedAuthority, ['whmcs', 'sevdesk'], true)) {
            $storedAuthority = '';
        }
        $frozenAuthority = self::frozenInvoiceAuthority($context);
        if (
            $storedAuthority !== ''
            && $frozenAuthority !== null
            && $storedAuthority !== $frozenAuthority
        ) {
            return false;
        }

        $effectiveAuthority = $storedAuthority !== '' ? $storedAuthority : $frozenAuthority;

        return $effectiveAuthority === $requestedAuthority;
    }

    /** @param array<string, mixed>|null $context */
    private static function frozenInvoiceAuthority(?array $context): ?string
    {
        $document = self::frozenConfirmedDocument($context);
        if (($document['documentType'] ?? null) !== MappingRepository::DOCUMENT_TYPE_INVOICE) {
            return null;
        }

        return $document['documentAuthority'];
    }
}
