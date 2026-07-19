<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceExporter;

final class InvoiceExporterTest extends TestCase
{
    public function testMarkerMatchRejectsMissingOrContradictoryInvoiceMarkers(): void
    {
        self::assertTrue(InvoiceExporter::markerMatches('Reference [WHMCS-INVOICE:10]', 10));
        self::assertFalse(InvoiceExporter::markerMatches('Reference [WHMCS-INVOICE:100]', 10));
        self::assertFalse(InvoiceExporter::markerMatches(
            '[WHMCS-INVOICE:10] duplicate [WHMCS-INVOICE:11]',
            10,
        ));
    }

    public function testItCreatesVerifiesMapsAndOpensAWhmcsAuthorityInvoice(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
            $this->invoiceResponse(100),
            $this->positionResponse(),
            $this->invoiceResponse(100),
            $this->positionResponse(),
            new Response(200, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
            $this->invoiceResponse(200),
            $this->positionResponse(),
        ], $history);
        $mappings = [];
        $checkpoints = [];
        $exporter = (new InvoiceExporter(
            $client,
            static fn (int $invoiceId): mixed => $mappings[$invoiceId]['remoteId'] ?? null,
            static function (
                int $invoiceId,
                string $remoteId,
                string $documentType,
                string $documentNumber,
            ) use (&$mappings): void {
                $mappings[$invoiceId] = compact('remoteId', 'documentType', 'documentNumber');
            },
            '70',
            '80',
        ))->withReferences('7', '8');

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
            static function (string $name) use (&$checkpoints): void {
                $checkpoints[] = $name;
            },
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('99', $result->remoteId);
        self::assertSame([
            10 => [
                'remoteId' => '99',
                'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                'documentNumber' => 'RE-10',
            ],
        ], $mappings);
        self::assertSame([
            'invoice_write_requested',
            'invoice_created',
            'mapping_persisted',
            'invoice_open_write_requested',
            'invoice_opened',
        ], $checkpoints);
        self::assertSame(['POST', 'GET', 'GET', 'GET', 'GET', 'PUT', 'GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));

        $payloadJson = (string) $history[0]['request']->getBody();
        $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('RE', $payload['invoice']['invoiceType']);
        self::assertSame(100, $payload['invoice']['status']);
        self::assertSame('RE-10', $payload['invoice']['invoiceNumber']);
        self::assertSame('Rechnung RE-10', $payload['invoice']['header']);
        self::assertSame('', $payload['invoice']['headText']);
        self::assertSame('', $payload['invoice']['footText']);
        self::assertSame(0, $payload['invoice']['timeToPay']);
        self::assertSame('[WHMCS-INVOICE:10]', $payload['invoice']['customerInternalNote']);
        self::assertSame(1, $payload['invoice']['taxRule']['id']);
        self::assertSame(7, $payload['invoice']['contactPerson']['id']);
        self::assertSame(1, $payload['invoicePosSave'][0]['quantity']);
        self::assertSame(8, $payload['invoicePosSave'][0]['unity']['id']);
        self::assertSame(100.0, $payload['invoicePosSave'][0]['price']);
        self::assertArrayNotHasKey('filename', $payload);
        self::assertArrayNotHasKey('taxType', $payload['invoice']);
        self::assertStringNotContainsString('accountDatev', $payloadJson);

        $openPayload = json_decode((string) $history[5]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['sendType' => 'VPDF', 'sendDraft' => false], $openPayload);
    }

    public function testRule19PayloadUsesCountryRatesAndNoVoucherAccount(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '120.00',
            '0',
            [new LineItem('Digital service', '120.00', '20', false)],
        );
        $tax = TaxDecision::allowInvoiceRule19(
            'eu_b2c_oss_rule19',
            'Confirmed digital service.',
            ['20'],
        );
        $target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
        ))->resolve($tax, true, true);

        $payload = $exporter->buildPayload($invoice, '42', $tax, 'FR', $target);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertSame(19, $payload['invoice']['taxRule']['id']);
        self::assertSame('FR', $payload['invoice']['deliveryAddressCountry']);
        self::assertSame(20.0, $payload['invoicePosSave'][0]['taxRate']);
        self::assertFalse($payload['invoice']['showNet']);
        self::assertStringNotContainsString('accountDatev', $encoded);
        self::assertArrayNotHasKey('filename', $payload);
    }

    public function testUnknownCreateOutcomeIsAmbiguousAndNeverRetriedOrMapped(): void
    {
        $history = [];
        $client = $this->client([
            new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
        ], $history);
        $persistCalls = 0;
        $exporter = new InvoiceExporter(
            $client,
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_create_failed_ambiguous', $result->code);
        self::assertSame(0, $persistCalls);
        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
    }

    #[DataProvider('definiteCreateRejectionProvider')]
    public function testDefiniteCreateRejectionIsMarkedSafeForHandlerClassification(
        int $httpStatus,
        string $expectedCode,
    ): void {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                new Response($httpStatus, ['Retry-After' => '60'], '{"error":{"code":"SYNTHETIC"}}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame($expectedCode, $result->code);
        self::assertTrue($result->context['definiteWriteRejected']);
        self::assertFalse($result->context['outcomeUnknown']);
        self::assertCount(1, $history);
    }

    /** @return iterable<string,array{int,string}> */
    public static function definiteCreateRejectionProvider(): iterable
    {
        yield 'validation rejection' => [422, 'invoice_create_failed_permanent'];
        yield 'rate limit rejection' => [429, 'api_rate_limited'];
    }

    public function testTransportFailureDuringCreateRemainsAnUnknownWriteOutcome(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                new ConnectException('Synthetic timeout.', new Request('POST', '/Invoice/Factory/saveInvoice')),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_create_failed_ambiguous', $result->code);
        self::assertTrue($result->context['outcomeUnknown']);
        self::assertArrayNotHasKey('definiteWriteRejected', $result->context);
        self::assertCount(1, $history);
    }

    public function testExactRemoteMismatchLeavesTheTypedMappingEmpty(): void
    {
        $history = [];
        $persistCalls = 0;
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
                $this->invoiceResponse(100, ['sumGross' => '118.99']),
            ], $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('remote_total_mismatch', $result->code);
        self::assertSame('99', $result->remoteId);
        self::assertSame(0, $persistCalls);
        self::assertCount(2, $history);
    }

    public function testImpossibleRemoteDateThatNormalisesToExpectedDateIsRejected(): void
    {
        $history = [];
        $persistCalls = 0;
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
                $this->invoiceResponse(100, ['invoiceDate' => '31.06.2026']),
            ], $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('remote_date_mismatch', $result->code);
        self::assertSame(0, $persistCalls);
        self::assertCount(2, $history);
    }

    public function testSevdeskAuthorityReturnsAVerifiedMappedDraftForDeliveryOrchestration(): void
    {
        $history = [];
        $mappings = [];
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
                $this->invoiceResponse(100),
                $this->positionResponse(),
            ], $history),
            static fn (): null => null,
            static function (int $invoiceId, string $remoteId, string $type, string $number) use (&$mappings): void {
                $mappings[] = [$invoiceId, $remoteId, $type, $number];
            },
            '7',
            '8',
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_SEVDESK),
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame([[10, '99', 'invoice', 'RE-10']], $mappings);
        self::assertSame(['POST', 'GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testFailedWriteCheckpointPreventsAnyRemoteRequest(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
            static fn (string $name): bool => $name !== 'invoice_write_requested',
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('checkpoint_persist_failed', $result->code);
        self::assertCount(0, $history);
    }

    public function testReconcileOpenedProvesSendByWithReadsOnly(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(200, ['sendType' => 'VPDF']),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->reconcileOpened(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('99', $result->remoteId);
        self::assertSame(['invoice_opened'], $checkpoints);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    public function testDeliverViaSevdeskPostsOnceAndVerifiesFinalInvoiceAndPositions(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(100),
                $this->positionResponse(),
                new Response(201, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
                $this->invoiceResponse(200, [
                    'sendType' => 'VM',
                    'sendDate' => '2026-07-18T14:35:12+02:00',
                ]),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->deliverViaSevdesk(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            ' customer@example.test ',
            ' Invoice RE-10 ',
            ' Your final Invoice is attached. ',
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('99', $result->remoteId);
        self::assertSame(['invoice_delivery_write_requested', 'invoice_delivered'], $checkpoints);
        self::assertSame(['GET', 'GET', 'POST', 'GET', 'GET'], $this->requestMethods($history));
        self::assertStringEndsWith('/Invoice/99/sendViaEmail', (string) $history[2]['request']->getUri());
        self::assertSame([
            'toEmail' => 'customer@example.test',
            'subject' => 'Invoice RE-10',
            'text' => 'Your final Invoice is attached.',
            'copy' => false,
        ], json_decode((string) $history[2]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testUnknownDeliveryWriteOutcomeIsAmbiguousAndNeverRetried(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(100),
                $this->positionResponse(),
                new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->deliverViaSevdesk(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            'customer@example.test',
            'Invoice RE-10',
            'Your final Invoice is attached.',
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_delivery_failed_ambiguous', $result->code);
        self::assertSame('99', $result->remoteId);
        self::assertSame(['invoice_delivery_write_requested'], $checkpoints);
        self::assertSame(['GET', 'GET', 'POST'], $this->requestMethods($history));
    }

    public function testUnknownSendByWriteOutcomeIsAmbiguousAndNeverRetried(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(100),
                $this->positionResponse(),
                new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->openForWhmcsAuthority(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_open_failed_ambiguous', $result->code);
        self::assertSame('99', $result->remoteId);
        self::assertSame(['invoice_open_write_requested'], $checkpoints);
        self::assertSame(['GET', 'GET', 'PUT'], $this->requestMethods($history));
    }

    public function testChangedDraftIsRejectedBeforeSendByWithoutAnyWriteCheckpoint(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(100, ['sumGross' => '120.00']),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->openForWhmcsAuthority(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_open_prewrite_remote_total_mismatch', $result->code);
        self::assertSame([], $checkpoints);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    public function testChangedDraftPositionsAreRejectedBeforeSevdeskDelivery(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(100),
                $this->positionResponse(['name' => 'Changed remotely']),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->deliverViaSevdesk(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            'customer@example.test',
            'Invoice RE-10',
            'Your final Invoice is attached.',
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_delivery_prewrite_remote_position_identity_mismatch', $result->code);
        self::assertSame([], $checkpoints);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    public function testFullPositionPageCannotProveAnOpenedInvoice(): void
    {
        $history = [];
        $position = json_decode((string) $this->positionResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(200, ['sendType' => 'VPDF']),
                new Response(200, [], json_encode([
                    'objects' => array_fill(0, 1000, $position['objects'][0]),
                ], JSON_THROW_ON_ERROR)),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->reconcileOpened(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame(
            'invoice_open_reconciliation_remote_position_search_truncated',
            $result->code,
        );
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    public function testReconcileDeliveredProvesEarlierWriteWithReadsOnly(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(200, [
                    'sendType' => 'VM',
                    'sendDate' => '2026-07-18T14:35:12+02:00',
                ]),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->reconcileDelivered(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame(['invoice_delivered'], $checkpoints);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    #[DataProvider('unprovenDeliveryStateProvider')]
    public function testReconcileDeliveredRejectsUnprovenStatusOrDeliveryMetadata(
        int $status,
        array $overrides,
        string $expectedCode,
    ): void {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse($status, $overrides),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->reconcileDelivered(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame($expectedCode, $result->code);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    /** @return iterable<string, array{int, array<string, mixed>, string}> */
    public static function unprovenDeliveryStateProvider(): iterable
    {
        yield 'Invoice is not final' => [
            100,
            ['sendType' => 'VM', 'sendDate' => '2026-07-18T14:35:12+02:00'],
            'invoice_delivery_reconciliation_remote_status_mismatch',
        ];
        yield 'send type missing' => [
            200,
            ['sendDate' => '2026-07-18T14:35:12+02:00'],
            'invoice_delivery_reconciliation_send_type_mismatch',
        ];
        yield 'send type does not prove email' => [
            200,
            ['sendType' => 'VPDF', 'sendDate' => '2026-07-18T14:35:12+02:00'],
            'invoice_delivery_reconciliation_send_type_mismatch',
        ];
        yield 'send date missing' => [
            200,
            ['sendType' => 'VM'],
            'invoice_delivery_reconciliation_send_date_missing',
        ];
        yield 'send date is not a timestamp' => [
            200,
            ['sendType' => 'VM', 'sendDate' => 'not-a-timestamp'],
            'invoice_delivery_reconciliation_send_date_invalid',
        ];
        yield 'send date has no explicit timezone' => [
            200,
            ['sendType' => 'VM', 'sendDate' => '2026-07-18T14:35:12'],
            'invoice_delivery_reconciliation_send_date_invalid',
        ];
        yield 'send date is not a real calendar date' => [
            200,
            ['sendType' => 'VM', 'sendDate' => '2026-02-31T14:35:12+01:00'],
            'invoice_delivery_reconciliation_send_date_invalid',
        ];
    }

    public function testReconcileOpenedRejectsMissingSendTypeWithoutExecutingAnotherWrite(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(200),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->reconcileOpened(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_open_reconciliation_send_type_mismatch', $result->code);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    #[DataProvider('changedInvoiceIdentityProvider')]
    public function testReconcileOpenedRejectsChangedContactOrDeliveryCountry(
        array $overrides,
        string $expectedCode,
    ): void {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(200, array_merge(['sendType' => 'VPDF'], $overrides)),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->reconcileOpened(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame($expectedCode, $result->code);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function changedInvoiceIdentityProvider(): iterable
    {
        yield 'contact changed' => [
            ['contact' => ['id' => '77', 'objectName' => 'Contact']],
            'invoice_open_reconciliation_remote_contact_mismatch',
        ];
        yield 'delivery country changed' => [
            ['deliveryAddressCountry' => 'FR'],
            'invoice_open_reconciliation_remote_delivery_country_mismatch',
        ];
    }

    public function testReconcileDeliveredRejectsPositionMismatchWithoutExecutingAnotherWrite(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(200, [
                    'sendType' => 'VM',
                    'sendDate' => '2026-07-18T14:35:12+02:00',
                ]),
                $this->positionResponse(['name' => 'Changed remotely']),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->reconcileDelivered(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_delivery_reconciliation_remote_position_identity_mismatch', $result->code);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    private function invoice(): InvoiceSnapshot
    {
        return new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '119.00',
            '0',
            [new LineItem('Hosting', '100.00', '19', true)],
        );
    }

    private function taxDecision(): TaxDecision
    {
        return TaxDecision::allow('domestic', '1000', '1', 'Domestic profile.');
    }

    private function target(string $authority): DocumentTargetDecision
    {
        return (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            $authority,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($this->taxDecision(), true, true);
    }

    /** @param array<string, mixed> $overrides */
    private function invoiceResponse(int $status, array $overrides = []): Response
    {
        $invoice = array_merge([
            'id' => '99',
            'objectName' => 'Invoice',
            'invoiceType' => 'RE',
            'invoiceNumber' => 'RE-10',
            'invoiceDate' => '01.07.2026',
            'currency' => 'EUR',
            'status' => (string) $status,
            'taxRule' => ['id' => '1', 'objectName' => 'TaxRule'],
            'contact' => ['id' => '42', 'objectName' => 'Contact'],
            'contactPerson' => ['id' => '7', 'objectName' => 'SevUser'],
            'showNet' => true,
            'deliveryAddressCountry' => 'DE',
            'customerInternalNote' => '[WHMCS-INVOICE:10]',
            'sumGross' => '119.00',
        ], $overrides);

        return new Response(200, [], json_encode(['objects' => [$invoice]], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $overrides */
    private function positionResponse(array $overrides = []): Response
    {
        $position = array_merge([
            'id' => '901',
            'objectName' => 'InvoicePos',
            'invoice' => ['id' => '99', 'objectName' => 'Invoice'],
            'unity' => ['id' => '8', 'objectName' => 'Unity'],
            'positionNumber' => '1',
            'quantity' => '1',
            'name' => 'Hosting',
            'text' => 'Hosting',
            'price' => '100.00',
            'taxRate' => '19',
        ], $overrides);

        return new Response(200, [], json_encode(['objects' => [$position]], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return list<string>
     */
    private function requestMethods(array $history): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        );
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new SevdeskClient(new Client(['handler' => $stack]), 'token');
    }
}
