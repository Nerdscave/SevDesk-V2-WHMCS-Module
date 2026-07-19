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
