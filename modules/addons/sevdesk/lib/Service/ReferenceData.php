<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;

/** Read-only sevdesk reference data used by setup, health and exports. */
final class ReferenceData
{
    /** @var array<array-key, mixed>|null */
    private ?array $receiptGuidance = null;

    /** @var array<string, string|null> */
    private array $countries = [];

    /** @var array<string, string|null> */
    private array $exactCountries = [];

    private ?string $addressCategoryId = null;

    private bool $addressCategoryLoaded = false;

    private ?string $emailKeyId = null;

    private bool $emailKeyLoaded = false;

    public function __construct(private readonly SevdeskClient $client)
    {
    }

    /** @return array<array-key, mixed> */
    public function receiptGuidance(bool $fresh = false): array
    {
        if ($fresh || $this->receiptGuidance === null) {
            $this->receiptGuidance = $this->client->get('/ReceiptGuidance/forRevenue');
        }

        return $this->receiptGuidance;
    }

    /** @return list<array{id:string,accountDatevId:string,accountNumber:string,name:string,accountName:string,description:string}> */
    public function revenueAccounts(): array
    {
        $accounts = [];
        foreach ($this->receiptGuidance() as $guide) {
            if (!is_array($guide)) {
                continue;
            }
            $id = self::numericId($guide['accountDatevId'] ?? null);
            if ($id === null) {
                continue;
            }
            $receiptTypes = array_map('strtoupper', array_map('strval', (array) ($guide['allowedReceiptTypes'] ?? [])));
            if ($receiptTypes !== [] && !in_array('REVENUE', $receiptTypes, true)) {
                continue;
            }
            $name = trim((string) ($guide['accountName'] ?? ''));
            $accounts[] = [
                'id' => $id,
                'accountDatevId' => $id,
                'accountNumber' => (string) ($guide['accountNumber'] ?? ''),
                'name' => $name,
                'accountName' => $name,
                'description' => (string) ($guide['description'] ?? ''),
            ];
        }

        usort($accounts, static fn (array $left, array $right): int => strnatcmp($left['accountNumber'], $right['accountNumber']));

        return $accounts;
    }

    public function bookkeepingVersion(): string
    {
        $response = $this->client->get('/Tools/bookkeepingSystemVersion');

        return trim((string) ($response['version'] ?? ''));
    }

    /** @return list<array{id:string,name:string}> */
    public function sevUsers(): array
    {
        return self::namedReferences($this->client->get('/SevUser'));
    }

    /** @return list<array{id:string,name:string}> */
    public function unities(): array
    {
        return self::namedReferences($this->client->get('/Unity'));
    }

    /** @return list<array{id:string,name:string}> */
    public function paymentMethods(): array
    {
        return self::namedReferences($this->client->get('/PaymentMethod'));
    }

    public function hasSevUser(string $id): bool
    {
        return self::containsReference($this->sevUsers(), $id);
    }

    public function hasUnity(string $id): bool
    {
        return self::containsReference($this->unities(), $id);
    }

    public function hasPaymentMethod(string $id): bool
    {
        return self::containsReference($this->paymentMethods(), $id);
    }

    public function countryId(string $countryCode): ?string
    {
        $countryCode = strtoupper(trim($countryCode));
        if (isset($this->countries[$countryCode]) || array_key_exists($countryCode, $this->countries)) {
            return $this->countries[$countryCode];
        }

        try {
            $response = $this->client->get('/StaticCountry', ['code' => $countryCode]);
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }

            return $this->countries[$countryCode] = null;
        }

        foreach (self::rows($response) as $country) {
            $code = strtoupper((string) ($country['code'] ?? $country['countryCode'] ?? ''));
            if ($code !== '' && $code !== $countryCode) {
                continue;
            }
            $id = self::numericId($country['id'] ?? null);
            if ($id !== null) {
                return $this->countries[$countryCode] = $id;
            }
        }

