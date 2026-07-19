<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use DateTimeImmutable;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;

/** Exact, read-only verification shared by Invoice creation and recovery. */
final class InvoiceRemoteVerifier
{
    public function __construct(
        private readonly string $sevUserId,
        private readonly string $unityId,
    ) {
    }

    /**
     * Returns a caller-neutral mismatch code. Callers retain ownership of their
     * established public error-code namespace.
     *
     * @param array<array-key, mixed> $remote
     */
    public function invoiceMismatch(
        array $remote,
        InvoiceSnapshot $invoice,
        ?string $sevdeskContactId,
        string $taxRuleId,
        int $expectedStatus,
        ?string $expectedRemoteId = null,
        ?string $deliveryCountryCode = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ?string {
        $actualRemoteId = self::numericId($remote['id'] ?? null);
        if ($actualRemoteId === null) {
            return 'id_invalid';
        }
        if ($expectedRemoteId !== null && $actualRemoteId !== $expectedRemoteId) {
            return 'id_mismatch';
        }
        if ((string) ($remote['objectName'] ?? '') !== 'Invoice') {
            return 'type_mismatch';
        }
        if ((string) ($remote['invoiceType'] ?? '') !== 'RE') {
            return 'invoice_type_mismatch';
        }
        if ((string) ($remote['invoiceNumber'] ?? '') !== $invoice->invoiceNumber) {
            return 'number_mismatch';
        }
        if (!self::sameDate($remote['invoiceDate'] ?? null, $invoice->invoiceDate)) {
            return 'date_mismatch';
        }
        if (strtoupper((string) ($remote['currency'] ?? '')) !== $invoice->currency) {
            return 'currency_mismatch';
        }
        if ((string) ($remote['taxRule']['id'] ?? '') !== $taxRuleId) {
            return 'tax_rule_mismatch';
        }
        if ((int) ($remote['status'] ?? 0) !== $expectedStatus) {
            return 'status_mismatch';
        }
        if (!self::markerMatches((string) ($remote['customerInternalNote'] ?? ''), $invoice->invoiceId)) {
            return 'marker_mismatch';
        }
        if ($sevdeskContactId !== null && (string) ($remote['contact']['id'] ?? '') !== $sevdeskContactId) {
            return 'contact_mismatch';
        }
        if ((string) ($remote['contactPerson']['id'] ?? '') !== $this->sevUserId) {
            return 'contact_person_mismatch';
        }
        if (self::remoteBoolean($remote['showNet'] ?? null) !== $invoice->lineItems[0]->net) {
            return 'net_mode_mismatch';
        }
        if ($deliveryCountryCode !== null) {
            $expectedCountry = strtoupper(trim($deliveryCountryCode));
            $reportedDeliveryCountry = self::normaliseCountryCode($remote['deliveryAddressCountry'] ?? null);
            if ($reportedDeliveryCountry !== null && $reportedDeliveryCountry !== $expectedCountry) {
                return 'delivery_country_mismatch';
            }
            // Normal Invoice GET responses may omit both country fields. OSS is
            // different: without a readable destination the tax result is not proven.
            if (in_array($taxRuleId, ['18', '19', '20'], true)) {
                if ($reportedDeliveryCountry === null) {
                    return 'delivery_country_unverifiable';
                }
            } else {
                $reportedBillingCountry = self::normaliseCountryCode($remote['addressCountry'] ?? null);
                if ($reportedBillingCountry !== null && $reportedBillingCountry !== $expectedCountry) {
                    return 'delivery_country_mismatch';
                }
            }
        }
        if ($eInvoiceContext !== null) {
            if (
                $sevdeskContactId === null
                || $sevdeskContactId !== $eInvoiceContext->contactId
            ) {
                return 'e_invoice_context_contact_mismatch';
            }
            if ($this->unityId !== $eInvoiceContext->unityId) {
                return 'e_invoice_context_unity_mismatch';
            }
            $eInvoiceMismatch = $eInvoiceContext->remoteMismatch($remote);
            if ($eInvoiceMismatch !== null) {
                return $eInvoiceMismatch;
            }
        }

        $sumGross = $remote['sumGross'] ?? null;
        if (!is_string($sumGross) && !is_int($sumGross) && !is_float($sumGross)) {
            return 'total_missing';
        }
        try {
            if (Decimal::toMinorUnits((string) $sumGross) !== $invoice->totalMinorUnits()) {
                return 'total_mismatch';
            }
        } catch (\InvalidArgumentException) {
            return 'total_invalid';
        }

        return null;
    }

    private static function normaliseCountryCode(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['code'] ?? $value['countryCode'] ?? $value['shortCode'] ?? null;
        }
        if (!is_string($value)) {
            return null;
        }

        $countryCode = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $countryCode) === 1 ? $countryCode : null;
    }

    /**
     * @param array<array-key, mixed> $response
     * @param bool $discardNonArrayListMembers Preserves the historical recovery
     *     classification, which treated malformed list members as a count mismatch.
     */
    public function positionsMismatch(
        array $response,
        InvoiceSnapshot $invoice,
        string $remoteId,
        bool $discardNonArrayListMembers = false,
    ): ?string {
        if (array_is_list($response)) {
            $positions = $discardNonArrayListMembers
                ? array_values(array_filter($response, 'is_array'))
                : $response;
        } else {
            $positions = isset($response['invoicePos']) && is_array($response['invoicePos'])
                ? [$response['invoicePos']]
                : [];
        }
        if (count($positions) >= 1000) {
            return 'position_search_truncated';
        }
        if (count($positions) !== count($invoice->lineItems)) {
            return 'position_count_mismatch';
        }

        usort($positions, static fn (mixed $left, mixed $right): int =>
            (int) (is_array($left) ? ($left['positionNumber'] ?? 0) : 0)
            <=> (int) (is_array($right) ? ($right['positionNumber'] ?? 0) : 0));

        foreach ($invoice->lineItems as $index => $lineItem) {
            $position = $positions[$index] ?? null;
            if (!is_array($position)) {
                return 'position_invalid';
            }
            if (
                (string) ($position['objectName'] ?? '') !== 'InvoicePos'
                || (string) ($position['invoice']['id'] ?? '') !== $remoteId
                || (string) ($position['unity']['id'] ?? '') !== $this->unityId
                || (int) ($position['positionNumber'] ?? 0) !== $index + 1
                || abs((float) ($position['quantity'] ?? 0) - 1.0) > 0.0001
                || (string) ($position['name'] ?? '') !== mb_substr($lineItem->description, 0, 255)
                || (string) ($position['text'] ?? '') !== mb_substr($lineItem->description, 0, 1000)
            ) {
                return 'position_identity_mismatch';
            }

            $price = $position['price'] ?? null;
            $taxRate = $position['taxRate'] ?? null;
            if (
                (!is_string($price) && !is_int($price) && !is_float($price))
                || (!is_string($taxRate) && !is_int($taxRate) && !is_float($taxRate))
            ) {
                return 'position_amount_missing';
            }
            try {
                if (
                    Decimal::toMinorUnits((string) $price) !== Decimal::toMinorUnits($lineItem->amount)
                    || abs(Decimal::toFloat((string) $taxRate) - Decimal::toFloat($lineItem->taxRate)) > 0.0001
                ) {
                    return 'position_amount_mismatch';
                }
            } catch (\InvalidArgumentException) {
                return 'position_amount_invalid';
            }
        }

        return null;
    }

    public static function markerMatches(string $note, int $invoiceId): bool
    {
        preg_match_all('/\[WHMCS-INVOICE:([1-9]\d*)\]/', $note, $matches);
        $foundMarkers = $matches[0];

        return count($foundMarkers) === 1
            && $foundMarkers[0] === '[WHMCS-INVOICE:' . $invoiceId . ']';
    }

    private static function remoteBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
    }

    private static function sameDate(mixed $value, DateTimeImmutable $expected): bool
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $timestamp = (int) $value;
            if ($timestamp > 0) {
                return (new DateTimeImmutable('@' . $timestamp))
                    ->setTimezone($expected->getTimezone())
                    ->format('Y-m-d') === $expected->format('Y-m-d');
            }
        }
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        foreach (['!d.m.Y', '!Y-m-d', DATE_ATOM, 'Y-m-d\TH:i:sP'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if (
                $date instanceof DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format('Y-m-d') === $expected->format('Y-m-d')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }
}
