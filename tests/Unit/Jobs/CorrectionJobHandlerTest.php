<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WHMCS\Module\Addon\SevDesk\Jobs\CorrectionJobHandler;

final class CorrectionJobHandlerTest extends TestCase
{
    public function testMalformedCandidateAfterCorrectionWriteRemainsAmbiguous(): void
    {
        $handler = (new ReflectionClass(CorrectionJobHandler::class))->newInstanceWithoutConstructor();

        $outcome = $handler(
            (object) [
                'checkpoint' => 'correction_voucher_write_requested',
                'candidate_json' => '{invalid',
                'sevdesk_id' => '8001',
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('correction_voucher_write_requested', $outcome->checkpoint);
        self::assertSame('8001', $outcome->sevdeskId);
        self::assertSame('invalid_correction_candidate', $outcome->errorCode);
    }

    public function testPermanentRemotePreflightFailureKeepsRiskyCorrectionCheckpoint(): void
    {
        $method = new ReflectionMethod(CorrectionJobHandler::class, 'preflightFailure');

        $outcome = $method->invoke(
            null,
            (object) [
                'checkpoint' => 'correction_voucher_write_requested',
                'sevdesk_id' => '8002',
            ],
            'Synthetic HTTP 422 preflight failure.',
            'correction_preflight_failed',
            422,
            'synthetic-uuid',
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('correction_voucher_write_requested', $outcome->checkpoint);
        self::assertSame('8002', $outcome->sevdeskId);
        self::assertSame(422, $outcome->httpStatus);
        self::assertSame('correction_preflight_failed', $outcome->errorCode);
    }

    #[DataProvider('transientPreflightCheckpointProvider')]
    public function testTransientPreflightRetryKeepsRiskyCorrectionCheckpoint(int $httpStatus): void
    {
        $method = new ReflectionMethod(CorrectionJobHandler::class, 'preflightResumeCheckpoint');

        self::assertSame(
            'correction_voucher_write_requested',
            $method->invoke(null, 'correction_voucher_write_requested'),
            'HTTP ' . $httpStatus . ' must retry the read-only recovery checkpoint.',
        );
    }

    /** @return iterable<string, array{int}> */
    public static function transientPreflightCheckpointProvider(): iterable
    {
        yield 'rate limited read' => [429];
        yield 'server read failure' => [503];
    }

    #[DataProvider('riskyCheckpointProvider')]
    public function testRiskyCorrectionCheckpointsRequireReadOnlyRecovery(string $checkpoint): void
    {
        self::assertTrue(CorrectionJobHandler::readOnlyRecoveryRequired($checkpoint));
    }

    /** @return iterable<string, array{string}> */
    public static function riskyCheckpointProvider(): iterable
    {
        yield 'legacy write requested' => ['correction_write_requested'];
        yield 'legacy created' => ['correction_created'];
        yield 'voucher write requested' => ['correction_voucher_write_requested'];
        yield 'voucher created' => ['correction_voucher_created'];
        yield 'mapping persisted' => ['correction_mapping_persisted'];
    }

    public function testFreshCorrectionDoesNotEnterReadOnlyRecovery(): void
    {
        self::assertFalse(CorrectionJobHandler::readOnlyRecoveryRequired('queued'));
    }
}
