<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\ContactResolution;
use WHMCS\Module\Addon\SevDesk\Support\Result;

/** Resolve, re-link or create a sevdesk contact without silently creating duplicates. */
final class ContactService
{
    /** @var Closure(int, string): (bool|null) */
    private readonly Closure $persistContactId;

    /** @var Closure(string): (int|string|null) */
    private readonly Closure $resolveCountryId;

    /** @var Closure(): (string|null) */
    private readonly Closure $resolveAddressCategoryId;

    /** @var Closure(): (string|null) */
    private readonly Closure $resolveEmailKeyId;

    /** @var Closure(): bool */
    private readonly Closure $allowCustomerNumberContactCreate;

    /**
     * Collaborator signatures:
     *
     * - $persistContactId(int $whmcsClientId, string $sevdeskContactId): bool|null
     * - $resolveCountryId(string $isoCountryCode): int|string|null
     * - optional category/key callables(): int|string|null
     *
     * Address and email category/key IDs must come from the target sevdesk account;
     * this service deliberately does not guess locale/account-specific identifiers.
     */
    public function __construct(
        private readonly SevdeskClient $client,
        callable $persistContactId,
        callable $resolveCountryId,
        private readonly string $contactCategoryId = '3',
        string|callable|null $addressCategoryId = null,
        string|callable|null $emailKeyId = null,
        bool|callable $allowCustomerNumberContactCreate = false,
    ) {
        $this->persistContactId = Closure::fromCallable($persistContactId);
        $this->resolveCountryId = Closure::fromCallable($resolveCountryId);
        self::assertOptionalNumericId($contactCategoryId, 'Contact category');
        $this->resolveAddressCategoryId = self::optionalIdResolver($addressCategoryId, 'Address category');
        $this->resolveEmailKeyId = self::optionalIdResolver($emailKeyId, 'Email key');
        $this->allowCustomerNumberContactCreate = is_bool($allowCustomerNumberContactCreate)
            ? static fn (): bool => $allowCustomerNumberContactCreate
            : Closure::fromCallable($allowCustomerNumberContactCreate);
    }

    /**
     * @param null|callable(string, array<string, scalar|null>): (bool|null) $checkpoint
     * @param bool $recoveryOnly Allow read-only relinking, but never a new contact create.
     * @return Result
     */
    public function resolve(
        ContactData $contact,
        ?callable $checkpoint = null,
        bool $recoveryOnly = false,
    ): Result {
        $checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);

        if ($contact->sevdeskContactId !== null) {
            try {
                if ($this->remoteContactExists($contact->sevdeskContactId)) {
                    $checkpointFailure = $this->checkpoint(
                        $checkpoint,
                        'contact_linked',
                        ['remoteContactId' => $contact->sevdeskContactId],
                    );
                    if ($checkpointFailure !== null) {
                        return $checkpointFailure;
                    }

                    return Result::success(new ContactResolution(
                        $contact->sevdeskContactId,
                        'configured',
                    ));
                }
                return Result::failure(
                    'configured_contact_missing',
                    'The configured sevdesk contact no longer exists and requires manual reconciliation.',
                    ['remoteContactId' => $contact->sevdeskContactId],
                );
            } catch (ApiException $exception) {
                return $this->apiFailure('contact_verification_failed', $exception);
            }
        }

        try {
            $search = $this->findByCustomerNumber($contact->whmcsClientId);
        } catch (ApiException $exception) {
            return $this->apiFailure('contact_search_failed', $exception);
        }
        $matches = $search['matches'];
        if ($search['truncated']) {
            return Result::failure(
                'contact_search_truncated',
                'sevdesk returned a full contact search page, so uniqueness could not be proven.',
                ['pageSize' => self::CONTACT_SEARCH_LIMIT],
            );
        }
        if ($search['unverifiable'] > 0) {
            return Result::failure(
                'contact_search_unverifiable',
                'sevdesk returned a possible customer-number match whose identity could not be proven.',
                ['unverifiableCount' => $search['unverifiable']],
            );
        }

        if (count($matches) > 1) {
            return Result::failure(
                'contact_conflict',
                'Multiple sevdesk contacts use this WHMCS customer number.',
                ['matchCount' => count($matches)],
            );
        }

        if (count($matches) === 1) {
            $contactId = self::extractContactId($matches[0]);
            if ($contactId === null) {
                return Result::failure(
                    'invalid_contact_response',
                    'sevdesk returned a contact without an ID.',
                );
            }

            $persistFailure = $this->persistLink($contact->whmcsClientId, $contactId, false);
            if ($persistFailure !== null) {
                return $persistFailure;
            }

            $checkpointFailure = $this->checkpoint(
                $checkpoint,
                'contact_linked',
                ['remoteContactId' => $contactId],
            );
            if ($checkpointFailure !== null) {
                return $checkpointFailure;
            }

            return Result::success(new ContactResolution($contactId, 'customer_number'));
        }

