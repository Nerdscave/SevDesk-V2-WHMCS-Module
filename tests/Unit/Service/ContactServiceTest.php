<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\ContactResolution;
use WHMCS\Module\Addon\SevDesk\Service\ContactService;

final class ContactServiceTest extends TestCase
{
    public function testItCreatesAndLinksContactBeforeAddingAddressAndEmail(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[]}'),
            new Response(201, [], '{"objects":{"id":42}}'),
            new Response(201, [], '{"id":91}'),
            new Response(201, [], '{"id":92}'),
        ], $history);
        $stored = [];
        $checkpoints = [];
        $service = new ContactService(
            $client,
            static function (int $clientId, string $contactId) use (&$stored): void {
                $stored[$clientId] = $contactId;
            },
            static fn (string $countryCode): int => $countryCode === 'DE' ? 1 : 2,
            '3',
            '47',
            '2',
        );

        $result = $service->resolve(
            $this->contact(),
            static function (string $name) use (&$checkpoints): void {
                $checkpoints[] = $name;
            },
        );

        self::assertTrue($result->isSuccess());
        self::assertInstanceOf(ContactResolution::class, $result->value());
        self::assertSame('42', $result->value()->contactId);
        self::assertSame('created', $result->value()->source);
        self::assertSame([7 => '42'], $stored);
        self::assertSame(['contact_write_requested', 'contact_linked'], $checkpoints);
        self::assertSame('/api/v1/ContactAddress', $history[2]['request']->getUri()->getPath());
        self::assertSame('/api/v1/CommunicationWay', $history[3]['request']->getUri()->getPath());
    }

    public function testItUsesVerifiedConfiguredContactWithoutUpdatingIt(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":55}]}'),
        ], $history);
        $persistCalls = 0;
        $service = new ContactService(
            $client,
            static function () use (&$persistCalls): void {
                ++$persistCalls;
            },
            static fn (): int => 1,
        );

        $contact = $this->contact('55');
        $result = $service->resolve($contact);

        self::assertTrue($result->isSuccess());
        self::assertSame('configured', $result->value()->source);
        self::assertSame(0, $persistCalls);
        self::assertCount(1, $history);
    }

    public function testMissingConfiguredContactBecomesRecoveryCaseInsteadOfCreatingDuplicate(): void
    {
        $history = [];
        $client = $this->client([
            new Response(400, [], '{"error":{"code":"CONTACT_NOT_FOUND"}}'),
        ], $history);
        $service = new ContactService($client, static fn (): bool => true, static fn (): int => 1);

        $result = $service->resolve($this->contact('55'));

        self::assertTrue($result->isFailure());
        self::assertSame('configured_contact_missing', $result->errorCode());
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
    }

    public function testMultipleCustomerNumberMatchesAreAConflict(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":1},{"id":2}]}'),
        ], $history);
        $service = new ContactService($client, static fn (): bool => true, static fn (): int => 1);

        $result = $service->resolve($this->contact());

        self::assertTrue($result->isFailure());
        self::assertSame('contact_conflict', $result->errorCode());
        self::assertSame(2, $result->context()['matchCount']);
        self::assertCount(1, $history);
    }

    public function testAContactLinkFailureStopsBeforeSupplementaryWrites(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[]}'),
            new Response(201, [], '{"objects":{"id":42}}'),
        ], $history);
        $service = new ContactService(
            $client,
            static fn (): bool => false,
            static fn (): int => 1,
            '3',
            '47',
            '2',
        );

        $result = $service->resolve($this->contact());

        self::assertTrue($result->isFailure());
        self::assertSame('contact_link_persist_failed', $result->errorCode());
        self::assertTrue($result->context()['ambiguous']);
        self::assertCount(2, $history);
    }

    public function testFailedPreWriteCheckpointPreventsContactPost(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[]}'),
        ], $history);
        $service = new ContactService($client, static fn (): bool => true, static fn (): int => 1);

        $result = $service->resolve(
            $this->contact(),
            static fn (string $name): bool => $name !== 'contact_write_requested',
        );

        self::assertTrue($result->isFailure());
        self::assertSame('checkpoint_persist_failed', $result->errorCode());
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
    }

    public function testRecoveryWithNoCustomerNumberMatchNeverCreatesAnotherContact(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[]}'),
        ], $history);
        $service = new ContactService($client, static fn (): bool => true, static fn (): int => 1);

        $result = $service->resolve($this->contact(), null, true);

        self::assertTrue($result->isFailure());
        self::assertSame('contact_recovery_no_match_ambiguous', $result->errorCode());
        self::assertTrue($result->context()['ambiguous']);
        self::assertSame(0, $result->context()['matchCount']);
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
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

    private function contact(?string $sevdeskId = null): ContactData
    {
        return new ContactData(
            7,
            $sevdeskId,
            'Example GmbH',
            'Erika',
            'Musterfrau',
            'billing@example.test',
            'Musterstr. 1',
            '',
            '12345',
            'Berlin',
            'DE',
            'DE123456789',
            false,
        );
    }
}
