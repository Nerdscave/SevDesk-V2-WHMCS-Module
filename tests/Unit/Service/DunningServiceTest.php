<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Repository\RelatedDocumentRepository;
use WHMCS\Module\Addon\SevDesk\Service\DunningService;
use WHMCS\Module\Addon\SevDesk\Service\InvoicePdf;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceXml;

final class DunningServiceTest extends TestCase
{
    /** @param array<string,mixed> $invoice */
    #[DataProvider('primaryInvoiceProvider')]
    public function testOpenPrimaryInvoiceNeedsAnExactUnpaidState(
        array $invoice,
        ?string $expectedMismatch,
    ): void {
        $client = $this->client([
            new Response(200, [], json_encode(['objects' => [$invoice]], JSON_THROW_ON_ERROR)),
        ]);
        $service = new DunningService(
            $client,
            new RelatedDocumentRepository(),
            new InvoicePdf($client),
            new InvoiceXml($client),
        );

        self::assertSame(
            $expectedMismatch,
            $service->primaryInvoiceMismatch('88', '42', 'INV-42', 11_900, true),
        );
    }

    /** @return iterable<string,array{array<string,mixed>,?string}> */
    public static function primaryInvoiceProvider(): iterable
    {
        $base = [
            'id' => 88,
            'objectName' => 'Invoice',
            'contact' => ['id' => 42, 'objectName' => 'Contact'],
            'invoiceNumber' => 'INV-42',
            'sumGross' => '119.00',
            'paidAmount' => '0.00',
            'status' => 200,
        ];

        yield 'open and unpaid' => [$base, null];
        yield 'partially paid' => [
            array_replace($base, ['paidAmount' => '0.01']),
            'primary_invoice_payment_state_mismatch',
        ];
        yield 'paid amount missing' => [
            array_diff_key($base, ['paidAmount' => true]),
            'primary_invoice_payment_state_mismatch',
        ];
        yield 'not open' => [
            array_replace($base, ['status' => 1000]),
            'primary_invoice_not_open',
        ];
        yield 'gross changed' => [
            array_replace($base, ['sumGross' => '118.99']),
            'primary_invoice_total_mismatch',
        ];
    }

    public function testRule22GuidanceNeedsTheExactRevenueAccountRuleAndZeroRate(): void
    {
        $valid = [[
            'accountDatevId' => 500,
            'allowedReceiptTypes' => ['REVENUE'],
            'allowedTaxRules' => [[
                'id' => 22,
                'taxRates' => [0],
            ]],
        ]];

        self::assertTrue(DunningService::guidanceAllowsRule22($valid, '500'));
        self::assertFalse(DunningService::guidanceAllowsRule22($valid, '501'));
        self::assertFalse(DunningService::guidanceAllowsRule22(
            [array_replace($valid[0], ['allowedReceiptTypes' => ['EXPENSE']])],
            '500',
        ));
        self::assertFalse(DunningService::guidanceAllowsRule22(
            [[
                ...$valid[0],
                'allowedTaxRules' => [['id' => 22, 'taxRates' => [19]]],
            ]],
            '500',
        ));
        self::assertFalse(DunningService::guidanceAllowsRule22(
            [array_replace($valid[0], ['allowedReceiptTypes' => []])],
            '500',
        ));
        self::assertFalse(DunningService::guidanceAllowsRule22(
            [$valid[0], 'malformed'],
            '500',
        ));
        self::assertFalse(DunningService::guidanceAllowsRule22(
            [[
                ...$valid[0],
                'allowedTaxRules' => [['id' => 22]],
            ]],
            '500',
        ));
    }

    /** @param list<Response> $responses */
    private function client(array $responses): SevdeskClient
    {
        return new SevdeskClient(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
            'test-token',
        );
    }
}