        if ($recoveryOnly) {
            // A previous create request may have reached sevdesk even when the
            // local process never received its response. A temporarily empty
            // search result is therefore not permission to create again.
            return Result::failure(
                'contact_recovery_no_match_ambiguous',
                'No unique sevdesk contact was found while recovering an earlier create request.',
                ['ambiguous' => true, 'matchCount' => 0],
            );
        }

        if (!(($this->allowCustomerNumberContactCreate)())) {
            return Result::failure(
                'contact_creation_not_confirmed',
                'Creating a new sevdesk contact from the WHMCS customer number requires operator confirmation.',
            );
        }

        $checkpointFailure = $this->checkpoint(
            $checkpoint,
            'contact_write_requested',
            ['whmcsClientId' => $contact->whmcsClientId],
        );
        if ($checkpointFailure !== null) {
            return $checkpointFailure;
        }

        try {
            $created = $this->client->post('/Contact', $this->contactPayload($contact), true, [201]);
        } catch (ApiException $exception) {
            return $this->apiFailure('contact_create_failed', $exception);
        }

        $contactId = self::extractContactId($created);
        if ($contactId === null) {
            return Result::failure(
                'contact_create_ambiguous',
                'sevdesk accepted the contact request but returned no contact ID.',
                ['ambiguous' => true],
            );
        }

        // This checkpoint intentionally happens before address/email creation. If
        // either optional follow-up fails, the next run verifies this ID instead
        // of creating the same contact again.
        $persistFailure = $this->persistLink($contact->whmcsClientId, $contactId, true);
        if ($persistFailure !== null) {
            return $persistFailure;
        }

        $checkpointFailure = $this->checkpoint(
            $checkpoint,
            'contact_linked',
            ['remoteContactId' => $contactId],
        );
        if ($checkpointFailure !== null) {
            return $checkpointFailure;
        }

        $warnings = [];
        $this->addAddress($contact, $contactId, $warnings);
        $this->addEmail($contact, $contactId, $warnings);

