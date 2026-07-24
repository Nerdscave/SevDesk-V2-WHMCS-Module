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
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceAddressContext;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceDiscount;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceExporter;

final class InvoiceExporterTest extends TestCase
{
    public function testConfirmedRule11DiscountUsesDiscountSaveAndExactDocumentGross(): void
    {
        $history = [];
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '80.00',
            '0',
            [new LineItem('Hosting', '100.00', '0', false)],
            [new InvoiceDiscount('Promotion', '20.00', '0', false, 42)],
        );
        $tax = TaxDecision::allowInvoice('small_business', '11', 'Small-business profile.', ['0']);
        $target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($tax, true, true);
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            discountsConfirmed: true,
        );

        $payload = $exporter->buildPayload(
            $invoice,
            '42',
            $tax,
            'DE',
            $target,
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(11, $payload['invoice']['taxRule']['id']);
        self::assertSame(100.0, $payload['invoicePosSave'][0]['price']);
        self::assertSame(
            InvoiceExporter::documentMarker($invoice),
            $payload['invoice']['customerInternalNote'],
        );
        self::assertSame([[
            'discount' => true,
            'text' => 'Promotion',
            'percentage' => false,
            'value' => 20.0,
            'objectName' => 'Discounts',
            'mapAll' => true,
        ]], $payload['discountSave']);
    }

    public function testRule11DiscountRemainsBlockedUntilItsCanaryIsConfirmed(): void
    {
        $history = [];
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '80.00',
            '0',
            [new LineItem('Hosting', '100.00', '0', false)],
            [new InvoiceDiscount('Promotion', '20.00', '0', false, 42)],
        );
        $tax = TaxDecision::allowInvoice('small_business', '11', 'Small-business profile.', ['0']);
        $target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($tax, true, true);
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->export($invoice, '42', $tax, 'DE', $target);

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('invoice_discount_canary_not_confirmed', $result->code);
        self::assertSame([], $history);
    }

    public function testOneCentLineTotalDifferenceIsRejectedBeforeAnInvoiceWrite(): void
    {
        $history = [];
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '119.01',
            '0',
            [new LineItem('Hosting', '100.00', '19', true)],
        );
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->export(
            $invoice,
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('invoice_total_mismatch', $result->code);
        self::assertSame([], $history);
    }

    public function testStructurallyConfirmedCreditKeepsTheFullInvoiceGross(): void
    {
        $history = [];
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '119.00',
            '20.00',
            [new LineItem('Hosting', '119.00', '0', false)],
        );
        $tax = TaxDecision::allowInvoice('small_business', '11', 'Small-business profile.', ['0']);
        $target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($tax, true, true);
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $payload = $exporter->buildPayload(
            $invoice,
            '42',
            $tax,
            'DE',
            $target,
            creditTreatmentConfirmed: true,
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(119.0, $payload['invoicePosSave'][0]['price']);
        self::assertSame('119.00', $invoice->total);
        self::assertSame('20.00', $invoice->creditApplied);
    }

    public function testConfirmedMassPaymentCreditCanCoexistWithOneConfirmedFixedDiscount(): void
    {
        $history = [];
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '80.00',
            '30.00',
            [new LineItem('Hosting', '100.00', '0', false)],
            [new InvoiceDiscount('Promotion', '20.00', '0', false, 42)],
        );
        $tax = TaxDecision::allowInvoice('small_business', '11', 'Small-business profile.', ['0']);
        $target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($tax, true, true);
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            discountsConfirmed: true,
        );

        $payload = $exporter->buildPayload(
            $invoice,
            '42',
            $tax,
            'DE',
            $target,
            creditTreatmentConfirmed: true,
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(100.0, $payload['invoicePosSave'][0]['price']);
        self::assertSame(20.0, $payload['discountSave'][0]['value']);
        self::assertSame(8_000, $invoice->calculatedDocumentGrossMinorUnits());
        self::assertSame(3_000, $invoice->appliedCreditMinorUnits());
    }

    public function testConfirmedMassPaymentCreditDoesNotSilentlyBecomeAZugferdInvoice(): void
    {
        $history = [];
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '119.00',
            '20.00',
            [new LineItem('Hosting', '100.00', '19', true)],
        );
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->export(
            $invoice,
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_SEVDESK),
            eInvoiceContext: $this->eInvoiceContext(),
            creditTreatmentConfirmed: true,
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('e_invoice_credit_not_supported', $result->code);
        self::assertSame([], $history);
    }

    public function testMarkerMatchRejectsMissingOrContradictoryInvoiceMarkers(): void
    {
        self::assertTrue(InvoiceExporter::markerMatches('Reference [WHMCS-INVOICE:10]', 10));
        self::assertFalse(InvoiceExporter::markerMatches('Reference [WHMCS-INVOICE:100]', 10));
        self::assertFalse(InvoiceExporter::markerMatches(
            '[WHMCS-INVOICE:10] duplicate [WHMCS-INVOICE:11]',
            10,
        ));
    }

    public function testNormalInvoiceWithoutFrozenBillingAddressStopsBeforeCreateCheckpointAndHttp(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
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
            static function (string $checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('invoice_address_context_missing', $result->code);
        self::assertSame([], $checkpoints);
        self::assertSame([], $history);
    }

    public function testNormalInvoicePayloadCannotFallBackToTheSevdeskDefaultAddress(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoice_address_context_missing');

        $exporter->buildPayload(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
        );
    }

    public function testNormalInvoiceWithoutFrozenBillingAddressCannotBeOpenedOrDelivered(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(100),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );

        $opened = $exporter->openForWhmcsAuthority(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
        );
        $delivered = $exporter->deliverViaSevdesk(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            'customer@example.test',
            'Invoice RE-10',
            'Your final Invoice is attached.',
        );

        self::assertSame('invoice_address_context_missing', $opened->code);
        self::assertSame('invoice_address_context_missing', $delivered->code);
        self::assertSame([], $history);
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
            static fn (): string => '1',
        ))->withReferences('7', '8');
        $addressContext = $exporter->resolveAddressContext($this->invoice(), $this->contact());
        self::assertInstanceOf(InvoiceAddressContext::class, $addressContext);

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
            static function (string $name) use (&$checkpoints): void {
                $checkpoints[] = $name;
            },
            invoiceAddressContext: $addressContext,
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status, $result->code . ': ' . $result->message);
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
        self::assertSame('DE', $payload['invoice']['deliveryAddressCountry']);
        self::assertFalse($payload['takeDefaultAddress']);
        self::assertSame('Synthetic Company', $payload['invoice']['addressName']);
        self::assertSame('Example Street 1', $payload['invoice']['addressStreet']);
        self::assertSame('12345', $payload['invoice']['addressZip']);
        self::assertSame('Example City', $payload['invoice']['addressCity']);
        self::assertSame(
            ['id' => 1, 'objectName' => 'StaticCountry'],
            $payload['invoice']['addressCountry'],
        );
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

    public function testConfirmedDiscountIsVerifiedAcrossCreateMappingAndOpenLifecycle(): void
    {
        $history = [];
        $discountInvoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '80.00',
            '0',
            [new LineItem('Hosting', '100.00', '0', false)],
            [new InvoiceDiscount('Promotion', '20.00', '0', false, 42)],
        );
        $tax = TaxDecision::allowInvoice('small_business', '11', 'Small-business profile.', ['0']);
        $target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($tax, true, true);
        $remote = [
            'invoiceDate' => '01.07.2025',
            'taxRule' => ['id' => '11', 'objectName' => 'TaxRule'],
            'showNet' => false,
            'sumGross' => '80.00',
            'sumDiscounts' => '20.00',
            'customerInternalNote' => InvoiceExporter::documentMarker($discountInvoice),
        ];
        $position = [
            'price' => '100.00',
            'taxRate' => '0',
        ];
        $client = $this->client([
            new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
            $this->invoiceResponse(100, $remote),
            $this->positionResponse($position),
            $this->invoiceResponse(100, $remote),
            $this->positionResponse($position),
            new Response(200, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
            $this->invoiceResponse(200, $remote),
            $this->positionResponse($position),
        ], $history);
        $mapping = null;
        $checkpoints = [];
        $checkpointContexts = [];
        $exporter = new InvoiceExporter(
            $client,
            static fn (): mixed => $mapping,
            static function (
                int $invoiceId,
                string $remoteId,
                string $documentType,
                string $documentNumber,
            ) use (&$mapping): void {
                $mapping = compact('invoiceId', 'remoteId', 'documentType', 'documentNumber');
            },
            '7',
            '8',
            discountsConfirmed: true,
        );

        $result = $exporter->export(
            $discountInvoice,
            '42',
            $tax,
            'DE',
            $target,
            static function (
                string $checkpoint,
                array $context,
            ) use (
                &$checkpoints,
                &$checkpointContexts,
            ): void {
                $checkpoints[] = $checkpoint;
                $checkpointContexts[$checkpoint] = $context;
            },
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status, $result->code);
        self::assertSame('99', $result->remoteId);
        self::assertSame('99', $mapping['remoteId'] ?? null);
        self::assertSame([
            'invoice_write_requested',
            'invoice_created',
            'mapping_persisted',
            'invoice_open_write_requested',
            'invoice_opened',
        ], $checkpoints);
        self::assertSame(
            ['POST', 'GET', 'GET', 'GET', 'GET', 'PUT', 'GET', 'GET'],
            $this->requestMethods($history),
        );
        self::assertSame(
            $discountInvoice->discountFingerprint(),
            $checkpointContexts['invoice_write_requested']['invoiceDiscountFingerprint'] ?? null,
        );
        self::assertSame(
            1,
            $checkpointContexts['mapping_persisted']['invoiceDiscountCount'] ?? null,
        );
        $payload = json_decode(
            (string) $history[0]['request']->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(20.0, $payload['discountSave'][0]['value']);
        self::assertSame(
            InvoiceExporter::documentMarker($discountInvoice),
            $payload['invoice']['customerInternalNote'],
        );
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

        $payload = $exporter->buildPayload(
            $invoice,
            '42',
            $tax,
            'FR',
            $target,
            invoiceAddressContext: $this->invoiceAddressContext('FR', '55'),
        );
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertSame(19, $payload['invoice']['taxRule']['id']);
        self::assertSame('fr', $payload['invoice']['deliveryAddressCountry']);
        self::assertSame(['id' => 55, 'objectName' => 'StaticCountry'], $payload['invoice']['addressCountry']);
        self::assertSame(20.0, $payload['invoicePosSave'][0]['taxRate']);
        self::assertFalse($payload['invoice']['showNet']);
        self::assertStringNotContainsString('accountDatev', $encoded);
        self::assertArrayNotHasKey('filename', $payload);
    }

    public function testBillingCountryReferenceIsRequiredBeforeTheWriteCheckpoint(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            static fn (): null => null,
        );

        $result = $exporter->resolveAddressContext($this->invoice(), $this->contact('FR'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('invoice_address_country_reference_missing', $result->code);
        self::assertSame([], $history);
    }

    public function testDeletedFrozenInvoiceReferencesBlockBeforeCheckpointAndPost(): void
    {
        $history = [];
        $validatedReferences = [];
        $checkpoints = [];
        $exporter = (new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '70',
            '80',
            validateReferences: static function (
                string $sevUserId,
                string $unityId,
            ) use (&$validatedReferences): bool {
                $validatedReferences[] = [$sevUserId, $unityId];

                return false;
            },
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
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('invoice_reference_snapshot_missing', $result->code);
        self::assertSame([['7', '8']], $validatedReferences);
        self::assertSame([], $checkpoints);
        self::assertSame([], $history);
    }

    #[DataProvider('referenceLookupFailureProvider')]
    public function testTransientInvoiceReferenceLookupRemainsSafeBeforeTheWrite(
        ApiException $failure,
        string $expectedCode,
    ): void {
        $history = [];
        $checkpoints = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            validateReferences: static function () use ($failure): never {
                throw $failure;
            },
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            $this->target(DocumentTargetResolver::AUTHORITY_WHMCS),
            static function (string $name) use (&$checkpoints): void {
                $checkpoints[] = $name;
            },
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame($expectedCode, $result->code);
        self::assertFalse($result->context['outcomeUnknown']);
        self::assertArrayNotHasKey('definiteWriteRejected', $result->context);
        self::assertSame([], $checkpoints);
        self::assertSame([], $history);
    }

    /** @return iterable<string,array{ApiException,string}> */
    public static function referenceLookupFailureProvider(): iterable
    {
        yield 'rate limit' => [
            new ApiException('Synthetic rate limit.', 429, 'RATE_LIMIT', retryAfterSeconds: 60),
            'api_rate_limited',
        ];
        yield 'transport timeout' => [
            new ApiException('Synthetic timeout.', null, 'transport_error', outcomeUnknown: true),
            'invoice_reference_revalidation_failed',
        ];
    }

    public function testPostCreateRecoveryDoesNotRevalidateCurrentReferenceLists(): void
    {
        $history = [];
        $validationCalls = 0;
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(200, ['sendType' => 'VPDF']),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
            validateReferences: static function () use (&$validationCalls): bool {
                ++$validationCalls;

                return false;
            },
        );

        $result = $exporter->reconcileOpened(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status, $result->code);
        self::assertSame(0, $validationCalls);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    #[DataProvider('countryLookupFailureProvider')]
    public function testBillingCountryLookupFailureRemainsAReadOnlyPreWriteFailure(
        ?int $httpStatus,
        string $sevdeskCode,
        bool $outcomeUnknown,
        string $expectedCode,
    ): void {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            static function () use ($httpStatus, $sevdeskCode, $outcomeUnknown): never {
                throw new ApiException(
                    'Synthetic country lookup failure.',
                    $httpStatus,
                    $sevdeskCode,
                    outcomeUnknown: $outcomeUnknown,
                );
            },
        );

        $result = $exporter->resolveAddressContext($this->invoice(), $this->contact('FR'));

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame($expectedCode, $result->code);
        self::assertFalse($result->context['outcomeUnknown']);
        self::assertArrayNotHasKey('definiteWriteRejected', $result->context);
        self::assertSame([], $history);
    }

    /** @return iterable<string,array{int|null,string,bool,string}> */
    public static function countryLookupFailureProvider(): iterable
    {
        yield 'rate limit' => [429, 'RATE_LIMIT', false, 'api_rate_limited'];
        yield 'server failure' => [503, 'SERVER_ERROR', false, 'invoice_address_country_reference_failed'];
        yield 'transport timeout' => [null, 'transport_error', true, 'invoice_address_country_reference_failed'];
        yield 'validation rejection' => [
            422,
            'VALIDATION',
            false,
            'invoice_address_country_reference_failed_permanent',
        ];
    }

    public function testRule19ExportUsesExactCountryReferenceAndBillingCountryReadback(): void
    {
        $history = [];
        $rule19Invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '120.00',
            '0',
            [new LineItem('Digital service', '120.00', '20', false)],
        );
        $rule19Tax = TaxDecision::allowInvoiceRule19(
            'eu_b2c_oss_rule19',
            'Confirmed digital service.',
            ['20'],
        );
        $rule19Target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
        ))->resolve($rule19Tax, true, true);
        $remoteDraft = [
            'taxRule' => ['id' => '19', 'objectName' => 'TaxRule'],
            'showNet' => false,
            'deliveryAddressCountry' => null,
            'addressCountry' => ['id' => '1490', 'objectName' => 'StaticCountry', 'code' => 'cy'],
            'sumGross' => '120.00',
        ];
        $remotePosition = [
            'name' => 'Digital service',
            'text' => 'Digital service',
            'price' => '120.00',
            'taxRate' => '20',
        ];
        $client = $this->client([
            new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
            $this->invoiceResponse(100, $remoteDraft),
            $this->positionResponse($remotePosition),
            $this->invoiceResponse(100, $remoteDraft),
            $this->positionResponse($remotePosition),
            new Response(200, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
            $this->invoiceResponse(200, array_merge($remoteDraft, ['sendType' => 'VPDF'])),
            $this->positionResponse($remotePosition),
        ], $history);
        $mappings = [];
        $exporter = new InvoiceExporter(
            $client,
            static fn (int $invoiceId): mixed => $mappings[$invoiceId] ?? null,
            static function (int $invoiceId, string $remoteId) use (&$mappings): void {
                $mappings[$invoiceId] = $remoteId;
            },
            '7',
            '8',
            static fn (string $countryCode): string => $countryCode === 'CY' ? '1490' : '',
        );

        $result = $exporter->export(
            $rule19Invoice,
            '42',
            $rule19Tax,
            'CY',
            $rule19Target,
            invoiceAddressContext: $this->invoiceAddressContext('CY', '1490'),
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status, $result->code . ': ' . $result->message);
        self::assertSame([10 => '99'], $mappings);
        $payload = json_decode((string) $history[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('cy', $payload['invoice']['deliveryAddressCountry']);
        self::assertSame(
            ['id' => 1490, 'objectName' => 'StaticCountry'],
            $payload['invoice']['addressCountry'],
        );
        foreach ([1, 3, 6] as $historyIndex) {
            self::assertStringContainsString(
                'embed=addressCountry',
                (string) $history[$historyIndex]['request']->getUri()->getQuery(),
            );
        }
        self::assertSame(1, $this->countRequests($history, 'POST', '/api/v1/Invoice/Factory/saveInvoice'));
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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

    public function testDefiniteEInvoiceValidationRejectionNamesRequiredDataWithoutFallback(): void
    {
        $history = [];
        $persistCalls = 0;
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(422, [], '{"error":{"code":"VALIDATION_FAILED"}}'),
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
            $this->target(DocumentTargetResolver::AUTHORITY_SEVDESK),
            eInvoiceContext: $this->eInvoiceContext(),
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('e_invoice_required_data_rejected', $result->code);
        self::assertTrue($result->context['definiteWriteRejected']);
        self::assertStringContainsString('no normal-Invoice fallback', $result->message);
        self::assertSame(0, $persistCalls);
        self::assertCount(1, $history);
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('checkpoint_persist_failed', $result->code);
        self::assertCount(0, $history);
    }

    public function testPreWriteGuardRunsBeforeCheckpointAndInvoicePost(): void
    {
        $history = [];
        $checkpoints = [];
        $lookups = 0;
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static function () use (&$lookups): null {
                ++$lookups;

                return null;
            },
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
            static function (string $name) use (&$checkpoints): bool {
                $checkpoints[] = $name;

                return true;
            },
            preWriteGuard: static fn (): bool => false,
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('pre_write_guard_failed', $result->code);
        self::assertSame(2, $lookups);
        self::assertSame([], $checkpoints);
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
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
            invoiceAddressContext: $this->invoiceAddressContext(),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_delivery_reconciliation_remote_position_identity_mismatch', $result->code);
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
    }

    public function testNativeZugferdCreateUsesFrozenStructuredDataAndVerifiesXml(): void
    {
        $history = [];
        $mappings = [];
        $checkpoints = [];
        $xml = $this->zugferdXml();
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
                $this->eInvoiceResponseWithoutFlag(100),
                $this->positionResponse(),
                $this->xmlResponse($xml),
            ], $history),
            static fn (): null => null,
            static function (
                int $invoiceId,
                string $remoteId,
                string $type,
                string $number,
                bool $isEInvoice,
                ?string $xmlSha256,
            ) use (&$mappings): void {
                $mappings[] = compact(
                    'invoiceId',
                    'remoteId',
                    'type',
                    'number',
                    'isEInvoice',
                    'xmlSha256',
                );
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
            static function (string $name) use (&$checkpoints): void {
                $checkpoints[] = $name;
            },
            $this->eInvoiceContext(),
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertTrue($result->context['isEInvoice']);
        self::assertSame(hash('sha256', $xml), $result->context['xmlSha256']);
        self::assertSame([[
            'invoiceId' => 10,
            'remoteId' => '99',
            'type' => 'invoice',
            'number' => 'RE-10',
            'isEInvoice' => true,
            'xmlSha256' => hash('sha256', $xml),
        ]], $mappings);
        self::assertSame([
            'invoice_write_requested',
            'invoice_created',
            'invoice_xml_verified',
            'mapping_persisted',
        ], $checkpoints);
        self::assertSame(['POST', 'GET', 'GET', 'GET'], $this->requestMethods($history));

        $payload = json_decode(
            (string) $history[0]['request']->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertTrue($payload['invoice']['propertyIsEInvoice']);
        self::assertSame(9, $payload['invoice']['paymentMethod']['id']);
        self::assertSame('Musterstr. 1', $payload['invoice']['addressStreet']);
        self::assertSame('12345', $payload['invoice']['addressZip']);
        self::assertSame('Berlin', $payload['invoice']['addressCity']);
        self::assertSame(1, $payload['invoice']['addressCountry']['id']);
        self::assertFalse($payload['takeDefaultAddress']);
    }

    public function testMissingEInvoiceFlagStillRequiresStructurallyValidXmlAfterCreate(): void
    {
        $history = [];
        $mappings = [];
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
                $this->eInvoiceResponseWithoutFlag(100),
                $this->positionResponse(),
                new Response(200, [], '{}'),
            ], $history),
            static fn (): null => null,
            static function () use (&$mappings): void {
                $mappings[] = true;
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
            eInvoiceContext: $this->eInvoiceContext(),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_xml_verification_invalid', $result->code);
        self::assertSame([], $mappings);
        self::assertSame(['POST', 'GET', 'GET', 'GET'], $this->requestMethods($history));
    }

    public function testExplicitFalseEInvoiceFlagIsNeverOverriddenByValidXmlAfterCreate(): void
    {
        $history = [];
        $xml = $this->zugferdXml();
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
                $this->eInvoiceResponse(100, ['propertyIsEInvoice' => false]),
                $this->positionResponse(),
                $this->xmlResponse($xml),
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
            $this->target(DocumentTargetResolver::AUTHORITY_SEVDESK),
            eInvoiceContext: $this->eInvoiceContext(),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('remote_e_invoice_flag_mismatch', $result->code);
        self::assertSame(['POST', 'GET'], $this->requestMethods($history));
    }

    public function testChangedNativeXmlNeverReplacesTheFrozenRecoveryHash(): void
    {
        $history = [];
        $oldXml = $this->zugferdXml();
        $newXml = str_replace(
            '</rsm:CrossIndustryInvoice>',
            '<!-- changed --></rsm:CrossIndustryInvoice>',
            $oldXml,
        );
        $exporter = new InvoiceExporter(
            $this->client([
                new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
                $this->eInvoiceResponse(100),
                $this->positionResponse(),
                $this->xmlResponse($newXml),
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
            $this->target(DocumentTargetResolver::AUTHORITY_SEVDESK),
            eInvoiceContext: $this->eInvoiceContext(hash('sha256', $oldXml)),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_xml_verification_hash_mismatch', $result->code);
        self::assertSame(hash('sha256', $oldXml), $result->context['xmlSha256']);
        self::assertSame(hash('sha256', $newXml), $result->context['observedXmlSha256']);
    }

    public function testZugferdDeliverySuppressesLooseXmlAttachment(): void
    {
        $history = [];
        $xml = $this->zugferdXml();
        $hash = hash('sha256', $xml);
        $exporter = new InvoiceExporter(
            $this->client([
                $this->eInvoiceResponse(100),
                $this->positionResponse(),
                $this->xmlResponse($xml),
                new Response(201, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
                $this->eInvoiceResponse(200, [
                    'sendType' => 'VM',
                    'sendDate' => '2026-07-18T14:35:12+02:00',
                ]),
                $this->positionResponse(),
                $this->xmlResponse($xml),
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
            'billing@example.test',
            'Rechnung RE-10',
            'Ihre Rechnung ist angehängt.',
            eInvoiceContext: $this->eInvoiceContext($hash),
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        $payload = json_decode(
            (string) $history[3]['request']->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertFalse($payload['sendXml']);
        self::assertSame(['GET', 'GET', 'GET', 'POST', 'GET', 'GET', 'GET'], $this->requestMethods($history));
    }

    public function testZugferdCanBeFinalisedWithSendByForWhmcsTemplateDelivery(): void
    {
        $history = [];
        $xml = $this->zugferdXml();
        $context = $this->eInvoiceContext(hash('sha256', $xml));
        $exporter = new InvoiceExporter(
            $this->client([
                $this->eInvoiceResponse(100),
                $this->positionResponse(),
                $this->xmlResponse($xml),
                new Response(200, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
                $this->eInvoiceResponse(200),
                $this->positionResponse(),
                $this->xmlResponse($xml),
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
            eInvoiceContext: $context,
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertTrue($result->context['isEInvoice']);
        self::assertSame(['GET', 'GET', 'GET', 'PUT', 'GET', 'GET', 'GET'], $this->requestMethods($history));
    }

    public function testZugferdCannotUseRule19AsSilentFallback(): void
    {
        $history = [];
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
        $tax = TaxDecision::allowInvoiceRule19('oss', 'confirmed', ['20']);
        $target = DocumentTargetDecision::select(
            DocumentTargetDecision::DOCUMENT_INVOICE,
            DocumentTargetResolver::AUTHORITY_SEVDESK,
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            '19',
            'selected',
            'selected',
        );
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $exporter->export($invoice, '42', $tax, 'DE', $target, eInvoiceContext: $this->eInvoiceContext());

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('e_invoice_tax_rule_not_supported', $result->code);
        self::assertSame([], $history);
    }

    public function testSendByIsBlockedWhenTheDraftHasNoFrozenWhmcsBillingAddress(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([
                $this->invoiceResponse(100, [
                    'addressName' => '',
                    'addressStreet' => '',
                    'addressZip' => '',
                    'addressCity' => '',
                    'addressCountry' => null,
                ]),
                $this->positionResponse(),
            ], $history),
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
            static fn (): string => '1',
        );
        $addressContext = $exporter->resolveAddressContext($this->invoice(), $this->contact());
        self::assertInstanceOf(InvoiceAddressContext::class, $addressContext);

        $result = $exporter->openForWhmcsAuthority(
            $this->invoice(),
            '99',
            $this->taxDecision(),
            '42',
            'DE',
            invoiceAddressContext: $addressContext,
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame(
            'invoice_open_prewrite_remote_invoice_address_missing',
            $result->code,
        );
        self::assertSame(['GET', 'GET'], $this->requestMethods($history));
        self::assertSame(0, $this->countRequests($history, 'PUT', '/api/v1/Invoice/99/sendBy'));
    }

    public function testIncompleteWhmcsBillingAddressIsRejectedBeforeAnInvoicePost(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            static fn (): string => '1',
        );
        $contact = new ContactData(
            20,
            '42',
            'Synthetic Company',
            'Synthetic',
            'Customer',
            'synthetic@example.invalid',
            'Example Street 1',
            '',
            '',
            'Example City',
            'DE',
            null,
            false,
        );

        $result = $exporter->resolveAddressContext($this->invoice(), $contact);

        self::assertInstanceOf(ExportResult::class, $result);
        self::assertSame('invoice_address_invalid', $result->code);
        self::assertSame([], $history);
    }

    public function testNonUniqueBillingCountryIsRejectedBeforeAnInvoicePost(): void
    {
        $history = [];
        $exporter = new InvoiceExporter(
            $this->client([], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            static fn (): null => null,
        );

        $result = $exporter->resolveAddressContext($this->invoice(), $this->contact());

        self::assertInstanceOf(ExportResult::class, $result);
        self::assertSame('invoice_address_country_reference_missing', $result->code);
        self::assertSame([], $history);
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

    private function contact(string $countryCode = 'DE'): ContactData
    {
        return new ContactData(
            20,
            '42',
            'Synthetic Company',
            'Synthetic',
            'Customer',
            'synthetic@example.invalid',
            'Example Street 1',
            '',
            '12345',
            'Example City',
            $countryCode,
            null,
            false,
        );
    }

    private function invoiceAddressContext(
        string $countryCode = 'DE',
        string $countryId = '1',
    ): InvoiceAddressContext {
        return InvoiceAddressContext::fromContact($this->contact($countryCode), $countryId);
    }

    private function target(string $authority): DocumentTargetDecision
    {
        return (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            $authority,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($this->taxDecision(), true, true);
    }

    private function eInvoiceContext(?string $xmlSha256 = null): EInvoiceContext
    {
        $hash = EInvoiceContext::addressHash('Example GmbH', 'Musterstr. 1', '12345', 'Berlin', 'DE');

        return EInvoiceContext::zugferd(
            '42',
            '9',
            '8',
            '1',
            $hash,
            'Example GmbH',
            'Musterstr. 1',
            '12345',
            'Berlin',
            'DE',
            $xmlSha256,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function eInvoiceResponse(int $status, array $overrides = []): Response
    {
        return $this->invoiceResponse($status, array_merge([
            'propertyIsEInvoice' => true,
            'paymentMethod' => ['id' => '9', 'objectName' => 'PaymentMethod'],
            'addressName' => 'Example GmbH',
            'addressStreet' => 'Musterstr. 1',
            'addressZip' => '12345',
            'addressCity' => 'Berlin',
            'addressCountry' => ['id' => '1', 'objectName' => 'StaticCountry', 'code' => 'DE'],
        ], $overrides));
    }

    private function eInvoiceResponseWithoutFlag(int $status): Response
    {
        return $this->invoiceResponse($status, [
            'paymentMethod' => ['id' => '9', 'objectName' => 'PaymentMethod'],
            'addressName' => 'Example GmbH',
            'addressStreet' => 'Musterstr. 1',
            'addressZip' => '12345',
            'addressCity' => 'Berlin',
            'addressCountry' => ['id' => '1', 'objectName' => 'StaticCountry', 'code' => 'DE'],
        ]);
    }

    private function xmlResponse(string $xml): Response
    {
        return new Response(200, [], json_encode(['objects' => $xml], JSON_THROW_ON_ERROR));
    }

    private function zugferdXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:'
            . 'CrossIndustryInvoice:100"></rsm:CrossIndustryInvoice>';
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
            'addressName' => 'Synthetic Company',
            'addressStreet' => 'Example Street 1',
            'addressZip' => '12345',
            'addressCity' => 'Example City',
            'addressCountry' => ['id' => '1', 'objectName' => 'StaticCountry', 'code' => 'DE'],
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

    /** @param array<int, array<string, mixed>> $history */
    private function countRequests(array $history, string $method, string $path): int
    {
        return count(array_filter(
            $history,
            static fn (array $entry): bool => $entry['request']->getMethod() === $method
                && $entry['request']->getUri()->getPath() === $path,
        ));
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