        return $this->countries[$countryCode] = null;
    }

    /**
     * Resolve a tax-relevant country without hiding API failures or accepting
     * an unlabelled/ambiguous result row.
     */
    public function exactCountryId(string $countryCode): ?string
    {
        $countryCode = strtoupper(trim($countryCode));
        if (isset($this->exactCountries[$countryCode]) || array_key_exists($countryCode, $this->exactCountries)) {
            return $this->exactCountries[$countryCode];
        }

        $matchingIds = [];
        foreach (self::rows($this->client->get('/StaticCountry', ['code' => $countryCode])) as $country) {
            $code = strtoupper(trim((string) ($country['code'] ?? $country['countryCode'] ?? '')));
            if ($code !== $countryCode) {
                continue;
            }
            $id = self::numericId($country['id'] ?? null);
            if ($id !== null) {
                $matchingIds[] = $id;
            }
        }

        if (count($matchingIds) !== 1) {
            return $this->exactCountries[$countryCode] = null;
        }

        return $this->exactCountries[$countryCode] = $matchingIds[0];
    }

    public function contactAddressCategoryId(): ?string
    {
        if ($this->addressCategoryLoaded) {
            return $this->addressCategoryId;
        }
        $this->addressCategoryLoaded = true;

        try {
            $rows = self::rows($this->client->get('/Category', ['objectType' => 'ContactAddress']));
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }

            return null;
        }

        $this->addressCategoryId = self::bestReferenceId($rows, ['rechnung', 'invoice', 'billing', 'geschäftlich']);

        return $this->addressCategoryId;
    }

    public function emailKeyId(): ?string
    {
        if ($this->emailKeyLoaded) {
            return $this->emailKeyId;
        }
        $this->emailKeyLoaded = true;

        try {
            $rows = self::rows($this->client->get('/CommunicationWayKey'));
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }

            return null;
        }

        $emailRows = array_values(array_filter($rows, static function (array $row): bool {
            $type = strtoupper((string) ($row['type'] ?? $row['communicationWayType'] ?? ''));

            return $type === '' || $type === 'EMAIL';
        }));
        $this->emailKeyId = self::bestReferenceId($emailRows, ['rechnung', 'invoice', 'billing', 'geschäftlich']);

        return $this->emailKeyId;
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @param list<string> $preferredTerms
     */
    private static function bestReferenceId(array $rows, array $preferredTerms): ?string
    {
        foreach ($rows as $row) {
            $label = mb_strtolower(implode(' ', array_filter([
                (string) ($row['name'] ?? ''),
                (string) ($row['translationCode'] ?? ''),
                (string) ($row['key'] ?? ''),
            ])));
            foreach ($preferredTerms as $term) {
                if (str_contains($label, $term)) {
                    $id = self::numericId($row['id'] ?? null);
                    if ($id !== null) {
                        return $id;
                    }
                }
            }
        }

        foreach ($rows as $row) {
            $id = self::numericId($row['id'] ?? null);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<array-key,mixed> $response
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $response): array
    {
        if ($response === []) {
            return [];
        }
        if (!array_is_list($response)) {
            return [$response];
        }

        return array_values(array_filter($response, 'is_array'));
    }

    private static function numericId(mixed $id): ?string
    {
        if (!is_int($id) && !is_string($id)) {
            return null;
        }
        $id = trim((string) $id);

        return preg_match('/^\d+$/', $id) === 1 ? $id : null;
    }

    /**
     * @param array<array-key, mixed> $response
     * @return list<array{id:string,name:string}>
     */
    private static function namedReferences(array $response): array
    {
        $references = [];
        foreach (self::rows($response) as $row) {
            $id = self::numericId($row['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $name = trim(implode(' ', array_filter([
                (string) ($row['name'] ?? ''),
                (string) ($row['firstName'] ?? ''),
                (string) ($row['lastName'] ?? ''),
                (string) ($row['translationCode'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== '')));
            $references[] = ['id' => $id, 'name' => $name !== '' ? $name : '#' . $id];
        }

        return $references;
    }

    /** @param list<array{id:string,name:string}> $references */
    private static function containsReference(array $references, string $id): bool
    {
        $id = trim($id);
        if (preg_match('/^\d+$/', $id) !== 1) {
            return false;
        }

        foreach ($references as $reference) {
            if ($reference['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
