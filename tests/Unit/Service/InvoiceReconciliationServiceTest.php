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
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceReconciliationService;

final class InvoiceReconciliationServiceTest extends TestCase
{
    public function testItRestoresOneExactTypedMappingUsingOnlyAFilteredRead(): void
    {
        $history = [];
        $mappings = [];
        $checkpoints = [];
        $service = (new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], json_encode([
                    'objects' => [$this->candidate('99')],
                ], JSON_THROW_ON_ERROR)),
                $this->positionResponse(),
            ], $history),
            static fn (): null => null,
            static function (int $invoiceId, string $remoteId, string $type, string $number) use (&$mappings): void {
                $mappings[] = [$invoiceId, $remoteId, $type, $number];
            },
            '70',
            '80',
        ))->withReferences('7', '8');

        $result = $service->reconcile(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            checkpoint: static function (string $name) use (&$checkpoints): void {
                $checkpoints[] = $name;
            },
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('99', $result->remoteId);
        self::assertSame([[10, '99', 'invoice', 'RE-10']], $mappings);
        self::assertSame(['mapping_persisted'], $checkpoints);
        self::assertCount(2, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        $query = $history[0]['request']->getUri()->getQuery();
        self::assertStringContainsString('invoiceNumber=RE-10', $query);
        self::assertStringContainsString('contact%5Bid%5D=42', $query);
    }

    public function testKnownRemoteRecoveryAfterAnUncertainCreateNeverPostsAgain(): void
    {
        $history = [];
        $persistCalls = 0;
        $service = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], json_encode([
                    'objects' => [$this->candidate('99')],
                ], JSON_THROW_ON_ERROR)),
                $this->positionResponse(),
            ], $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $service->reconcile($this->invoice(), '42', $this->taxDecision(), 'DE', '99');

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame(1, $persistCalls);
        self::assertCount(2, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('/api/v1/Invoice/99', $history[0]['request']->getUri()->getPath());
        self::assertSame('/api/v1/Invoice/99/getPositions', $history[1]['request']->getUri()->getPath());
    }

    public function testAssociativeSinglePositionResponseCanRestoreTheMapping(): void
    {
        $history = [];
        $mappings = [];
        $position = json_decode(
            (string) $this->positionResponse()->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $service = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], json_encode([
                    'objects' => [$this->candidate('99')],
                ], JSON_THROW_ON_ERROR)),
                new Response(200, [], json_encode([
                    'invoicePos' => $position['objects'][0],
                ], JSON_THROW_ON_ERROR)),
            ], $history),
            static fn (): null => null,
            static function (int $invoiceId, string $remoteId, string $type, string $number) use (&$mappings): void {
                $mappings[] = [$invoiceId, $remoteId, $type, $number];
            },
            '7',
            '8',
        );

        $result = $service->reconcile($this->invoice(), '42', $this->taxDecision(), 'DE', '99');

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('99', $result->remoteId);
        self::assertSame([[10, '99', 'invoice', 'RE-10']], $mappings);
        self::assertSame(['GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testAContradictoryCandidateRemainsAmbiguousAndUnmapped(): void
    {
        $history = [];
        $persistCalls = 0;
        $candidate = $this->candidate('99');
        $candidate['currency'] = 'USD';
        $service = new InvoiceReconciliationService(
            $this->client(new Response(200, [], json_encode([
                'objects' => [$candidate],
            ], JSON_THROW_ON_ERROR)), $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $service->reconcile($this->invoice(), '42', $this->taxDecision(), 'DE');

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_reconciliation_no_match', $result->code);
        self::assertSame(0, $persistCalls);
    }

    public function testMultipleExactCandidatesRemainAmbiguousAndUnmapped(): void
    {
        $history = [];
        $persistCalls = 0;
        $service = new InvoiceReconciliationService(
            $this->client(new Response(200, [], json_encode([
                'objects' => [$this->candidate('99'), $this->candidate('100')],
            ], JSON_THROW_ON_ERROR)), $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $service->reconcile($this->invoice(), '42', $this->taxDecision(), 'DE');

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_reconciliation_multiple_matches', $result->code);
        self::assertSame(2, $result->context['matchCount']);
        self::assertSame(0, $persistCalls);
    }

    public function testFullSearchPageBlocksBecauseGlobalUniquenessCannotBeProven(): void
    {
        $history = [];
        $persistCalls = 0;
        $service = new InvoiceReconciliationService(
            $this->client(new Response(200, [], json_encode([
                'objects' => array_fill(0, 1000, $this->candidate('99')),
            ], JSON_THROW_ON_ERROR)), $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $service->reconcile($this->invoice(), '42', $this->taxDecision(), 'DE');

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_reconciliation_search_truncated', $result->code);
        self::assertSame(1000, $result->context['candidateCount']);
        self::assertSame(0, $persistCalls);
        self::assertCount(1, $history);
        self::assertStringContainsString('limit=1000', $history[0]['request']->getUri()->getQuery());
    }

    public function testMatchingHeaderWithDifferentPositionsRemainsAmbiguousAndUnmapped(): void
    {
        $history = [];
        $persistCalls = 0;
        $position = $this->positionResponse('99.99');
        $service = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], json_encode([
                    'objects' => [$this->candidate('99')],
                ], JSON_THROW_ON_ERROR)),
                $position,
            ], $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $service->reconcile($this->invoice(), '42', $this->taxDecision(), 'DE');

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_reconciliation_position_amount_mismatch', $result->code);
        self::assertSame(0, $persistCalls);
        self::assertSame(['GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testFullPositionPageCannotRestoreAMapping(): void
    {
        $history = [];
        $persistCalls = 0;
        $position = json_decode((string) $this->positionResponse()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $service = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], json_encode([
                    'objects' => [$this->candidate('99')],
                ], JSON_THROW_ON_ERROR)),
                new Response(200, [], json_encode([
                    'objects' => array_fill(0, 1000, $position['objects'][0]),
                ], JSON_THROW_ON_ERROR)),
            ], $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $service->reconcile($this->invoice(), '42', $this->taxDecision(), 'DE');

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_reconciliation_position_search_truncated', $result->code);
        self::assertSame(0, $persistCalls);
        self::assertSame(['GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testHistoricalGuardOnlyClearsAfterInvoiceAndVoucherReadsAreEmpty(): void
    {
        $history = [];
        $service = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[]}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $service->historicalDuplicateRisk(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('invoice_duplicate_guard_clear', $result->code);
        self::assertNull($result->remoteId);
        self::assertSame(['/api/v1/Invoice', '/api/v1/Invoice', '/api/v1/Voucher', '/api/v1/Voucher'], array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $history,
        ));
        self::assertStringContainsString('invoiceNumber=RE-10', $history[0]['request']->getUri()->getQuery());
        self::assertStringNotContainsString('startAmount=', $history[1]['request']->getUri()->getQuery());
        self::assertStringNotContainsString('endAmount=', $history[1]['request']->getUri()->getQuery());
        self::assertStringContainsString('descriptionLike=RE-10', $history[2]['request']->getUri()->getQuery());
        self::assertStringContainsString(
            'descriptionLike=%5BWHMCS-INVOICE%3A10%5D',
            $history[3]['request']->getUri()->getQuery(),
        );
    }

    public function testHistoricalGuardBlocksEveryRemoteNumberCandidateWithoutMappingIt(): void
    {
        $history = [];
        $persistCalls = 0;
        $service = new InvoiceReconciliationService(
            $this->client(new Response(200, [], json_encode([
                'objects' => [['id' => '77', 'invoiceNumber' => 'RE-10']],
            ], JSON_THROW_ON_ERROR)), $history),
            static fn (): null => null,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            '7',
            '8',
        );

        $result = $service->historicalDuplicateRisk(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('historical_remote_duplicate_possible', $result->code);
        self::assertSame(0, $persistCalls);
        self::assertCount(1, $history);
    }

    public function testHistoricalGuardAlsoBlocksContextOrVoucherCandidates(): void
    {
        $history = [];
        $contextService = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], json_encode([
                    'objects' => [$this->candidate('77')],
                ], JSON_THROW_ON_ERROR)),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );
        $contextRisk = $contextService->historicalDuplicateRisk(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
        );

        self::assertSame('historical_remote_duplicate_possible', $contextRisk->code);
        self::assertSame(1, $contextRisk->context['contextCandidateCount']);

        $history = [];
        $nonMatchingService = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], json_encode(['objects' => [array_merge(
                    $this->candidate('78'),
                    ['sumGross' => '1.00'],
                )]], JSON_THROW_ON_ERROR)),
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[]}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );
        $nonMatchingRisk = $nonMatchingService->historicalDuplicateRisk(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
        );

        self::assertSame(ExportResult::SUCCEEDED, $nonMatchingRisk->status);

        $history = [];
        $markerlessVoucherService = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[{"id":"88"}]}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );
        $markerlessVoucherRisk = $markerlessVoucherService->historicalDuplicateRisk(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
        );

        self::assertSame('historical_remote_duplicate_possible', $markerlessVoucherRisk->code);
        self::assertSame(1, $markerlessVoucherRisk->context['markerlessVoucherCandidateCount']);

        $history = [];
        $voucherService = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], '{"objects":[{"id":"89"}]}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );
        $voucherRisk = $voucherService->historicalDuplicateRisk(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
        );

        self::assertSame('historical_remote_duplicate_possible', $voucherRisk->code);
        self::assertSame(1, $voucherRisk->context['voucherCandidateCount']);
    }

    public function testEInvoiceRecoveryVerifiesXmlAndPersistsTypedMetadata(): void
    {
        $history = [];
        $mappings = [];
        $xml = $this->zugferdXml();
        $candidate = array_merge($this->candidate('99'), $this->eInvoiceFields());
        $service = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], json_encode(['objects' => [$candidate]], JSON_THROW_ON_ERROR)),
                $this->positionResponse(),
                new Response(200, [], json_encode(['objects' => $xml], JSON_THROW_ON_ERROR)),
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
                $mappings[] = [$invoiceId, $remoteId, $type, $number, $isEInvoice, $xmlSha256];
            },
            '7',
            '8',
        );

        $result = $service->reconcile(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            eInvoiceContext: $this->eInvoiceContext(hash('sha256', $xml)),
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame([[
            10,
            '99',
            'invoice',
            'RE-10',
            true,
            hash('sha256', $xml),
        ]], $mappings);
        self::assertSame(['GET', 'GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testEInvoiceRecoveryNeverAcceptsAChangedXmlHash(): void
    {
        $history = [];
        $oldXml = $this->zugferdXml();
        $newXml = str_replace(
            '</rsm:CrossIndustryInvoice>',
            '<!-- changed --></rsm:CrossIndustryInvoice>',
            $oldXml,
        );
        $candidate = array_merge($this->candidate('99'), $this->eInvoiceFields());
        $service = new InvoiceReconciliationService(
            $this->client([
                new Response(200, [], json_encode(['objects' => [$candidate]], JSON_THROW_ON_ERROR)),
                $this->positionResponse(),
                new Response(200, [], json_encode(['objects' => $newXml], JSON_THROW_ON_ERROR)),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
        );

        $result = $service->reconcile(
            $this->invoice(),
            '42',
            $this->taxDecision(),
            'DE',
            eInvoiceContext: $this->eInvoiceContext(hash('sha256', $oldXml)),
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('invoice_reconciliation_xml_hash_mismatch', $result->code);
        self::assertSame(hash('sha256', $oldXml), $result->context['xmlSha256']);
        self::assertSame(hash('sha256', $newXml), $result->context['observedXmlSha256']);
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

    /** @return array<string, mixed> */
    private function eInvoiceFields(): array
    {
        return [
            'propertyIsEInvoice' => true,
            'paymentMethod' => ['id' => '9', 'objectName' => 'PaymentMethod'],
            'addressName' => 'Example GmbH',
            'addressStreet' => 'Musterstr. 1',
            'addressZip' => '12345',
            'addressCity' => 'Berlin',
            'addressCountry' => ['id' => '1', 'objectName' => 'StaticCountry', 'code' => 'DE'],
        ];
    }

    private function zugferdXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:'
            . 'CrossIndustryInvoice:100"></rsm:CrossIndustryInvoice>';
    }

    /** @return array<string, mixed> */
    private function candidate(string $id): array
    {
        return [
            'id' => $id,
            'objectName' => 'Invoice',
            'invoiceType' => 'RE',
            'invoiceNumber' => 'RE-10',
            'invoiceDate' => '01.07.2026',
            'currency' => 'EUR',
            'status' => '100',
            'taxRule' => ['id' => '1', 'objectName' => 'TaxRule'],
            'contact' => ['id' => '42', 'objectName' => 'Contact'],
            'contactPerson' => ['id' => '7', 'objectName' => 'SevUser'],
            'showNet' => true,
            'deliveryAddressCountry' => 'DE',
            'customerInternalNote' => '[WHMCS-INVOICE:10]',
            'sumGross' => '119.00',
        ];
    }

    private function positionResponse(string $price = '100.00'): Response
    {
        return new Response(200, [], json_encode(['objects' => [[
            'id' => '901',
            'objectName' => 'InvoicePos',
            'invoice' => ['id' => '99', 'objectName' => 'Invoice'],
            'unity' => ['id' => '8', 'objectName' => 'Unity'],
            'positionNumber' => '1',
            'quantity' => '1',
            'name' => 'Hosting',
            'text' => 'Hosting',
            'price' => $price,
            'taxRate' => '19',
        ]]], JSON_THROW_ON_ERROR));
    }

    /** @param Response|list<Response> $responses */
    private function client(Response|array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler(
            $responses instanceof Response ? [$responses] : $responses,
        ));
        $stack->push(Middleware::history($history));

        return new SevdeskClient(new Client(['handler' => $stack]), 'token');
    }
}
