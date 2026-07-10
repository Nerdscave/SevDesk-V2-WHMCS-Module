<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Jobs\ExportJobHandler;

final class ExportJobHandlerTest extends TestCase
{
    public function testWorkerStatusEligibilityIsFailClosed(): void
    {
        self::assertTrue(ExportJobHandler::statusIsExportable('Paid', true));
        self::assertFalse(ExportJobHandler::statusIsExportable('Unpaid', true));

        self::assertTrue(ExportJobHandler::statusIsExportable('Paid', false));
        self::assertTrue(ExportJobHandler::statusIsExportable('Unpaid', false));

        foreach (['Draft', 'Cancelled', 'Refunded', 'Collections', 'Payment Pending', ''] as $status) {
            self::assertFalse(
                ExportJobHandler::statusIsExportable($status, false),
                'Unexpected export eligibility for status ' . $status,
            );
        }
    }

    public function testOnlyContactWriteCheckpointsEnterReadOnlyRecovery(): void
    {
        self::assertTrue(ExportJobHandler::contactRecoveryRequired('contact_write_requested'));
        self::assertTrue(ExportJobHandler::contactRecoveryRequired('contact_linked'));
        self::assertFalse(ExportJobHandler::contactRecoveryRequired('pdf_validated'));
        self::assertFalse(ExportJobHandler::contactRecoveryRequired('voucher_write_requested'));
    }
}
