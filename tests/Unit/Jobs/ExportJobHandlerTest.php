<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
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

    public function testTransientCountryReferenceReadCanRetryBeforeInvoiceWrite(): void
    {
        foreach (
            [
                ['httpStatus' => 503, 'sevdeskCode' => 'SERVER_ERROR'],
                ['httpStatus' => null, 'sevdeskCode' => 'transport_error'],
            ] as $context
        ) {
            $outcome = $this->invokeFailureOutcome(
                'invoice_country_reference_failed',
                $context + ['outcomeUnknown' => false],
                'document_type_selected',
            );

            self::assertSame('retry_wait', $outcome->status);
            self::assertSame('document_type_selected', $outcome->checkpoint);
        }
    }

    public function testTransientFrozenInvoiceReferenceReadCanRetryBeforeInvoiceWrite(): void
    {
        foreach (
            [
                ['httpStatus' => 429, 'sevdeskCode' => 'RATE_LIMIT', 'retryAfterSeconds' => 60],
                ['httpStatus' => null, 'sevdeskCode' => 'transport_error'],
            ] as $context
        ) {
            $outcome = $this->invokeFailureOutcome(
                $context['httpStatus'] === 429
                    ? 'api_rate_limited'
                    : 'invoice_reference_revalidation_failed',
                $context + ['outcomeUnknown' => false],
                'e_invoice_target_selected',
            );

            self::assertSame('retry_wait', $outcome->status);
            self::assertSame('e_invoice_target_selected', $outcome->checkpoint);
        }
    }

    public function testPermanentCountryReferenceReadFailureDoesNotRetry(): void
    {
        $outcome = $this->invokeFailureOutcome(
            'invoice_country_reference_failed_permanent',
            ['httpStatus' => 422, 'outcomeUnknown' => false],
            'document_type_selected',
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('document_type_selected', $outcome->checkpoint);
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

    public function testInvalidPersistedImportDateFailsClosed(): void
    {
        $config = new Config();
        (new \ReflectionProperty(Config::class, 'values'))->setValue($config, [
            'import_after' => 'not-a-date',
        ]);
        $handler = (new \ReflectionClass(ExportJobHandler::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ExportJobHandler::class, 'config'))->setValue($handler, $config);
        $method = new \ReflectionMethod(ExportJobHandler::class, 'isAfterConfiguredStart');

        self::assertFalse($method->invoke($handler, '2026-07-27'));
    }

    public function testHistoricalZeroTaxOverrideForcesAFrozenVoucherTargetUnderInvoiceOnly(): void
    {
        $handler = (new \ReflectionClass(ExportJobHandler::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ExportJobHandler::class, 'frozenOrNewTarget');
        $candidate = [
            'historicalBackfill' => true,
            'historicalZeroTaxOverride' => true,
            'historicalZeroTaxOverrideVersion' => 'rule17_voucher_v1',
            'historicalZeroTaxAccountDatevId' => '4120',
            'historicalZeroTaxRuleId' => '17',
            'historicalZeroTaxUntil' => '2025-12-31',
            'historicalZeroTaxManualReclassificationRequired' => true,
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedInvoiceLifecycleMode' => 'after_payment_proforma',
            'requestedLateFeeMode' => 'blocked',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
        ];
        $tax = TaxDecision::allow(
            'historical_zero_tax_manual_override',
            '4120',
            '17',
            'Synthetic provisional decision.',
        )->withValidatedGuidance(['0']);
        $checkpointContext = [];

        $target = $method->invoke(
            $handler,
            (object) ['action' => 'export_document', 'checkpoint' => 'queued'],
            $candidate,
            $tax,
            'Paid',
            'INV-2025-1',
            static function (string $checkpoint, array $context) use (&$checkpointContext): bool {
                self::assertSame('document_type_selected', $checkpoint);
                $checkpointContext = $context;

                return true;
            },
        );

        self::assertInstanceOf(DocumentTargetDecision::class, $target);
        self::assertTrue($target->allowed);
        self::assertSame('voucher', $target->documentType);
        self::assertSame('voucher_only', $target->exportMode);
        self::assertSame('whmcs', $target->documentAuthority);
        self::assertSame('17', $target->taxRuleId);
        self::assertSame('4120', $checkpointContext['targetAccountDatevId'] ?? null);
        self::assertSame('voucher', $checkpointContext['targetDocumentType'] ?? null);
    }

    public function testHistoricalZeroTaxDiscountOverrideFreezesAMailFreeInvoiceTarget(): void
    {
        $config = new Config();
        (new \ReflectionProperty(Config::class, 'values'))->setValue($config, [
            'export_mode' => 'invoice_only',
            'invoice_canary_confirmed' => 'on',
            'invoice_sev_user_id' => '41',
            'invoice_unity_id' => '7',
        ]);
        $handler = (new \ReflectionClass(ExportJobHandler::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ExportJobHandler::class, 'config'))->setValue($handler, $config);
        $method = new \ReflectionMethod(ExportJobHandler::class, 'frozenOrNewTarget');
        $candidate = [
            'historicalBackfill' => true,
            'historicalZeroTaxOverride' => true,
            'historicalZeroTaxOverrideVersion' => 'rule17_invoice_discount_v1',
            'historicalZeroTaxRuleId' => '17',
            'historicalZeroTaxUntil' => '2025-12-31',
            'historicalZeroTaxManualReclassificationRequired' => true,
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedInvoiceLifecycleMode' => 'after_payment_proforma',
            'requestedLateFeeMode' => 'blocked',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
        ];
        $tax = TaxDecision::allowInvoice(
            'third_country',
            '17',
            'Synthetic provisional decision.',
            ['0'],
        );
        $checkpointContext = [];

        $target = $method->invoke(
            $handler,
            (object) ['action' => 'export_document', 'checkpoint' => 'queued'],
            $candidate,
            $tax,
            'Paid',
            'INV-2025-2',
            static function (string $checkpoint, array $context) use (&$checkpointContext): bool {
                self::assertSame('document_type_selected', $checkpoint);
                $checkpointContext = $context;

                return true;
            },
        );

        self::assertInstanceOf(DocumentTargetDecision::class, $target);
        self::assertTrue($target->allowed);
        self::assertSame('invoice', $target->documentType);
        self::assertSame('invoice_only', $target->exportMode);
        self::assertSame('whmcs', $target->documentAuthority);
        self::assertSame('17', $target->taxRuleId);
        self::assertNull($checkpointContext['targetAccountDatevId'] ?? null);
        self::assertSame('41', $checkpointContext['targetSevUserId'] ?? null);
        self::assertSame('7', $checkpointContext['targetUnityId'] ?? null);
        self::assertSame('off', $checkpointContext['targetEInvoiceMode'] ?? null);
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

    /** @param array<string,scalar|null> $context */
    private function invokeFailureOutcome(string $code, array $context, string $checkpoint): JobOutcome
    {
        $handler = (new \ReflectionClass(ExportJobHandler::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ExportJobHandler::class, 'failureResultToOutcome');

        /** @var JobOutcome $outcome */
        $outcome = $method->invoke(
            $handler,
            $code,
            'Synthetic read failure.',
            $context,
            (object) ['checkpoint' => $checkpoint, 'attempts' => 1],
        );

        return $outcome;
    }
}
