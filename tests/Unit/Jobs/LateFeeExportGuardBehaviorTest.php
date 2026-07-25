<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Jobs\ExportJobHandler;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\ContactService;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceItemExportPolicy;
use WHMCS\Module\Addon\SevDesk\Service\PdfRenderer;
use WHMCS\Module\Addon\SevDesk\Service\ReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class LateFeeExportGuardBehaviorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!class_exists(Capsule::class, false)) {
            class_alias(IlluminateCapsule::class, Capsule::class);
        }
        $database = new IlluminateCapsule();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module');
            $table->string('setting');
            $table->text('value')->nullable();
            $table->unique(['module', 'setting']);
        });
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
        $GLOBALS['CONFIG']['TaxType'] = 'Inclusive';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['CONFIG']['TaxType']);
        parent::tearDown();
    }

    /** @return iterable<string, array{string,string}> */
    public static function checkpoints(): iterable
    {
        yield 'new export' => ['queued', 'permanent_failed'];
        yield 'possible earlier write' => ['invoice_write_requested', 'ambiguous'];
    }

    #[DataProvider('checkpoints')]
    public function testLateFeeStopsBeforeAccountingIo(
        string $checkpoint,
        string $expectedStatus,
    ): void {
        $apiCalls = 0;
        $pdfCalls = 0;
        $taxCalls = 0;
        $checkpointCalls = [];
        $httpHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([]));
        $handlerStack->push(Middleware::history($httpHistory));
        $client = new SevdeskClient(
            new Client([
                'handler' => $handlerStack,
            ]),
            'synthetic-token',
        );
        $config = new Config();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway(
                $config,
                static function (string $command) use (&$apiCalls): array {
                    ++$apiCalls;
                    self::assertSame('GetInvoice', $command);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-07-01',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '10.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '10.00',
                        'credit' => '0.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [[
                            'id' => 1,
                            'type' => 'LateFee',
                            'relid' => 0,
                            'description' => 'Synthetic late fee',
                            'amount' => '10.00',
                            'taxed' => false,
                        ]]],
                    ];
                },
            ),
            new MappingRepository(),
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static function () use (&$pdfCalls): string {
                ++$pdfCalls;

                return "%PDF-1.7\nnot expected";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, new MappingRepository()),
            static function () use (&$taxCalls): TaxPolicy {
                ++$taxCalls;
                throw new \LogicException('LateFee must stop before tax classification.');
            },
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => $checkpoint,
                'attempts' => 1,
                'sevdesk_id' => $checkpoint === 'queued' ? null : '99',
                'candidate_json' => null,
            ],
            static function (string $name) use (&$checkpointCalls): bool {
                $checkpointCalls[] = $name;

                return true;
            },
        );

        self::assertSame($expectedStatus, $outcome->status);
        self::assertSame(InvoiceItemExportPolicy::LATE_FEE_REQUIRES_REVIEW, $outcome->errorCode);
        self::assertSame(1, $apiCalls);
        self::assertSame(0, $pdfCalls);
        self::assertSame(0, $taxCalls);
        self::assertSame([], $checkpointCalls);
        self::assertSame([], $httpHistory);
    }
}
