<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

/** Request-local proof that WHMCS attempted the initial Invoice email. */
final class DirectDeliveryIntentContext
{
    /** @var array<int,true> */
    private static array $prepared = [];

    /** @var array<int,true> */
    private static array $requested = [];

    public static function prepare(int $invoiceId): void
    {
        if ($invoiceId < 1) {
            throw new \InvalidArgumentException('A valid WHMCS invoice ID is required.');
        }
        self::$prepared[$invoiceId] = true;
    }

    public static function confirm(int $invoiceId): void
    {
        if (!isset(self::$prepared[$invoiceId])) {
            return;
        }
        unset(self::$prepared[$invoiceId]);
        self::$requested[$invoiceId] = true;
    }

    public static function isPrepared(int $invoiceId): bool
    {
        return isset(self::$prepared[$invoiceId]);
    }

    public static function isRequested(int $invoiceId): bool
    {
        return isset(self::$requested[$invoiceId]);
    }

    /**
     * EmailPreSend itself is the proof for cron/autogen invoices because
     * WHMCS documents InvoiceCreationPreEmail as an admin-area hook.
     */
    public static function request(int $invoiceId): void
    {
        if ($invoiceId < 1) {
            throw new \InvalidArgumentException('A valid WHMCS invoice ID is required.');
        }
        unset(self::$prepared[$invoiceId]);
        self::$requested[$invoiceId] = true;
    }

    public static function acknowledge(int $invoiceId): void
    {
        unset(self::$requested[$invoiceId], self::$prepared[$invoiceId]);
    }
}
