<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

/**
 * Request-local bridge from InvoicePaidPreEmail to EmailPreSend.
 *
 * The payment hook runs before WHMCS builds its first paid-invoice email. No
 * database job is created here, so InvoicePaid remains the delivery trigger.
 */
final class InvoiceEmailGuardContext
{
    /** @var array<int, true> */
    private static array $invoiceIds = [];

    /** Returns false when the same invoice was already registered. */
    public static function register(int $invoiceId): bool
    {
        if ($invoiceId < 1) {
            throw new \InvalidArgumentException('A valid WHMCS Invoice ID is required.');
        }

        if (isset(self::$invoiceIds[$invoiceId])) {
            return false;
        }

        self::$invoiceIds[$invoiceId] = true;

        return true;
    }

    public static function appliesTo(int $invoiceId): bool
    {
        return $invoiceId > 0 && isset(self::$invoiceIds[$invoiceId]);
    }

    public static function discard(int $invoiceId): void
    {
        unset(self::$invoiceIds[$invoiceId]);
    }
}
