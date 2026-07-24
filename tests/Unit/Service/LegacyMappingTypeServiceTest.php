<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Service;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Service\LegacyMappingTypeService;

final class LegacyMappingTypeServiceTest extends TestCase
{
    public function testExactVoucherIsSuggestedWithoutPersistingAnything(): void
    {
        $history = new ArrayObject();
        $persistCalls = 0;
        $service = new LegacyMappingTypeService(
            $this->client([$this->voucherResponse(), $this->notFound()], $history),
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('suggested', $result['status']);
        self::assertSame('legacy_mapping_voucher_suggested', $result['code']);
        self::assertSame('voucher', $result['suggestedType']);
        self::assertSame('INV-42', $result['documentNumber']);
        self::assertTrue($result['context']['numberEvidence']);
        self::assertTrue($result['context']['markerEvidence']);
        self::assertFalse($result['context']['legacyMarkerMissing']);
        self::assertSame(0, $persistCalls);
        self::assertSame('/api/v1/Voucher/88', self::requestPath($history, 0));
        self::assertSame('/api/v1/Invoice/88', self::requestPath($history, 1));
        self::assertSame(['GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history->getArrayCopy(),
        ));
    }

    public function testMarkerlessOriginalVoucherProducesAWeakerExplicitSuggestion(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(description: 'INV-42'),
                $this->notFound(),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('suggested', $result['status']);
        self::assertSame('voucher', $result['suggestedType']);
        self::assertTrue($result['context']['numberEvidence']);
        self::assertFalse($result['context']['markerEvidence']);
        self::assertTrue($result['context']['legacyMarkerMissing']);
        self::assertStringContainsString('legacy document has no Rewrite marker', $result['message']);
        self::assertCount(2, $history);
    }

    public function testMarkerlessInvoiceProducesAWeakerExplicitSuggestion(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->notFound(),
                $this->invoiceResponse(marker: ''),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('suggested', $result['status']);
        self::assertSame('invoice', $result['suggestedType']);
        self::assertTrue($result['context']['numberEvidence']);
        self::assertFalse($result['context']['markerEvidence']);
        self::assertTrue($result['context']['legacyMarkerMissing']);
        self::assertCount(2, $history);
    }

    public function testExactInvoiceIsSuggestedOnlyAfterVoucher404(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([$this->notFound(), $this->invoiceResponse()], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('suggested', $result['status']);
        self::assertSame('legacy_mapping_invoice_suggested', $result['code']);
        self::assertSame('invoice', $result['suggestedType']);
        self::assertCount(2, $history);
    }

    public function testEmptyWhmcsNumberUsesInternalInvoiceIdAndMarkerWithoutAssumingAType(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(description: '42 [WHMCS-INVOICE:42]'),
                $this->notFound(),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, '', '88');

        self::assertSame('suggested', $result['status']);
        self::assertSame('voucher', $result['suggestedType']);
        self::assertSame('42', $result['documentNumber']);
        self::assertCount(2, $history);
    }

    public function testEmptyWhmcsNumberStillRequiresTheInternalIdAsRemoteDocumentNumber(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->notFound(),
                $this->invoiceResponse(invoiceNumber: 'INV-42'),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, '', '88');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_type_no_match', $result['code']);
        self::assertArrayNotHasKey('suggestedType', $result);
    }

    public function testDocumentedInvoiceNotFound400IsAlsoAnAbsenceResult(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(),
                new Response(400, [], '{"error":{"code":"NOT_FOUND"}}'),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('suggested', $result['status']);
        self::assertSame('voucher', $result['suggestedType']);
        self::assertCount(2, $history);
    }

    public function testDocumentedVoucherNotFound400IsAlsoAnAbsenceResult(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                new Response(400, [], '{"error":{"code":"NOT_FOUND"}}'),
                $this->invoiceResponse(),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('suggested', $result['status']);
        self::assertSame('invoice', $result['suggestedType']);
        self::assertCount(2, $history);
        self::assertSame('/api/v1/Voucher/88', self::requestPath($history, 0));
        self::assertSame('/api/v1/Invoice/88', self::requestPath($history, 1));
    }

