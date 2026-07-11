<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
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
use WHMCS\Module\Addon\SevDesk\Service\PdfRenderer;
use WHMCS\Module\Addon\SevDesk\Service\ReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ExportJobHandlerFlowTest extends TestCase
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
        Capsule::schema()->dropIfExists('tbladdonmodules');
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->unique();
            $table->string('sevdesk_id')->nullable()->unique();
        });
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module');
            $table->string('setting');
            $table->text('value')->nullable();
        });

        $GLOBALS['CONFIG']['TaxType'] = 'Exclusive';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['CONFIG']['TaxType']);

        parent::tearDown();
    }

    public function testAppliedCreditStopsBeforePdfAndContactResolution(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[]}'),
            new Response(201, [], '{"objects":{"id":"42"}}'),
        ], $history);
        $persistContactCalls = 0;
        $pdfRenderCalls = 0;
        $config = new Config();
        $mappings = new MappingRepository();
        $contacts = new ContactService(
            $client,
            static function () use (&$persistContactCalls): bool {
                ++$persistContactCalls;

                return true;
            },
            static fn (): string => '1',
        );
        $pdf = new PdfRenderer(static function () use (&$pdfRenderCalls): string {
            ++$pdfRenderCalls;

            return "%PDF-1.7\nsynthetic invoice document";
        });
        $exporter = new VoucherExporter($client, static fn (): null => null, static fn (): bool => true);
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, $this->localApi(...)),
            $mappings,
            new JobRepository(),
            $contacts,
            $pdf,
            $exporter,
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'Invoice-only validation must run before Receipt Guidance is resolved.',
            ),
        );
        $checkpoints = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_voucher',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => null,
            ],
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('credit_applied_requires_review', $outcome->errorCode);
        self::assertSame(0, $pdfRenderCalls, 'A locally blocked voucher must not render a PDF.');
        self::assertSame(0, $persistContactCalls, 'A locally blocked voucher must not resolve or create a contact.');
        self::assertCount(0, $history, 'A locally blocked voucher must not perform any sevdesk contact request.');
        self::assertSame([], $checkpoints);
    }

    public function testUnknownContactWriteRecoveryOutranksMappingsAndBusinessTerminals(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[]}'),
        ], $history);
        $persistContactCalls = 0;
        $pdfRenderCalls = 0;
        $config = new Config();
        $mappings = new MappingRepository();
        $mappings->link(10, '77');
        $contacts = new ContactService(
            $client,
            static function () use (&$persistContactCalls): bool {
                ++$persistContactCalls;

                return true;
            },
            static fn (): string => '1',
        );
        $pdf = new PdfRenderer(static function () use (&$pdfRenderCalls): string {
            ++$pdfRenderCalls;

            return "%PDF-1.7\nsynthetic invoice document";
        });
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['status'] = 'Unpaid';
                    $response['credit'] = '0.00';
                }

                return $response;
            }),
            $mappings,
            new JobRepository(),
            $contacts,
            $pdf,
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'Contact recovery must run before Receipt Guidance is resolved.',
            ),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_voucher',
                'checkpoint' => 'contact_write_requested',
                'attempts' => 1,
                'candidate_json' => null,
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('contact_recovery_no_match_ambiguous', $outcome->errorCode);
        self::assertSame(0, $pdfRenderCalls);
        self::assertSame(0, $persistContactCalls);
        self::assertCount(1, $history, 'Recovery may search but must not create a second contact.');
        self::assertSame('GET', $history[0]['request']->getMethod());
    }

    #[DataProvider('contactRecoveryReadFailureProvider')]
    public function testContactRecoveryReadFailurePreservesRecoveryCheckpoint(
        int $attempts,
        string $expectedStatus,
    ): void {
        $history = [];
        $client = $this->client([
            new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
        ], $history);
        $pdfRenderCalls = 0;
        $config = new Config();
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, $this->localApi(...)),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static function () use (&$pdfRenderCalls): string {
                ++$pdfRenderCalls;

                return "%PDF-1.7\nsynthetic invoice document";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'Contact recovery must finish before Receipt Guidance is resolved.',
            ),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_voucher',
                'checkpoint' => 'contact_write_requested',
                'attempts' => $attempts,
                'candidate_json' => '{"whmcsClientId":20}',
            ],
            static fn (): bool => true,
        );

        self::assertSame($expectedStatus, $outcome->status);
        self::assertSame('contact_search_failed', $outcome->errorCode);
        self::assertSame(500, $outcome->httpStatus);
        self::assertSame('contact_write_requested', $outcome->checkpoint);
        self::assertSame(0, $pdfRenderCalls);
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
    }

    /** @return iterable<string, array{int,string}> */
    public static function contactRecoveryReadFailureProvider(): iterable
    {
        yield 'safe read retry' => [1, 'retry_wait'];
        yield 'exhausted read becomes ambiguous' => [4, 'ambiguous'];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function localApi(string $command, array $parameters): array
    {
        if ($command === 'GetInvoice') {
            self::assertSame(10, $parameters['invoiceid']);

            return [
                'result' => 'success',
                'userid' => 20,
                'status' => 'Paid',
                'date' => '2026-07-01',
                'invoicenum' => 'RE-10',
                'currencycode' => 'EUR',
                'total' => '119.00',
                'credit' => '20.00',
                'taxrate' => '19',
                'taxrate2' => '0',
                'items' => [
                    'item' => [[
                        'id' => 1,
                        'type' => 'Hosting',
                        'description' => 'Synthetic hosting item',
                        'amount' => '100.00',
                        'taxed' => true,
                    ]],
                ],
            ];
        }

        if ($command === 'GetClientsDetails') {
            self::assertSame(20, $parameters['clientid']);

            return [
                'result' => 'success',
                'client' => [
                    'id' => 20,
                    'currency_code' => 'EUR',
                    'companyname' => 'Synthetic Company',
                    'firstname' => 'Synthetic',
                    'lastname' => 'Customer',
                    'email' => 'synthetic@example.invalid',
                    'address1' => 'Example Street 1',
                    'postcode' => '12345',
                    'city' => 'Example City',
                    'countrycode' => 'DE',
                    'taxexempt' => false,
                    'customfields' => ['customfield' => []],
                ],
            ];
        }

        self::fail('Unexpected WHMCS local API command: ' . $command);
    }

    /**
     * @param list<Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new SevdeskClient(new Client(['handler' => $stack]), 'synthetic-token');
    }
}
