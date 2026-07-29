<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

/**
 * Document-independent item-type exclusions that must run before tax
 * classification or any remote accounting write.
 */
final class InvoiceItemExportPolicy
{
    public const LATE_FEE_REQUIRES_REVIEW = 'late_fee_tax_treatment_requires_review';

    public const LATE_FEE_MESSAGE = 'WHMCS-Positionen vom Typ LateFee werden nicht automatisch exportiert. '
        . 'Mahn- und Verzugsgebühren benötigen einen eigenen steuerlichen Grund und dürfen nicht still '
        . 'als normaler Leistungsumsatz behandelt werden.';

    public const LATE_FEE_MODE_BLOCKED = 'blocked';
    public const LATE_FEE_MODE_REMINDER_THEN_RULE22 = 'reminder_then_rule22';

    /** @param iterable<string> $itemTypes */
    public static function containsLateFee(iterable $itemTypes): bool
    {
        foreach ($itemTypes as $itemType) {
            if (strtolower(trim($itemType)) === 'latefee') {
                return true;
            }
        }

        return false;
    }
}
