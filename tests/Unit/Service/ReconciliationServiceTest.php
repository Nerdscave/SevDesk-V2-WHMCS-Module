<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\ReconciliationService;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ReconciliationServiceTest extends TestCase
{
    private static ?IlluminateCapsule $database = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!class_exists(Capsule::class, false)) {
            class_alias(IlluminateCapsule::class, Capsule::class);
        }

        self::$database = new IlluminateCapsule();
        self::$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        self::$database->setAsGlobal();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Capsule::schema()->dropIfExists('mod_sevdesk');
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->unique();
            $table->string('sevdesk_id')->nullable()->unique();
            $table->string('document_type', 16)->nullable();
            $table->string('document_authority', 16)->nullable();
            $table->string('document_number')->nullable();
            $table->dateTime('document_ready_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->boolean('is_e_invoice')->nullable();
            $table->string('xml_sha256', 64)->nullable();
        });
    }

    protected function tearDown(): void
    {
        Capsule::schema()->dropIfExists('mod_sevdesk');
        parent::tearDown();
    }

    public function testRule11VoucherIsMappedOnlyAfterExactHeaderAndPositionReadback(): void
    {
        $history = [];
        $service = new ReconciliationService(
            $this->client([
                $this->voucherResponse('11'),
                $this->positionsResponse('500'),
            ], $history),
            new MappingRepository(),
        );

        $result = $service->reconcile(
            $this->smallBusinessInvoice(),
            '42',
            '99',
            '11',
            '500',
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertSame('99', $result->remoteId);
        $mapping = Capsule::table('mod_sevdesk')->where('invoice_id', 10)->first();
        self::assertNotNull($mapping);
        self::assertSame('99', $mapping->sevdesk_id);
        self::assertSame(MappingRepository::DOCUMENT_TYPE_VOUCHER, $mapping->document_type);
        self::assertSame('RE-10', $mapping->document_number);
        self::assertCount(2, $history);
        self::assertSame('/api/v1/Voucher/99', $history[0]['request']->getUri()->getPath());
        self::assertSame('/api/v1/VoucherPos', $history[1]['request']->getUri()->getPath());
    }

    public function testWrongRuleOrAccountNeverRestoresMapping(): void
    {
        $history = [];
        $service = new ReconciliationService(
            $this->client([
                $this->voucherResponse('1'),
            ], $history),
            new MappingRepository(),
        );

        $result = $service->reconcile(
            $this->smallBusinessInvoice(),
            '42',
            '99',
            '11',
            '500',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('voucher_reconciliation_tax_rule_mismatch', $result->code);
        self::assertFalse(Capsule::table('mod_sevdesk')->where('invoice_id', 10)->exists());
        self::assertCount(1, $history);

        $history = [];
        $service = new ReconciliationService(
            $this->client([
                $this->voucherResponse('11'),
                $this->positionsResponse('999'),
            ], $history),
            new MappingRepository(),
        );

        $result = $service->reconcile(
            $this->smallBusinessInvoice(),
            '42',
            '99',
            '11',
            '500',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('voucher_reconciliation_position_identity_mismatch', $result->code);
        self::assertFalse(Capsule::table('mod_sevdesk')->where('invoice_id', 10)->exists());
        self::assertCount(2, $history);
    }

    public function testMarkerSearchPaginatesBeforeExactReadback(): void
    {
        $history = [];
        $firstPage = [];
        for ($id = 1; $id <= 100; ++$id) {
            $firstPage[] = [
                'id' => (string) $id,
                'description' => 'Unrelated Voucher ' . $id,
            ];
        }
        $service = new ReconciliationService(
            $this->client([
                $this->jsonResponse($firstPage),
                $this->jsonResponse([[
                    'id' => '99',
                    'description' => 'RE-10 [WHMCS-INVOICE:10]',
                ]]),
                $this->voucherResponse('11'),
                $this->positionsResponse('500'),
            ], $history),
            new MappingRepository(),
        );

        $result = $service->reconcile(
            $this->smallBusinessInvoice(),
            '42',
            null,
            '11',
            '500',
        );

        self::assertSame(ExportResult::SUCCEEDED, $result->status);
        self::assertCount(4, $history);
        parse_str($history[0]['request']->getUri()->getQuery(), $firstQuery);
        parse_str($history[1]['request']->getUri()->getQuery(), $secondQuery);
        self::assertSame('0', (string) ($firstQuery['offset'] ?? ''));
        self::assertSame('100', (string) ($secondQuery['offset'] ?? ''));
        self::assertSame('/api/v1/Voucher/99', $history[2]['request']->getUri()->getPath());
    }

    public function testFullMarkerSearchCapIsAmbiguousAndNeverMaps(): void
    {
        $history = [];
        $fullPage = [];
        for ($id = 1; $id <= 100; ++$id) {
            $fullPage[] = [
                'id' => (string) $id,
                'description' => 'Unrelated Voucher ' . $id,
            ];
        }
        $responses = [];
        for ($page = 0; $page < 10; ++$page) {
            $responses[] = $this->jsonResponse($fullPage);
        }
        $service = new ReconciliationService(
            $this->client($responses, $history),
            new MappingRepository(),
        );

        $result = $service->reconcile(
            $this->smallBusinessInvoice(),
            '42',
            null,
            '11',
            '500',
        );

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('reconciliation_search_truncated', $result->code);
        self::assertFalse(Capsule::table('mod_sevdesk')->where('invoice_id', 10)->exists());
        self::assertCount(10, $history);
    }

    public function testMissingFrozenTaxContractPerformsNoRemoteRead(): void
    {
        $history = [];
        $service = new ReconciliationService(
            $this->client([], $history),
            new MappingRepository(),
        );

        $result = $service->reconcile($this->smallBusinessInvoice(), '42', '99');

        self::assertSame(ExportResult::AMBIGUOUS, $result->status);
        self::assertSame('voucher_reconciliation_context_missing', $result->code);
        self::assertCount(0, $history);
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new SevdeskClient(new Client(['handler' => $stack]), 'token');
    }

    /** @param list<array<string,mixed>> $objects */
    private function jsonResponse(array $objects): Response
    {
        return new Response(200, [], json_encode(['objects' => $objects], JSON_THROW_ON_ERROR));
    }

    private function voucherResponse(string $taxRuleId): Response
    {
        return $this->jsonResponse([[
            'id' => '99',
            'objectName' => 'Voucher',
            'voucherType' => 'VOU',
            'creditDebit' => 'D',
            'status' => 100,
            'voucherDate' => '01.07.2025',
            'currency' => 'EUR',
            'supplier' => ['id' => '42', 'objectName' => 'Contact'],
            'taxRule' => ['id' => $taxRuleId, 'objectName' => 'TaxRule'],
            'description' => 'RE-10 [WHMCS-INVOICE:10]',
            'sumGross' => '50.00',
        ]]);
    }

    private function positionsResponse(string $accountDatevId): Response
    {
        return $this->jsonResponse([[
            'id' => '501',
            'objectName' => 'VoucherPos',
            'voucher' => ['id' => '99', 'objectName' => 'Voucher'],
            'accountDatev' => ['id' => $accountDatevId, 'objectName' => 'AccountDatev'],
            'taxRate' => '0',
            'net' => false,
            'sumNet' => '50.00',
            'sumGross' => '50.00',
            'comment' => 'Hosting',
        ]]);
    }

    private function smallBusinessInvoice(): InvoiceSnapshot
    {
        return new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '50.00',
            '0',
            [new LineItem('Hosting', '50.00', '0', false)],
        );
    }
}
