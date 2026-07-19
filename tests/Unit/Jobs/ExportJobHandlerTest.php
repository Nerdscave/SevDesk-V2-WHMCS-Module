<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Jobs\ExportJobHandler;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;

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

    public function testPartialEuB2cSnapshotCannotFallBackToMutableConfiguration(): void
    {
        $method = new \ReflectionMethod(ExportJobHandler::class, 'documentEuB2cMode');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke(null, [
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedOssProfile' => 'blocked',
        ], TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED);
    }

    public function testFrozenEuB2cSnapshotWinsOverRequestedAndFallbackValues(): void
    {
        $method = new \ReflectionMethod(ExportJobHandler::class, 'documentEuB2cMode');

        self::assertSame(
            TaxPolicy::EU_B2C_BLOCKED,
            $method->invoke(null, [
                'targetAllowed' => true,
                'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
                'requestedEuB2cMode' => TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED,
            ], TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED),
        );
    }

    public function testDefiniteInvoiceWriteValidationFailureRewindsBeforeCreate(): void
    {
        $outcome = $this->failureOutcome(422, true, 'invoice_write_requested');

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('document_type_selected', $outcome->checkpoint);
    }

    public function testDefiniteInvoiceRateLimitCanSafelyRetryTheCreate(): void
    {
        $outcome = $this->failureOutcome(429, true, 'invoice_write_requested');

        self::assertSame('retry_wait', $outcome->status);
        self::assertSame('document_type_selected', $outcome->checkpoint);
    }

    public function testRateLimitedReadOnlyRecoveryKeepsTheUnknownWriteCheckpoint(): void
    {
        $outcome = $this->failureOutcome(429, false, 'invoice_write_requested');

        self::assertSame('retry_wait', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
    }

    public function testUnknownInvoiceWriteOutcomeNeverRewindsToCreate(): void
    {
        $handler = (new \ReflectionClass(ExportJobHandler::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ExportJobHandler::class, 'failureResultToOutcome');
        /** @var JobOutcome $outcome */
        $outcome = $method->invoke(
            $handler,
            'invoice_create_failed_ambiguous',
            'Synthetic unknown response.',
            ['httpStatus' => 500, 'outcomeUnknown' => true],
            (object) ['checkpoint' => 'invoice_write_requested', 'attempts' => 1],
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
    }

    public function testTypedInvoiceMappingOnlyContinuesAfterInvoiceWriteStarted(): void
    {
        $method = new \ReflectionMethod(ExportJobHandler::class, 'isInvoiceContinuation');
        $candidate = ['targetDocumentType' => 'invoice'];

        self::assertFalse($method->invoke(
            null,
            (object) ['action' => 'export_document', 'checkpoint' => 'document_type_selected'],
            $candidate,
            MappingRepository::DOCUMENT_TYPE_INVOICE,
        ));
        self::assertTrue($method->invoke(
            null,
            (object) ['action' => 'export_document', 'checkpoint' => 'invoice_write_requested'],
            $candidate,
            MappingRepository::DOCUMENT_TYPE_INVOICE,
        ));
    }

    public function testVoucherPreWriteFlowChecksBothLocalCheckpointResults(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/modules/addons/sevdesk/lib/Jobs/ExportJobHandler.php');
        self::assertIsString($source);

        self::assertStringContainsString("!\$checkpoint('preflight_complete'", $source);
        self::assertStringContainsString("!\$checkpoint('pdf_validated'", $source);
        self::assertStringContainsString('voucher_preflight_checkpoint_failed', $source);
        self::assertStringContainsString('voucher_pdf_checkpoint_failed', $source);
    }

    private function failureOutcome(int $httpStatus, bool $definiteWriteRejected, string $checkpoint): JobOutcome
    {
        $handler = (new \ReflectionClass(ExportJobHandler::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ExportJobHandler::class, 'failureResultToOutcome');

        /** @var JobOutcome $outcome */
        $outcome = $method->invoke(
            $handler,
            $httpStatus === 429 ? 'api_rate_limited' : 'invoice_create_failed_permanent',
            'Synthetic definite response.',
            [
                'httpStatus' => $httpStatus,
                'definiteWriteRejected' => $definiteWriteRejected,
                'retryAfterSeconds' => 60,
            ],
            (object) ['checkpoint' => $checkpoint, 'attempts' => 1],
        );

        return $outcome;
    }
}
