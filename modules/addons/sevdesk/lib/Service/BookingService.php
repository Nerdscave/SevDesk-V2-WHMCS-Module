<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use DateTimeImmutable;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;

/**
 * Previews and explicitly confirms one unambiguous bank-transaction booking.
 *
 * This service does not search by customer names or approximate amounts. The
 * WHMCS transaction reference, amount and account currency must all match, and
 * exactly one still-unbooked sevdesk transaction may remain.
 *
 * @phpstan-type BookingConfirmation array{
 *     reference: string,
 *     whmcsTransactionId: string,
 *     voucherId: string,
 *     transactionId: string,
 *     checkAccountId: string,
 *     amount: string,
 *     amountMinorUnits: int,
 *     currency: string,
 *     bookingDate: string,
 *     bookingType: 'FULL_PAYMENT'|'N',
 *     voucherPaidMinorUnits: int
 * }
 * @phpstan-type BookingResult array{
 *     status: 'ready'|'succeeded'|'blocked'|'failed'|'ambiguous',
 *     code: string,
 *     message: string,
 *     confirmation?: BookingConfirmation,
 *     context?: array<string, scalar|null>
 * }
 */
final class BookingService
{
    public function __construct(private readonly SevdeskClient $client)
    {
    }

    /**
     * @param array{
     *     kind: string,
     *     whmcsTransactionId: string,
     *     voucherId: int|string,
     *     amount: int|float|string,
     *     currency: string,
     *     bookingDate: string
     * } $request bookingDate must use YYYY-MM-DD.
     * @return BookingResult
     */
    public function preview(array $request): array
    {
        try {
            $payment = $this->normalisePayment($request);
        } catch (\InvalidArgumentException $exception) {
            return self::result('blocked', 'invalid_booking_request', $exception->getMessage());
        }

        if ($payment['kind'] !== 'payment') {
            return self::result(
                'blocked',
                'unsupported_payment_kind',
                'Refunds and chargebacks are never booked automatically.',
            );
        }

        try {
            $voucher = $this->readOne('/Voucher/' . rawurlencode($payment['voucherId']), 'voucher');
            $voucherState = $this->voucherState($voucher, $payment);
            if (!$voucherState['allowed']) {
                return self::result('blocked', $voucherState['code'], $voucherState['message']);
            }

            $accountCache = [];
            $candidates = $this->matchingTransactions($payment, $accountCache);
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'booking_preview_failed');
        }

        if (count($candidates) === 0) {
            return self::result(
                'blocked',
                'no_payment_candidate',
                'No unbooked sevdesk transaction matches reference, amount and currency.',
            );
        }
        if (count($candidates) > 1) {
            return self::result(
                'blocked',
                'multiple_payment_candidates',
                'Multiple unbooked sevdesk transactions match. Manual selection is required.',
                ['matchCount' => count($candidates)],
            );
        }

        $candidate = $candidates[0];
        $confirmation = [
            'whmcsTransactionId' => $payment['whmcsTransactionId'],
            'voucherId' => $payment['voucherId'],
            'transactionId' => $candidate['transactionId'],
            'checkAccountId' => $candidate['checkAccountId'],
            'amount' => $payment['amount'],
            'amountMinorUnits' => $payment['amountMinorUnits'],
            'currency' => $payment['currency'],
            'bookingDate' => $payment['bookingDate'],
            'bookingType' => $voucherState['bookingType'],
            'voucherPaidMinorUnits' => $voucherState['paidMinorUnits'],
        ];
        $confirmation['reference'] = self::confirmationReference($confirmation);

