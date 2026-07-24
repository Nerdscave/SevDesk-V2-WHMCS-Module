<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ClientAreaBehaviorTest extends TestCase
{
    public function testForeignInvoiceOwnerCannotReachMappingOrRemotePdf(): void
    {
        $result = $this->runJsonScenario('foreign_owner');

        self::assertSame(404, $result['httpStatus']);
        self::assertSame(0, $result['pdfCalls']);
        self::assertSame('Das Rechnungsdokument ist nicht verfügbar.', $result['result']['vars']['message']);
    }

    public function testDelegatedUserWithoutInvoicePermissionCannotReachMappingOrRemotePdf(): void
    {
        $result = $this->runJsonScenario('missing_invoice_permission');

        self::assertSame(404, $result['httpStatus']);
        self::assertSame(0, $result['mappingCalls']);
        self::assertSame(0, $result['pdfCalls']);
        self::assertSame('Das Rechnungsdokument ist nicht verfügbar.', $result['result']['vars']['message']);
    }

    public function testWrongDocumentTypeIsNotExposed(): void
    {
        $result = $this->runJsonScenario('wrong_type');

        self::assertSame(404, $result['httpStatus']);
        self::assertSame(0, $result['pdfCalls']);
    }

    public function testIncompleteReadyMetadataNeverFetchesRemotePdf(): void
    {
        $result = $this->runJsonScenario('not_ready');

        self::assertSame(409, $result['httpStatus']);
        self::assertSame(0, $result['pdfCalls']);
        self::assertStringContainsString('noch nicht verfügbar', $result['result']['vars']['message']);
    }

    public function testUnpaidInvoiceNeverFetchesAnAlreadyReadyRemotePdf(): void
    {
        $result = $this->runJsonScenario('unpaid');

        self::assertSame(409, $result['httpStatus']);
        self::assertSame(0, $result['pdfCalls']);
        self::assertStringContainsString('noch nicht verfügbar', $result['result']['vars']['message']);
    }

    public function testStatusChangeDuringRemoteReadPreventsStreaming(): void
    {
        $result = $this->runJsonScenario('status_changes_during_pdf');

        self::assertSame(409, $result['httpStatus']);
        self::assertSame(1, $result['pdfCalls']);
        self::assertStringContainsString('noch nicht verfügbar', $result['result']['vars']['message']);
    }

    public function testChangedFinalPdfHashFailsClosedWithSanitisedResponse(): void
    {
        $result = $this->runJsonScenario('hash_mismatch');

        self::assertSame(503, $result['httpStatus']);
        self::assertSame(1, $result['pdfCalls']);
        self::assertStringContainsString('nicht sicher bereitgestellt', $result['result']['vars']['message']);
        self::assertStringNotContainsString('synthetic sevdesk invoice', $result['result']['vars']['message']);
        self::assertCount(1, $result['logs']);
    }

    public function testPdfAuthenticationFailureTripsTheGlobalAlarm(): void
    {
        $result = $this->runJsonScenario('auth_failure');

        self::assertSame(503, $result['httpStatus']);
        self::assertSame(1, $result['pdfCalls']);
        self::assertSame('api_authentication_failed', $result['storedConfig']['health_alarm']);
        self::assertSame('', $result['storedConfig']['sync_enabled']);
    }

    public function testPdfAuthenticationSafetyWritesRemainIndependent(): void
    {
        $alarmFailure = $this->runJsonScenario('auth_alarm_write_failure');
        self::assertArrayNotHasKey('health_alarm', $alarmFailure['storedConfig']);
        self::assertSame('on', $alarmFailure['storedConfig']['runtime_review_required']);
        self::assertSame('synthetic-new-token', $alarmFailure['storedConfig']['runtime_quarantine_token']);
        self::assertSame('', $alarmFailure['storedConfig']['sync_enabled']);
        self::assertCount(2, $alarmFailure['logs']);

        $syncFailure = $this->runJsonScenario('auth_sync_write_failure');
        self::assertSame('api_authentication_failed', $syncFailure['storedConfig']['health_alarm']);
        self::assertArrayNotHasKey('sync_enabled', $syncFailure['storedConfig']);
        self::assertCount(2, $syncFailure['logs']);
    }

    public function testOwnedTypedReadyMappingStreamsOnlyTheVerifiedPdfBytes(): void
    {
        $result = $this->runProcess('ready');

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame("%PDF-1.7\nsynthetic sevdesk invoice\n%%EOF", $result['stdout']);
    }

    public function testPreviouslyFinalInvoiceRemainsAvailableAfterRefundedStatus(): void
    {
        $result = $this->runProcess('refunded');

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame("%PDF-1.7\nsynthetic sevdesk invoice\n%%EOF", $result['stdout']);
    }

    public function testWhmcsAuthorityNeverFetchesTheSevdeskCustomerPdf(): void
    {
        $result = $this->runJsonScenario('whmcs_authority');

        self::assertSame(404, $result['httpStatus']);
        self::assertSame(0, $result['pdfCalls']);
    }

    /** @return array<string,mixed> */
    private function runJsonScenario(string $scenario): array
    {
        $result = $this->runProcess($scenario);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array{exitCode:int,stdout:string,stderr:string} */
    private function runProcess(string $scenario): array
    {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/Fixtures/clientarea-behavior-harness.php',
            $scenario,
        ], [
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
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
