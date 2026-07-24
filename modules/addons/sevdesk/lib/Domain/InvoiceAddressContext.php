<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/**
 * Runtime-only WHMCS billing address for one sevdesk Invoice.
 *
 * Only frozenContext() may enter a persisted job. The recipient fields remain
 * in memory solely for the Invoice payload and exact read-only verification.
 */
final class InvoiceAddressContext
{
    private const HASH_PREFIX = "sevdesk-invoice-address-v1\0";

    private function __construct(
        public readonly string $countryId,
        public readonly string $expectedAddressHash,
        private readonly string $recipientName,
        private readonly string $street,
        private readonly string $zip,
        private readonly string $city,
        private readonly string $countryCode,
    ) {
    }

    public static function fromContact(ContactData $contact, string $countryId): self
    {
        $countryId = self::requiredId($countryId);
        $recipientName = self::requiredText($contact->displayName(), 255, 'Recipient name');
        $street = self::requiredStreet($contact->street, $contact->addressLine2);
        $zip = self::requiredText($contact->postcode, 20, 'Postal code');
        $city = self::requiredText($contact->city, 100, 'City');
        $countryCode = strtoupper(trim($contact->countryCode));
        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new \InvalidArgumentException('Invoice address needs a two-letter country code.');
        }

        return new self(
            $countryId,
            self::addressHash($recipientName, $street, $zip, $city, $countryCode),
            $recipientName,
            $street,
            $zip,
            $city,
            $countryCode,
        );
    }

    public static function addressHash(
        string $recipientName,
        string $street,
        string $zip,
        string $city,
        string $countryCode,
    ): string {
        $canonical = implode("\0", [
            self::normaliseText($recipientName),
            self::normaliseText($street),
            self::normaliseText($zip),
            self::normaliseText($city),
            strtoupper(trim($countryCode)),
        ]);

        return hash('sha256', self::HASH_PREFIX . $canonical);
    }

    /** @return array<string, string|array<string, int|string>> */
    public function invoicePayloadFields(): array
    {
        return [
            'address' => implode("\n", [
                $this->recipientName,
                $this->street,
                $this->zip . ' ' . $this->city,
                $this->countryCode,
            ]),
            'addressName' => $this->recipientName,
            'addressStreet' => $this->street,
            'addressZip' => $this->zip,
            'addressCity' => $this->city,
            'addressCountry' => [
                'id' => self::payloadId($this->countryId),
                'objectName' => 'StaticCountry',
            ],
        ];
    }

    /**
     * @return array{
     *     invoiceAddressCountryId:string,
     *     invoiceAddressHash:string
     * }
     */
    public function frozenContext(): array
    {
        return [
            'invoiceAddressCountryId' => $this->countryId,
            'invoiceAddressHash' => $this->expectedAddressHash,
        ];
    }

    /** @param array<array-key, mixed> $remote */
    public function remoteMismatch(array $remote): ?string
    {
        $remoteName = self::normaliseText((string) ($remote['addressName'] ?? ''));
        $remoteStreet = self::normaliseText((string) ($remote['addressStreet'] ?? ''));
        $remoteZip = self::normaliseText((string) ($remote['addressZip'] ?? ''));
        $remoteCity = self::normaliseText((string) ($remote['addressCity'] ?? ''));
        if ($remoteName === '' || $remoteStreet === '' || $remoteZip === '' || $remoteCity === '') {
            return 'invoice_address_missing';
        }

        $remoteCountry = $remote['addressCountry'] ?? null;
        if (is_array($remoteCountry)) {
            if ((string) ($remoteCountry['id'] ?? '') !== $this->countryId) {
                return 'invoice_address_country_mismatch';
            }
            $reportedCode = strtoupper(trim((string) (
                $remoteCountry['code']
                ?? $remoteCountry['countryCode']
                ?? $remoteCountry['shortCode']
                ?? ''
            )));
            if ($reportedCode !== '' && $reportedCode !== $this->countryCode) {
                return 'invoice_address_country_mismatch';
            }
        } elseif (strtoupper(trim((string) $remoteCountry)) !== $this->countryCode) {
            return 'invoice_address_country_mismatch';
        }

        $remoteHash = self::addressHash(
            $remoteName,
            $remoteStreet,
            $remoteZip,
            $remoteCity,
            $this->countryCode,
        );

        return hash_equals($this->expectedAddressHash, $remoteHash)
            ? null
            : 'invoice_address_hash_mismatch';
    }

    private static function requiredId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
            throw new \InvalidArgumentException('Invoice address needs a valid StaticCountry ID.');
        }

        return $id;
    }

    private static function requiredText(string $value, int $maxLength, string $label): string
    {
        $value = self::normaliseText($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($label . ' is missing or exceeds the supported length.');
        }

        return $value;
    }

    private static function requiredStreet(string $street, string $addressLine2): string
    {
        $lines = array_values(array_filter(
            [self::normaliseText($street), self::normaliseText($addressLine2)],
            static fn (string $line): bool => $line !== '',
        ));
        $value = implode("\n", $lines);
        if ($value === '' || mb_strlen($value) > 255) {
            throw new \InvalidArgumentException('Street is missing or exceeds the supported length.');
        }

        return $value;
    }

    private static function normaliseText(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r", "\n"], ' ', $value));

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function payloadId(string $id): int|string
    {
        return strlen($id) < 19 ? (int) $id : $id;
    }
}