    public function testIdCollisionNeverProducesASuggestion(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([$this->voucherResponse(), $this->invoiceResponse()], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_type_collision', $result['code']);
        self::assertSame(2, $result['context']['matchCount']);
        self::assertArrayNotHasKey('suggestedType', $result);
    }

    public function testIdCollisionAlsoBlocksWhenOnlyOneObjectMatches(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(),
                $this->invoiceResponse(invoiceNumber: 'INV-OTHER'),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_type_collision', $result['code']);
        self::assertSame(1, $result['context']['matchCount']);
        self::assertArrayNotHasKey('suggestedType', $result);
    }

    public function testWrongMarkerNeverProducesASuggestion(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(description: 'INV-42 [WHMCS-INVOICE:999]'),
                $this->notFound(),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_type_no_match', $result['code']);
        self::assertSame(0, $result['context']['matchCount']);
        self::assertArrayNotHasKey('suggestedType', $result);
    }

    public function testWrongInvoiceNumberNeverProducesASuggestion(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->notFound(),
                $this->invoiceResponse(invoiceNumber: 'INV-OTHER'),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_type_no_match', $result['code']);
        self::assertArrayNotHasKey('suggestedType', $result);
    }

    public function testContradictoryInvoiceMarkersNeverProduceASuggestion(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->notFound(),
                $this->invoiceResponse(marker: '[WHMCS-INVOICE:42] [WHMCS-INVOICE:43]'),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_type_no_match', $result['code']);
        self::assertArrayNotHasKey('suggestedType', $result);
    }

    public function testVoucherInvoiceNumberMustNotBeOnlyASubstring(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(description: 'INV-42 [WHMCS-INVOICE:42]'),
                $this->notFound(),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-4', '88');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_type_no_match', $result['code']);
        self::assertArrayNotHasKey('suggestedType', $result);
    }

    public function testNon404ApiFailureIsNotTreatedAsNoMatch(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
                $this->notFound(),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('failed', $result['status']);
        self::assertSame('legacy_mapping_type_check_failed', $result['code']);
        self::assertSame(500, $result['context']['httpStatus']);
        self::assertArrayNotHasKey('suggestedType', $result);
        self::assertCount(2, $history);
    }

    public function testSecondEndpointFailureInvalidatesAnOtherwiseMatchingVoucher(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(),
                new Response(503, [], '{"error":{"code":"UNAVAILABLE"}}'),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('failed', $result['status']);
        self::assertSame('legacy_mapping_type_check_failed', $result['code']);
        self::assertSame(503, $result['context']['httpStatus']);
        self::assertArrayNotHasKey('suggestedType', $result);
        self::assertCount(2, $history);
    }

    public function testEmptySuccessfulVoucherResponseInvalidatesMatchingInvoice(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                new Response(200, [], '{"objects":[]}'),
                $this->invoiceResponse(),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('failed', $result['status']);
        self::assertSame('legacy_mapping_type_check_failed', $result['code']);
        self::assertArrayNotHasKey('suggestedType', $result);
        self::assertCount(2, $history);
    }

