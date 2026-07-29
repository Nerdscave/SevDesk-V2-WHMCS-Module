<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Jobs\DunningJobHandler;

final class DunningJobHandlerTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function definitiveCheckpointProvider(): iterable
    {
        yield 'reminder create' => ['reminder_create_requested', 'queued'];
        yield 'reminder delivery' => [
            'reminder_delivery_write_requested',
            'reminder_mapping_persisted',
        ];
        yield 'cancellation create' => ['cancellation_write_requested', 'queued'];
        yield 'cancellation delivery' => [
            'cancellation_delivery_write_requested',
            'cancellation_mapping_persisted',
        ];
        yield 'fee voucher create' => ['late_fee_voucher_write_requested', 'queued'];
    }

    #[DataProvider('definitiveCheckpointProvider')]
    public function testDefinitiveRateLimitResumesBeforeTheRejectedWrite(
        string $checkpoint,
        string $expectedCheckpoint,
    ): void {
        $handler = (new ReflectionClass(DunningJobHandler::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DunningJobHandler::class, 'apiFailure');

        $outcome = $method->invoke(
            $handler,
            (object) ['checkpoint' => $checkpoint, 'attempts' => 1],
            new ApiException('rate limited', 429, 'RATE_LIMIT', retryAfterSeconds: 120),
        );

        self::assertSame('retry_wait', $outcome->status);
        self::assertSame($expectedCheckpoint, $outcome->checkpoint);
        self::assertSame(120, $outcome->retryAfterSeconds);
    }

    public function testUnknownWriteOutcomeNeverMovesBehindTheRiskyCheckpoint(): void
    {
        $handler = (new ReflectionClass(DunningJobHandler::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DunningJobHandler::class, 'apiFailure');

        $outcome = $method->invoke(
            $handler,
            (object) [
                'checkpoint' => 'cancellation_delivery_write_requested',
                'attempts' => 1,
                'sevdesk_id' => '7101',
            ],
            new ApiException('transport failed', null, 'transport_error', outcomeUnknown: true),
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('cancellation_delivery_write_requested', $outcome->checkpoint);
        self::assertSame('7101', $outcome->sevdeskId);
    }
}
