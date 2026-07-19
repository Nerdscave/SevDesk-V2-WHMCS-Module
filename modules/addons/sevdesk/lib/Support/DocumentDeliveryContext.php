<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;

/** Decides whether one local invoice belongs to the sevdesk delivery surface. */
final class DocumentDeliveryContext
{
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
        if (
            $context === null
            || $context['documentAuthority'] !== 'sevdesk'
            || $context['exportMode'] !== 'invoice_only'
        ) {
            return false;
        }

        if ($mapping !== null) {
            return ($mapping->document_type ?? null) === MappingRepository::DOCUMENT_TYPE_INVOICE
                && $context['source'] === 'frozen'
                && $context['allowed'] === true
                && $context['documentType'] === 'invoice';
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
}
