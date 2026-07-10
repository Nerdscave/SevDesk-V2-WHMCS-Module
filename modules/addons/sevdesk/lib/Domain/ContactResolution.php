<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

final class ContactResolution
{
    /** @param list<string> $warnings */
    public function __construct(
        public readonly string $contactId,
        public readonly string $source,
        public readonly array $warnings = [],
    ) {
        if ($contactId === '') {
            throw new \InvalidArgumentException('A resolved contact ID is required.');
        }
    }
}
