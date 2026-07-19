<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/**
 * Runtime-only data for one explicitly selected ZUGFeRD Invoice.
 *
 * Only the IDs and hashes returned by frozenContext() may enter a persisted job.
 * The recipient fields are kept here solely long enough to build and verify the
 * sevdesk request.
 */
final class EInvoiceContext
{
    private const HASH_PREFIX = "sevdesk-e-invoice-address-v1\0";

    private function __construct(
        public readonly string $contactId,
        public readonly string $paymentMethodId,
        public readonly string $unityId,
        public readonly string $countryId,
        public readonly string $expectedAddressHash,
        private readonly string $recipientName,
        private readonly string $street,
        private readonly string $zip,
        private readonly string $city,
        private readonly string $countryCode,
        public readonly ?string $expectedXmlSha256,
    ) {
    }

    public static function zugferd(
        string $contactId,
        string $paymentMethodId,
        string $unityId,
        string $countryId,
        string $expectedAddressHash,
        string $recipientName,
        string $street,
        string $zip,
        string $city,
        string $countryCode,
        ?string $expectedXmlSha256 = null,
    ): self {
        $contactId = self::requiredId($contactId, 'Contact');
        $paymentMethodId = self::requiredId($paymentMethodId, 'PaymentMethod');
        $unityId = self::requiredId($unityId, 'Unity');
        $countryId = self::requiredId($countryId, 'StaticCountry');
        $recipientName = self::requiredText($recipientName, 255, 'Recipient name');
        $street = self::requiredText($street, 255, 'Street');
        $zip = self::requiredText($zip, 20, 'Postal code');
        $city = self::requiredText($city, 100, 'City');
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode !== 'DE') {
            throw new \InvalidArgumentException('ZUGFeRD v1 is restricted to German recipient addresses.');
        }

        $expectedAddressHash = strtolower(trim($expectedAddressHash));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedAddressHash) !== 1) {
            throw new \InvalidArgumentException('The frozen E-Invoice address hash is invalid.');
        }
        $actualAddressHash = self::addressHash($recipientName, $street, $zip, $city, $countryCode);
        if (!hash_equals($expectedAddressHash, $actualAddressHash)) {
            throw new \InvalidArgumentException('The current recipient address differs from the frozen E-Invoice address.');
        }

        if ($expectedXmlSha256 !== null) {
            $expectedXmlSha256 = strtolower(trim($expectedXmlSha256));
            if (preg_match('/^[a-f0-9]{64}$/', $expectedXmlSha256) !== 1) {
                throw new \InvalidArgumentException('The frozen E-Invoice XML hash is invalid.');
            }
        }

        return new self(
            $contactId,
            $paymentMethodId,
            $unityId,
            $countryId,
            $expectedAddressHash,
            $recipientName,
            $street,
            $zip,
            $city,
            $countryCode,
            $expectedXmlSha256,
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

    public function withExpectedXmlSha256(string $xmlSha256): self
    {
        $xmlSha256 = strtolower(trim($xmlSha256));
        if (preg_match('/^[a-f0-9]{64}$/', $xmlSha256) !== 1) {
            throw new \InvalidArgumentException('The verified E-Invoice XML hash is invalid.');
        }
        if ($this->expectedXmlSha256 !== null && !hash_equals($this->expectedXmlSha256, $xmlSha256)) {
            throw new \InvalidArgumentException('The verified E-Invoice XML hash changed.');
        }
        if ($this->expectedXmlSha256 === $xmlSha256) {
            return $this;
        }

        return new self(
            $this->contactId,
            $this->paymentMethodId,
            $this->unityId,
            $this->countryId,
            $this->expectedAddressHash,
            $this->recipientName,
            $this->street,
            $this->zip,
            $this->city,
            $this->countryCode,
            $xmlSha256,
        );
    }

    /** @return array<string, scalar|array<string, int|string>> */
    public function invoicePayloadFields(): array
    {
        return [
            'propertyIsEInvoice' => true,
            'paymentMethod' => [
                'id' => self::payloadId($this->paymentMethodId),
                'objectName' => 'PaymentMethod',
            ],
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
     *     isEInvoice:true,
     *     eInvoiceContactId:string,
     *     eInvoicePaymentMethodId:string,
     *     eInvoiceUnityId:string,
     *     eInvoiceCountryId:string,
     *     eInvoiceAddressHash:string,
     *     xmlSha256:string|null
     * }
     */
    public function frozenContext(?string $xmlSha256 = null): array
    {
        return [
            'isEInvoice' => true,
            'eInvoiceContactId' => $this->contactId,
            'eInvoicePaymentMethodId' => $this->paymentMethodId,
            'eInvoiceUnityId' => $this->unityId,
            'eInvoiceCountryId' => $this->countryId,
            'eInvoiceAddressHash' => $this->expectedAddressHash,
            'xmlSha256' => $xmlSha256 ?? $this->expectedXmlSha256,
        ];
    }

    /** @param array<array-key, mixed> $remote */
    public function remoteMismatch(array $remote): ?string
    {
        if ((string) ($remote['contact']['id'] ?? '') !== $this->contactId) {
            return 'e_invoice_contact_mismatch';
        }
        if ((string) ($remote['paymentMethod']['id'] ?? '') !== $this->paymentMethodId) {
            return 'e_invoice_payment_method_mismatch';
        }
        if (self::remoteBoolean($remote['propertyIsEInvoice'] ?? null) !== true) {
            return 'e_invoice_flag_mismatch';
        }

        $remoteName = self::normaliseText((string) ($remote['addressName'] ?? ''));
        $remoteStreet = self::normaliseText((string) ($remote['addressStreet'] ?? ''));
        $remoteZip = self::normaliseText((string) ($remote['addressZip'] ?? ''));
        $remoteCity = self::normaliseText((string) ($remote['addressCity'] ?? ''));
        if ($remoteName === '' || $remoteStreet === '' || $remoteZip === '' || $remoteCity === '') {
            return 'e_invoice_address_missing';
        }

        $remoteCountry = $remote['addressCountry'] ?? null;
        if (is_array($remoteCountry)) {
            if ((string) ($remoteCountry['id'] ?? '') !== $this->countryId) {
                return 'e_invoice_country_mismatch';
            }
            $reportedCode = strtoupper(trim((string) (
                $remoteCountry['code']
                ?? $remoteCountry['countryCode']
                ?? $remoteCountry['shortCode']
                ?? ''
            )));
            if ($reportedCode !== '' && $reportedCode !== $this->countryCode) {
                return 'e_invoice_country_mismatch';
            }
        } elseif (strtoupper(trim((string) $remoteCountry)) !== $this->countryCode) {
            return 'e_invoice_country_mismatch';
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
            : 'e_invoice_address_hash_mismatch';
    }

    private static function requiredId(string $id, string $label): string
    {
        $id = trim($id);
        if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
            throw new \InvalidArgumentException($label . ' needs a valid sevdesk ID.');
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

    private static function normaliseText(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r", "\n"], ' ', $value));

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function payloadId(string $id): int|string
    {
        return strlen($id) < 19 ? (int) $id : $id;
    }

    private static function remoteBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
    }
}
