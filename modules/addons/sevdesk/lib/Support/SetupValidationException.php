<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use RuntimeException;

final class SetupValidationException extends RuntimeException
{
    /** @param array<string, string> $fieldErrors Field IDs and locally authored messages. */
    public function __construct(public readonly array $fieldErrors)
    {
        parent::__construct(implode(' ', $fieldErrors));
    }
}
