<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HookBehaviorTest extends TestCase
{
    public function testFirstPaidInvoiceEmailIsBlockedBeforeAnExportJobExists(): void
    {
        $result = $this->runScenario('first_paid_email');

        self::assertTrue($result['guard']);
        self::assertSame(0, $result['jobsBeforeInvoicePaid']);
        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testInvoicePaidRemainsTheActualDeliveryTrigger(): void
    {
        $result = $this->runScenario('invoice_paid_delivery');

        self::assertSame(0, $result['jobsBeforeInvoicePaid']);
        self::assertSame(1, $result['jobsAfterInvoicePaid']);
        self::assertSame('InvoicePaid', $result['trigger']);
        self::assertTrue($result['deliveryRequested']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testPaidInvoiceGuardFailsClosedOnALocalReadError(): void
    {
        $result = $this->runScenario('local_read_failure');

        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertTrue($result['logged']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testUnclassifiableLaterMailDoesNotSuppressWhmcsOnAMappingReadError(): void
    {
        $result = $this->runScenario('later_local_read_failure');

        self::assertFalse($result['guard']);
        self::assertSame([], $result['mailResult']);
        self::assertTrue($result['logged']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testLaterWhmcsAuthorityMailIsNotGloballySuppressedOnAMappingReadError(): void
    {
        $result = $this->runScenario('later_whmcs_local_read_failure');

        self::assertSame([], $result['mailResult']);
        self::assertTrue($result['logged']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testUnclassifiableLaterTemplateDoesNotSuppressAllWhmcsMail(): void
    {
        $result = $this->runScenario('later_template_read_failure');

        self::assertSame([], $result['mailResult']);
        self::assertTrue($result['logged']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testWhmcsAuthorityDoesNotSuppressTheNormalInvoiceEmail(): void
    {
        $result = $this->runScenario('whmcs_authority');

        self::assertFalse($result['guard']);
        self::assertSame([], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testDisabledAutomaticSyncStillProtectsSevdeskAuthorityMail(): void
    {
        $result = $this->runScenario('automatic_enqueue_disabled');

        self::assertSame(0, $result['jobCount']);
        self::assertTrue($result['guard']);
        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testMissingRewriteSignatureCannotEnqueueAutomaticWork(): void
    {
        $result = $this->runScenario('runtime_signature_missing');

        self::assertSame(0, $result['jobCount']);
        self::assertFalse($result['guard']);
        self::assertSame([], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testRuntimeReviewBlocksAutomaticEnqueueAndCronRunner(): void
    {
        $result = $this->runScenario('runtime_review_required');

        self::assertSame(0, $result['jobCount']);
        self::assertSame(0, $result['runnerCalls']);
        self::assertTrue($result['guard']);
        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testUnconfirmedInvoiceCanaryStillProtectsSevdeskAuthorityMail(): void
    {
        $result = $this->runScenario('invoice_canary_disabled');

        self::assertSame(0, $result['jobCount']);
        self::assertTrue($result['guard']);
        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testAuthenticationAlarmStillProtectsSevdeskAuthorityMail(): void
    {
        $result = $this->runScenario('authentication_alarm');

        self::assertSame(1, $result['jobCount']);
        self::assertSame('export_document', $result['action']);
        self::assertSame('export_voucher:42', $result['dedupeKey']);
        self::assertTrue($result['deliveryRequested']);
        self::assertSame('pending', $result['clientState']);
        self::assertTrue($result['guard']);
        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testGuardDoesNotSuppressANonInvoiceTemplate(): void
    {
        $result = $this->runScenario('non_invoice_template');

        self::assertSame([], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testActiveAttachmentContextHandsTheVerifiedPdfToWhmcsExactlyOnce(): void
    {
        $result = $this->runScenario('active_attachment_context');

        self::assertSame([
            'attachments' => [[
                'filename' => 'sevdesk-invoice.pdf',
                'data' => "%PDF-1.7\nsynthetic sevdesk invoice",
            ]],
        ], $result['mailResult']);
        self::assertFalse($result['contextRemaining']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testWrongAttachmentTokenAbortsMailWithoutForgingAConsumptionReceipt(): void
    {
        $result = $this->runScenario('wrong_attachment_token');

        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertTrue($result['contextRemaining']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testGlobalModeChangeDoesNotReclassifyAnExistingLegacyMapping(): void
    {
        $result = $this->runScenario('existing_legacy_mapping');

        self::assertFalse($result['guard']);
        self::assertSame([], $result['mailResult']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testMappedSevdeskInvoiceKeepsPaidMailGuardAcrossALaterReadFailure(): void
    {
        $result = $this->runScenario('mapped_sevdesk_invoice_later_read_failure');

        self::assertTrue($result['guard']);
        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertTrue($result['logged']);
        self::assertSame(0, $result['remoteCalls']);
    }

    public function testFrozenSevdeskAuthoritySurvivesALaterGlobalSwitchToWhmcs(): void
    {
        $result = $this->runScenario('mapped_sevdesk_invoice_after_global_whmcs_switch');

        self::assertTrue($result['guard']);
        self::assertSame(['abortsend' => true], $result['mailResult']);
        self::assertTrue($result['logged']);
        self::assertSame(0, $result['remoteCalls']);
    }

    #[DataProvider('enqueueMatrixProvider')]
    public function testInvoiceHookModeMatrix(
        string $mode,
        bool $onlyPaid,
        string $event,
        int $expectedJobs,
    ): void {
        $result = $this->runScenario(
            'enqueue_matrix',
            $mode,
            $onlyPaid ? 'on' : '',
            $event,
        );

        self::assertSame($expectedJobs, $result['jobCount']);
        self::assertSame(0, $result['remoteCalls']);
        if ($expectedJobs === 1) {
            self::assertSame('export_document', $result['action']);
            self::assertSame('export_voucher:42', $result['dedupeKey']);
            self::assertSame($event, $result['trigger']);
        }
    }

    /** @return iterable<string, array{string,bool,string,int}> */
    public static function enqueueMatrixProvider(): iterable
    {
        yield 'voucher paid-only created' => ['voucher_only', true, 'InvoiceCreated', 0];
        yield 'voucher paid-only paid' => ['voucher_only', true, 'InvoicePaid', 1];
        yield 'voucher open created' => ['voucher_only', false, 'InvoiceCreated', 1];
        yield 'voucher open paid' => ['voucher_only', false, 'InvoicePaid', 0];
        yield 'hybrid paid-only created' => ['invoice_for_oss', true, 'InvoiceCreated', 0];
        yield 'hybrid paid-only paid' => ['invoice_for_oss', true, 'InvoicePaid', 1];
        yield 'hybrid open created' => ['invoice_for_oss', false, 'InvoiceCreated', 1];
        yield 'hybrid open paid' => ['invoice_for_oss', false, 'InvoicePaid', 1];
        yield 'invoice-only paid setting created' => ['invoice_only', true, 'InvoiceCreated', 0];
        yield 'invoice-only paid setting paid' => ['invoice_only', true, 'InvoicePaid', 1];
        yield 'invoice-only open setting created' => ['invoice_only', false, 'InvoiceCreated', 0];
        yield 'invoice-only open setting paid' => ['invoice_only', false, 'InvoicePaid', 1];
    }

    public function testClientInvoiceHookReturnsReadyAdapterContractWithoutRemoteIo(): void
    {
        $result = $this->runScenario('client_invoice_ready');

        self::assertSame(0, $result['remoteCalls']);
        self::assertSame([
            'authority' => 'sevdesk',
            'state' => 'ready',
            'invoiceNumber' => 'RE-42',
            'downloadUrl' => '/whmcs/index.php?m=sevdesk&a=download&id=42',
        ], $result['result']['sevdeskDocument']);
    }

    /** @return array<string, mixed> */
    private function runScenario(string $scenario, string ...$arguments): array
    {
        $command = [
            PHP_BINARY,
            __DIR__ . '/Fixtures/hook-behavior-harness.php',
            $scenario,
            ...$arguments,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, is_string($stderr) ? $stderr : 'Hook harness failed.');
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
