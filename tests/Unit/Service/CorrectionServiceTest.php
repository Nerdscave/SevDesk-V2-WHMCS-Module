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
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;

final class CorrectionServiceTest extends TestCase
{
    public function testConfirmedRefundCreatesExplicitNegativeMultiRateRevenueVoucher(): void
    {
        $history = [];
        $mappings = [];
        $service = new CorrectionService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                $this->originalVoucherResponse(),
                new Response(201, [], '{"objects":{"voucher":{"id":"99","sumGross":"-172.50"}}}'),
            ], $history),
            static fn (string $reference): mixed => $mappings[$reference] ?? null,
            static function (string $reference, string $remoteId) use (&$mappings): void {
                $mappings[$reference] = $remoteId;
            },
        );
        $checkpoints = [];

        $result = $service->create(
            $this->request('172.50'),
            $this->taxDecision(['7', '19']),
            [
                new LineItem('Seven percent allocation', '50.00', '7', true),
                new LineItem('Nineteen percent allocation', '100.00', '19', true),
            ],
            true,
            static function (string $name, array $context) use (&$checkpoints): void {
                $checkpoints[] = [$name, $context];
            },
        );

        $reference = CorrectionService::dedupeReference('RF-9');
        self::assertSame('succeeded', $result['status']);
        self::assertSame('99', $result['remoteId']);
        self::assertSame([$reference => '99'], $mappings);
        self::assertSame([
            'correction_voucher_write_requested',
            'correction_voucher_created',
            'correction_mapping_persisted',
        ], array_column($checkpoints, 0));

        self::assertCount(3, $history);
        self::assertSame('POST', $history[2]['request']->getMethod());
        self::assertSame('/api/v1/Voucher/Factory/saveVoucher', $history[2]['request']->getUri()->getPath());
        $payload = json_decode((string) $history[2]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('D', $payload['voucher']['creditDebit']);
        self::assertSame(100, $payload['voucher']['status']);
        self::assertSame(1, $payload['voucher']['taxRule']['id']);
        self::assertSame(-50.0, $payload['voucherPosSave'][0]['sumNet']);
        self::assertSame(-100.0, $payload['voucherPosSave'][1]['sumNet']);
        self::assertSame(100, $payload['voucherPosSave'][1]['accountDatev']['id']);
        self::assertArrayNotHasKey('enshrined', $payload['voucher']);
        self::assertArrayNotHasKey('filename', $payload);
        self::assertStringContainsString(VoucherExporter::marker(10), $payload['voucher']['description']);
        self::assertStringContainsString('[SEVDESK-VOUCHER:88]', $payload['voucher']['description']);
        self::assertStringContainsString(CorrectionService::refundMarker('RF-9'), $payload['voucher']['description']);
        self::assertStringNotContainsString('RF-9', $payload['voucher']['description']);
    }

    public function testExplicitConfirmationIsRequiredBeforeCallbacksOrApiCalls(): void
    {
        $history = [];
        $findCalls = 0;
        $service = new CorrectionService(
            $this->client([], $history),
            static function () use (&$findCalls): null {
                ++$findCalls;

                return null;
            },
            static fn (): bool => true,
        );

        $result = $service->create(
            $this->request('119.00'),
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            false,
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('confirmation_required', $result['code']);
        self::assertSame(0, $findCalls);
        self::assertCount(0, $history);
    }

    public function testInvoiceMappingIsBlockedWithoutCreditNoteOrVoucherFallback(): void
    {
        $history = [];
        $callbackCalls = 0;
        $service = new CorrectionService(
            $this->client([], $history),
            static function () use (&$callbackCalls): null {
                ++$callbackCalls;

                return null;
            },
            static function () use (&$callbackCalls): bool {
                ++$callbackCalls;

                return true;
            },
        );
        $request = $this->request('119.00');
        $request['documentType'] = 'invoice';

        $result = $service->create(
            $request,
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('invoice_correction_not_supported', $result['code']);
        self::assertSame(0, $callbackCalls);
        self::assertCount(0, $history);
    }

    public function testLegacyMappingWithoutDocumentTypeFailsClosed(): void
    {
        $history = [];
        $service = new CorrectionService(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
        );
        $request = $this->request('119.00');
        unset($request['documentType']);

        $result = $service->create(
            $request,
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('correction_mapping_document_type_unknown', $result['code']);
        self::assertCount(0, $history);
    }

    public function testExistingLocalRefundReferenceSkipsAllRemoteCalls(): void
    {
        $history = [];
        $service = new CorrectionService(
            $this->client([], $history),
            static fn (): string => '99',
            static fn (): bool => true,
        );

        $result = $service->create(
            $this->request('119.00'),
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
        );

        self::assertSame('skipped', $result['status']);
        self::assertSame('correction_already_mapped', $result['code']);
        self::assertSame('99', $result['remoteId']);
        self::assertCount(0, $history);
    }

    public function testUniqueRemoteMarkerIsReconciledWithoutSecondVoucherPost(): void
    {
        $history = [];
        $stored = [];
        $description = 'Correction INV-10 '
            . VoucherExporter::marker(10)
            . ' [SEVDESK-VOUCHER:88] '
            . CorrectionService::refundMarker('RF-9');
        $service = new CorrectionService(
            $this->client([
                new Response(200, [], json_encode([
                    'objects' => [[
                        'id' => '99',
                        'description' => $description,
                        'currency' => 'EUR',
                        'creditDebit' => 'D',
                        'sumGross' => '-119.00',
                        'supplier' => ['id' => '42', 'objectName' => 'Contact'],
                        'taxRule' => ['id' => '1', 'objectName' => 'TaxRule'],
                    ]],
                ], JSON_THROW_ON_ERROR)),
            ], $history),
            static fn (): null => null,
            static function (string $reference, string $remoteId) use (&$stored): void {
                $stored[$reference] = $remoteId;
            },
        );

        $result = $service->create(
            $this->request('119.00'),
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
            null,
            true,
        );

        self::assertSame('skipped', $result['status']);
        self::assertSame('correction_reconciled', $result['code']);
        self::assertSame('99', $result['remoteId']);
        self::assertSame([
            CorrectionService::dedupeReference('RF-9') => '99',
        ], $stored);
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
    }

    public function testReadOnlyRecoveryWithNoMarkerMatchNeverCreatesAnotherVoucher(): void
    {
        $history = [];
        $checkpointCalls = 0;
        $service = new CorrectionService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
        );

        $result = $service->create(
            $this->request('119.00'),
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
            static function () use (&$checkpointCalls): void {
                ++$checkpointCalls;
            },
            true,
        );

        self::assertSame('ambiguous', $result['status']);
        self::assertSame('correction_reconciliation_no_match', $result['code']);
        self::assertSame(0, $result['context']['matchCount']);
        self::assertSame(0, $checkpointCalls);
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame(0, count(array_filter(
            $history,
            static fn (array $entry): bool => $entry['request']->getMethod() === 'POST',
        )));
    }

    public function testRemoteMarkerWithDifferentTotalIsAmbiguousInsteadOfCreatingDuplicate(): void
    {
        $history = [];
        $description = VoucherExporter::marker(10)
            . ' [SEVDESK-VOUCHER:88] '
            . CorrectionService::refundMarker('RF-9');
        $service = new CorrectionService(
            $this->client([
                new Response(200, [], json_encode([
                    'objects' => [[
                        'id' => '99',
                        'description' => $description,
                        'currency' => 'EUR',
                        'creditDebit' => 'D',
                        'sumGross' => '-118.00',
                        'supplier' => ['id' => '42', 'objectName' => 'Contact'],
                        'taxRule' => ['id' => '1', 'objectName' => 'TaxRule'],
                    ]],
                ], JSON_THROW_ON_ERROR)),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
        );

        $result = $service->create(
            $this->request('119.00'),
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
        );

        self::assertSame('ambiguous', $result['status']);
        self::assertSame('correction_marker_conflict', $result['code']);
        self::assertCount(1, $history);
    }

    public function testUnknownVoucherPostOutcomeIsAmbiguousAndNeverMappedOrRetried(): void
    {
        $history = [];
        $persistCalls = 0;
        $service = new CorrectionService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                $this->originalVoucherResponse(),
                new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
            ], $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
        );

        $result = $service->create(
            $this->request('119.00'),
            $this->taxDecision(),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
        );

        self::assertSame('ambiguous', $result['status']);
        self::assertSame('correction_voucher_write_failed_ambiguous', $result['code']);
        self::assertSame(0, $persistCalls);
        self::assertCount(3, $history);
        self::assertSame(1, count(array_filter(
            $history,
            static fn (array $entry): bool => $entry['request']->getMethod() === 'POST',
        )));
    }

    public function testChargebackAndUnvalidatedTaxProfileAreBlockedBeforeApiCalls(): void
    {
        $history = [];
        $service = new CorrectionService(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
        );

        $chargeback = $this->request('119.00');
        $chargeback['kind'] = 'chargeback';
        $chargebackResult = $service->create(
            $chargeback,
            $this->taxDecision(),
            [new LineItem('Chargeback', '100.00', '19', true)],
            true,
        );
        $unvalidatedResult = $service->create(
            $this->request('119.00'),
            TaxDecision::allow('domestic', '100', '1', 'Domestic sale'),
            [new LineItem('Refund', '100.00', '19', true)],
            true,
        );

        self::assertSame('unsupported_correction_kind', $chargebackResult['code']);
        self::assertSame('receipt_guidance_not_validated', $unvalidatedResult['code']);
        self::assertCount(0, $history);
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
     *     whmcsRefundTransactionId: string,
     *     invoiceId: int,
     *     invoiceNumber: string,
     *     originalVoucherId: string,
     *     contactId: string,
     *     refundAmount: string,
     *     currency: string,
     *     voucherDate: string
     * }
     */
    private function request(string $refundAmount): array
    {
        return [
            'kind' => 'refund',
            'documentType' => 'voucher',
            'whmcsRefundTransactionId' => 'RF-9',
            'invoiceId' => 10,
            'invoiceNumber' => 'INV-10',
            'originalVoucherId' => '88',
            'contactId' => '42',
            'refundAmount' => $refundAmount,
            'currency' => 'EUR',
            'voucherDate' => '2026-07-10',
        ];
    }

    private function originalVoucherResponse(): Response
    {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '88',
                'objectName' => 'Voucher',
                'description' => 'INV-10 ' . VoucherExporter::marker(10),
                'currency' => 'EUR',
                'creditDebit' => 'D',
                'sumGross' => '200.00',
                'supplier' => ['id' => '42', 'objectName' => 'Contact'],
                'taxRule' => ['id' => '1', 'objectName' => 'TaxRule'],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $allowedRates */
    private function taxDecision(array $allowedRates = ['19']): TaxDecision
    {
        return TaxDecision::allow('domestic', '100', '1', 'Domestic sale')
            ->withValidatedGuidance($allowedRates);
    }
}
