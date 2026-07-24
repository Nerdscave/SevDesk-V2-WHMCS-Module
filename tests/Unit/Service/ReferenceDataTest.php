<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Service\ReferenceData;

final class ReferenceDataTest extends TestCase
{
    public function testExactCountryLookupAcceptsOneMatchingCodedRowAndCachesIt(): void
    {
        $history = [];
        $references = new ReferenceData($this->client([
            new Response(200, [], '{"objects":[{"id":1490,"code":"cy"}]}'),
        ], $history));

        self::assertSame('1490', $references->exactCountryId(' cy '));
        self::assertSame('1490', $references->exactCountryId('CY'));
        self::assertCount(1, $history);
        self::assertStringContainsString('code=CY', (string) $history[0]['request']->getUri()->getQuery());
    }

    public function testExactCountryLookupSelectsUnitedKingdomFromSevdeskGbDuplicates(): void
    {
        $history = [];
        $references = new ReferenceData($this->client([
            new Response(200, [], json_encode([
                'objects' => [
                    ['id' => 74, 'code' => 'gb', 'nameEn' => 'England'],
                    ['id' => 9, 'code' => 'gb', 'nameEn' => 'Great Britain'],
                    ['id' => 77, 'code' => 'gb', 'nameEn' => 'United Kingdom'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ], $history));

        self::assertSame('77', $references->exactCountryId('GB'));
        self::assertCount(1, $history);
    }

    public function testExactCountryLookupRejectsGbDuplicatesWithoutOneCanonicalLabel(): void
    {
        $history = [];
        $references = new ReferenceData($this->client([
            new Response(200, [], json_encode([
                'objects' => [
                    ['id' => 74, 'code' => 'gb', 'nameEn' => 'England'],
                    ['id' => 9, 'code' => 'gb', 'nameEn' => 'Great Britain'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ], $history));

        self::assertNull($references->exactCountryId('GB'));
        self::assertCount(1, $history);
    }

    #[DataProvider('ambiguousCountryResponseProvider')]
    public function testExactCountryLookupRejectsUnlabelledWrongOrAmbiguousRows(string $responseBody): void
    {
        $history = [];
        $references = new ReferenceData($this->client([
            new Response(200, [], $responseBody),
        ], $history));

        self::assertNull($references->exactCountryId('CY'));
        self::assertCount(1, $history);
    }

    /** @return iterable<string,array{string}> */
    public static function ambiguousCountryResponseProvider(): iterable
    {
        yield 'unlabelled result' => ['{"objects":[{"id":1490}]}'];
        yield 'different code' => ['{"objects":[{"id":1490,"code":"DE"}]}'];
        yield 'two matching rows' => [
            '{"objects":[{"id":1490,"code":"CY"},{"id":1491,"code":"cy"}]}',
        ];
        yield 'empty result' => ['{"objects":[]}'];
    }

    public function testExactCountryLookupPropagatesTransientFailureAndDoesNotCacheIt(): void
    {
        $history = [];
        $references = new ReferenceData($this->client([
            new Response(503, [], '{"error":{"code":"SERVER_ERROR"}}'),
            new Response(200, [], '{"objects":[{"id":1490,"code":"CY"}]}'),
        ], $history));

        try {
            $references->exactCountryId('CY');
            self::fail('The transient lookup failure must reach the job retry boundary.');
        } catch (ApiException $exception) {
            self::assertSame(503, $exception->httpStatus);
        }

        self::assertSame('1490', $references->exactCountryId('CY'));
        self::assertCount(2, $history);
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
