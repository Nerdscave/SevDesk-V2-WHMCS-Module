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
 *     documentType: 'voucher'|'invoice',
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
    private const TRANSACTION_SEARCH_LIMIT = 1000;

    public function __construct(private readonly SevdeskClient $client)
    {
    }

    /**
     * @param array{
     *     kind: string,
     *     documentType: string,
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
        $documentTypeFailure = self::documentTypeFailure($request['documentType'] ?? null);
        if ($documentTypeFailure !== null) {
            return $documentTypeFailure;
        }

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
            $document = $this->readOne(
                self::documentPath($payment['documentType'], $payment['voucherId']),
                $payment['documentType'],
            );
            $documentState = $this->documentState($document, $payment);
            if (!$documentState['allowed']) {
                return self::result('blocked', $documentState['code'], $documentState['message']);
            }

            $accountCache = [];
            $search = $this->matchingTransactions($payment, $accountCache);
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'booking_preview_failed');
        }
        if ($search['truncated']) {
            return self::result(
                'blocked',
                'payment_candidate_search_truncated',
                'The sevdesk transaction search reached its safe page limit; uniqueness cannot be proven.',
            );
        }
        $candidates = $search['matches'];

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
            'documentType' => $payment['documentType'],
            'whmcsTransactionId' => $payment['whmcsTransactionId'],
            'voucherId' => $payment['voucherId'],
            'transactionId' => $candidate['transactionId'],
            'checkAccountId' => $candidate['checkAccountId'],
            'amount' => $payment['amount'],
            'amountMinorUnits' => $payment['amountMinorUnits'],
            'currency' => $payment['currency'],
            'bookingDate' => $payment['bookingDate'],
            'bookingType' => $documentState['bookingType'],
            'voucherPaidMinorUnits' => $documentState['paidMinorUnits'],
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
     *     documentType: string,
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

        $documentTypeFailure = self::documentTypeFailure($confirmation['documentType'] ?? null);
        if ($documentTypeFailure !== null) {
            return $documentTypeFailure;
        }

        try {
            $booking = $this->normaliseConfirmation($confirmation);
        } catch (\InvalidArgumentException $exception) {
            return self::result('blocked', 'invalid_confirmation', $exception->getMessage());
        }

        $expectedReference = self::usesLegacyVoucherReference($confirmation)
            ? self::legacyVoucherConfirmationReference($booking)
            : self::confirmationReference($booking);
        if (!hash_equals($expectedReference, $booking['reference'])) {
            return self::result(
                'blocked',
                'confirmation_changed',
                'The booking preview changed and must be generated again.',
            );
        }

        $payment = [
            'kind' => 'payment',
            'documentType' => $booking['documentType'],
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
            $document = $this->readOne(
                self::documentPath($booking['documentType'], $booking['voucherId']),
                $booking['documentType'],
            );
            $documentState = $this->documentState($document, $payment);
            if (!$documentState['allowed'] || $documentState['bookingType'] !== $booking['bookingType']) {
                return self::result(
                    'blocked',
                    $booking['documentType'] . '_changed_since_preview',
                    'The sevdesk document balance or state changed. Generate a new preview.',
                );
            }
            if ($documentState['paidMinorUnits'] !== $booking['voucherPaidMinorUnits']) {
                return self::result(
                    'blocked',
                    $booking['documentType'] . '_payment_baseline_changed',
                    'The sevdesk document paid amount changed. Generate a new preview.',
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
            $search = $this->matchingTransactions($payment, $accountCache);
            if ($search['truncated']) {
                return self::result(
                    'blocked',
                    'payment_candidate_search_truncated',
                    'The sevdesk transaction search reached its safe page limit; uniqueness cannot be proven.',
                );
            }
            $candidates = $search['matches'];
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
                'documentType' => $booking['documentType'],
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
                self::documentPath($booking['documentType'], $booking['voucherId']) . '/bookAmount',
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

        if (!self::validBookingResponse($response, $booking['documentType'], $booking['voucherId'])) {
            return self::result(
                'ambiguous',
                'booking_response_ambiguous',
                'sevdesk accepted the request but returned no verifiable booking log. Reconcile before retrying.',
                ['voucherId' => $booking['voucherId'], 'transactionId' => $booking['transactionId']],
            );
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'booking_completed', [
                'documentType' => $booking['documentType'],
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
            'The sevdesk document was booked against the unique bank transaction.',
            [
                'documentType' => $booking['documentType'],
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
        $documentTypeFailure = self::documentTypeFailure($confirmation['documentType'] ?? null);
        if ($documentTypeFailure !== null) {
            return $documentTypeFailure;
        }

        try {
            $booking = $this->normaliseConfirmation($confirmation);
        } catch (\InvalidArgumentException $exception) {
            return self::result('blocked', 'invalid_confirmation', $exception->getMessage());
        }

        $expectedReference = self::usesLegacyVoucherReference($confirmation)
            ? self::legacyVoucherConfirmationReference($booking)
            : self::confirmationReference($booking);
        if (!hash_equals($expectedReference, $booking['reference'])) {
            return self::result('blocked', 'confirmation_changed', 'The stored booking confirmation changed.');
        }

        try {
            $document = $this->readOne(
                self::documentPath($booking['documentType'], $booking['voucherId']),
                $booking['documentType'],
            );
            $transaction = $this->readOne(
                '/CheckAccountTransaction/' . rawurlencode($booking['transactionId']),
                'checkAccountTransaction',
            );
        } catch (ApiException $exception) {
            return $this->apiFailure($exception, 'booking_reconciliation_failed');
        }

        if (
            self::numericId($document['id'] ?? null) !== $booking['voucherId']
            || strtoupper((string) ($document['currency'] ?? '')) !== $booking['currency']
            || !$this->transactionIdentityMatches($transaction, $booking)
        ) {
            return self::result(
                'ambiguous',
                'booking_reconciliation_identity_mismatch',
                'Document or transaction identity changed; the booking must be checked manually.',
            );
        }

        $paid = $document['paidAmount'] ?? null;
        if (!is_int($paid) && !is_float($paid) && !is_string($paid)) {
            return self::result(
                'ambiguous',
                'booking_reconciliation_amount_missing',
                'sevdesk returned no verifiable paid amount for the document.',
            );
        }
        try {
            $paidMinorUnits = Decimal::toMinorUnits((string) $paid);
        } catch (\InvalidArgumentException) {
            return self::result(
                'ambiguous',
                'booking_reconciliation_amount_invalid',
                'sevdesk returned an invalid paid amount for the document.',
            );
        }

        $transactionStatus = (int) ($transaction['status'] ?? 0);
        $expectedPaid = $booking['voucherPaidMinorUnits'] + $booking['amountMinorUnits'];
        if (in_array($transactionStatus, [200, 400], true) && $paidMinorUnits === $expectedPaid) {
            return self::result(
                'ambiguous',
                'booking_reconciliation_link_unprovable',
                'The document amount and transaction status are compatible with the booking, but sevdesk '
                    . 'does not expose their concrete relation through a read endpoint.',
                [
                    'documentType' => $booking['documentType'],
                    'voucherId' => $booking['voucherId'],
                    'transactionId' => $booking['transactionId'],
                    'transactionStatus' => $transactionStatus,
                    'paidMinorUnits' => $paidMinorUnits,
                ],
            );
        }
        if ($transactionStatus === 100 && $paidMinorUnits === $booking['voucherPaidMinorUnits']) {
            return self::result(
                'blocked',
                'booking_not_applied',
                'The transaction is still unlinked and the document paid amount is unchanged. '
                    . 'Create a new preview before retrying.',
            );
        }

        return self::result(
            'ambiguous',
            'booking_reconciliation_inconclusive',
            'The current document and transaction state does not prove one unique outcome.',
            ['transactionStatus' => $transactionStatus, 'paidMinorUnits' => $paidMinorUnits],
        );
    }

    /**
     * Upgrade exactly the Voucher-only booking-v1 snapshot written by release
     * 2.0.0. The old signed reference proves the historical schema; a merely
     * missing documentType is never enough to infer Voucher.
     *
     * @param array<string,mixed> $confirmation
     * @return array<string,mixed>|null
     */
    public function upgradeLegacyVoucherConfirmation(array $confirmation): ?array
    {
        if (
            (isset($confirmation['documentType']) && trim((string) $confirmation['documentType']) !== '')
            || isset($confirmation['bookingSchema'])
        ) {
            return null;
        }

        $upgraded = $confirmation;
        $upgraded['documentType'] = 'voucher';
        try {
            $booking = $this->normaliseConfirmation($upgraded);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (!hash_equals(self::legacyVoucherConfirmationReference($booking), $booking['reference'])) {
            return null;
        }

        $upgraded['bookingSchema'] = 'booking-v1';

        return $upgraded;
    }

    /** @param array<string,mixed> $confirmation */
    public function confirmationIsAuthentic(array $confirmation): bool
    {
        try {
            $booking = $this->normaliseConfirmation($confirmation);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $expected = self::usesLegacyVoucherReference($confirmation)
            ? self::legacyVoucherConfirmationReference($booking)
            : self::confirmationReference($booking);

        return hash_equals($expected, $booking['reference']);
    }

    /**
     * @param array<string, mixed> $request
     * @return array{
     *     kind: string,
     *     documentType: 'voucher'|'invoice',
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
        $documentType = strtolower(trim((string) ($request['documentType'] ?? '')));
        if (!in_array($documentType, ['voucher', 'invoice'], true)) {
            throw new \InvalidArgumentException('A confirmed sevdesk document type is required.');
        }

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
            throw new \InvalidArgumentException('A numeric sevdesk document ID is required.');
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
            'documentType' => $documentType,
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
     *     documentType: 'voucher'|'invoice',
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
            'documentType' => $confirmation['documentType'] ?? null,
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
            throw new \InvalidArgumentException('The preview contains no valid document payment baseline.');
        }

        return [
            'reference' => $reference,
            'documentType' => $payment['documentType'],
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
     * @param array<string, mixed> $document
     * @param array{
     *     documentType: 'voucher'|'invoice',
     *     voucherId: string,
     *     amountMinorUnits: int,
     *     currency: string
     * } $payment
     * @return array{allowed: bool, code: string, message: string, bookingType: 'FULL_PAYMENT'|'N',paidMinorUnits:int}
     */
    private function documentState(array $document, array $payment): array
    {
        $type = $payment['documentType'];
        if (self::numericId($document['id'] ?? null) !== $payment['voucherId']) {
            return [
                'allowed' => false,
                'code' => $type . '_id_mismatch',
                'message' => 'sevdesk returned a different document than requested.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }
        $bookableStatuses = $type === 'invoice' ? ['200', '750'] : ['100', '750'];
        $status = (string) ($document['status'] ?? '');
        if ($status === '1000') {
            return [
                'allowed' => false,
                'code' => $type . '_already_paid',
                'message' => 'The sevdesk document is already fully paid.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }
        if (!in_array($status, $bookableStatuses, true)) {
            return [
                'allowed' => false,
                'code' => $type . '_not_open',
                'message' => 'Only an open or partially paid sevdesk document can be booked.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }
        if (strtoupper((string) ($document['currency'] ?? '')) !== $payment['currency']) {
            return [
                'allowed' => false,
                'code' => $type . '_currency_mismatch',
                'message' => 'The sevdesk document and WHMCS payment use different currencies.',
                'bookingType' => 'N',
                'paidMinorUnits' => 0,
            ];
        }

        $gross = $document['sumGross'] ?? null;
        $paid = $document['paidAmount'] ?? '0';
        if (
            (!is_int($gross) && !is_float($gross) && !is_string($gross))
            || (!is_int($paid) && !is_float($paid) && !is_string($paid))
        ) {
            return [
                'allowed' => false,
                'code' => 'invalid_' . $type . '_amounts',
                'message' => 'sevdesk returned no verifiable document balance.',
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
                'code' => 'invalid_' . $type . '_amounts',
                'message' => 'sevdesk returned invalid document amounts.',
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
                'message' => 'The payment does not fit the document\'s remaining open amount.',
                'bookingType' => 'N',
                'paidMinorUnits' => $paidMinor,
            ];
        }

        return [
            'allowed' => true,
            'code' => $type . '_bookable',
            'message' => 'The sevdesk document is open and the payment fits its balance.',
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
     * @return array{matches:list<array{transactionId: string, checkAccountId: string}>,truncated:bool}
     */
    private function matchingTransactions(array $payment, array &$accountCache): array
    {
        $response = $this->client->get('/CheckAccountTransaction', [
            'isBooked' => 'false',
            'paymtPurpose' => $payment['whmcsTransactionId'],
            'onlyCredit' => 'true',
            'limit' => self::TRANSACTION_SEARCH_LIMIT,
            'offset' => 0,
        ]);

        $records = self::records($response, 'checkAccountTransaction');
        if (count($records) >= self::TRANSACTION_SEARCH_LIMIT) {
            return ['matches' => [], 'truncated' => true];
        }

        $matches = [];
        foreach ($records as $transaction) {
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

        return ['matches' => $matches, 'truncated' => false];
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
        if (
            !is_string($purpose)
            || !self::purposeContainsExactReference($purpose, $payment['whmcsTransactionId'])
        ) {
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

    private static function purposeContainsExactReference(string $purpose, string $reference): bool
    {
        $purpose = trim($purpose);
        $reference = trim($reference);
        if ($purpose === '' || $reference === '') {
            return false;
        }

        // Bank purposes may add prose, but the immutable gateway reference must remain one complete token.
        return preg_match(
            '/(?:^|\s)' . preg_quote($reference, '/') . '(?=\s|$)/u',
            $purpose,
        ) === 1;
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
            'booking-v2',
            $confirmation['documentType'],
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

    /** @param array<string,mixed> $confirmation */
    private static function legacyVoucherConfirmationReference(array $confirmation): string
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

    /** @param array<string,mixed> $confirmation */
    private static function usesLegacyVoucherReference(array $confirmation): bool
    {
        return ($confirmation['bookingSchema'] ?? null) === 'booking-v1'
            && strtolower(trim((string) ($confirmation['documentType'] ?? ''))) === 'voucher';
    }

    /** @param array<array-key, mixed> $response */
    private static function validBookingResponse(
        array $response,
        string $documentType,
        string $documentId,
    ): bool {
        $nestedKey = $documentType === 'invoice' ? 'invoiceLog' : 'voucherLog';
        $records = self::records($response, $nestedKey);
        if (count($records) !== 1) {
            return false;
        }
        $log = $records[0];
        if (self::numericId($log['id'] ?? null) === null) {
            return false;
        }

        $objectName = (string) ($log['objectName'] ?? '');
        if ($documentType === 'invoice') {
            // The published schema currently calls this relation `creditNote`,
            // while sevdesk installations may return the semantically correct
            // `invoice` key. Both must still identify the exact Invoice.
            $relation = is_array($log['invoice'] ?? null)
                ? $log['invoice']
                : ($log['creditNote'] ?? null);
        } else {
            $relation = $log['voucher'] ?? null;
        }
        $responseDocumentId = is_array($relation)
            ? self::numericId($relation['id'] ?? null)
            : null;

        $expectedObjectName = $documentType === 'invoice' ? 'InvoiceLog' : 'VoucherLog';

        return $objectName === $expectedObjectName && $responseDocumentId === $documentId;
    }

    private static function documentPath(string $documentType, string $documentId): string
    {
        $resource = $documentType === 'invoice' ? 'Invoice' : 'Voucher';

        return '/' . $resource . '/' . rawurlencode($documentId);
    }

    /** @return BookingResult|null */
    private static function documentTypeFailure(mixed $documentType): ?array
    {
        if ($documentType === null || (is_string($documentType) && trim($documentType) === '')) {
            return self::result(
                'blocked',
                'booking_document_type_missing',
                'The legacy sevdesk mapping has no confirmed document type.',
            );
        }
        if (
            !is_string($documentType)
            || !in_array(strtolower(trim($documentType)), ['voucher', 'invoice'], true)
        ) {
            return self::result(
                'blocked',
                'booking_document_type_invalid',
                'The sevdesk mapping uses an unsupported document type.',
            );
        }

        return null;
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
        $context = $exception->context();
        if (!$ambiguous && $code === 'booking_write_failed') {
            $context['definiteWriteRejected'] = true;
        }

        return self::result(
            $ambiguous ? 'ambiguous' : 'failed',
            $ambiguous ? $code . '_ambiguous' : $code,
            $ambiguous
                ? 'The booking write may have succeeded. Reconcile document and transaction before retrying.'
                : 'The sevdesk booking request could not be completed.',
            $context,
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
