<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/** Normalised WHMCS client data needed when resolving a sevdesk contact. */
final class ContactData
{
    public readonly ?string $sevdeskContactId;

    public readonly string $countryCode;

    public function __construct(
        public readonly int $whmcsClientId,
        ?string $sevdeskContactId,
        #[\SensitiveParameter]
        public readonly string $companyName,
        #[\SensitiveParameter]
        public readonly string $firstName,
        #[\SensitiveParameter]
        public readonly string $lastName,
        #[\SensitiveParameter]
        public readonly string $email,
        #[\SensitiveParameter]
        public readonly string $street,
        #[\SensitiveParameter]
        public readonly string $addressLine2,
        #[\SensitiveParameter]
        public readonly string $postcode,
        #[\SensitiveParameter]
        public readonly string $city,
        string $countryCode,
        #[\SensitiveParameter]
        public readonly ?string $vatNumber,
        public readonly bool $taxExempt,
    ) {
        if ($whmcsClientId < 1) {
            throw new \InvalidArgumentException('WHMCS client ID must be positive.');
        }

        $sevdeskContactId = trim((string) $sevdeskContactId);
        if ($sevdeskContactId !== '' && preg_match('/^\d+$/', $sevdeskContactId) !== 1) {
            throw new \InvalidArgumentException('The configured sevdesk contact ID must be numeric.');
        }
        $this->sevdeskContactId = $sevdeskContactId !== '' ? $sevdeskContactId : null;

        $countryCode = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new \InvalidArgumentException('Country must be a two-letter code.');
        }
        $this->countryCode = $countryCode;

        if (trim($companyName) === '' && trim($firstName . $lastName) === '') {
            throw new \InvalidArgumentException('A company or person name is required.');
        }
    }

    public function isOrganisation(): bool
    {
        return trim($this->companyName) !== '';
    }

    public function displayName(): string
    {
        if ($this->isOrganisation()) {
            return trim($this->companyName);
        }

        return trim($this->firstName . ' ' . $this->lastName);
    }
}
