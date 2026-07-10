<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Jobs\CorrectionJobHandler;

final class CorrectionJobHandlerTest extends TestCase
{
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
