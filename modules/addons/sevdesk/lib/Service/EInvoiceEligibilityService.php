<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use DateTimeImmutable;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Support\Result;

/** Selects native ZUGFeRD fail-closed and without persisting recipient data. */
final class EInvoiceEligibilityService
{
    public const MODE_OFF = 'off';

    public const MODE_ZUGFERD_DOMESTIC_B2B = 'zugferd_domestic_b2b';

    public function __construct(
        private readonly Config $config,
        private readonly WhmcsGateway $whmcs,
        private readonly SevdeskClient $client,
        private readonly ReferenceData $referenceData,
    ) {
    }

    /**
     * @param array<string,mixed> $candidate
     * @return Result Success contains EInvoiceContext|null.
     */
    public function decide(
        InvoiceSnapshot $invoice,
        ContactData $contact,
        string $remoteContactId,
        TaxDecision $tax,
        DocumentTargetDecision $target,
        array $candidate,
        bool $revalidateFrozenPrerequisites = true,
    ): Result {
        if (array_key_exists('targetIsEInvoice', $candidate)) {
            return $this->restoreFrozen(
                $contact,
                $remoteContactId,
                $candidate,
                $revalidateFrozenPrerequisites,
            );
        }

        $requestedMode = trim((string) ($candidate['requestedEInvoiceMode'] ?? self::MODE_OFF));
        if ($requestedMode === self::MODE_OFF || self::truthy($candidate['historicalBackfill'] ?? false)) {
            return Result::success(null);
        }
        if ($requestedMode !== self::MODE_ZUGFERD_DOMESTIC_B2B) {
            return Result::failure(
                'e_invoice_context_invalid',
                'The requested E-Invoice profile is invalid.',
            );
        }
        if ($tax->taxRuleId === '19' && $target->taxRuleId === '19') {
            // OSS Rule 19 deliberately remains a normal Invoice even when the
            // customer has opted into the separate domestic ZUGFeRD profile.
            return Result::success(null);
        }

        $activeFrom = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            trim((string) ($candidate['requestedEInvoiceActiveFrom'] ?? '')),
        );
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            !$activeFrom instanceof DateTimeImmutable
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            return Result::failure(
                'e_invoice_context_invalid',
                'The requested E-Invoice activation date is invalid.',
            );
        }
        if ($invoice->invoiceDate < $activeFrom) {
            return Result::success(null);
        }

        $requestedFieldId = (int) ($candidate['requestedEInvoiceClientFieldId'] ?? 0);
        $configuredFieldId = $this->config->int('e_invoice_client_field_id');
        if (
            $requestedFieldId < 1
            || $requestedFieldId !== $configuredFieldId
            || !$this->whmcs->isEInvoiceOptInField($requestedFieldId)
        ) {
            return Result::failure(
                'e_invoice_opt_in_field_invalid',
                'The frozen E-Invoice opt-in field is no longer a valid admin-only client tickbox.',
            );
        }
        if (!$this->whmcs->eInvoiceOptedIn($invoice->clientId)) {
            return Result::success(null);
        }

        if (
            $target->documentType !== DocumentTargetDecision::DOCUMENT_INVOICE
            || $target->exportMode !== DocumentTargetResolver::MODE_INVOICE_ONLY
            || $target->documentAuthority !== DocumentTargetResolver::AUTHORITY_SEVDESK
        ) {
            return Result::failure(
                'e_invoice_target_not_supported',
                'An explicitly selected E-Invoice requires invoice_only with sevdesk document authority.',
            );
        }
        if (
            !$this->config->bool('e_invoice_canary_confirmed')
            || !self::truthy($candidate['requestedEInvoiceCanaryConfirmed'] ?? false)
        ) {
            return Result::failure(
                'e_invoice_canary_not_confirmed',
                'The separate native E-Invoice canary is not confirmed.',
            );
        }
        if (!class_exists(\XMLReader::class)) {
            return Result::failure(
                'e_invoice_xml_runtime_missing',
                'PHP XMLReader is required before a native E-Invoice can be selected.',
            );
        }
        if (
            !$contact->isOrganisation()
            || $contact->countryCode !== 'DE'
            || $tax->taxRuleId !== '1'
            || $target->taxRuleId !== '1'
        ) {
            return Result::failure(
                'e_invoice_tax_or_recipient_not_supported',
                'The selected E-Invoice is not a German B2B Rule-1 Invoice.',
            );
        }

        $paymentMethodId = trim((string) ($candidate['requestedEInvoicePaymentMethodId'] ?? ''));
        $unityId = trim((string) ($candidate['requestedEInvoiceUnityId']
            ?? $candidate['targetUnityId']
            ?? ''));
        $sevUserId = trim((string) ($candidate['requestedEInvoiceSevUserId']
            ?? $candidate['targetSevUserId']
            ?? ''));
        if (
            !self::numericId($remoteContactId)
            || !self::numericId($paymentMethodId)
            || !self::numericId($unityId)
            || !self::numericId($sevUserId)
            || !$this->referenceData->hasPaymentMethod($paymentMethodId)
            || !$this->referenceData->hasUnity($unityId)
            || !$this->referenceData->hasSevUser($sevUserId)
        ) {
            return Result::failure(
                'e_invoice_reference_missing',
                'PaymentMethod, Unity, SevUser or Contact could not be proven in the current sevdesk tenant.',
            );
        }

        $countryId = $this->referenceData->countryId('DE');
        if ($countryId === null || !self::numericId($countryId)) {
            return Result::failure(
                'e_invoice_country_reference_missing',
                'The German sevdesk country reference could not be resolved.',
            );
        }

        $street = trim($contact->street);
        if (trim($contact->addressLine2) !== '') {
            $street .= ($street === '' ? '' : "\n") . trim($contact->addressLine2);
        }
        if (
            trim($contact->companyName) === ''
            || $street === ''
            || trim($contact->postcode) === ''
            || trim($contact->city) === ''
            || filter_var(trim($contact->email), FILTER_VALIDATE_EMAIL) === false
        ) {
            return Result::failure(
                'e_invoice_recipient_data_missing',
                'The explicitly selected E-Invoice lacks a complete German organisation address or email.',
            );
        }

        $addressHash = EInvoiceContext::addressHash(
            $contact->companyName,
            $street,
            $contact->postcode,
            $contact->city,
            $contact->countryCode,
        );
        $remoteCheck = $this->verifyRemoteContact(
            $remoteContactId,
            trim($contact->email),
            $countryId,
            $addressHash,
        );
        if ($remoteCheck !== null) {
            return Result::failure($remoteCheck, 'The selected sevdesk contact is not ready for a native E-Invoice.');
        }

        try {
            return Result::success(EInvoiceContext::zugferd(
                $remoteContactId,
                $paymentMethodId,
                $unityId,
                $countryId,
                $addressHash,
                $contact->companyName,
                $street,
                $contact->postcode,
                $contact->city,
                $contact->countryCode,
            ));
        } catch (\InvalidArgumentException) {
            return Result::failure(
                'e_invoice_recipient_data_invalid',
                'The explicitly selected E-Invoice recipient data exceeds the supported native sevdesk contract.',
            );
        }
    }

    /** @param array<string,mixed> $candidate */
    private function restoreFrozen(
        ContactData $contact,
        string $remoteContactId,
        array $candidate,
        bool $revalidatePrerequisites,
    ): Result {
        if (!self::truthy($candidate['targetIsEInvoice'] ?? false)) {
            return Result::success(null);
        }
        if (!$this->config->bool('e_invoice_canary_confirmed')) {
            return Result::failure(
                'e_invoice_canary_not_confirmed',
                'The E-Invoice canary was revoked after target selection.',
            );
        }

        $street = trim($contact->street);
        if (trim($contact->addressLine2) !== '') {
            $street .= ($street === '' ? '' : "\n") . trim($contact->addressLine2);
        }
        try {
            $context = EInvoiceContext::zugferd(
                trim((string) ($candidate['targetEInvoiceContactId'] ?? '')),
                trim((string) ($candidate['targetEInvoicePaymentMethodId'] ?? '')),
                trim((string) ($candidate['targetEInvoiceUnityId'] ?? '')),
                trim((string) ($candidate['targetEInvoiceCountryId'] ?? '')),
                trim((string) ($candidate['targetEInvoiceAddressHash'] ?? '')),
                $contact->companyName,
                $street,
                $contact->postcode,
                $contact->city,
                $contact->countryCode,
                self::nullableHash($candidate['xmlSha256'] ?? null),
            );
        } catch (\InvalidArgumentException) {
            return Result::failure(
                'e_invoice_frozen_context_changed',
                'The current recipient or E-Invoice references differ from the frozen target.',
            );
        }
        if (!hash_equals($context->contactId, trim($remoteContactId))) {
            return Result::failure(
                'e_invoice_contact_changed',
                'The resolved sevdesk contact differs from the frozen E-Invoice contact.',
            );
        }
        if ($revalidatePrerequisites) {
            $sevUserId = trim((string) ($candidate['targetSevUserId'] ?? ''));
            if (
                !self::numericId($sevUserId)
                || !$this->referenceData->hasPaymentMethod($context->paymentMethodId)
                || !$this->referenceData->hasUnity($context->unityId)
                || !$this->referenceData->hasSevUser($sevUserId)
                || $this->referenceData->countryId('DE') !== $context->countryId
            ) {
                return Result::failure(
                    'e_invoice_frozen_reference_changed',
                    'A frozen E-Invoice reference is no longer present in the current sevdesk tenant.',
                );
            }
            $remoteMismatch = $this->verifyRemoteContact(
                $context->contactId,
                trim($contact->email),
                $context->countryId,
                $context->expectedAddressHash,
            );
            if ($remoteMismatch !== null) {
                return Result::failure(
                    $remoteMismatch,
                    'The frozen sevdesk contact no longer satisfies the native E-Invoice prerequisites.',
                );
            }
        }

        return Result::success($context);
    }

    private function verifyRemoteContact(
        string $contactId,
        string $expectedEmail,
        string $countryId,
        string $expectedAddressHash,
    ): ?string {
        $contact = self::one($this->client->get('/Contact/' . rawurlencode($contactId)));
        if (
            (string) ($contact['id'] ?? '') !== $contactId
            || trim((string) ($contact['buyerReference'] ?? '')) === ''
            || self::remoteBoolean($contact['governmentAgency'] ?? null) !== false
        ) {
            return 'e_invoice_contact_master_data_missing';
        }

        // Some sevdesk tenants return an empty list when type/main are combined
        // with the contact filter. Read the bounded contact set and enforce
        // EMAIL + main locally so the prerequisite remains fail-closed.
        $emails = self::rows($this->client->get('/CommunicationWay', [
            'contact[id]' => $contactId,
            'contact[objectName]' => 'Contact',
            'limit' => 1000,
            'offset' => 0,
        ]));
        if (count($emails) >= 1000) {
            return 'e_invoice_contact_email_search_truncated';
        }
        $mainEmails = array_values(array_filter($emails, static fn (array $row): bool =>
            (string) ($row['contact']['id'] ?? '') === $contactId
            && strtoupper(trim((string) ($row['type'] ?? ''))) === 'EMAIL'
            && self::remoteBoolean($row['main'] ?? null) === true));
        if (
            count($mainEmails) !== 1
            || strcasecmp(trim((string) ($mainEmails[0]['value'] ?? '')), $expectedEmail) !== 0
        ) {
            return 'e_invoice_contact_main_email_missing';
        }

        $addresses = self::rows($this->client->get('/ContactAddress', [
            'contact[id]' => $contactId,
            'contact[objectName]' => 'Contact',
            'limit' => 1000,
            'offset' => 0,
        ]));
        if (count($addresses) >= 1000) {
            return 'e_invoice_contact_address_search_truncated';
        }
        foreach ($addresses as $address) {
            if (
                (string) ($address['contact']['id'] ?? '') !== $contactId
                || (string) ($address['country']['id'] ?? '') !== $countryId
            ) {
                continue;
            }
            $street = trim((string) ($address['street'] ?? ''));
            $zip = trim((string) ($address['zip'] ?? ''));
            $city = trim((string) ($address['city'] ?? ''));
            $name = trim((string) ($address['name'] ?? ''));
            if ($name === '' || $street === '' || $zip === '' || $city === '') {
                continue;
            }
            if (
                hash_equals(
                    $expectedAddressHash,
                    EInvoiceContext::addressHash($name, $street, $zip, $city, 'DE'),
                )
            ) {
                return null;
            }
        }

        return 'e_invoice_contact_address_missing_or_changed';
    }

    /**
     * @param array<int|string, mixed> $response
     * @return array<int|string, mixed>
     */
    private static function one(array $response): array
    {
        $rows = self::rows($response);

        return count($rows) === 1 ? $rows[0] : [];
    }

    /**
     * @param array<int|string, mixed> $response
     * @return list<array<int|string, mixed>>
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

    private static function numericId(string $value): bool
    {
        return preg_match('/^[1-9]\d*$/', trim($value)) === 1;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true));
    }

    private static function remoteBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }

    private static function nullableHash(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
