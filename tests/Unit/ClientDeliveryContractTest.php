<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ClientDeliveryContractTest extends TestCase
{
    private string $hooks;

    private string $entrypoint;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2) . '/modules/addons/sevdesk/';
        $hooks = file_get_contents($root . 'hooks.php');
        $entrypoint = file_get_contents($root . 'sevdesk.php');
        self::assertIsString($hooks);
        self::assertIsString($entrypoint);
        $this->hooks = $hooks;
        $this->entrypoint = $entrypoint;
    }

    public function testClientAreaHookPublishesOnlyTheSmallLocalAdapterContract(): void
    {
        $method = $this->sourceBetween(
            $this->hooks,
            'function sevdesk_client_invoice_variables',
            'function sevdesk_email_pre_send',
        );

        self::assertStringContainsString('latestDocumentContextForInvoice(', $method);
        self::assertStringContainsString('DocumentDeliveryContext::usesSevdeskInvoiceAuthority(', $method);
        self::assertStringContainsString('ClientDocumentPresenter::present(', $method);
        self::assertStringContainsString('index.php?m=sevdesk&a=download&id=', $method);
        self::assertStringNotContainsString("get('document_authority'", $method);
        self::assertStringNotContainsString('invoicePdf()', $method);
        self::assertStringNotContainsString('client()', $method);
    }

    public function testPaidInvoiceMailGuardIsLocalAndExactlyScoped(): void
    {
        $method = $this->sourceBetween(
            $this->hooks,
            'function sevdesk_email_pre_send',
            "add_hook('AdminAreaHeadOutput'",
        );

        self::assertStringContainsString("whereRaw('LOWER(type) = ?', ['invoice'])", $method);
        self::assertStringContainsString("strcasecmp(trim(\$invoiceStatus), 'Paid')", $method);
        self::assertStringContainsString('latestDocumentContextForInvoice(', $method);
        self::assertStringContainsString('DocumentDeliveryContext::usesSevdeskInvoiceAuthority(', $method);
        self::assertStringContainsString('isActiveCustomInvoiceTemplate($template)', $method);
        self::assertStringContainsString('EmailAttachmentContext::hasActiveContext(', $method);
        self::assertStringContainsString('EmailAttachmentContext::consume(', $method);
        self::assertStringContainsString("return ['attachments' => [\$attachment]]", $method);
        self::assertStringContainsString("return ['abortsend' => true]", $method);
        self::assertStringContainsString("return \$guardApplies ? ['abortsend' => true] : []", $method);
        self::assertStringNotContainsString("get('invoice_delivery_channel'", $method);
        self::assertStringNotContainsString("get('document_authority'", $method);
        self::assertStringNotContainsString('client()', $method);
        self::assertStringNotContainsString('invoicePdf()', $method);
        self::assertStringNotContainsString('SevdeskClient', $method);
    }

    public function testDownloadProxyUsesOnlyOwnedTypedReadyLocalMapping(): void
    {
        $start = strpos($this->entrypoint, 'function sevdesk_clientarea');
        self::assertNotFalse($start);
        $method = substr($this->entrypoint, $start);

        self::assertStringContainsString('new CurrentUser()', $method);
        self::assertStringContainsString('->client()', $method);
        self::assertStringContainsString('->user()', $method);
        self::assertStringContainsString("getClientsByPermission('invoices')", $method);
        self::assertStringContainsString('invoiceOwnerId($invoiceId)', $method);
        self::assertStringContainsString('findByInvoice($invoiceId)', $method);
        self::assertStringContainsString('latestDocumentContextForInvoice(', $method);
        self::assertStringContainsString('DocumentDeliveryContext::usesSevdeskInvoiceAuthority(', $method);
        self::assertStringContainsString('ClientDocumentPresenter::isReadyInvoiceMapping(', $method);
        self::assertStringContainsString('invoicePdf()->fetch((string) $mapping->sevdesk_id)', $method);
        self::assertStringContainsString("hash_equals(\$expectedHash, \$pdf['sha256'])", $method);
        self::assertStringContainsString('tripAuthenticationSafetyGates()', $method);
        self::assertStringContainsString("header('Content-Type: application/pdf')", $method);
        self::assertStringNotContainsString("\$_GET['sevdesk_id']", $method);
    }

    public function testAutomaticEnqueueFreezesTheRequestedDeliveryContext(): void
    {
        $method = $this->sourceBetween(
            $this->hooks,
            'function sevdesk_enqueue_invoice',
            'function sevdesk_enqueue_review',
        );

        foreach (
            [
                'requestedExportMode',
                'requestedDocumentAuthority',
                'requestedOssProfile',
                'requestedEuB2cMode',
                'requestedDeliveryChannel',
                'requestedEInvoiceMode',
                'requestedEInvoiceClientFieldId',
                'requestedEInvoicePaymentMethodId',
                'requestedEInvoiceActiveFrom',
                'requestedEInvoiceCanaryConfirmed',
                'requestedEInvoiceSevUserId',
                'requestedEInvoiceUnityId',
            ] as $field
        ) {
            self::assertStringContainsString("'" . $field . "'", $method);
        }
    }

    public function testTwentyOneAdapterKeepsTheUnpaidProformaPdfVisible(): void
    {
        $adapter = file_get_contents(
            dirname(__DIR__, 2)
            . '/modules/addons/sevdesk/theme-adapters/twenty-one/sevdesk-invoice-authority.tpl',
        );
        self::assertIsString($adapter);

        $hideRule = strpos($adapter, 'a[href*="dl.php?type=i"]');
        $proformaGuard = strpos($adapter, "state !== 'proforma'");
        self::assertNotFalse($hideRule);
        self::assertNotFalse($proformaGuard);
        self::assertLessThan($hideRule, $proformaGuard);
    }

    private function sourceBetween(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        self::assertNotFalse($start);
        $end = strpos($source, $endMarker, $start + strlen($startMarker));
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }
}
