<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Service\InvoicePdf;

final class InvoicePdfTest extends TestCase
{
    public function testFetchValidatesAndHashesPdf(): void
    {
        $pdf = "%PDF-1.7\nsynthetic\n%%EOF";
        $service = new InvoicePdf($this->client(new Response(200, [], json_encode([
            'filename' => '../RE 42.pdf',
            'mimeType' => 'application/pdf',
            'base64encoded' => true,
            'content' => base64_encode($pdf),
        ], JSON_THROW_ON_ERROR))));

        self::assertSame([
            'filename' => 'RE-42.pdf',
            'contents' => $pdf,
            'sha256' => hash('sha256', $pdf),
        ], $service->fetch('88'));
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $service = new InvoicePdf($this->client(new Response(200, [], json_encode([
            'filename' => 'RE-42.pdf',
            'mimeType' => 'application/pdf',
            'base64encoded' => true,
            'content' => base64_encode('not a pdf'),
        ], JSON_THROW_ON_ERROR))));

        $this->expectException(\RuntimeException::class);
        $service->fetch('88');
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPdfResponseProvider')]
    public function testInvalidPdfResponsesAreRejected(array $payload): void
    {
        $service = new InvoicePdf($this->client(new Response(
            200,
            [],
            json_encode($payload, JSON_THROW_ON_ERROR),
        )));

        $this->expectException(\RuntimeException::class);
        $service->fetch('88');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPdfResponseProvider(): iterable
    {
        $validPdf = "%PDF-1.7\nsynthetic\n%%EOF";

        yield 'wrong MIME type' => [[
            'mimeType' => 'application/octet-stream',
            'base64encoded' => true,
            'content' => base64_encode($validPdf),
        ]];
        yield 'response does not declare Base64' => [[
            'mimeType' => 'application/pdf',
            'base64encoded' => false,
            'content' => base64_encode($validPdf),
        ]];
        yield 'malformed Base64' => [[
            'mimeType' => 'application/pdf',
            'base64encoded' => true,
            'content' => 'not-valid-base64*',
        ]];
        yield 'missing EOF marker' => [[
            'mimeType' => 'application/pdf',
            'base64encoded' => true,
            'content' => base64_encode("%PDF-1.7\nsynthetic without trailer"),
        ]];
        yield 'missing PDF fields' => [['objects' => []]];
    }

    public function testPdfLargerThanTenMebibytesIsRejected(): void
    {
        $pdf = "%PDF-1.7\n" . str_repeat('x', 10_485_760) . "\n%%EOF";
        $service = new InvoicePdf($this->client(new Response(200, [], json_encode([
            'mimeType' => 'application/pdf',
            'base64encoded' => true,
            'content' => base64_encode($pdf),
        ], JSON_THROW_ON_ERROR))));

        $this->expectException(\RuntimeException::class);
        $service->fetch('88');
    }

    public function testInvalidRemoteInvoiceIdIsRejectedBeforeTheApiCall(): void
    {
        $service = new InvoicePdf($this->client(new Response(200, [], '{}')));

        $this->expectException(\InvalidArgumentException::class);
        $service->fetch('../88');
    }

    public function testSingleObjectListResponseRemainsCompatible(): void
    {
        $pdf = "%PDF-1.7\nsynthetic\n%%EOF";
        $service = new InvoicePdf($this->client(new Response(200, [], json_encode([[
            'filename' => 'invoice.pdf',
            'mimeType' => 'application/pdf',
            'base64encoded' => 1,
            'content' => base64_encode($pdf),
        ]], JSON_THROW_ON_ERROR))));

        self::assertSame($pdf, $service->fetch('88')['contents']);
    }

    private function client(Response $response): SevdeskClient
    {
        return new SevdeskClient(
            new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]),
            'test-token',
            'http://127.0.0.1/api/v1',
        );
    }
}
