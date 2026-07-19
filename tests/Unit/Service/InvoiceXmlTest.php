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
use WHMCS\Module\Addon\SevDesk\Service\InvoiceXml;

final class InvoiceXmlTest extends TestCase
{
    public function testFetchValidatesAndHashesNativeZugferdXml(): void
    {
        $xml = self::validXml();
        $service = new InvoiceXml($this->client(new Response(200, [], json_encode([
            'objects' => $xml,
        ], JSON_THROW_ON_ERROR))));

        self::assertSame([
            'contents' => $xml,
            'sha256' => hash('sha256', $xml),
        ], $service->fetch('88'));
    }

    /** @param array<string, mixed> $response */
    #[DataProvider('invalidXmlProvider')]
    public function testMalformedOrUnsafeXmlIsRejected(array $response): void
    {
        $service = new InvoiceXml($this->client(new Response(
            200,
            [],
            json_encode($response, JSON_THROW_ON_ERROR),
        )));

        $this->expectException(\RuntimeException::class);
        $service->fetch('88');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidXmlProvider(): iterable
    {
        yield 'missing XML' => [['objects' => []]];
        yield 'not an XML document' => [['objects' => 'not XML']];
        yield 'wrong document vocabulary' => [[
            'objects' => '<?xml version="1.0"?><Invoice></Invoice>',
        ]];
        yield 'external entities forbidden' => [[
            'objects' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///x">]>'
                . '<rsm:CrossIndustryInvoice>&e;</rsm:CrossIndustryInvoice>',
        ]];
        yield 'malformed CII vocabulary' => [[
            'objects' => '<?xml version="1.0"?>'
                . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:'
                . 'CrossIndustryInvoice:100"><broken></rsm:CrossIndustryInvoice>',
        ]];
        yield 'unqualified CII lookalike' => [[
            'objects' => '<?xml version="1.0"?><CrossIndustryInvoice></CrossIndustryInvoice>',
        ]];
    }

    public function testInvalidRemoteIdIsRejectedBeforeNetworkAccess(): void
    {
        $service = new InvoiceXml($this->client(new Response(200, [], '{}')));

        $this->expectException(\InvalidArgumentException::class);
        $service->fetch('../88');
    }

    private static function validXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:'
            . 'CrossIndustryInvoice:100"></rsm:CrossIndustryInvoice>';
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
