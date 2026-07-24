<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use DateTimeImmutable;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;

/** Exact, read-only verification shared by Voucher creation and recovery. */
final class VoucherRemoteVerifier
{
    /**
     * @param array<array-key, mixed> $response
     */
    public function voucherMismatch(
        array $response,
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        string $taxRuleId,
        string $accountDatevId,
        int $expectedStatus = 100,
        ?string $expectedRemoteId = null,
    ): ?string {
        if (
            self::numericId($sevdeskContactId) === null
            || self::numericId($taxRuleId) === null
            || self::numericId($accountDatevId) === null
        ) {
            return 'verification_context_invalid';
        }

        $remote = self::singleVoucher($response);
        if ($remote === null) {
            return 'response_invalid';
        }

        $actualRemoteId = self::numericId($remote['id'] ?? null);
        if ($actualRemoteId === null) {
            return 'id_invalid';
        }
        if ($expectedRemoteId !== null && $actualRemoteId !== $expectedRemoteId) {
            return 'id_mismatch';
        }
        if ((string) ($remote['objectName'] ?? '') !== 'Voucher') {
            return 'type_mismatch';
        }
        if ((string) ($remote['voucherType'] ?? '') !== 'VOU') {
            return 'voucher_type_mismatch';
        }
        if ((string) ($remote['creditDebit'] ?? '') !== 'D') {
            return 'credit_debit_mismatch';
        }
        if ((int) ($remote['status'] ?? 0) !== $expectedStatus) {
            return 'status_mismatch';
        }
        if (!self::sameDate($remote['voucherDate'] ?? null, $invoice->invoiceDate)) {
            return 'date_mismatch';
        }
        if (strtoupper(trim((string) ($remote['currency'] ?? ''))) !== $invoice->currency) {
            return 'currency_mismatch';
        }
        if ((string) ($remote['supplier']['id'] ?? '') !== $sevdeskContactId) {
            return 'contact_mismatch';
        }
        if ((string) ($remote['taxRule']['id'] ?? '') !== $taxRuleId) {
            return 'tax_rule_mismatch';
        }
        if (!self::markerMatches((string) ($remote['description'] ?? ''), $invoice->invoiceId)) {
            return 'marker_mismatch';
        }

        $sumGross = $remote['sumGross'] ?? null;
        if (!is_string($sumGross) && !is_int($sumGross) && !is_float($sumGross)) {
            return 'total_missing';
        }
        try {
            // Voucher totals historically allow the documented one-cent WHMCS
            // rounding difference. Position amounts below remain exact.
            if (abs(Decimal::toMinorUnits((string) $sumGross) - $invoice->totalMinorUnits()) > 1) {
                return 'total_mismatch';
            }
        } catch (\InvalidArgumentException) {
            return 'total_invalid';
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $response
     */
    public function positionsMismatch(
        array $response,
        InvoiceSnapshot $invoice,
        string $remoteId,
        string $accountDatevId,
    ): ?string {
        if (
            self::numericId($remoteId) === null
            || self::numericId($accountDatevId) === null
        ) {
            return 'position_context_invalid';
        }

        $positions = self::voucherPositions($response);
        if ($positions === null) {
            return 'position_response_invalid';
        }
        if (count($positions) >= 1000) {
            return 'position_search_truncated';
        }
        if (count($positions) !== count($invoice->lineItems)) {
            return 'position_count_mismatch';
        }

        $actual = [];
        foreach ($positions as $position) {
            if (
                (string) ($position['objectName'] ?? '') !== 'VoucherPos'
                || (string) ($position['voucher']['id'] ?? '') !== $remoteId
                || (string) ($position['voucher']['objectName'] ?? '') !== 'Voucher'
                || (string) ($position['accountDatev']['id'] ?? '') !== $accountDatevId
                || (string) ($position['accountDatev']['objectName'] ?? '') !== 'AccountDatev'
            ) {
                return 'position_identity_mismatch';
            }

            $net = self::remoteBoolean($position['net'] ?? null);
            $taxRate = $position['taxRate'] ?? null;
            if (
                $net === null
                || (!is_string($taxRate) && !is_int($taxRate) && !is_float($taxRate))
            ) {
                return 'position_amount_missing';
            }
            $amount = $position[$net ? 'sumNet' : 'sumGross'] ?? null;
            if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
                return 'position_amount_missing';
            }

            try {
                $actual[] = self::positionSignature(
                    (string) ($position['comment'] ?? ''),
                    Decimal::toMinorUnits((string) $amount),
                    Decimal::toFloat((string) $taxRate),
                    $net,
                );
            } catch (\InvalidArgumentException) {
                return 'position_amount_invalid';
            }
        }

        $expected = [];
        foreach ($invoice->lineItems as $lineItem) {
            try {
                $expected[] = self::positionSignature(
                    substr($lineItem->description, 0, 255),
                    Decimal::toMinorUnits($lineItem->amount),
                    Decimal::toFloat($lineItem->taxRate),
                    $lineItem->net,
                );
            } catch (\InvalidArgumentException) {
                return 'position_expected_invalid';
            }
        }

        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected ? null : 'position_mismatch';
    }

    public static function markerMatches(string $description, int $invoiceId): bool
    {
        preg_match_all('/\[WHMCS-INVOICE:([1-9]\d*)\]/', $description, $matches);
        $foundMarkers = $matches[0];

        return count($foundMarkers) === 1
            && $foundMarkers[0] === VoucherExporter::marker($invoiceId);
    }

    /** @param array<array-key, mixed> $candidate */
    public static function remoteId(array $candidate): ?string
    {
        $candidate = isset($candidate['voucher']) && is_array($candidate['voucher'])
            ? $candidate['voucher']
            : $candidate;

        return self::numericId($candidate['id'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>|null
     */
    private static function singleVoucher(array $response): ?array
    {
        if (isset($response['voucher']) && is_array($response['voucher'])) {
            $response = $response['voucher'];
        }
        if (array_is_list($response)) {
            if (count($response) !== 1 || !is_array($response[0])) {
                return null;
            }
            $response = $response[0];
            if (isset($response['voucher']) && is_array($response['voucher'])) {
                $response = $response['voucher'];
            }
        }

        return $response !== [] ? $response : null;
    }

    /**
     * @param array<array-key, mixed> $response
     * @return list<array<array-key, mixed>>|null
     */
    private static function voucherPositions(array $response): ?array
    {
        if (!array_is_list($response)) {
            $wrapped = $response['voucherPos'] ?? null;
            if (!is_array($wrapped)) {
                return null;
            }
            $response = array_is_list($wrapped) ? $wrapped : [$wrapped];
        }

        $positions = [];
        foreach ($response as $position) {
            if (!is_array($position)) {
                return null;
            }
            $positions[] = $position;
        }

        return $positions;
    }

    private static function positionSignature(
        string $comment,
        int $amountMinorUnits,
        float $taxRate,
        bool $net,
    ): string {
        return hash('sha256', implode("\0", [
            $comment,
            (string) $amountMinorUnits,
            number_format($taxRate, 4, '.', ''),
            $net ? '1' : '0',
        ]));
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

    private static function remoteBoolean(mixed $value): ?bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => null,
        };
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
