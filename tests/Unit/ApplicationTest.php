<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Jobs\JobRunner;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Service\ContactService;

final class ApplicationTest extends TestCase
{
    public function testRunnerCompositionDoesNotConstructRemoteServices(): void
    {
        $reflection = new ReflectionClass(Application::class);
        /** @var Application $application */
        $application = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('config')->setValue($application, new Config());
        $reflection->getProperty('jobs')->setValue($application, new JobRepository());

        self::assertInstanceOf(JobRunner::class, $application->runner());

        foreach (
            [
                'client',
                'referenceData',
                'contacts',
                'exporter',
                'reconciliation',
                'bookings',
                'corrections',
                'exportJobHandler',
                'bookingJobHandler',
                'correctionJobHandler',
            ] as $property
        ) {
            self::assertNull($reflection->getProperty($property)->getValue($application), $property);
        }
    }

    public function testContactCompositionDoesNotFetchOptionalReferenceData(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'synthetic-token');
        $reflection = new ReflectionClass(Application::class);
        /** @var Application $application */
        $application = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($application, $client);

        self::assertInstanceOf(ContactService::class, $application->contacts());
        self::assertCount(0, $history, 'Optional contact reference IDs must stay lazy until a new contact exists.');
    }

    public function testContactCompositionUsesTheExactCountryResolverForSevdeskDuplicates(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], '{"objects":[
                {"id":74,"code":"GB","nameEn":"England"},
                {"id":9,"code":"GB","nameEn":"Great Britain"},
                {"id":77,"code":"GB","nameEn":"United Kingdom"}
            ]}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'synthetic-token');
        $reflection = new ReflectionClass(Application::class);
        /** @var Application $application */
        $application = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($application, $client);

        $service = $application->contacts();
        $countryResolver = (new ReflectionClass(ContactService::class))
            ->getProperty('resolveCountryId')
            ->getValue($service);

        self::assertInstanceOf(\Closure::class, $countryResolver);
        self::assertSame('77', $countryResolver('GB'));
        self::assertCount(1, $history);
    }

    #[DataProvider('checkpointProvider')]
    public function testRemoteWriteCheckpointClassification(string $checkpoint, bool $expectedRisk): void
    {
        self::assertSame($expectedRisk, JobRepository::isRiskyCheckpoint($checkpoint));
    }

    public function testCheckpointClassificationSeparatesUnknownWritesFromVerifiedEffects(): void
    {
        self::assertTrue(JobRepository::isWriteOutcomeUnknownCheckpoint('invoice_write_requested'));
        self::assertFalse(JobRepository::isVerifiedSideEffectCheckpoint('invoice_write_requested'));

        self::assertFalse(JobRepository::isWriteOutcomeUnknownCheckpoint('invoice_opened'));
        self::assertTrue(JobRepository::isVerifiedSideEffectCheckpoint('invoice_opened'));

        self::assertFalse(JobRepository::isWriteOutcomeUnknownCheckpoint('booking_completed'));
        self::assertTrue(JobRepository::isVerifiedSideEffectCheckpoint('booking_completed'));
    }

    /** @return iterable<string, array{string,bool}> */
    public static function checkpointProvider(): iterable
    {
        yield 'queued is safe' => ['queued', false];
        yield 'validated PDF is safe' => ['pdf_validated', false];
        yield 'contact request is risky' => ['contact_write_requested', true];
        yield 'voucher request is risky' => ['voucher_write_requested', true];
        yield 'created voucher is risky' => ['voucher_created', true];
        yield 'booking request is risky' => ['booking_write_requested', true];
        yield 'completed booking needs reconciliation' => ['booking_completed', true];
        yield 'correction request is risky' => ['correction_voucher_write_requested', true];
        yield 'created correction is risky' => ['correction_voucher_created', true];
    }
}
