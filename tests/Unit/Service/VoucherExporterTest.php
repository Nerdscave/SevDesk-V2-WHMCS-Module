<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;

final class VoucherExporterTest extends TestCase
{
    public function testOneCentWhmcsRoundingDifferenceRemainsAllowedForVoucher(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
            new Response(201, [], '{"objects":{"voucher":{"id":99}}}'),
            $this->voucherResponse('119.00'),
            $this->voucherPositionsResponse(),
        ], $history);
        $mappings = [];
        $exporter = new VoucherExporter(
            $client,
            static fn (int $invoiceId): mixed => $mappings[$invoiceId] ?? null,
            static function (int $invoiceId, string $remoteId) use (&$mappings): void {
                $mappings[$invoiceId] = $remoteId;
            },
        );
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

        $result = $exporter->export(
            $invoice,
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame([10 => '99'], $mappings);
        self::assertCount(4, $history);
    }

    public function testSuccessfulExportWritesMappingAfterRemoteTotalValidation(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
            new Response(201, [], '{"objects":{"voucher":{"id":99}}}'),
            $this->voucherResponse(),
            $this->voucherPositionsResponse(),
        ], $history);
        $mappings = [];
        $checkpoints = [];
        $checkpointContexts = [];
        $exporter = new VoucherExporter(
            $client,
            static fn (int $invoiceId): mixed => $mappings[$invoiceId] ?? null,
            static function (int $invoiceId, string $remoteId) use (&$mappings): void {
                $mappings[$invoiceId] = $remoteId;
            },
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
            static function (string $name, array $context) use (&$checkpoints, &$checkpointContexts): void {
                $checkpoints[] = $name;
                $checkpointContexts[$name] = $context;
            },
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('99', $result->remoteId);
        self::assertSame([10 => '99'], $mappings);
        self::assertSame([
            'pdf_upload_requested',
            'pdf_uploaded',
            'voucher_write_requested',
            'voucher_created',
            'mapping_persisted',
        ], $checkpoints);
        self::assertSame('1', $checkpointContexts['voucher_write_requested']['targetTaxRuleId'] ?? null);
        self::assertSame('100', $checkpointContexts['voucher_write_requested']['targetAccountDatevId'] ?? null);

        $payload = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('[WHMCS-INVOICE:10]', substr($payload['voucher']['description'], -18));
        self::assertSame(1, $payload['voucher']['taxRule']['id']);
        self::assertSame(100, $payload['voucher']['status']);
        self::assertSame(100.0, $payload['voucherPosSave'][0]['sumNet']);
        self::assertArrayNotHasKey('accountingType', $payload['voucherPosSave'][0]);
        self::assertArrayNotHasKey('taxType', $payload['voucher']);
    }

    public function testLostOrFailedVoucherWriteIsAmbiguousAndNeverMapped(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
            new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
        ], $history);
        $persistCalls = 0;
        $exporter = new VoucherExporter(
            $client,
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('voucher_create_failed_ambiguous', $result->code);
        self::assertSame(0, $persistCalls);
    }

    public function testRemoteTotalMismatchLeavesMappingEmpty(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
            new Response(201, [], '{"objects":{"voucher":{"id":99}}}'),
            $this->voucherResponse('118.00'),
        ], $history);
        $persistCalls = 0;
        $exporter = new VoucherExporter(
            $client,
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('remote_total_mismatch', $result->code);
        self::assertSame('99', $result->remoteId);
        self::assertSame(0, $persistCalls);
    }

    public function testMappingIsRecheckedImmediatelyBeforeVoucherWrite(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
        ], $history);
        $lookups = 0;
        $exporter = new VoucherExporter(
            $client,
            static function () use (&$lookups): ?string {
                ++$lookups;

                return $lookups === 1 ? null : '77';
            },
            static fn (): bool => true,
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
        );

        self::assertSame(ExportResult::SKIPPED, $result->status);
        self::assertSame('77', $result->remoteId);
        self::assertSame(2, $lookups);
        self::assertCount(1, $history);
    }

    public function testPreWriteGuardRunsAfterUploadAndPreventsVoucherPost(): void
    {
        $history = [];
        $checkpoints = [];
        $exporter = new VoucherExporter(
            $this->client([
                new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
            static function (string $name) use (&$checkpoints): bool {
                $checkpoints[] = $name;

                return true;
            },
            false,
            static fn (): bool => false,
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('pre_write_guard_failed', $result->code);
        self::assertSame(['pdf_upload_requested', 'pdf_uploaded'], $checkpoints);
        self::assertCount(1, $history);
    }

    public function testFailedVoucherWriteCheckpointPreventsVoucherPost(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
        ], $history);
        $exporter = new VoucherExporter($client, static fn (): null => null, static fn (): bool => true);

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
            static fn (string $name): bool => $name !== 'voucher_write_requested',
        );

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('checkpoint_persist_failed', $result->code);
        self::assertCount(1, $history);
    }

    public function testAppliedCreditFailsPreflightWithoutAnyRemoteRequest(): void
    {
        $history = [];
        $client = $this->client([], $history);
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
        $exporter = new VoucherExporter($client, static fn (): null => null, static fn (): bool => true);

        $result = $exporter->export($invoice, '42', $this->taxDecision(), '%PDF-1.7');

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('credit_applied_requires_review', $result->code);
        self::assertCount(0, $history);
    }

    public function testForeignCurrencyFailsPreflightWithoutAnyRemoteRequest(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'USD',
            '119.00',
            '0',
            [new LineItem('Hosting', '100.00', '19', true)],
        );
        $persistCalls = 0;
        $exporter = new VoucherExporter(
            $client,
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
        );

        $result = $exporter->export($invoice, '42', $this->taxDecision(), '%PDF-1.7');

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('foreign_currency_requires_review', $result->code);
        self::assertSame(0, $persistCalls);
        self::assertCount(0, $history);
    }

    public function testExplicitCreditTreatmentExportsFullGrossWithoutProportionalReduction(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
            new Response(201, [], '{"objects":{"voucher":{"id":99}}}'),
            $this->voucherResponse(),
            $this->voucherPositionsResponse(),
        ], $history);
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
        $mappings = [];
        $exporter = new VoucherExporter(
            $client,
            static fn (int $invoiceId): mixed => $mappings[$invoiceId] ?? null,
            static function (int $invoiceId, string $remoteId) use (&$mappings): void {
                $mappings[$invoiceId] = $remoteId;
            },
        );

        $result = $exporter->export(
            $invoice,
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
            null,
            true,
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame([10 => '99'], $mappings);
        $payload = json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100.0, $payload['voucherPosSave'][0]['sumNet']);
        self::assertSame('119.00', $invoice->total);
        self::assertSame('20.00', $invoice->creditApplied);
    }

    public function testWrongRemoteAccountAfterCreateNeverWritesMapping(): void
    {
        $history = [];
        $mappings = [];
        $exporter = new VoucherExporter(
            $this->client([
                new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
                new Response(201, [], '{"objects":{"voucher":{"id":99}}}'),
                $this->voucherResponse(),
                $this->voucherPositionsResponse('999'),
            ], $history),
            static fn (): null => null,
            static function (int $invoiceId, string $remoteId) use (&$mappings): void {
                $mappings[$invoiceId] = $remoteId;
            },
        );

        $result = $exporter->export(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            "%PDF-1.7\nfake document",
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('voucher_remote_position_identity_mismatch', $result->code);
        self::assertSame([], $mappings);
        self::assertCount(4, $history);
    }

    public function testNegativePositionFailsPreflightWithoutRemoteWrite(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '100.00',
            '0',
            [
                new LineItem('Service', '119.00', '19', false),
                new LineItem('Discount', '-19.00', '0', false),
            ],
        );
        $tax = TaxDecision::allow('domestic', '100', '1', 'Domestic sale')
            ->withValidatedGuidance(['0', '19']);
        $exporter = new VoucherExporter($client, static fn (): null => null, static fn (): bool => true);

        $result = $exporter->export($invoice, '42', $tax, '%PDF-1.7');

        self::assertSame(ExportResult::FAILED, $result->status);
        self::assertSame('negative_line_requires_review', $result->code);
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

        return new SevdeskClient(new Client(['handler' => $stack]), 'token');
    }

    private function voucherResponse(
        string $sumGross = '119.00',
        string $taxRuleId = '1',
        string $contactId = '42',
    ): Response {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '99',
                'objectName' => 'Voucher',
                'voucherType' => 'VOU',
                'creditDebit' => 'D',
                'status' => 100,
                'voucherDate' => '01.07.2026',
                'currency' => 'EUR',
                'supplier' => ['id' => $contactId, 'objectName' => 'Contact'],
                'taxRule' => ['id' => $taxRuleId, 'objectName' => 'TaxRule'],
                'description' => 'RE-10 [WHMCS-INVOICE:10]',
                'sumGross' => $sumGross,
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function voucherPositionsResponse(string $accountDatevId = '100'): Response
    {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '501',
                'objectName' => 'VoucherPos',
                'voucher' => ['id' => '99', 'objectName' => 'Voucher'],
                'accountDatev' => ['id' => $accountDatevId, 'objectName' => 'AccountDatev'],
                'taxRate' => '19',
                'net' => true,
                'sumNet' => '100.00',
                'sumGross' => '119.00',
                'comment' => 'Hosting',
            ]],
        ], JSON_THROW_ON_ERROR));
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
        return TaxDecision::allow('domestic', '100', '1', 'Domestic sale')
            ->withValidatedGuidance(['19']);
    }
}
