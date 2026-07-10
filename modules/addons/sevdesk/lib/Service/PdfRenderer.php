<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use RuntimeException;

/** Generates and validates the original WHMCS invoice PDF in memory. */
final class PdfRenderer
{
    /** @var null|Closure(int): string */
    private readonly ?Closure $renderer;

    /** @param null|callable(int): string $renderer */
    public function __construct(?callable $renderer = null)
    {
        $this->renderer = $renderer === null ? null : Closure::fromCallable($renderer);
    }

    public function render(int $invoiceId): string
    {
        if ($invoiceId < 1) {
            throw new \InvalidArgumentException('Invalid WHMCS invoice ID.');
        }

        if ($this->renderer !== null) {
            $contents = ($this->renderer)($invoiceId);
        } else {
            if (!defined('ROOTDIR')) {
                throw new RuntimeException('WHMCS ROOTDIR is unavailable.');
            }

            $invoiceFunctions = ROOTDIR . '/includes/invoicefunctions.php';
            if (!is_file($invoiceFunctions)) {
                throw new RuntimeException('WHMCS invoice PDF functions could not be loaded.');
            }

            require_once $invoiceFunctions;
            if (!function_exists('pdfInvoice')) {
                throw new RuntimeException('WHMCS pdfInvoice() is unavailable.');
            }

            $contents = pdfInvoice($invoiceId);
        }

        if (!is_string($contents) || strlen($contents) < 16 || !str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('WHMCS returned an empty or invalid invoice PDF.');
        }

        return $contents;
    }
}