        return [
            'status' => 'ready',
            'code' => 'booking_ready',
            'message' => 'Exactly one matching unbooked transaction is ready for confirmation.',
            'confirmation' => ['reference' => $confirmation['reference']] + $confirmation,
        ];
    }

    /**
     * Revalidates the complete preview immediately before the remote write.
     *
     * @param array{
     *     reference: string,
     *     whmcsTransactionId: string,
     *     voucherId: int|string,
     *     transactionId: int|string,
     *     checkAccountId: int|string,
     *     amount: int|float|string,
     *     amountMinorUnits?: int,
     *     currency: string,
     *     bookingDate: string,
     *     bookingType: string,
     *     voucherPaidMinorUnits: int
     * } $confirmation The exact structure returned by preview().
     * @param null|callable(string, array<string, scalar|null>): (void|bool) $checkpoint
     * @return BookingResult
     */
    public function confirm(
        array $confirmation,
        bool $confirmed,
        ?callable $checkpoint = null,
    ): array {
        if (!$confirmed) {
            return self::result(
                'blocked',
                'confirmation_required',
                'The booking must be explicitly confirmed before sevdesk is changed.',
            );
        }

        try {
            $booking = $this->normaliseConfirmation($confirmation);
        } catch (\InvalidArgumentException $exception) {
            return self::result('blocked', 'invalid_confirmation', $exception->getMessage());
        }

        $expectedReference = self::confirmationReference($booking);
        if (!hash_equals($expectedReference, $booking['reference'])) {
            return self::result(
                'blocked',
                'confirmation_changed',
                'The booking preview changed and must be generated again.',
            );
        }

        $payment = [
            'kind' => 'payment',
            'whmcsTransactionId' => $booking['whmcsTransactionId'],
            'voucherId' => $booking['voucherId'],
            'amount' => $booking['amount'],
            'amountMinorUnits' => $booking['amountMinorUnits'],
            'currency' => $booking['currency'],
            'bookingDate' => $booking['bookingDate'],
        ];

        try {
            // These reads are deliberately performed immediately before the
            // write. A stale preview is not authority to book changed objects.
            $voucher = $this->readOne('/Voucher/' . rawurlencode($booking['voucherId']), 'voucher');
            $voucherState = $this->voucherState($voucher, $payment);
            if (!$voucherState['allowed'] || $voucherState['bookingType'] !== $booking['bookingType']) {
                return self::result(
                    'blocked',
                    'voucher_changed_since_preview',
                    'The voucher balance or state changed. Generate a new preview.',
                );
            }
            if ($voucherState['paidMinorUnits'] !== $booking['voucherPaidMinorUnits']) {
                return self::result(
                    'blocked',
                    'voucher_payment_baseline_changed',
                    'The voucher paid amount changed. Generate a new preview.',
                );
            }

            $transaction = $this->readOne(
                '/CheckAccountTransaction/' . rawurlencode($booking['transactionId']),
                'checkAccountTransaction',
            );
            if (!$this->transactionMatches($transaction, $payment)) {
                return self::result(
                    'blocked',
                    'transaction_changed_since_preview',
                    'The selected bank transaction is no longer an exact unbooked match.',
                );
            }

            $transactionId = self::numericId($transaction['id'] ?? null);
            $checkAccountId = self::numericId($transaction['checkAccount']['id'] ?? null);
            if (
                $transactionId !== $booking['transactionId']
                || $checkAccountId !== $booking['checkAccountId']
            ) {
                return self::result(
                    'blocked',
                    'transaction_changed_since_preview',
                    'The selected transaction or account changed. Generate a new preview.',
                );
            }

            $accountCache = [];
            $account = $this->readOne(
                '/CheckAccount/' . rawurlencode($booking['checkAccountId']),
                'checkAccount',
            );
            if (!$this->accountUsesCurrency($account, $booking['checkAccountId'], $booking['currency'])) {
                return self::result(
                    'blocked',
                    'account_currency_changed',
                    'The bank account currency no longer matches the WHMCS payment.',
                );
            }
            $accountCache[$booking['checkAccountId']] = $account;

            // Uniqueness is rechecked as well. A second matching transaction
            // imported after preview must stop the booking.
            $candidates = $this->matchingTransactions($payment, $accountCache);
            if (
                count($candidates) !== 1
                || $candidates[0]['transactionId'] !== $booking['transactionId']
            ) {
                return self::result(
                    'blocked',
                    count($candidates) === 0
                        ? 'payment_candidate_disappeared'
                        : 'payment_candidates_changed',
                    'The set of matching transactions changed. Generate a new preview.',
                    ['matchCount' => count($candidates)],
                );
            }
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'booking_revalidation_failed');
        }

        $checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);
        if (
            !$this->emitCheckpoint($checkpoint, 'booking_write_requested', [
            'voucherId' => $booking['voucherId'],
            'transactionId' => $booking['transactionId'],
            ])
        ) {
            return self::result(
                'failed',
                'checkpoint_persist_failed',
                'The booking checkpoint could not be stored; no API write was attempted.',
            );
        }

        try {
            $response = $this->client->put(
                '/Voucher/' . rawurlencode($booking['voucherId']) . '/bookAmount',
                [
                    'amount' => Decimal::toFloat($booking['amount']),
                    'date' => self::apiDate($booking['bookingDate']),
                    'type' => $booking['bookingType'],
                    'checkAccount' => [
                        'id' => self::payloadId($booking['checkAccountId']),
                        'objectName' => 'CheckAccount',
                    ],
                    'checkAccountTransaction' => [
                        'id' => self::payloadId($booking['transactionId']),
                        'objectName' => 'CheckAccountTransaction',
                    ],
                    'createFeed' => false,
                ],
                true,
                [200],
            );
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'booking_write_failed');
        }

        if (!self::validBookingResponse($response, $booking['voucherId'])) {
            return self::result(
                'ambiguous',
                'booking_response_ambiguous',
                'sevdesk accepted the request but returned no verifiable booking log. Reconcile before retrying.',
                ['voucherId' => $booking['voucherId'], 'transactionId' => $booking['transactionId']],
            );
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'booking_completed', [
            'voucherId' => $booking['voucherId'],
            'transactionId' => $booking['transactionId'],
            ])
        ) {
            return self::result(
                'ambiguous',
                'checkpoint_persist_failed_after_booking',
                'The booking may be complete, but its result checkpoint could not be stored. '
                    . 'Reconcile before retrying.',
                ['voucherId' => $booking['voucherId'], 'transactionId' => $booking['transactionId']],
            );
        }

        return self::result(
            'succeeded',
            'booking_completed',
            'The sevdesk voucher was booked against the unique bank transaction.',
            [
                'voucherId' => $booking['voucherId'],
                'transactionId' => $booking['transactionId'],
                'bookingType' => $booking['bookingType'],
            ],
        );
    }

    /**
     * Reconciles an unknown bookAmount result without issuing another write.
     *
     * @param array<string, mixed> $confirmation
     * @return BookingResult
     */
    public function reconcile(array $confirmation): array
    {
        try {
            $booking = $this->normaliseConfirmation($confirmation);
        } catch (\InvalidArgumentException $exception) {
            return self::result('blocked', 'invalid_confirmation', $exception->getMessage());
        }

        if (!hash_equals(self::confirmationReference($booking), $booking['reference'])) {
            return self::result('blocked', 'confirmation_changed', 'The stored booking confirmation changed.');
        }

        try {
            $voucher = $this->readOne('/Voucher/' . rawurlencode($booking['voucherId']), 'voucher');
            $transaction = $this->readOne(
                '/CheckAccountTransaction/' . rawurlencode($booking['transactionId']),
                'checkAccountTransaction',
            );
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'booking_reconciliation_failed');
        }

        if (
            self::numericId($voucher['id'] ?? null) !== $booking['voucherId']
            || strtoupper((string) ($voucher['currency'] ?? '')) !== $booking['currency']
            || !$this->transactionIdentityMatches($transaction, $booking)
        ) {
            return self::result(
                'ambiguous',
                'booking_reconciliation_identity_mismatch',
                'Voucher or transaction identity changed; the booking must be checked manually.',
            );
        }

        $paid = $voucher['paidAmount'] ?? null;
        if (!is_int($paid) && !is_float($paid) && !is_string($paid)) {
            return self::result(
                'ambiguous',
                'booking_reconciliation_amount_missing',
                'sevdesk returned no verifiable paid amount for the voucher.',
            );
        }
        try {
            $paidMinorUnits = Decimal::toMinorUnits((string) $paid);
        } catch (\InvalidArgumentException) {
            return self::result(
                'ambiguous',
                'booking_reconciliation_amount_invalid',
                'sevdesk returned an invalid paid amount for the voucher.',
            );
        }

        $transactionStatus = (int) ($transaction['status'] ?? 0);
        $expectedPaid = $booking['voucherPaidMinorUnits'] + $booking['amountMinorUnits'];
        if (in_array($transactionStatus, [200, 400], true) && $paidMinorUnits === $expectedPaid) {
            return self::result(
                'succeeded',
                'booking_reconciled',
                'The linked transaction and voucher paid amount prove that the booking succeeded.',
                ['voucherId' => $booking['voucherId'], 'transactionId' => $booking['transactionId']],
            );
        }
        if ($transactionStatus === 100 && $paidMinorUnits === $booking['voucherPaidMinorUnits']) {
            return self::result(
                'blocked',
                'booking_not_applied',
                'The transaction is still unlinked and the voucher paid amount is unchanged. Create a new preview before retrying.',
            );
        }

        return self::result(
            'ambiguous',
            'booking_reconciliation_inconclusive',
            'The current voucher and transaction state does not prove one unique outcome.',
            ['transactionStatus' => $transactionStatus, 'paidMinorUnits' => $paidMinorUnits],
        );
    }

    /**
     * @param array<string, mixed> $request
     * @return array{
     *     kind: string,
     *     whmcsTransactionId: string,
     *     voucherId: string,
     *     amount: string,
     *     amountMinorUnits: int,
     *     currency: string,
     *     bookingDate: string
     * }
     */
    private function normalisePayment(array $request): array
    {
        $kind = strtolower(trim((string) ($request['kind'] ?? '')));
        if (!in_array($kind, ['payment', 'refund', 'chargeback'], true)) {
            throw new \InvalidArgumentException('The transaction kind must be payment, refund or chargeback.');
        }

        $whmcsTransactionId = trim((string) preg_replace(
            '/[\x00-\x1F\x7F]+/',
            ' ',
            (string) ($request['whmcsTransactionId'] ?? ''),
        ));
        if ($whmcsTransactionId === '' || strlen($whmcsTransactionId) > 160) {
            throw new \InvalidArgumentException('A valid WHMCS transaction ID is required.');
        }

        $voucherId = self::numericId($request['voucherId'] ?? null);
        if ($voucherId === null) {
            throw new \InvalidArgumentException('A numeric sevdesk voucher ID is required.');
        }

        $amountValue = $request['amount'] ?? null;
        if (!is_int($amountValue) && !is_float($amountValue) && !is_string($amountValue)) {
            throw new \InvalidArgumentException('A decimal payment amount is required.');
        }
        $amount = Decimal::assert((string) $amountValue, 'Payment amount');
        $amountMinorUnits = Decimal::toMinorUnits($amount);
        if ($amountMinorUnits <= 0) {
            throw new \InvalidArgumentException('The payment amount must be positive.');
        }

        $currency = strtoupper(trim((string) ($request['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('A three-letter payment currency is required.');
        }

        $bookingDate = self::normaliseDate((string) ($request['bookingDate'] ?? ''));

        return [
            'kind' => $kind,
            'whmcsTransactionId' => $whmcsTransactionId,
            'voucherId' => $voucherId,
            'amount' => $amount,
            'amountMinorUnits' => $amountMinorUnits,
            'currency' => $currency,
            'bookingDate' => $bookingDate,
        ];
    }

    /**
     * @param array<string, mixed> $confirmation
     * @return array{
     *     reference: string,
     *     whmcsTransactionId: string,
     *     voucherId: string,
     *     transactionId: string,
     *     checkAccountId: string,
     *     amount: string,
     *     amountMinorUnits: int,
     *     currency: string,
     *     bookingDate: string,
     *     bookingType: 'FULL_PAYMENT'|'N',
     *     voucherPaidMinorUnits: int
     * }
     */
    private function normaliseConfirmation(array $confirmation): array
    {
        $payment = $this->normalisePayment([
            'kind' => 'payment',
            'whmcsTransactionId' => $confirmation['whmcsTransactionId'] ?? null,
            'voucherId' => $confirmation['voucherId'] ?? null,
            'amount' => $confirmation['amount'] ?? null,
            'currency' => $confirmation['currency'] ?? null,
            'bookingDate' => $confirmation['bookingDate'] ?? null,
        ]);

        $transactionId = self::numericId($confirmation['transactionId'] ?? null);
        $checkAccountId = self::numericId($confirmation['checkAccountId'] ?? null);
        if ($transactionId === null || $checkAccountId === null) {
            throw new \InvalidArgumentException('The preview contains invalid sevdesk object IDs.');
        }

        $reference = strtolower(trim((string) ($confirmation['reference'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $reference) !== 1) {
            throw new \InvalidArgumentException('The preview confirmation reference is invalid.');
        }

        $bookingType = (string) ($confirmation['bookingType'] ?? '');
        if (!in_array($bookingType, ['FULL_PAYMENT', 'N'], true)) {
            throw new \InvalidArgumentException('The preview booking type is invalid.');
        }
        $voucherPaidMinorUnits = $confirmation['voucherPaidMinorUnits'] ?? null;
        if (!is_int($voucherPaidMinorUnits) || $voucherPaidMinorUnits < 0) {
            throw new \InvalidArgumentException('The preview contains no valid voucher payment baseline.');
        }

        return [
            'reference' => $reference,
            'whmcsTransactionId' => $payment['whmcsTransactionId'],
            'voucherId' => $payment['voucherId'],
            'transactionId' => $transactionId,
            'checkAccountId' => $checkAccountId,
            'amount' => $payment['amount'],
            'amountMinorUnits' => $payment['amountMinorUnits'],
            'currency' => $payment['currency'],
            'bookingDate' => $payment['bookingDate'],
            'bookingType' => $bookingType,
            'voucherPaidMinorUnits' => $voucherPaidMinorUnits,
        ];
    }

    /**
     * @param array<string, mixed> $voucher
     * @param array{voucherId: string, amountMinorUnits: int, currency: string} $payment
     * @return array{allowed: bool, code: string, message: string, bookingType: 'FULL_PAYMENT'|'N',paidMinorUnits:int}
     */
    private function voucherState(array $voucher, array $payment): array
    {
        if (self::numericId($voucher['id'] ?? null) !== $payment['voucherId']) {
            return [
                'allowed' => false,
                'code' => 'voucher_id_mismatch',
                'message' => 'sevdesk returned a different voucher than requested.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }
        if (!in_array((string) ($voucher['status'] ?? ''), ['100', '750'], true)) {
            return [
                'allowed' => false,
                'code' => 'voucher_not_open',
                'message' => 'Only an open or partially paid voucher can be booked.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }
        if (strtoupper((string) ($voucher['currency'] ?? '')) !== $payment['currency']) {
            return [
                'allowed' => false,
                'code' => 'voucher_currency_mismatch',
                'message' => 'The voucher and WHMCS payment use different currencies.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }

        $gross = $voucher['sumGross'] ?? null;
        $paid = $voucher['paidAmount'] ?? '0';
        if (
            (!is_int($gross) && !is_float($gross) && !is_string($gross))
            || (!is_int($paid) && !is_float($paid) && !is_string($paid))
        ) {
            return [
                'allowed' => false,
                'code' => 'invalid_voucher_amounts',
                'message' => 'sevdesk returned no verifiable voucher balance.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }

        try {
            $grossMinor = Decimal::toMinorUnits((string) $gross);
            $paidMinor = $paid === '' ? 0 : Decimal::toMinorUnits((string) $paid);
        } catch (\InvalidArgumentException) {
            return [
                'allowed' => false,
                'code' => 'invalid_voucher_amounts',
                'message' => 'sevdesk returned invalid voucher amounts.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }

        $remaining = $grossMinor - $paidMinor;
        if (
            $grossMinor <= 0
            || $paidMinor < 0
            || $paidMinor > $grossMinor
            || $remaining <= 0
            || $payment['amountMinorUnits'] > $remaining
        ) {
            return [
                'allowed' => false,
                'code' => 'payment_exceeds_open_amount',
                'message' => 'The payment does not fit the voucher\'s remaining open amount.',
                'bookingType' => 'N',
                'paidMinorUnits' => $paidMinor,
            ];
        }

        return [
            'allowed' => true,
            'code' => 'voucher_bookable',
            'message' => 'The voucher is open and the payment fits its balance.',
            'bookingType' => $payment['amountMinorUnits'] === $remaining ? 'FULL_PAYMENT' : 'N',
            'paidMinorUnits' => $paidMinor,
        ];
    }

    /**
     * @param array{
     *     whmcsTransactionId: string,
     *     amountMinorUnits: int,
     *     currency: string
     * } $payment
     * @param array<string, array<string, mixed>> $accountCache
     * @return list<array{transactionId: string, checkAccountId: string}>
     */
    private function matchingTransactions(array $payment, array &$accountCache): array
    {
        $response = $this->client->get('/CheckAccountTransaction', [
            'isBooked' => 'false',
            'paymtPurpose' => $payment['whmcsTransactionId'],
            'onlyCredit' => 'true',
            'limit' => 101,
        ]);

        $matches = [];
        foreach (self::records($response, 'checkAccountTransaction') as $transaction) {
            if (!$this->transactionMatches($transaction, $payment)) {
                continue;
            }

            $transactionId = self::numericId($transaction['id'] ?? null);
            $checkAccountId = self::numericId($transaction['checkAccount']['id'] ?? null);
            if ($transactionId === null || $checkAccountId === null) {
                continue;
            }

            if (!isset($accountCache[$checkAccountId])) {
                $accountCache[$checkAccountId] = $this->readOne(
                    '/CheckAccount/' . rawurlencode($checkAccountId),
                    'checkAccount',
                );
            }
            if (
                !$this->accountUsesCurrency(
                    $accountCache[$checkAccountId],
                    $checkAccountId,
                    $payment['currency'],
                )
            ) {
                continue;
            }

            $matches[] = [
                'transactionId' => $transactionId,
                'checkAccountId' => $checkAccountId,
            ];
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array{whmcsTransactionId: string, amountMinorUnits: int} $payment
     */
    private function transactionMatches(array $transaction, array $payment): bool
    {
        return (string) ($transaction['status'] ?? '') === '100'
            && $this->transactionIdentityMatches($transaction, $payment);
    }

    /**
     * @param array<string, mixed> $transaction
     * @param array{whmcsTransactionId:string,amountMinorUnits:int,transactionId?:string,checkAccountId?:string} $payment
     */
    private function transactionIdentityMatches(array $transaction, array $payment): bool
    {
        if (
            isset($payment['transactionId'])
            && self::numericId($transaction['id'] ?? null) !== $payment['transactionId']
        ) {
            return false;
        }
        if (
            isset($payment['checkAccountId'])
            && self::numericId($transaction['checkAccount']['id'] ?? null) !== $payment['checkAccountId']
        ) {
            return false;
        }

        $purpose = $transaction['paymtPurpose'] ?? null;
        if (!is_string($purpose) || !str_contains($purpose, $payment['whmcsTransactionId'])) {
            return false;
        }

        $amount = $transaction['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            return false;
        }

        try {
            return Decimal::toMinorUnits((string) $amount) === $payment['amountMinorUnits'];
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @param array<string, mixed> $account */
    private function accountUsesCurrency(array $account, string $accountId, string $currency): bool
    {
        return self::numericId($account['id'] ?? null) === $accountId
            && strtoupper((string) ($account['currency'] ?? '')) === $currency
            && (!array_key_exists('status', $account) || (string) $account['status'] === '100');
    }

    /** @return array<string, mixed> */
    private function readOne(string $path, string $nestedKey): array
    {
        $records = self::records($this->client->get($path), $nestedKey);
        if (count($records) !== 1) {
            throw new ApiException(
                'sevdesk returned no unique object for a required booking read.',
                null,
                'unexpected_booking_response',
            );
        }

        return $records[0];
    }

    /**
     * @param array<array-key, mixed> $response
     * @return list<array<string, mixed>>
     */
    private static function records(array $response, string $nestedKey): array
    {
        if ($response === []) {
            return [];
        }

        $records = array_is_list($response) ? $response : [$response];
        $result = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (isset($record[$nestedKey]) && is_array($record[$nestedKey])) {
                $record = $record[$nestedKey];
            }
            $result[] = $record;
        }

        return $result;
    }

    /** @param array<string, mixed> $confirmation */
    private static function confirmationReference(array $confirmation): string
    {
        return hash('sha256', implode('|', [
            'booking-v1',
            $confirmation['whmcsTransactionId'],
            $confirmation['voucherId'],
            $confirmation['transactionId'],
            $confirmation['checkAccountId'],
            (string) $confirmation['amountMinorUnits'],
            $confirmation['currency'],
            $confirmation['bookingDate'],
            $confirmation['bookingType'],
            (string) $confirmation['voucherPaidMinorUnits'],
        ]));
    }

    /** @param array<array-key, mixed> $response */
    private static function validBookingResponse(array $response, string $voucherId): bool
    {
        $records = self::records($response, 'voucherLog');
        if (count($records) !== 1) {
            return false;
        }
        $log = $records[0];
        if (self::numericId($log['id'] ?? null) === null) {
            return false;
        }

        $objectName = (string) ($log['objectName'] ?? '');
        $responseVoucherId = self::numericId($log['voucher']['id'] ?? null);

        return $objectName === 'VoucherLog' && $responseVoucherId === $voucherId;
    }

    private static function normaliseDate(string $date): string
    {
        $date = trim($date);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new \InvalidArgumentException('The booking date must use YYYY-MM-DD.');
        }

        return $date;
    }

    private static function apiDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('d.m.Y') : $date;
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $id) === 1 ? $id : null;
    }

    private static function payloadId(string $id): int|string
    {
        return ctype_digit($id) && strlen($id) < 19 ? (int) $id : $id;
    }

    /**
     * @param null|Closure(string, array<string, scalar>): (void|bool) $checkpoint
     * @param array<string, scalar> $context
     */
    private function emitCheckpoint(?Closure $checkpoint, string $name, array $context): bool
    {
        if ($checkpoint === null) {
            return true;
        }

        try {
            return $checkpoint($name, $context) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return BookingResult
     */
    private function apiFailure(ApiException $exception, string $code): array
    {
        $ambiguous = $exception->outcomeUnknown;

        return self::result(
            $ambiguous ? 'ambiguous' : 'failed',
            $ambiguous ? $code . '_ambiguous' : $code,
            $ambiguous
                ? 'The booking write may have succeeded. Reconcile voucher and transaction before retrying.'
                : 'The sevdesk booking request could not be completed.',
            $exception->context(),
        );
    }

    /**
     * @param 'ready'|'succeeded'|'blocked'|'failed'|'ambiguous' $status
     * @param array<string, scalar|null> $context
     * @return BookingResult
     */
    private static function result(
        string $status,
        string $code,
        string $message,
        array $context = [],
    ): array {
        $result = ['status' => $status, 'code' => $code, 'message' => $message];
        if ($context !== []) {
            $result['context'] = $context;
        }

        return $result;
    }
}
