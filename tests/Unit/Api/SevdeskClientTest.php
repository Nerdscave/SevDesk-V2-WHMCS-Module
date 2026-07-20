<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;

final class SevdeskClientTest extends TestCase
{
    public function testItRejectsPlainHttpForNonLoopbackHosts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SevdeskClient(new Client(), 'token', 'http://example.test/api/v1');
    }

    public function testItUsesRawAuthorizationAndUnwrapsObjects(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"objects":[{"id":12}]}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'raw-api-token');

        self::assertSame([['id' => 12]], $client->get('/Contact', ['customerNumber' => '42']));
        self::assertSame('raw-api-token', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('WHMCS-sevdesk/2.1.0-rc.4', $history[0]['request']->getHeaderLine('User-Agent'));
        self::assertSame(5.0, $history[0]['options']['connect_timeout']);
        self::assertSame(30.0, $history[0]['options']['timeout']);
        self::assertStringContainsString('customerNumber=42', (string) $history[0]['request']->getUri());
    }

    public function testItAlsoAcceptsDirectResourceResponses(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(201, [], '{"id":12,"objectName":"Contact"}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'token');

        self::assertSame(['id' => 12, 'objectName' => 'Contact'], $client->post('/Contact', []));
        self::assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
    }

    public function testUploadUsesTheLongerTimeoutAndSanitisesTheFilename(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(201, [], '{"objects":{"filename":"remote.pdf"}}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'token');

        $response = $client->upload('/Voucher/Factory/uploadTempFile', '../unsafe invoice.pdf', '%PDF-data');

        self::assertSame(['filename' => 'remote.pdf'], $response);
        self::assertSame(60.0, $history[0]['options']['timeout']);
        self::assertStringContainsString('unsafe-invoice.pdf', (string) $history[0]['request']->getBody());
    }

    public function testLargeJsonIsOptInForBase64EncodedInvoicePdfs(): void
    {
        $base64 = str_repeat('A', 2_100_000);
        $body = json_encode(['objects' => ['base64Encoded' => $base64]], JSON_THROW_ON_ERROR);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], $body),
            new Response(200, [], $body),
        ]));
        $stack->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'token');

        try {
            $client->get('/Invoice/99/getPdf');
            self::fail('The ordinary JSON limit must remain at 2 MiB.');
        } catch (ApiException $exception) {
            self::assertSame('response_too_large', $exception->sevdeskCode);
        }

        $response = $client->getLargeJson('/Invoice/99/getPdf', ['download' => true]);
        self::assertSame($base64, $response['base64Encoded']);
        self::assertSame(60.0, $history[1]['options']['timeout']);
        self::assertStringContainsString('download=1', (string) $history[1]['request']->getUri());
    }

    public function testRawPdfResourceDisablesBrokenHttpContentDecoding(): void
    {
        $pdf = "%PDF-1.7\nsynthetic\n%%EOF";
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf; charset=UTF-8'], $pdf),
        ]));
        $stack->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'token');

        self::assertSame([
            'kind' => 'binary',
            'mimeType' => 'application/pdf',
            'content' => $pdf,
        ], $client->getPdfResource('/Invoice/99/getPdf', ['download' => true]));
        self::assertSame('identity', $history[0]['request']->getHeaderLine('Accept-Encoding'));
        self::assertSame(60.0, $history[0]['options']['timeout']);
        self::assertFalse($history[0]['options']['decode_content']);
        if (defined('CURLOPT_HTTP_CONTENT_DECODING')) {
            self::assertFalse($history[0]['options']['curl'][constant('CURLOPT_HTTP_CONTENT_DECODING')]);
        }
    }

    public function testPdfResourceRetainsDocumentedJsonEnvelopeCompatibility(): void
    {
        $client = $this->clientWith(new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"mimeType":"application/pdf"}',
        ));

        self::assertSame([
            'kind' => 'json',
            'payload' => ['mimeType' => 'application/pdf'],
        ], $client->getPdfResource('/Invoice/99/getPdf'));
    }

    public function testRawPdfResourceKeepsTheTenMebibyteLimit(): void
    {
        $client = $this->clientWith(new Response(
            200,
            ['Content-Type' => 'application/pdf'],
            str_repeat('x', 10_485_761),
        ));

        try {
            $client->getPdfResource('/Invoice/99/getPdf');
            self::fail('The raw PDF size limit must be enforced.');
        } catch (ApiException $exception) {
            self::assertSame(200, $exception->httpStatus);
            self::assertSame('response_too_large', $exception->sevdeskCode);
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    public function testPdfResourceRequiresHttp200(): void
    {
        $client = $this->clientWith(new Response(
            201,
            ['Content-Type' => 'application/json'],
            '{"mimeType":"application/pdf"}',
        ));

        try {
            $client->getPdfResource('/Invoice/99/getPdf');
            self::fail('The PDF resource must require HTTP 200.');
        } catch (ApiException $exception) {
            self::assertSame(201, $exception->httpStatus);
            self::assertSame('unexpected_http_status', $exception->sevdeskCode);
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    public function testPdfResourceRejectsPartialRawResponse(): void
    {
        $client = $this->clientWith(new Response(
            206,
            ['Content-Type' => 'application/pdf'],
            "%PDF-1.7\npartial\n%%EOF",
        ));

        try {
            $client->getPdfResource('/Invoice/99/getPdf');
            self::fail('A partial PDF response must not be accepted.');
        } catch (ApiException $exception) {
            self::assertSame(206, $exception->httpStatus);
            self::assertSame('unexpected_http_status', $exception->sevdeskCode);
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    public function testPdfResourceRetainsAuthenticationFailureClassification(): void
    {
        $client = $this->clientWith(new Response(
            401,
            ['Content-Type' => 'application/json'],
            '{"error":{"code":"AUTHENTICATION_FAILED"}}',
        ));

        try {
            $client->getPdfResource('/Invoice/99/getPdf');
            self::fail('Expected authentication failure.');
        } catch (ApiException $exception) {
            self::assertSame(401, $exception->httpStatus);
            self::assertSame('AUTHENTICATION_FAILED', $exception->sevdeskCode);
            self::assertTrue($exception->isAuthenticationFailure());
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    public function testApiExceptionOnlyKeepsWhitelistedErrorMetadata(): void
    {
        $body = json_encode([
            'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'person@example.test secret-data'],
            'exceptionUUID' => '123e4567-e89b-12d3-a456-426614174000',
        ], JSON_THROW_ON_ERROR);
        $client = $this->clientWith(new Response(422, [], $body));

        try {
            $client->post('/Contact', ['name' => 'Sensitive Person']);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->httpStatus);
            self::assertSame('VALIDATION_ERROR', $exception->sevdeskCode);
            self::assertSame('123e4567-e89b-12d3-a456-426614174000', $exception->exceptionUuid);
            self::assertFalse($exception->outcomeUnknown);
            self::assertStringNotContainsString('person@example.test', $exception->getMessage());
            self::assertStringNotContainsString('Sensitive Person', $exception->getMessage());
        }
    }

    public function testAnEchoedTokenIsRedactedEvenFromAnErrorCode(): void
    {
        $token = '0123456789abcdef0123456789abcdef';
        $client = new SevdeskClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(400, [], '{"error":{"code":"' . $token . '"}}'),
            ])),
        ]), $token);

        try {
            $client->post('/Contact', []);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame('redacted', $exception->sevdeskCode);
            self::assertStringNotContainsString($token, $exception->getMessage());
        }
    }

    #[DataProvider('authenticationFailureStatuses')]
    public function testAuthenticationFailuresAreClassifiedWithoutUnknownWriteOutcome(int $status): void
    {
        $client = $this->clientWith(new Response(
            $status,
            [],
            '{"error":{"code":"AUTHENTICATION_FAILED"}}',
        ));

        try {
            $client->post('/Voucher/Factory/saveVoucher', ['voucher' => []]);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->httpStatus);
            self::assertSame('AUTHENTICATION_FAILED', $exception->sevdeskCode);
            self::assertTrue($exception->isAuthenticationFailure());
            self::assertFalse($exception->isPermanentClientFailure());
            self::assertFalse($exception->isRateLimit());
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function authenticationFailureStatuses(): iterable
    {
        yield 'HTTP 401' => [401];
        yield 'HTTP 403' => [403];
    }

    public function testConflictIsAKnownPermanentClientFailure(): void
    {
        $client = $this->clientWith(new Response(
            409,
            [],
            '{"error":{"code":"RESOURCE_CONFLICT"}}',
        ));

        try {
            $client->post('/Voucher/Factory/saveVoucher', ['voucher' => []]);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->httpStatus);
            self::assertSame('RESOURCE_CONFLICT', $exception->sevdeskCode);
            self::assertFalse($exception->isAuthenticationFailure());
            self::assertTrue($exception->isPermanentClientFailure());
            self::assertFalse($exception->isRateLimit());
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    public function testInvalidSuccessfulWriteResponseIsAmbiguous(): void
    {
        $client = $this->clientWith(new Response(201, [], '<html>proxy error</html>'));

        $this->expectException(ApiException::class);
        try {
            $client->post('/Voucher/Factory/saveVoucher', ['voucher' => []]);
        } catch (ApiException $exception) {
            self::assertTrue($exception->outcomeUnknown);
            self::assertSame('invalid_json', $exception->sevdeskCode);
            throw $exception;
        }
    }

    public function testRateLimitExposesBoundedRetryAfterWithoutUnknownOutcome(): void
    {
        $client = $this->clientWith(new Response(
            429,
            ['Retry-After' => '99999'],
            '{"error":{"code":"RATE_LIMIT"}}',
        ));

        try {
            $client->post('/Contact', []);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->isRateLimit());
            self::assertSame(21_600, $exception->retryAfterSeconds);
            self::assertFalse($exception->outcomeUnknown);
        }
    }

    public function testUnexpectedSuccessStatusOnWriteIsAmbiguousWhen201IsRequired(): void
    {
        $client = $this->clientWith(new Response(200, [], '{"id":12}'));

        try {
            $client->post('/Contact', [], true, [201]);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame('unexpected_http_status', $exception->sevdeskCode);
            self::assertTrue($exception->outcomeUnknown);
        }
    }

    public function testRequestTimeoutStatusIsAnUnknownWriteOutcome(): void
    {
        $client = $this->clientWith(new Response(408, [], '{"error":{"code":"REQUEST_TIMEOUT"}}'));

        try {
            $client->post('/Voucher/Factory/saveVoucher', ['voucher' => []]);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(408, $exception->httpStatus);
            self::assertTrue($exception->outcomeUnknown);
        }
    }

    public function testTransportFailureIsOnlyAmbiguousForAWrite(): void
    {
        $handler = new MockHandler([
            new ConnectException('sensitive transport details', new Request('GET', 'https://example.test')),
            new ConnectException('sensitive transport details', new Request('POST', 'https://example.test')),
        ]);
        $client = new SevdeskClient(new Client(['handler' => HandlerStack::create($handler)]), 'token');

        try {
            $client->get('/Contact');
            self::fail('Expected read exception.');
        } catch (ApiException $exception) {
            self::assertFalse($exception->outcomeUnknown);
            self::assertStringNotContainsString('sensitive', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        try {
            $client->post('/Contact', []);
            self::fail('Expected write exception.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->outcomeUnknown);
            self::assertStringNotContainsString('sensitive', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private function clientWith(Response $response): SevdeskClient
    {
        return new SevdeskClient(new Client([
            'handler' => HandlerStack::create(new MockHandler([$response])),
        ]), 'token');
    }
}