    public function testEmptySuccessfulInvoiceResponseInvalidatesMatchingVoucher(): void
    {
        $history = new ArrayObject();
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(),
                new Response(200, [], '{"objects":[]}'),
            ], $history),
            static fn (): bool => true,
        );

        $result = $service->inspect(42, 'INV-42', '88');

        self::assertSame('failed', $result['status']);
        self::assertSame('legacy_mapping_type_check_failed', $result['code']);
        self::assertArrayNotHasKey('suggestedType', $result);
        self::assertCount(2, $history);
    }

    public function testConfirmationRepeatsBothReadsBeforeAdditivePersistence(): void
    {
        $history = new ArrayObject();
        $persisted = [];
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->notFound(),
                $this->invoiceResponse(),
                $this->notFound(),
                $this->invoiceResponse(),
            ], $history),
            static function (
                int $invoiceId,
                string $remoteId,
                string $type,
                string $documentNumber,
                string $documentAuthority,
            ) use (&$persisted): void {
                $persisted = compact(
                    'invoiceId',
                    'remoteId',
                    'type',
                    'documentNumber',
                    'documentAuthority',
                );
            },
        );

        $initial = $service->inspect(42, 'INV-42', '88');
        self::assertSame('suggested', $initial['status']);
        self::assertSame([], $persisted);

        $result = $service->confirm(42, 'INV-42', '88', 'invoice', 'whmcs');

        self::assertSame('confirmed', $result['status']);
        self::assertSame('legacy_mapping_type_confirmed', $result['code']);
        self::assertSame([
            'invoiceId' => 42,
            'remoteId' => '88',
            'type' => 'invoice',
            'documentNumber' => 'INV-42',
            'documentAuthority' => 'whmcs',
        ], $persisted);
        self::assertCount(4, $history);
    }

    public function testConfirmationPersistsInternalInvoiceIdWhenWhmcsNumberIsEmpty(): void
    {
        $history = new ArrayObject();
        $persistedNumber = null;
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->notFound(),
                $this->invoiceResponse(invoiceNumber: '42'),
            ], $history),
            static function (
                int $invoiceId,
                string $remoteId,
                string $type,
                string $documentNumber,
                string $documentAuthority,
            ) use (&$persistedNumber): void {
                $persistedNumber = $documentNumber;
            },
        );

        $result = $service->confirm(42, '', '88', 'invoice', 'whmcs');

        self::assertSame('confirmed', $result['status']);
        self::assertSame('42', $result['documentNumber']);
        self::assertSame('42', $persistedNumber);
    }

    public function testMarkerlessVoucherConfirmationRepeatsBothReadsBeforePersistence(): void
    {
        $history = new ArrayObject();
        $persisted = [];
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->voucherResponse(description: 'INV-42'),
                $this->notFound(),
                $this->voucherResponse(description: 'INV-42'),
                $this->notFound(),
            ], $history),
            static function (
                int $invoiceId,
                string $remoteId,
                string $type,
                string $documentNumber,
                string $documentAuthority,
            ) use (&$persisted): void {
                $persisted = compact(
                    'invoiceId',
                    'remoteId',
                    'type',
                    'documentNumber',
                    'documentAuthority',
                );
            },
        );

        $inspection = $service->inspect(42, 'INV-42', '88');
        self::assertSame('suggested', $inspection['status']);
        self::assertFalse($inspection['context']['markerEvidence']);
        self::assertSame([], $persisted);

        $result = $service->confirm(42, 'INV-42', '88', 'voucher', 'whmcs');

        self::assertSame('confirmed', $result['status']);
        self::assertSame([
            'invoiceId' => 42,
            'remoteId' => '88',
            'type' => 'voucher',
            'documentNumber' => 'INV-42',
            'documentAuthority' => 'whmcs',
        ], $persisted);
        self::assertCount(4, $history);
    }

    public function testChangedConfirmationDoesNotPersist(): void
    {
        $history = new ArrayObject();
        $persistCalls = 0;
        $service = new LegacyMappingTypeService(
            $this->client([$this->notFound(), $this->invoiceResponse()], $history),
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
        );

        $result = $service->confirm(42, 'INV-42', '88', 'voucher', 'whmcs');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_confirmation_changed', $result['code']);
        self::assertSame(0, $persistCalls);
        self::assertCount(2, $history);
    }

    public function testFinalInvoiceCanReceiveExplicitSevdeskAuthority(): void
    {
        foreach ([200, 750, 1000] as $remoteStatus) {
            $persistedAuthority = null;
            $service = new LegacyMappingTypeService(
                $this->client([
                    $this->notFound(),
                    $this->invoiceResponse(status: $remoteStatus),
                ], new ArrayObject()),
                static function (
                    int $invoiceId,
                    string $remoteId,
                    string $type,
                    string $documentNumber,
                    string $documentAuthority,
                ) use (&$persistedAuthority): void {
                    $persistedAuthority = $documentAuthority;
                },
            );

            $result = $service->confirm(42, 'INV-42', '88', 'invoice', 'sevdesk');

            self::assertSame('confirmed', $result['status']);
            self::assertSame('sevdesk', $result['context']['documentAuthority']);
            self::assertSame('sevdesk', $persistedAuthority);
        }
    }

    public function testDraftInvoiceCannotReceiveSevdeskAuthority(): void
    {
        $persistCalls = 0;
        $service = new LegacyMappingTypeService(
            $this->client([
                $this->notFound(),
                $this->invoiceResponse(status: 100),
            ], new ArrayObject()),
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
        );

        $result = $service->confirm(42, 'INV-42', '88', 'invoice', 'sevdesk');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_delivery_not_ready', $result['code']);
        self::assertSame(0, $persistCalls);
    }

    public function testVoucherCannotReceiveSevdeskAuthority(): void
    {
        $result = (new LegacyMappingTypeService(
            $this->client([], new ArrayObject()),
            static function (): void {
            },
        ))->confirm(42, 'INV-42', '88', 'voucher', 'sevdesk');

        self::assertSame('blocked', $result['status']);
        self::assertSame('legacy_mapping_authority_invalid', $result['code']);
    }

    /**
     * @param list<Response> $responses
     * @param ArrayObject<int, array<mixed, mixed>> $history
     */
    private function client(array $responses, ArrayObject $history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new SevdeskClient(new Client(['handler' => $stack]), 'test-token');
    }

    /** @param ArrayObject<int, array<mixed, mixed>> $history */
    private static function requestPath(ArrayObject $history, int $index): string
    {
        $entry = $history[$index] ?? null;
        $request = is_array($entry) ? ($entry['request'] ?? null) : null;
        if (!$request instanceof RequestInterface) {
            self::fail('Expected request history entry is missing.');
        }

        return $request->getUri()->getPath();
    }

    private function voucherResponse(string $description = 'INV-42 [WHMCS-INVOICE:42]'): Response
    {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '88',
                'objectName' => 'Voucher',
                'description' => $description,
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function invoiceResponse(
        string $invoiceNumber = 'INV-42',
        string $marker = '[WHMCS-INVOICE:42]',
        int $status = 100,
    ): Response {
        return new Response(200, [], json_encode([
            'objects' => [[
                'id' => '88',
                'objectName' => 'Invoice',
                'invoiceType' => 'RE',
                'invoiceNumber' => $invoiceNumber,
                'customerInternalNote' => $marker,
                'status' => $status,
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function notFound(): Response
    {
        return new Response(404, [], '{"error":{"code":"NOT_FOUND"}}');
    }
}
