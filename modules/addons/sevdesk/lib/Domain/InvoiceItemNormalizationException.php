<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/** Carries a safe, operator-facing result code from local WHMCS item validation. */
final class InvoiceItemNormalizationException extends \RuntimeException
{
    public function __construct(
        public readonly string $resultCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
