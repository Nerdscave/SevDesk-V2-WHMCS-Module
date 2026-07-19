<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Service\BookingService;

final class BookingServiceTest extends TestCase
{
    public function testPreviewAndConfirmationBookTheUniqueFullPaymentAfterFreshReads(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
            $this->voucherResponse(),
            $this->transactionResponse(),
            $this->accountResponse(),
            $this->transactionListResponse(),
            new Response(200, [], '{"id":"91","objectName":"VoucherLog","voucher":{"id":"88"}}'),
        ], $history));

        $preview = $service->preview($this->payment());

        self::assertSame('ready', $preview['status']);
        self::assertSame('FULL_PAYMENT', $preview['confirmation']['bookingType']);
        self::assertSame('73', $preview['confirmation']['transactionId']);
        self::assertSame('9', $preview['confirmation']['checkAccountId']);
        parse_str($history[1]['request']->getUri()->getQuery(), $query);
        self::assertSame('false', $query['isBooked']);
        self::assertSame('true', $query['onlyCredit']);
        self::assertSame('TX-42', $query['paymtPurpose']);
        self::assertSame('1000', $query['limit']);
        self::assertSame('0', $query['offset']);

        $checkpoints = [];
        $result = $service->confirm(
            $preview['confirmation'],
            true,
            static function (string $name, array $context) use (&$checkpoints): void {
                $checkpoints[] = [$name, $context];
            },
        );

        self::assertSame('succeeded', $result['status']);
        self::assertSame('booking_completed', $result['code']);
        self::assertSame([
            'booking_write_requested',
            'booking_completed',
        ], array_column($checkpoints, 0));

        self::assertCount(8, $history);
        self::assertSame('GET', $history[3]['request']->getMethod());
        self::assertSame('/api/v1/Voucher/88', $history[3]['request']->getUri()->getPath());
        self::assertSame('GET', $history[4]['request']->getMethod());
        self::assertSame('/api/v1/CheckAccountTransaction/73', $history[4]['request']->getUri()->getPath());
        self::assertSame('PUT', $history[7]['request']->getMethod());
        self::assertSame('/api/v1/Voucher/88/bookAmount', $history[7]['request']->getUri()->getPath());

        $payload = json_decode((string) $history[7]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('FULL_PAYMENT', $payload['type']);
        self::assertSame(119.0, $payload['amount']);
        self::assertSame('10.07.2026', $payload['date']);
        self::assertSame(73, $payload['checkAccountTransaction']['id']);
        self::assertSame(9, $payload['checkAccount']['id']);
    }

    public function testInvoicePreviewAndConfirmationUseInvoiceEndpointsAndLog(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->invoiceResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
            $this->invoiceResponse(),
            $this->transactionResponse(),
            $this->accountResponse(),
            $this->transactionListResponse(),
            new Response(200, [], '{"id":"91","objectName":"InvoiceLog","invoice":{"id":"88"}}'),
        ], $history));

        $preview = $service->preview($this->payment(documentType: 'invoice'));

        self::assertSame('ready', $preview['status']);
        self::assertSame('invoice', $preview['confirmation']['documentType']);
        self::assertSame('/api/v1/Invoice/88', $history[0]['request']->getUri()->getPath());

        $result = $service->confirm($preview['confirmation'], true);

        self::assertSame('succeeded', $result['status']);
        self::assertSame('/api/v1/Invoice/88', $history[3]['request']->getUri()->getPath());
        self::assertSame('/api/v1/Invoice/88/bookAmount', $history[7]['request']->getUri()->getPath());
    }

    public function testLegacyOrUnsupportedDocumentTypeBlocksBeforeAnyApiRead(): void
    {
        foreach ([null, '', 'credit_note'] as $documentType) {
            $history = [];
            $service = new BookingService($this->client([], $history));
            $payment = $this->payment();
            $payment['documentType'] = $documentType;

            $result = $service->preview($payment);

            self::assertSame('blocked', $result['status']);
            self::assertSame(
                $documentType === null || $documentType === ''
                    ? 'booking_document_type_missing'
                    : 'booking_document_type_invalid',
                $result['code'],
            );
            self::assertCount(0, $history);
        }
    }

    public function testOnlyAnAuthenticBookingV1SnapshotCanUpgradeToLegacyVoucher(): void
    {
        $history = [];
        $service = new BookingService($this->client([], $history));
        $legacy = [
            'whmcsTransactionId' => 'TX-42',
            'voucherId' => '88',
            'transactionId' => '73',
            'checkAccountId' => '9',
            'amount' => '119.00',
            'amountMinorUnits' => 11900,
            'currency' => 'EUR',
            'bookingDate' => '2026-07-10',
            'bookingType' => 'FULL_PAYMENT',
            'voucherPaidMinorUnits' => 0,
        ];
        $legacy['reference'] = hash('sha256', implode('|', [
            'booking-v1', 'TX-42', '88', '73', '9', '11900', 'EUR',
            '2026-07-10', 'FULL_PAYMENT', '0',
        ]));

        $upgraded = $service->upgradeLegacyVoucherConfirmation($legacy);

        self::assertNotNull($upgraded);
        self::assertSame('voucher', $upgraded['documentType']);
        self::assertSame('booking-v1', $upgraded['bookingSchema']);
        self::assertTrue($service->confirmationIsAuthentic($upgraded));

        $legacy['amount'] = '118.00';
        $legacy['amountMinorUnits'] = 11800;
        self::assertNull($service->upgradeLegacyVoucherConfirmation($legacy));
    }

    public function testPreviewUsesPartialBookingTypeForAmountBelowRemainingBalance(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse('119.00', '19.00', '750'),
            $this->transactionListResponse('50.00'),
            $this->accountResponse(),
        ], $history));

        $preview = $service->preview($this->payment(amount: '50.00'));

        self::assertSame('ready', $preview['status']);
        self::assertSame('N', $preview['confirmation']['bookingType']);
        self::assertCount(3, $history);
    }

    public function testFullyPaidDocumentHasADistinctNonActionablePreviewCode(): void
    {
        foreach (['voucher', 'invoice'] as $documentType) {
            $history = [];
            $document = $documentType === 'invoice'
                ? $this->invoiceResponse('119.00', '119.00', '1000')
                : $this->voucherResponse('119.00', '119.00', '1000');
            $service = new BookingService($this->client([$document], $history));

            $preview = $service->preview($this->payment(documentType: $documentType));

            self::assertSame('blocked', $preview['status']);
            self::assertSame($documentType . '_already_paid', $preview['code']);
            self::assertCount(1, $history);
        }
    }

    public function testChangedPaidAmountBaselineBlocksPartialPaymentBeforeWrite(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse('119.00', '10.00', '750'),
            $this->transactionListResponse('50.00'),
            $this->accountResponse(),
            $this->voucherResponse('119.00', '20.00', '750'),
        ], $history));
        $preview = $service->preview($this->payment(amount: '50.00'));

        $result = $service->confirm($preview['confirmation'], true);

        self::assertSame('blocked', $result['status']);
        self::assertSame('voucher_payment_baseline_changed', $result['code']);
        self::assertCount(4, $history);
        self::assertSame(0, count(array_filter(
            $history,
            static fn (array $entry): bool => $entry['request']->getMethod() === 'PUT',
        )));
    }

    public function testMultipleExactTransactionsAreNeverPresentedAsBookable(): void
    {
        $history = [];
        $transactions = json_encode([
            'objects' => [
                $this->transaction('73'),
                $this->transaction('74'),
            ],
        ], JSON_THROW_ON_ERROR);
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            new Response(200, [], $transactions),
            $this->accountResponse(),
        ], $history));

        $preview = $service->preview($this->payment());

        self::assertSame('blocked', $preview['status']);
        self::assertSame('multiple_payment_candidates', $preview['code']);
        self::assertSame(2, $preview['context']['matchCount']);
        self::assertCount(3, $history);
    }

    public function testTransactionReferenceMustMatchACompletePurposeToken(): void
    {
        $history = [];
        $transactions = json_encode([
            'objects' => [
                $this->transaction('73', purpose: 'WHMCS payment TX-420'),
            ],
        ], JSON_THROW_ON_ERROR);
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            new Response(200, [], $transactions),
        ], $history));

        $preview = $service->preview($this->payment());

        self::assertSame('blocked', $preview['status']);
        self::assertSame('no_payment_candidate', $preview['code']);
        self::assertCount(2, $history, 'TX-42 must not match the longer reference TX-420.');
    }

    public function testFullTransactionSearchPageBlocksBeforeAccountReads(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->fullTransactionListResponse(),
        ], $history));

        $preview = $service->preview($this->payment());

        self::assertSame('blocked', $preview['status']);
        self::assertSame('payment_candidate_search_truncated', $preview['code']);
        self::assertCount(2, $history, 'A full search page must block before resolving transaction accounts.');
        parse_str($history[1]['request']->getUri()->getQuery(), $query);
        self::assertSame('1000', $query['limit']);
        self::assertSame('0', $query['offset']);
    }

    public function testFullTransactionSearchPageAlsoBlocksConfirmationBeforeCheckpointAndWrite(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
            $this->voucherResponse(),
            $this->transactionResponse(),
            $this->accountResponse(),
            $this->fullTransactionListResponse(),
        ], $history));
        $preview = $service->preview($this->payment());
        $checkpoints = [];

        $result = $service->confirm(
            $preview['confirmation'],
            true,
            static function (string $name) use (&$checkpoints): void {
                $checkpoints[] = $name;
            },
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('payment_candidate_search_truncated', $result['code']);
        self::assertSame([], $checkpoints);
        self::assertCount(7, $history);
        self::assertSame(0, count(array_filter(
            $history,
            static fn (array $entry): bool => $entry['request']->getMethod() === 'PUT',
        )));
    }

    public function testAccountCurrencyMismatchProducesNoCandidate(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse('USD'),
        ], $history));

        $preview = $service->preview($this->payment());

        self::assertSame('blocked', $preview['status']);
        self::assertSame('no_payment_candidate', $preview['code']);
    }

    public function testRefundAndChargebackAreBlockedBeforeAnyApiRead(): void
    {
        foreach (['refund', 'chargeback'] as $kind) {
            $history = [];
            $service = new BookingService($this->client([], $history));

            $result = $service->preview($this->payment(kind: $kind));

            self::assertSame('blocked', $result['status']);
            self::assertSame('unsupported_payment_kind', $result['code']);
            self::assertCount(0, $history);
        }
    }

    public function testConfirmationFlagIsRequiredAndDoesNotPerformFreshReadsWhenMissing(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
        ], $history));
        $preview = $service->preview($this->payment());

        $result = $service->confirm($preview['confirmation'], false);

        self::assertSame('blocked', $result['status']);
        self::assertSame('confirmation_required', $result['code']);
        self::assertCount(3, $history);
    }

    public function testChangedPreviewFieldsInvalidateConfirmationBeforeFreshReads(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
        ], $history));
        $preview = $service->preview($this->payment());
        $confirmation = $preview['confirmation'];
        $confirmation['amount'] = '118.00';

        $result = $service->confirm($confirmation, true);

        self::assertSame('blocked', $result['status']);
        self::assertSame('confirmation_changed', $result['code']);
        self::assertCount(3, $history);
    }

    public function testChangedDocumentTypeInvalidatesConfirmationBeforeFreshReads(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
        ], $history));
        $preview = $service->preview($this->payment());
        $confirmation = $preview['confirmation'];
        $confirmation['documentType'] = 'invoice';

        $result = $service->confirm($confirmation, true);

        self::assertSame('blocked', $result['status']);
        self::assertSame('confirmation_changed', $result['code']);
        self::assertCount(3, $history);
    }

    public function testUnknownPutOutcomeIsAmbiguousAndNeverRetriedInsideService(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
            $this->voucherResponse(),
            $this->transactionResponse(),
            $this->accountResponse(),
            $this->transactionListResponse(),
            new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
        ], $history));
        $preview = $service->preview($this->payment());

        $result = $service->confirm($preview['confirmation'], true);

        self::assertSame('ambiguous', $result['status']);
        self::assertSame('booking_write_failed_ambiguous', $result['code']);
        self::assertCount(8, $history);
        self::assertSame(1, count(array_filter(
            $history,
            static fn (array $entry): bool => $entry['request']->getMethod() === 'PUT',
        )));
    }

    public function testVoucherLogForDifferentVoucherDoesNotProveSuccess(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
            $this->voucherResponse(),
            $this->transactionResponse(),
            $this->accountResponse(),
            $this->transactionListResponse(),
            new Response(200, [], '{"id":"91","objectName":"VoucherLog","voucher":{"id":"999"}}'),
        ], $history));
        $preview = $service->preview($this->payment());

        $result = $service->confirm($preview['confirmation'], true);

        self::assertSame('ambiguous', $result['status']);
        self::assertSame('booking_response_ambiguous', $result['code']);
    }

    public function testReconciliationCannotProveTheConcreteTransactionDocumentLink(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
            $this->voucherResponse('119.00', '119.00'),
            $this->transactionResponse('119.00', '200'),
        ], $history));
        $preview = $service->preview($this->payment());

        $result = $service->reconcile($preview['confirmation']);

        self::assertSame('ambiguous', $result['status']);
        self::assertSame('booking_reconciliation_link_unprovable', $result['code']);
        self::assertCount(5, $history);
        self::assertSame(0, count(array_filter(
            $history,
            static fn (array $entry): bool => $entry['request']->getMethod() === 'PUT',
        )));
    }

    public function testReconciliationProvesThatBookingWasNotApplied(): void
    {
        $history = [];
        $service = new BookingService($this->client([
            $this->voucherResponse(),
            $this->transactionListResponse(),
            $this->accountResponse(),
            $this->voucherResponse(),
            $this->transactionResponse(),
        ], $history));
        $preview = $service->preview($this->payment());

        $result = $service->reconcile($preview['confirmation']);

        self::assertSame('blocked', $result['status']);
        self::assertSame('booking_not_applied', $result['code']);
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new SevdeskClient(new Client(['handler' => $stack]), 'test-token');
    }

    /**
     * @return array{
     *     kind: string,
     *     documentType: string,
     *     whmcsTransactionId: string,
     *     voucherId: string,
     *     amount: string,
     *     currency: string,
     *     bookingDate: string
     * }
     */
    private function payment(
        string $amount = '119.00',
        string $kind = 'payment',
        string $documentType = 'voucher',
    ): array {
        return [
            'kind' => $kind,
            'documentType' => $documentType,
            'whmcsTransactionId' => 'TX-42',
            'voucherId' => '88',
            'amount' => $amount,
            'currency' => 'EUR',
            'bookingDate' => '2026-07-10',
        ];
    }

    private function voucherResponse(
        string $sumGross = '119.00',
        string $paidAmount = '0.00',
        string $status = '100',
    ): Response {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '88',
                'objectName' => 'Voucher',
                'status' => $status,
                'currency' => 'EUR',
                'sumGross' => $sumGross,
                'paidAmount' => $paidAmount,
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function invoiceResponse(
        string $sumGross = '119.00',
        string $paidAmount = '0.00',
        string $status = '200',
    ): Response {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '88',
                'objectName' => 'Invoice',
                'status' => $status,
                'currency' => 'EUR',
                'sumGross' => $sumGross,
                'paidAmount' => $paidAmount,
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function transactionListResponse(string $amount = '119.00'): Response
    {
        return new Response(200, [], json_encode([
            'objects' => [$this->transaction('73', $amount)],
        ], JSON_THROW_ON_ERROR));
    }

    private function fullTransactionListResponse(): Response
    {
        $transactions = [];
        for ($id = 1; $id <= 1000; ++$id) {
            $transactions[] = $this->transaction((string) $id);
        }

        return new Response(200, [], json_encode([
            'objects' => $transactions,
        ], JSON_THROW_ON_ERROR));
    }

    private function transactionResponse(string $amount = '119.00', string $status = '100'): Response
    {
        return new Response(200, [], json_encode([
            'objects' => [$this->transaction('73', $amount, $status)],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function transaction(
        string $id,
        string $amount = '119.00',
        string $status = '100',
        string $purpose = 'WHMCS payment TX-42',
    ): array {
        return [
            'id' => $id,
            'objectName' => 'CheckAccountTransaction',
            'status' => $status,
            'paymtPurpose' => $purpose,
            'amount' => $amount,
            'checkAccount' => ['id' => '9', 'objectName' => 'CheckAccount'],
        ];
    }

    private function accountResponse(string $currency = 'EUR'): Response
    {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '9',
                'objectName' => 'CheckAccount',
                'currency' => $currency,
                'status' => '100',
            ]],
        ], JSON_THROW_ON_ERROR));
    }
}