        return Result::success(new ContactResolution($contactId, 'created', $warnings));
    }

    private function remoteContactExists(string $contactId): bool
    {
        try {
            $response = $this->client->get('/Contact/' . rawurlencode($contactId));
        } catch (ApiException $exception) {
            if (in_array($exception->httpStatus, [400, 404], true)) {
                return false;
            }

            throw $exception;
        }

        if ($response === []) {
            return false;
        }

        if (array_is_list($response)) {
            foreach ($response as $candidate) {
                if (is_array($candidate) && self::extractContactId($candidate) === $contactId) {
                    return true;
                }
            }

            return false;
        }

        return self::extractContactId($response) === $contactId;
    }

    private const int CONTACT_SEARCH_LIMIT = 1000;

    /** @return array{matches:list<array<array-key,mixed>>,unverifiable:int,truncated:bool} */
    private function findByCustomerNumber(int $whmcsClientId): array
    {
        $response = $this->client->get('/Contact', [
            'customerNumber' => (string) $whmcsClientId,
            'depth' => '1',
            'limit' => self::CONTACT_SEARCH_LIMIT,
            'offset' => 0,
        ]);

        if ($response === []) {
            return ['matches' => [], 'unverifiable' => 0, 'truncated' => false];
        }

        $candidates = array_is_list($response) ? $response : [$response];
        if (count($candidates) >= self::CONTACT_SEARCH_LIMIT) {
            return ['matches' => [], 'unverifiable' => 0, 'truncated' => true];
        }
        $matches = [];
        $unverifiable = 0;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $contactId = self::extractContactId($candidate);
            if ($contactId === null) {
                continue;
            }

            $matchesCustomer = self::contactMatchesCustomerNumber($candidate, $whmcsClientId);
            if ($matchesCustomer === null) {
                $details = $this->client->get('/Contact/' . rawurlencode($contactId));
                $matchesCustomer = self::matchingContactDetailsUseCustomerNumber(
                    $details,
                    $contactId,
                    $whmcsClientId,
                );
            }
            if ($matchesCustomer === true) {
                $matches[] = $candidate;
            } elseif ($matchesCustomer === null) {
                ++$unverifiable;
            }
        }

        return ['matches' => $matches, 'unverifiable' => $unverifiable, 'truncated' => false];
    }

    /** @return array<string, mixed> */
    private function contactPayload(ContactData $contact): array
    {
        $payload = [
            'customerNumber' => (string) $contact->whmcsClientId,
            // New contacts receive the neutral B2B buyer reference required by
            // sevdesk's native E-Invoice contract. Existing contacts are never
            // patched or re-linked by this service.
            'buyerReference' => (string) $contact->whmcsClientId,
            'governmentAgency' => false,
            'status' => 1000,
            'category' => [
                'id' => self::payloadId($this->contactCategoryId),
                'objectName' => 'Category',
            ],
            'exemptVat' => $contact->taxExempt,
            'description' => 'Created by the WHMCS sevdesk module',
        ];

        $vatNumber = trim((string) $contact->vatNumber);
        if ($vatNumber !== '') {
            $payload['vatNumber'] = $vatNumber;
        }

        if ($contact->isOrganisation()) {
            $payload['name'] = trim($contact->companyName);
        } else {
            // `surename` is sevdesk's documented (historic) spelling for first name.
            $payload['surename'] = trim($contact->firstName);
            $payload['familyname'] = trim($contact->lastName);
        }

        return $payload;
    }

    /** @param list<string> $warnings */
    private function addAddress(ContactData $contact, string $contactId, array &$warnings): void
    {
        $hasAddress = trim($contact->street . $contact->addressLine2 . $contact->postcode . $contact->city) !== '';
        if (!$hasAddress) {
            return;
        }

        try {
            $addressCategoryId = ($this->resolveAddressCategoryId)();
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }
            $warnings[] = 'address_not_added:category_resolution_failed';
            return;
        } catch (Throwable) {
            $warnings[] = 'address_not_added:category_resolution_failed';
            return;
        }
        if ($addressCategoryId === null) {
            $warnings[] = 'address_not_added:category_not_configured';
            return;
        }

        try {
            $countryId = ($this->resolveCountryId)($contact->countryCode);
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }
            $warnings[] = 'address_not_added:country_resolution_failed';
            return;
        } catch (Throwable) {
            $warnings[] = 'address_not_added:country_resolution_failed';
            return;
        }

        $countryId = trim((string) $countryId);
        if ($countryId === '' || preg_match('/^\d+$/', $countryId) !== 1) {
            $warnings[] = 'address_not_added:country_not_found';
            return;
        }

        $street = trim($contact->street);
        if (trim($contact->addressLine2) !== '') {
            $street .= ($street === '' ? '' : "\n") . trim($contact->addressLine2);
        }

        try {
            $this->client->post('/ContactAddress', [
                'contact' => ['id' => self::payloadId($contactId), 'objectName' => 'Contact'],
                'street' => $street,
                'zip' => trim($contact->postcode),
                'city' => trim($contact->city),
                'country' => ['id' => self::payloadId($countryId), 'objectName' => 'StaticCountry'],
                'category' => [
                    'id' => self::payloadId($addressCategoryId),
                    'objectName' => 'Category',
                ],
                'name' => $contact->displayName(),
            ], true, [201]);
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }
            $warnings[] = 'address_not_added:' . self::warningCode($exception);
        }
    }

    /** @param list<string> $warnings */
    private function addEmail(ContactData $contact, string $contactId, array &$warnings): void
    {
        $email = trim($contact->email);
        if ($email === '') {
            return;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $warnings[] = 'email_not_added:invalid_address';
            return;
        }
        try {
            $emailKeyId = ($this->resolveEmailKeyId)();
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }
            $warnings[] = 'email_not_added:key_resolution_failed';
            return;
        } catch (Throwable) {
            $warnings[] = 'email_not_added:key_resolution_failed';
            return;
        }
        if ($emailKeyId === null) {
            $warnings[] = 'email_not_added:key_not_configured';
            return;
        }

        try {
            $this->client->post('/CommunicationWay', [
                'contact' => ['id' => self::payloadId($contactId), 'objectName' => 'Contact'],
                'type' => 'EMAIL',
                'value' => $email,
                'key' => [
                    'id' => self::payloadId($emailKeyId),
                    'objectName' => 'CommunicationWayKey',
                ],
                'main' => true,
            ], true, [201]);
        } catch (ApiException $exception) {
            if ($exception->isAuthenticationFailure()) {
                throw $exception;
            }
            $warnings[] = 'email_not_added:' . self::warningCode($exception);
        }
    }

    /** @return Result|null */
    private function persistLink(int $whmcsClientId, string $contactId, bool $newRemoteContact): ?Result
    {
        try {
            $result = ($this->persistContactId)($whmcsClientId, $contactId);
            if ($result === false) {
                throw new \RuntimeException('Persistence callback returned false.');
            }
        } catch (Throwable) {
            return Result::failure(
                'contact_link_persist_failed',
                'The sevdesk contact ID could not be stored in WHMCS.',
                [
                    'remoteContactId' => $contactId,
                    'ambiguous' => $newRemoteContact,
                ],
            );
        }

        return null;
    }

    /** @return Result */
    private function apiFailure(string $code, ApiException $exception): Result
    {
        $context = [
            'httpStatus' => $exception->httpStatus,
            'sevdeskCode' => $exception->sevdeskCode,
            'exceptionUuid' => $exception->exceptionUuid,
            'retryAfterSeconds' => $exception->retryAfterSeconds,
            'ambiguous' => $exception->outcomeUnknown,
        ];
        if (!$exception->outcomeUnknown && $code === 'contact_create_failed') {
            $context['definiteWriteRejected'] = true;
        }

        return Result::failure(
            $exception->outcomeUnknown ? $code . '_ambiguous' : $code,
            'A sevdesk contact operation failed.',
            $context,
        );
    }

    /**
     * @param Closure(string, array<string, scalar>): (bool|null)|null $checkpoint
     * @param array<string, scalar> $context
     * @return Result|null
     */
    private function checkpoint(?Closure $checkpoint, string $name, array $context): ?Result
    {
        if ($checkpoint === null) {
            return null;
        }

        try {
            $result = $checkpoint($name, $context);
            if ($result === false) {
                throw new \RuntimeException('Checkpoint callback returned false.');
            }
        } catch (Throwable) {
            return Result::failure(
                'checkpoint_persist_failed',
                'The contact workflow checkpoint could not be stored.',
                $context,
            );
        }

        return null;
    }

    /** @param array<array-key, mixed> $response */
    private static function extractContactId(array $response): ?string
    {
        $id = $response['id'] ?? null;
        if ($id === null && isset($response['contact']) && is_array($response['contact'])) {
            $id = $response['contact']['id'] ?? null;
        }

        if (!is_int($id) && !is_string($id)) {
            return null;
        }

        $id = trim((string) $id);

        return $id !== '' && preg_match('/^\d+$/', $id) === 1 ? $id : null;
    }

    private static function warningCode(ApiException $exception): string
    {
        if ($exception->outcomeUnknown) {
            return 'ambiguous';
        }
        if ($exception->sevdeskCode !== null) {
            return $exception->sevdeskCode;
        }

        return $exception->httpStatus !== null ? 'http_' . $exception->httpStatus : 'transport_error';
    }

    /** @param array<array-key, mixed> $candidate */
    private static function contactMatchesCustomerNumber(array $candidate, int $whmcsClientId): ?bool
    {
        if (self::extractContactId($candidate) === null) {
            return false;
        }

        if (!array_key_exists('customerNumber', $candidate)) {
            return null;
        }

        return (string) $candidate['customerNumber'] === (string) $whmcsClientId;
    }

    /** @param array<array-key, mixed> $response */
    private static function matchingContactDetailsUseCustomerNumber(
        array $response,
        string $contactId,
        int $whmcsClientId,
    ): ?bool {
        $candidates = array_is_list($response) ? $response : [$response];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || self::extractContactId($candidate) !== $contactId) {
                continue;
            }

            return self::contactMatchesCustomerNumber($candidate, $whmcsClientId);
        }

        return null;
    }

    private static function payloadId(string $id): int|string
    {
        return ctype_digit($id) && strlen($id) < 19 ? (int) $id : $id;
    }

    /** @return Closure(): (string|null) */
    private static function optionalIdResolver(string|callable|null $value, string $field): Closure
    {
        if (!is_callable($value)) {
            self::assertOptionalNumericId($value, $field);

            return static fn (): ?string => $value;
        }

        $resolver = Closure::fromCallable($value);

        return static function () use ($resolver, $field): ?string {
            $resolved = $resolver();
            if ($resolved === null) {
                return null;
            }
            if (!is_int($resolved) && !is_string($resolved)) {
                throw new \InvalidArgumentException($field . ' resolver must return a numeric ID or null.');
            }
            $id = (string) $resolved;
            self::assertOptionalNumericId($id, $field);

            return $id;
        };
    }

    private static function assertOptionalNumericId(?string $id, string $field): void
    {
        if ($id !== null && ($id === '' || preg_match('/^\d+$/', $id) !== 1)) {
            throw new \InvalidArgumentException($field . ' ID must be numeric.');
        }
    }
}
