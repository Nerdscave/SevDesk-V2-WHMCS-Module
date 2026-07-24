<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceDiscount;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Jobs\ExportJobHandler;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\ContactService;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceExporter;
use WHMCS\Module\Addon\SevDesk\Service\InvoicePdf;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\PdfRenderer;
use WHMCS\Module\Addon\SevDesk\Service\ReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsPaymentStructureService;
use WHMCS\Module\Addon\SevDesk\Support\EmailAttachmentContext;

require_once dirname(__DIR__) . '/Fixtures/loaded-email-hook.php';

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ExportJobHandlerFlowTest extends TestCase
{
    private static ?IlluminateCapsule $database = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!defined('ROOTDIR')) {
            define('ROOTDIR', dirname(__DIR__) . '/Fixtures/whmcs-root');
        }
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
        Capsule::schema()->dropIfExists('tblconfiguration');
        Capsule::schema()->dropIfExists('tblcustomfields');
        Capsule::schema()->dropIfExists('tblemailtemplates');
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
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module');
            $table->string('setting');
            $table->text('value')->nullable();
        });
        Capsule::schema()->create('tblconfiguration', static function ($table): void {
            $table->increments('id');
            $table->string('setting')->unique();
            $table->text('value')->nullable();
        });
        Capsule::schema()->create('tblcustomfields', static function ($table): void {
            $table->increments('id');
            $table->string('type');
            $table->string('fieldname');
        });
        Capsule::schema()->create('tblemailtemplates', static function ($table): void {
            $table->increments('id');
            $table->string('type');
            $table->string('name');
            $table->boolean('custom')->default(false);
            $table->boolean('disabled')->default(false);
        });
        Capsule::table('tblcustomfields')->insert([
            'id' => 123,
            'type' => 'client',
            'fieldname' => 'sevdesk ID',
        ]);
        (new Config())->set('custom_field_id', 123);

        $GLOBALS['CONFIG']['TaxType'] = 'Exclusive';
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['CONFIG']['TaxType'],
            $GLOBALS['CONFIG']['EnableProformaInvoicing'],
            $GLOBALS['CONFIG']['Template'],
        );

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
        self::assertSame([], $checkpoints, 'No remote-capable path was reached.');
    }

    public function testUnexpectedDatabaseFailureDoesNotExposeSqlBindingsOrCustomerData(): void
    {
        $secret = 'synthetic-private-binding';
        $customerData = 'private-customer@example.invalid';
        $queryError = new QueryException(
            'synthetic',
            'SELECT * FROM tblclients WHERE email = ? AND secret = ?',
            [$customerData, $secret],
            new PDOException('Synthetic database failure'),
        );
        self::assertStringContainsString($secret, $queryError->getMessage());
        self::assertStringContainsString($customerData, $queryError->getMessage());

        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway(
                $config,
                function (string $command, array $parameters): array {
                    $response = $this->localApi($command, $parameters);
                    if ($command === 'GetInvoice') {
                        $response['credit'] = '0.00';
                        $response['total'] = '119.00';
                    }

                    return $response;
                },
            ),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw $queryError,
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
        self::assertSame('local_preflight_failed', $outcome->errorCode);
        self::assertStringContainsString('Referenz:', $outcome->message);
        self::assertStringNotContainsString($secret, $outcome->message);
        self::assertStringNotContainsString($customerData, $outcome->message);
        self::assertStringNotContainsString('tblclients', $outcome->message);
        self::assertStringNotContainsString('SELECT', $outcome->message);
        self::assertNull($outcome->candidate);
        self::assertSame(
            ['queued', 'queued'],
            $checkpoints,
            'The PII-free invoice contract and contact link are frozen before tax-guidance I/O.',
        );
        self::assertSame([], $history);
    }

    public function testPureMassPaymentContainerIsSkippedBeforeAnyRemoteWrite(): void
    {
        $this->resetPaymentStructureTables();
        Capsule::table('tblinvoices')->insert([
            [
                'id' => 100,
                'userid' => 20,
                'status' => 'Paid',
                'subtotal' => '20.00',
                'credit' => '0.00',
                'tax' => '0.00',
                'tax2' => '0.00',
                'total' => '20.00',
            ],
            [
                'id' => 10,
                'userid' => 20,
                'status' => 'Paid',
                'subtotal' => '100.00',
                'credit' => '20.00',
                'tax' => '0.00',
                'tax2' => '0.00',
                'total' => '80.00',
            ],
        ]);
        Capsule::table('tblinvoiceitems')->insert([
            ['invoiceid' => 100, 'type' => 'Invoice', 'relid' => 10, 'amount' => '20.00'],
            ['invoiceid' => 10, 'type' => 'Hosting', 'relid' => 42, 'amount' => '100.00'],
        ]);
        Capsule::table('tblaccounts')->insert([
            [
                'invoiceid' => 100,
                'amountin' => '20.00',
                'amountout' => '0.00',
                'refundid' => 0,
            ],
            [
                'invoiceid' => 10,
                'amountin' => '80.00',
                'amountout' => '0.00',
                'refundid' => 0,
            ],
        ]);

        $history = [];
        $client = $this->client([], $history);
        $contactCheckpointCalls = 0;
        $pdfRenderCalls = 0;
        $config = new Config();
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway(
                $config,
                static function (string $command, array $parameters): array {
                    self::assertSame('GetInvoice', $command);
                    self::assertSame(100, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-07-01',
                        'invoicenum' => 'MASS-100',
                        'currencycode' => 'EUR',
                        'subtotal' => '20.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '20.00',
                        'credit' => '0.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [[
                            'id' => 1,
                            'type' => 'Invoice',
                            'relid' => 10,
                            'description' => 'Synthetic invoice reference',
                            'amount' => '20.00',
                            'taxed' => 0,
                        ]]],
                    ];
                },
            ),
            $mappings,
            new JobRepository(),
            new ContactService(
                $client,
                static function () use (&$contactCheckpointCalls): bool {
                    ++$contactCheckpointCalls;

                    return true;
                },
                static fn (): string => '1',
            ),
            new PdfRenderer(static function () use (&$pdfRenderCalls): string {
                ++$pdfRenderCalls;

                return "%PDF-1.7\nnot expected";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'A mass-payment container must stop before tax classification.',
            ),
            paymentStructure: new WhmcsPaymentStructureService(),
        );
        $checkpoints = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 100,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => null,
            ],
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
        );

        self::assertSame('skipped', $outcome->status);
        self::assertStringContainsString('kein eigener Umsatzbeleg', $outcome->message);
        self::assertSame(0, $pdfRenderCalls);
        self::assertSame(0, $contactCheckpointCalls);
        self::assertSame([], $history);
        self::assertSame([], $checkpoints);
    }

    public function testUnprovenCreditedInvoiceIsBlockedBeforeAnyRemoteWrite(): void
    {
        $this->resetPaymentStructureTables();
        Capsule::table('tblinvoices')->insert([
            'id' => 10,
            'userid' => 20,
            'status' => 'Paid',
            'subtotal' => '100.00',
            'credit' => '20.00',
            'tax' => '0.00',
            'tax2' => '0.00',
            'total' => '80.00',
        ]);
        Capsule::table('tblinvoiceitems')->insert([
            'invoiceid' => 10,
            'type' => 'Hosting',
            'relid' => 42,
            'amount' => '100.00',
        ]);
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => 10,
            'amountin' => '80.00',
            'amountout' => '0.00',
            'refundid' => 0,
        ]);

        $history = [];
        $client = $this->client([], $history);
        $contactCheckpointCalls = 0;
        $pdfRenderCalls = 0;
        $config = new Config();
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway(
                $config,
                static function (string $command, array $parameters): array {
                    self::assertSame('GetInvoice', $command);
                    self::assertSame(10, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-07-01',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '100.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '80.00',
                        'credit' => '20.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [[
                            'id' => 1,
                            'type' => 'Hosting',
                            'relid' => 42,
                            'description' => 'Synthetic hosting item',
                            'amount' => '100.00',
                            'taxed' => 0,
                        ]]],
                    ];
                },
            ),
            $mappings,
            new JobRepository(),
            new ContactService(
                $client,
                static function () use (&$contactCheckpointCalls): bool {
                    ++$contactCheckpointCalls;

                    return true;
                },
                static fn (): string => '1',
            ),
            new PdfRenderer(static function () use (&$pdfRenderCalls): string {
                ++$pdfRenderCalls;

                return "%PDF-1.7\nnot expected";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'An unproven credit must stop before tax classification.',
            ),
            paymentStructure: new WhmcsPaymentStructureService(),
        );
        $checkpoints = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
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
        self::assertSame('mass_payment_parent_missing', $outcome->errorCode);
        self::assertSame(0, $pdfRenderCalls);
        self::assertSame(0, $contactCheckpointCalls);
        self::assertSame([], $history);
        self::assertSame([], $checkpoints);
    }

    public function testConfirmedOrdinaryCreditContinuesOnTheVoucherPath(): void
    {
        $this->resetPaymentStructureTables();
        Capsule::table('tblinvoices')->insert([
            'id' => 10,
            'userid' => 20,
            'status' => 'Paid',
            'subtotal' => '100.00',
            'credit' => '20.00',
            'tax' => '19.00',
            'tax2' => '0.00',
            'total' => '99.00',
        ]);
        Capsule::table('tblinvoiceitems')->insert([
            'invoiceid' => 10,
            'type' => 'Hosting',
            'relid' => 42,
            'amount' => '100.00',
        ]);
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => 10,
            'amountin' => '99.00',
            'amountout' => '0.00',
            'refundid' => 0,
        ]);

        $history = [];
        $client = $this->client([
            new Response(500, [], '{}'),
        ], $history);
        $config = new Config();
        $config->set('export_mode', 'voucher_only');
        $config->set('document_authority', 'whmcs');
        $config->set('oss_profile', 'blocked');
        $config->set('eu_b2c_mode', 'blocked');
        $mappings = new MappingRepository();
        $pdfRenderCalls = 0;
        $whmcs = new WhmcsGateway(
            $config,
            static function (string $command, array $parameters): array {
                if ($command === 'GetInvoice') {
                    self::assertSame(10, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-07-01',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '100.00',
                        'tax' => '19.00',
                        'tax2' => '0.00',
                        'total' => '99.00',
                        'credit' => '20.00',
                        'taxrate' => '19',
                        'taxrate2' => '0',
                        'items' => ['item' => [[
                            'id' => 1,
                            'type' => 'Hosting',
                            'relid' => 42,
                            'description' => 'Synthetic hosting item',
                            'amount' => '100.00',
                            'taxed' => true,
                        ]]],
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
                            'customfields' => ['customfield' => [[
                                'id' => 123,
                                'value' => '42',
                            ]]],
                        ],
                    ];
                }

                self::fail('Unexpected WHMCS local API command: ' . $command);
            },
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static function () use (&$pdfRenderCalls): string {
                ++$pdfRenderCalls;

                return "%PDF-1.7\nsynthetic invoice document";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            paymentStructure: new WhmcsPaymentStructureService(),
        );
        $checkpoints = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'requestedExportMode' => 'voucher_only',
                    'requestedDocumentAuthority' => 'whmcs',
                    'requestedOssProfile' => 'blocked',
                    'requestedEuB2cMode' => 'blocked',
                    'requestedEInvoiceMode' => 'off',
                    'credit_treatment' => 'full_gross_voucher',
                ], JSON_THROW_ON_ERROR),
            ],
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
        );

        self::assertSame(
            'retry_wait',
            $outcome->status,
            (string) $outcome->errorCode . ': ' . $outcome->message,
        );
        self::assertSame('contact_verification_failed', $outcome->errorCode);
        self::assertSame(1, $pdfRenderCalls);
        self::assertContains('document_type_selected', $checkpoints);
        self::assertContains('preflight_complete', $checkpoints);
        self::assertContains('pdf_validated', $checkpoints);
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
    }

    public function testFullGrossVoucherConfirmationCannotBypassAMalformedMassPaymentContainer(): void
    {
        $this->resetPaymentStructureTables();
        Capsule::table('tblinvoices')->insert([
            'id' => 100,
            'userid' => 20,
            'status' => 'Paid',
            'subtotal' => '25.00',
            'credit' => '0.00',
            'tax' => '0.00',
            'tax2' => '0.00',
            'total' => '25.00',
        ]);
        Capsule::table('tblinvoiceitems')->insert([
            ['invoiceid' => 100, 'type' => 'Invoice', 'relid' => 10, 'amount' => '20.00'],
            ['invoiceid' => 100, 'type' => 'Hosting', 'relid' => 42, 'amount' => '5.00'],
        ]);
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => 100,
            'amountin' => '25.00',
            'amountout' => '0.00',
            'refundid' => 0,
        ]);

        $history = [];
        $client = $this->client([], $history);
        $pdfRenderCalls = 0;
        $config = new Config();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway(
                $config,
                static function (string $command, array $parameters): array {
                    self::assertSame('GetInvoice', $command);
                    self::assertSame(100, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-07-01',
                        'invoicenum' => 'MASS-100',
                        'currencycode' => 'EUR',
                        'subtotal' => '25.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '25.00',
                        'credit' => '0.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [
                            [
                                'id' => 1,
                                'type' => 'Invoice',
                                'relid' => 10,
                                'description' => 'Synthetic invoice reference',
                                'amount' => '20.00',
                                'taxed' => 0,
                            ],
                            [
                                'id' => 2,
                                'type' => 'Hosting',
                                'relid' => 42,
                                'description' => 'Unexpected revenue item',
                                'amount' => '5.00',
                                'taxed' => 0,
                            ],
                        ]],
                    ];
                },
            ),
            new MappingRepository(),
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static function () use (&$pdfRenderCalls): string {
                ++$pdfRenderCalls;

                return "%PDF-1.7\nnot expected";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, new MappingRepository()),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'A malformed mass-payment container must stop before tax classification.',
            ),
            paymentStructure: new WhmcsPaymentStructureService(),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 100,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'requestedExportMode' => 'voucher_only',
                    'requestedDocumentAuthority' => 'whmcs',
                    'requestedOssProfile' => 'blocked',
                    'requestedEuB2cMode' => 'blocked',
                    'requestedEInvoiceMode' => 'off',
                    'credit_treatment' => 'full_gross_voucher',
                ], JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('mixed_invoice_reference_items', $outcome->errorCode);
        self::assertSame(0, $pdfRenderCalls);
        self::assertSame([], $history);
    }

    public function testUnsupportedDiscountTaxRuleStopsBeforeAnySevdeskContactWrite(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('export_mode', 'invoice_only');
        $config->set('document_authority', 'whmcs');
        $config->set('oss_profile', 'blocked');
        $config->set('eu_b2c_mode', 'blocked');
        $config->set('invoice_canary_confirmed', true);
        $config->set('small_business_invoice_canary_confirmed', true);
        $config->set('invoice_discount_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('taxRuleGeneral', '1');
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway(
            $config,
            static function (string $command, array $parameters): array {
                if ($command === 'GetInvoice') {
                    self::assertSame(10, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2026-07-01',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '80.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '80.00',
                        'credit' => '0.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [
                            [
                                'id' => 1,
                                'type' => 'Hosting',
                                'relid' => 42,
                                'description' => 'Synthetic hosting item',
                                'amount' => '100.00',
                                'taxed' => 0,
                            ],
                            [
                                'id' => 2,
                                'type' => 'PromoHosting',
                                'relid' => 42,
                                'description' => 'Synthetic promotion',
                                'amount' => '-20.00',
                                'taxed' => 0,
                            ],
                        ]],
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
            },
        );
        $contactCheckpointCalls = 0;
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService(
                $client,
                static function () use (&$contactCheckpointCalls): bool {
                    ++$contactCheckpointCalls;

                    return true;
                },
                static fn (): string => '1',
            ),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
        );
        $checkpoints = [];
        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'requestedExportMode' => 'invoice_only',
                    'requestedDocumentAuthority' => 'whmcs',
                    'requestedOssProfile' => 'blocked',
                    'requestedEuB2cMode' => 'blocked',
                    'requestedEInvoiceMode' => 'off',
                ], JSON_THROW_ON_ERROR),
            ],
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('invoice_discount_tax_rule_not_supported', $outcome->errorCode);
        self::assertSame(0, $contactCheckpointCalls);
        self::assertNotContains('contact_write_requested', $checkpoints);
        self::assertSame([], $history);
    }

    public function testExactMassPaymentAllowsOneConfirmedPromoDiscountAndCredit(): void
    {
        foreach (['tblaccounts', 'tblinvoiceitems', 'tblinvoices'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::schema()->create('tblinvoices', static function ($table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('userid');
            $table->string('status');
            $table->decimal('subtotal', 18, 4);
            $table->decimal('credit', 18, 4);
            $table->decimal('tax', 18, 4);
            $table->decimal('tax2', 18, 4);
            $table->decimal('total', 18, 4);
        });
        Capsule::schema()->create('tblinvoiceitems', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->string('type');
            $table->unsignedInteger('relid')->nullable();
            $table->decimal('amount', 18, 4);
        });
        Capsule::schema()->create('tblaccounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->decimal('amountin', 18, 4)->default(0);
            $table->decimal('amountout', 18, 4)->default(0);
            $table->unsignedInteger('refundid')->default(0);
        });
        Capsule::table('tblinvoices')->insert([
            [
                'id' => 100,
                'userid' => 20,
                'status' => 'Paid',
                'subtotal' => '20.00',
                'credit' => '0.00',
                'tax' => '0.00',
                'tax2' => '0.00',
                'total' => '20.00',
            ],
            [
                'id' => 10,
                'userid' => 20,
                'status' => 'Paid',
                'subtotal' => '80.00',
                'credit' => '20.00',
                'tax' => '0.00',
                'tax2' => '0.00',
                'total' => '60.00',
            ],
        ]);
        Capsule::table('tblinvoiceitems')->insert([
            ['invoiceid' => 100, 'type' => 'Invoice', 'relid' => 10, 'amount' => '20.00'],
            ['invoiceid' => 10, 'type' => 'Hosting', 'relid' => 42, 'amount' => '100.00'],
            ['invoiceid' => 10, 'type' => 'PromoHosting', 'relid' => 42, 'amount' => '-20.00'],
        ]);
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => 100,
            'amountin' => '20.00',
            'amountout' => '0.00',
            'refundid' => 0,
        ]);
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => 10,
            'amountin' => '60.00',
            'amountout' => '0.00',
            'refundid' => 0,
        ]);

        $expectedInvoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new \DateTimeImmutable('2025-12-31'),
            'EUR',
            '80.00',
            '20.00',
            [new LineItem('Synthetic hosting item', '100.00', '0', true)],
            [new InvoiceDiscount('Synthetic promotion', '20.00', '0', true, 42)],
        );
        $remoteInvoice = static function (int $status) use ($expectedInvoice): Response {
            return new Response(200, [], json_encode(['objects' => [[
                'id' => '99',
                'objectName' => 'Invoice',
                'invoiceType' => 'RE',
                'invoiceNumber' => 'RE-10',
                'invoiceDate' => '31.12.2025',
                'currency' => 'EUR',
                'status' => (string) $status,
                'taxRule' => ['id' => '11', 'objectName' => 'TaxRule'],
                'contact' => ['id' => '42', 'objectName' => 'Contact'],
                'contactPerson' => ['id' => '7', 'objectName' => 'SevUser'],
                'showNet' => true,
                'deliveryAddressCountry' => 'DE',
                'addressName' => 'Synthetic Customer',
                'addressStreet' => 'Example Street 1',
                'addressZip' => '12345',
                'addressCity' => 'Example City',
                'addressCountry' => ['id' => '1', 'objectName' => 'StaticCountry', 'code' => 'DE'],
                'customerInternalNote' => InvoiceExporter::documentMarker($expectedInvoice),
                'sumGross' => '80.00',
                'sumDiscounts' => '20.00',
            ]]], JSON_THROW_ON_ERROR));
        };
        $remotePositions = static fn (): Response => new Response(200, [], json_encode([
            'objects' => [[
                'id' => '901',
                'objectName' => 'InvoicePos',
                'invoice' => ['id' => '99', 'objectName' => 'Invoice'],
                'unity' => ['id' => '8', 'objectName' => 'Unity'],
                'positionNumber' => '1',
                'quantity' => '1',
                'name' => 'Synthetic hosting item',
                'text' => 'Synthetic hosting item',
                'price' => '100.00',
                'taxRate' => '0',
            ]],
        ], JSON_THROW_ON_ERROR));
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact","customerNumber":"20"}]}'),
            new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
            $remoteInvoice(100),
            $remotePositions(),
            $remoteInvoice(100),
            $remotePositions(),
            new Response(200, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
            $remoteInvoice(200),
            $remotePositions(),
        ], $history);
        $config = new Config();
        $config->set('export_mode', 'invoice_only');
        $config->set('document_authority', 'whmcs');
        $config->set('oss_profile', 'blocked');
        $config->set('eu_b2c_mode', 'blocked');
        $config->set('invoice_canary_confirmed', true);
        $config->set('small_business_invoice_canary_confirmed', true);
        $config->set('invoice_discount_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_until', '31-12-2025');
        $config->set('small_business_confirmed', true);
        $config->set('taxRuleSmallBusinessOwner', '11');
        $mappings = new MappingRepository();
        $invoiceExporter = new InvoiceExporter(
            $client,
            static fn (int $invoiceId): ?string => isset($mappings->findCompleteByInvoice($invoiceId)->sevdesk_id)
                ? (string) $mappings->findCompleteByInvoice($invoiceId)->sevdesk_id
                : null,
            static function (
                int $invoiceId,
                string $remoteId,
                string $type,
                string $number,
            ) use (
                $mappings,
            ): void {
                $mappings->linkDocument($invoiceId, $remoteId, $type, $number);
            },
            '7',
            '8',
            resolveCountryId: static fn (): string => '1',
            discountsConfirmed: true,
        );
        $configuredContactId = null;
        $whmcs = new WhmcsGateway(
            $config,
            static function (
                string $command,
                array $parameters,
            ) use (
                &$configuredContactId,
            ): array {
                if ($command === 'GetInvoice') {
                    self::assertSame(10, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-12-31',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '80.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '60.00',
                        'credit' => '20.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [
                            [
                                'id' => 1,
                                'type' => 'Hosting',
                                'relid' => 42,
                                'description' => 'Synthetic hosting item',
                                'amount' => '100.00',
                                'taxed' => 0,
                            ],
                            [
                                'id' => 2,
                                'type' => 'PromoHosting',
                                'relid' => 42,
                                'description' => 'Synthetic promotion',
                                'amount' => '-20.00',
                                'taxed' => 0,
                            ],
                        ]],
                    ];
                }
                if ($command === 'GetClientsDetails') {
                    return [
                        'result' => 'success',
                        'client' => [
                            'id' => 20,
                            'currency_code' => 'EUR',
                            'companyname' => '',
                            'firstname' => 'Synthetic',
                            'lastname' => 'Customer',
                            'email' => 'synthetic@example.invalid',
                            'address1' => 'Example Street 1',
                            'postcode' => '12345',
                            'city' => 'Example City',
                            'countrycode' => 'DE',
                            'taxexempt' => false,
                            'customfields' => ['customfield' => $configuredContactId === null ? [] : [[
                                'id' => 123,
                                'value' => $configuredContactId,
                            ]]],
                        ],
                    ];
                }

                self::fail('Unexpected WHMCS local API command: ' . $command);
            },
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService(
                $client,
                static function (int $_clientId, string $contactId) use (&$configuredContactId): bool {
                    $configuredContactId = $contactId;

                    return true;
                },
                static fn (): string => '1',
            ),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            $invoiceExporter,
            new InvoiceReconciliationService(
                $client,
                static fn (int $invoiceId): ?string =>
                    isset($mappings->findCompleteByInvoice($invoiceId)->sevdesk_id)
                        ? (string) $mappings->findCompleteByInvoice($invoiceId)->sevdesk_id
                        : null,
                static function (
                    int $invoiceId,
                    string $remoteId,
                    string $type,
                    string $number,
                ) use (
                    $mappings,
                ): void {
                    $mappings->linkDocument($invoiceId, $remoteId, $type, $number);
                },
                '7',
                '8',
            ),
            new InvoicePdf($client),
            paymentStructure: new WhmcsPaymentStructureService(),
        );
        $checkpoints = [];
        $checkpointContexts = [];
        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'requestedExportMode' => 'invoice_only',
                    'requestedDocumentAuthority' => 'whmcs',
                    'requestedOssProfile' => 'blocked',
                    'requestedEuB2cMode' => 'blocked',
                    'requestedEInvoiceMode' => 'off',
                ], JSON_THROW_ON_ERROR),
            ],
            static function (
                string $checkpoint,
                array $context = [],
            ) use (
                &$checkpoints,
                &$checkpointContexts,
            ): bool {
                $checkpoints[] = $checkpoint;
                $checkpointContexts[$checkpoint] = $context;

                return true;
            },
        );

        self::assertSame('succeeded', $outcome->status, (string) $outcome->errorCode);
        self::assertSame('99', $outcome->sevdeskId);
        self::assertSame(
            ['GET', 'POST', 'GET', 'GET', 'GET', 'GET', 'PUT', 'GET', 'GET'],
            array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history),
        );
        self::assertContains('document_type_selected', $checkpoints);
        self::assertContains('invoice_write_requested', $checkpoints);
        self::assertContains('mapping_persisted', $checkpoints);
        self::assertContains('invoice_opened', $checkpoints);
        self::assertSame(
            '11',
            $checkpointContexts['document_type_selected']['targetTaxRuleId'] ?? null,
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) ($checkpointContexts['document_type_selected']['massPaymentFingerprint'] ?? ''),
        );
        self::assertSame(
            $expectedInvoice->discountFingerprint(),
            $checkpointContexts['invoice_write_requested']['invoiceDiscountFingerprint'] ?? null,
        );
        $payload = json_decode(
            (string) $history[1]['request']->getBody(),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(11, $payload['invoice']['taxRule']['id'] ?? null);
        self::assertSame(100.0, $payload['invoicePosSave'][0]['price'] ?? null);
        self::assertSame(20.0, $payload['discountSave'][0]['value'] ?? null);
        self::assertSame(
            InvoiceExporter::documentMarker($expectedInvoice),
            $payload['invoice']['customerInternalNote'] ?? null,
        );
        $mapping = $mappings->findCompleteByInvoice(10);
        self::assertNotNull($mapping);
        self::assertSame(MappingRepository::DOCUMENT_TYPE_INVOICE, $mapping->document_type);
        self::assertSame('RE-10', $mapping->document_number);
        self::assertNotNull($mapping->document_ready_at);
    }

    public function testExactMassPaymentGetsAFreshPreWriteCheckBeforeSelectingTheDocumentTarget(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('none', false);
        $outcome = $result['outcome'];

        self::assertSame('retry_wait', $outcome->status);
        self::assertSame('contact_verification_failed', $outcome->errorCode);
        self::assertSame(3, $result['getInvoiceCalls']);
        self::assertContains('document_type_selected', $result['checkpoints']);
        self::assertContains('preflight_complete', $result['checkpoints']);
        self::assertSame(1, $result['pdfRenderCalls']);
        self::assertCount(1, $result['history']);
        self::assertSame('GET', $result['history'][0]['request']->getMethod());
    }

    public function testHookQueuedMassPaymentTargetCannotSwitchToAnotherParent(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('none', false, 101);
        $outcome = $result['outcome'];

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('mass_payment_structure_changed', $outcome->errorCode);
        self::assertSame(1, $result['getInvoiceCalls']);
        self::assertSame([], $result['checkpoints']);
        self::assertSame(0, $result['pdfRenderCalls']);
        self::assertSame([], $result['history']);
    }

    public function testHookQueuedMassPaymentTargetAcceptsItsConfirmedParent(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('none', false, 100);

        self::assertSame('retry_wait', $result['outcome']->status);
        self::assertSame('contact_verification_failed', $result['outcome']->errorCode);
        self::assertContains('document_type_selected', $result['checkpoints']);
        self::assertSame(1, $result['pdfRenderCalls']);
    }

    public function testHookQueuedMassPaymentTargetCannotBecomeOrdinaryBeforeWorker(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation(
            'ordinary_before_worker',
            false,
            100,
        );

        self::assertSame('permanent_failed', $result['outcome']->status);
        self::assertSame('mass_payment_structure_changed', $result['outcome']->errorCode);
        self::assertSame(1, $result['getInvoiceCalls']);
        self::assertSame([], $result['checkpoints']);
        self::assertSame(0, $result['pdfRenderCalls']);
        self::assertSame([], $result['history']);
    }

    public function testConflictingPaidHookParentsBlockBeforeRemoteIo(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('none', false, 100, true);

        self::assertSame('permanent_failed', $result['outcome']->status);
        self::assertSame('mass_payment_structure_changed', $result['outcome']->errorCode);
        self::assertSame(1, $result['getInvoiceCalls']);
        self::assertSame([], $result['checkpoints']);
        self::assertSame(0, $result['pdfRenderCalls']);
        self::assertSame([], $result['history']);
    }

    public function testMassPaymentDatabaseDriftAfterTheInvoiceSnapshotStopsBeforeRemoteIo(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('database', false);
        $outcome = $result['outcome'];

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('mass_payment_structure_changed', $outcome->errorCode);
        self::assertSame(2, $result['getInvoiceCalls']);
        self::assertSame([], $result['checkpoints']);
        self::assertSame(0, $result['pdfRenderCalls']);
        self::assertSame([], $result['history']);
    }

    public function testMassPaymentSnapshotDriftStopsEvenWhenTheDatabaseFingerprintIsStable(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('snapshot', false);
        $outcome = $result['outcome'];

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('whmcs_invoice_contract_changed', $outcome->errorCode);
        self::assertSame([], $result['checkpoints']);
        self::assertSame(0, $result['pdfRenderCalls']);
        self::assertSame([], $result['history']);
    }

    public function testMassPaymentDriftDuringPdfAndContactIoStopsBeforeDocumentPost(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('after_io', false);
        $outcome = $result['outcome'];

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('pre_write_guard_failed', $outcome->errorCode);
        self::assertSame(4, $result['getInvoiceCalls']);
        self::assertSame(1, $result['pdfRenderCalls']);
        self::assertContains('pdf_uploaded', $result['checkpoints']);
        self::assertNotContains('voucher_write_requested', $result['checkpoints']);
        self::assertCount(2, $result['history']);
        self::assertSame(
            ['GET', 'POST'],
            array_map(
                static fn (array $entry): string => $entry['request']->getMethod(),
                $result['history'],
            ),
        );
        self::assertStringNotContainsString(
            '/Voucher/Factory/saveVoucher',
            (string) $result['history'][1]['request']->getUri(),
        );
    }

    public function testMassPaymentDriftAfterPossibleWriteIsAmbiguousWithoutAnotherWrite(): void
    {
        $result = $this->runMassPaymentPreWriteRevalidation('database', true);
        $outcome = $result['outcome'];

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('mass_payment_structure_changed_after_write', $outcome->errorCode);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('99', $outcome->sevdeskId);
        self::assertSame([], $result['checkpoints']);
        self::assertSame(0, $result['pdfRenderCalls']);
        self::assertSame([], $result['history']);
    }

    #[DataProvider('invoiceContractDriftProvider')]
    public function testInvoiceContractDriftDuringRemoteIoStopsBeforeDocumentPost(string $drift): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact"}]}'),
        ], $history);
        $config = new Config();
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('invoice_canary_confirmed', true);
        $config->set('small_business_invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_until', '31-12-2025');
        $config->set('small_business_confirmed', true);
        $config->set('taxRuleSmallBusinessOwner', '11');
        $getInvoiceCalls = 0;
        $getClientCalls = 0;
        $whmcs = new WhmcsGateway(
            $config,
            static function (
                string $command,
                array $parameters,
            ) use (
                $drift,
                &$getInvoiceCalls,
                &$getClientCalls,
            ): array {
                if ($command === 'GetInvoice') {
                    self::assertSame(10, $parameters['invoiceid']);
                    ++$getInvoiceCalls;
                    $invoice = [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-12-31',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '100.00',
                        'credit' => '0.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '100.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [[
                            'id' => 1,
                            'type' => 'Hosting',
                            'relid' => 42,
                            'description' => 'Synthetic hosting item',
                            'amount' => '100.00',
                            'taxed' => false,
                        ]]],
                    ];
                    if ($getInvoiceCalls === 4) {
                        if ($drift === 'date') {
                            $invoice['date'] = '2026-01-01';
                        } elseif ($drift === 'number') {
                            $invoice['invoicenum'] = 'RE-10-CHANGED';
                        } elseif ($drift === 'taxrate') {
                            $invoice['taxrate'] = '19';
                        } elseif ($drift === 'taxed') {
                            $invoice['items']['item'][0]['taxed'] = true;
                        } elseif ($drift === 'description') {
                            $invoice['items']['item'][0]['description'] = 'Changed hosting item';
                        }
                    }

                    return $invoice;
                }
                if ($command === 'GetClientsDetails') {
                    self::assertSame(20, $parameters['clientid']);
                    ++$getClientCalls;
                    $client = [
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
                        'customfields' => ['customfield' => [[
                            'id' => 123,
                            'value' => '42',
                        ]]],
                    ];
                    if ($getClientCalls === 3 && $drift === 'country') {
                        $client['countrycode'] = 'AT';
                    }
                    if ($getClientCalls === 3 && $drift === 'taxexempt') {
                        $client['taxexempt'] = true;
                    }
                    if ($getClientCalls === 3 && $drift === 'contact_link') {
                        $client['customfields']['customfield'][0]['value'] = '84';
                    }

                    return ['result' => 'success', 'client' => $client];
                }

                self::fail('Unexpected WHMCS local API command: ' . $command);
            },
        );
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
                static fn (): string => '1',
            ),
            new InvoiceReconciliationService(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
            ),
            new InvoicePdf($client),
        );
        $checkpoints = [];
        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'requestedExportMode' => 'invoice_only',
                    'requestedDocumentAuthority' => 'whmcs',
                    'requestedOssProfile' => 'blocked',
                    'requestedEuB2cMode' => 'blocked',
                    'requestedEInvoiceMode' => 'off',
                ], JSON_THROW_ON_ERROR),
            ],
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('pre_write_guard_failed', $outcome->errorCode);
        self::assertNotContains('invoice_write_requested', $checkpoints);
        self::assertSame(4, $getInvoiceCalls);
        self::assertSame(3, $getClientCalls);
        self::assertNotEmpty($history);
        self::assertSame(
            [],
            array_values(array_filter(
                $history,
                static fn (array $entry): bool => $entry['request']->getMethod() === 'POST',
            )),
            'No sevdesk POST is allowed after the local contract changed.',
        );
    }

    /** @return iterable<string,array{string}> */
    public static function invoiceContractDriftProvider(): iterable
    {
        yield 'small-business cutoff date' => ['date'];
        yield 'effective invoice number' => ['number'];
        yield 'invoice tax rate' => ['taxrate'];
        yield 'item taxed flag' => ['taxed'];
        yield 'payload description' => ['description'];
        yield 'contact country' => ['country'];
        yield 'contact tax exemption' => ['taxexempt'];
        yield 'configured sevdesk contact link' => ['contact_link'];
    }

    public function testChangedDiscountStructureAfterInvoiceWriteBecomesAmbiguousWithoutRemoteIo(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('invoice_discount_canary_confirmed', true);
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway(
                $config,
                static function (string $command, array $parameters): array {
                    if ($command === 'GetClientsDetails') {
                        self::assertSame(20, $parameters['clientid']);

                        return [
                            'result' => 'success',
                            'client' => ['id' => 20, 'currency_code' => 'EUR'],
                        ];
                    }
                    self::assertSame('GetInvoice', $command);
                    self::assertSame(10, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-07-01',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '80.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '80.00',
                        'credit' => '0.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [
                            [
                                'id' => 1,
                                'type' => 'Hosting',
                                'relid' => 42,
                                'description' => 'Synthetic hosting item',
                                'amount' => '100.00',
                                'taxed' => 0,
                            ],
                            [
                                'id' => 2,
                                'type' => 'PromoHosting',
                                'relid' => 43,
                                'description' => 'Changed synthetic promotion',
                                'amount' => '-20.00',
                                'taxed' => 0,
                            ],
                        ]],
                    ];
                },
            ),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
        );
        $candidate = [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetTaxRuleId' => '11',
            'targetCode' => 'invoice_selected',
            'targetMessage' => 'Synthetic frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
        ];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'invoice_write_requested',
                'attempts' => 1,
                'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('invoice_discount_changed_after_write', $outcome->errorCode);
        self::assertSame('promohosting_pair_not_found', $outcome->candidate['detectedDiscountError'] ?? null);
        self::assertSame([], $history);
    }

    public function testChangedDiscountTextAfterInvoiceWriteCannotReuseTheFrozenDiscountMarker(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('invoice_discount_canary_confirmed', true);
        $originalInvoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new \DateTimeImmutable('2025-07-01'),
            'EUR',
            '80.00',
            '0',
            [new LineItem('Synthetic hosting item', '100.00', '0', false)],
            [new InvoiceDiscount('Original promotion', '20.00', '0', false, 42)],
        );
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway(
                $config,
                static function (string $command, array $parameters): array {
                    if ($command === 'GetClientsDetails') {
                        self::assertSame(20, $parameters['clientid']);

                        return [
                            'result' => 'success',
                            'client' => ['id' => 20, 'currency_code' => 'EUR'],
                        ];
                    }
                    self::assertSame('GetInvoice', $command);
                    self::assertSame(10, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-07-01',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '80.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '80.00',
                        'credit' => '0.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [
                            [
                                'id' => 1,
                                'type' => 'Hosting',
                                'relid' => 42,
                                'description' => 'Synthetic hosting item',
                                'amount' => '100.00',
                                'taxed' => 0,
                            ],
                            [
                                'id' => 2,
                                'type' => 'PromoHosting',
                                'relid' => 42,
                                'description' => 'Changed promotion',
                                'amount' => '-20.00',
                                'taxed' => 0,
                            ],
                        ]],
                    ];
                },
            ),
            new MappingRepository(),
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, new MappingRepository()),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
        );
        $candidate = [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetTaxRuleId' => '11',
            'targetCode' => 'invoice_selected',
            'targetMessage' => 'Synthetic frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'invoiceDiscountCount' => 1,
            'invoiceDiscountFingerprint' => $originalInvoice->discountFingerprint(),
        ];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'invoice_write_requested',
                'attempts' => 1,
                'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('invoice_discount_changed_after_write', $outcome->errorCode);
        self::assertSame([], $history);
    }

    public function testUnknownContactWriteWithoutFrozenInvoiceContractStopsBeforeRecoveryIo(): void
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
                    $response['total'] = '119.00';
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
        self::assertSame('whmcs_invoice_contract_snapshot_missing_after_write', $outcome->errorCode);
        self::assertSame(0, $pdfRenderCalls);
        self::assertSame(0, $persistContactCalls);
        self::assertSame([], $history, 'A risky legacy job without a frozen contract may only be reviewed.');
    }

    #[DataProvider('effectiveInvoiceNumberProvider')]
    public function testInvoiceOnlyJobUsesTheEffectiveNumberWithoutRenderingWhmcsPdf(
        string $storedInvoiceNumber,
        string $effectiveInvoiceNumber,
        string $invoiceDate,
        bool $smallBusinessCutoffConfigured,
    ): void {
        $remoteInvoiceDate = (new \DateTimeImmutable($invoiceDate))->format('d.m.Y');
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact"}]}'),
            new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
            $this->invoiceResponse(100, $effectiveInvoiceNumber, $remoteInvoiceDate),
            $this->invoicePositionResponse(),
            $this->invoiceResponse(100, $effectiveInvoiceNumber, $remoteInvoiceDate),
            $this->invoicePositionResponse(),
            new Response(200, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
            $this->invoiceResponse(200, $effectiveInvoiceNumber, $remoteInvoiceDate),
            $this->invoicePositionResponse(),
        ], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        if ($smallBusinessCutoffConfigured) {
            $config->set('smallBusinessOwner', true);
            $config->set('small_business_until', '31-12-2025');
            $config->set('small_business_confirmed', true);
            $config->set('taxRuleSmallBusinessOwner', '11');
        }
        $mappings = new MappingRepository();
        $pdfRenderCalls = 0;
        $whmcs = new WhmcsGateway($config, function (
            string $command,
            array $parameters,
        ) use (
            $storedInvoiceNumber,
            $invoiceDate,
        ): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
                $response['total'] = '119.00';
                $response['invoicenum'] = $storedInvoiceNumber;
                $response['date'] = $invoiceDate;
            }
            if ($command === 'GetClientsDetails') {
                $response['client']['customfields'] = ['customfield' => [[
                    'id' => 123,
                    'value' => '42',
                ]]];
            }

            return $response;
        });
        $invoiceExporter = new InvoiceExporter(
            $client,
            static fn (int $invoiceId): ?string => isset($mappings->findCompleteByInvoice($invoiceId)->sevdesk_id)
                ? (string) $mappings->findCompleteByInvoice($invoiceId)->sevdesk_id
                : null,
            static function (int $invoiceId, string $remoteId, string $type, string $number) use ($mappings): void {
                $mappings->linkDocument($invoiceId, $remoteId, $type, $number);
            },
            '7',
            '8',
            static fn (): string => '1',
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static function () use (&$pdfRenderCalls): string {
                ++$pdfRenderCalls;

                return "%PDF-1.7\nnot expected";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'invoice_only must not resolve Voucher Receipt Guidance.',
            ),
            $invoiceExporter,
            new InvoiceReconciliationService(
                $client,
                static fn (int $invoiceId): ?string => isset($mappings->findCompleteByInvoice($invoiceId)->sevdesk_id)
                    ? (string) $mappings->findCompleteByInvoice($invoiceId)->sevdesk_id
                    : null,
                static function (int $invoiceId, string $remoteId, string $type, string $number) use ($mappings): void {
                    $mappings->linkDocument($invoiceId, $remoteId, $type, $number);
                },
                '7',
                '8',
            ),
            new InvoicePdf($client),
            static fn (): DocumentTargetResolver => new DocumentTargetResolver(
                DocumentTargetResolver::MODE_INVOICE_ONLY,
                DocumentTargetResolver::AUTHORITY_WHMCS,
                DocumentTargetResolver::OSS_BLOCKED,
            ),
        );
        $checkpoints = [];
        $checkpointContexts = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'delivery_requested' => false,
                    'requestedExportMode' => DocumentTargetResolver::MODE_INVOICE_ONLY,
                    'requestedDocumentAuthority' => DocumentTargetResolver::AUTHORITY_WHMCS,
                    'requestedOssProfile' => DocumentTargetResolver::OSS_BLOCKED,
                    'requestedEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
                    'requestedDeliveryChannel' => null,
                ], JSON_THROW_ON_ERROR),
            ],
            static function (
                string $checkpoint,
                array $context = [],
            ) use (
                &$checkpoints,
                &$checkpointContexts,
            ): bool {
                $checkpoints[] = $checkpoint;
                $checkpointContexts[$checkpoint] = $context;

                return true;
            },
        );

        self::assertSame('succeeded', $outcome->status);
        self::assertSame('99', $outcome->sevdeskId);
        self::assertSame(0, $pdfRenderCalls);
        self::assertSame(
            ['GET', 'POST', 'GET', 'GET', 'GET', 'GET', 'PUT', 'GET', 'GET'],
            array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history),
        );
        self::assertContains('document_type_selected', $checkpoints);
        self::assertContains('invoice_created', $checkpoints);
        self::assertContains('mapping_persisted', $checkpoints);
        self::assertContains('invoice_open_write_requested', $checkpoints);
        self::assertSame(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            $checkpointContexts['document_type_selected']['targetExportMode'] ?? null,
        );
        self::assertSame(
            TaxPolicy::EU_B2C_BLOCKED,
            $checkpointContexts['document_type_selected']['targetEuB2cMode'] ?? null,
        );
        self::assertSame('1', $checkpointContexts['document_type_selected']['targetTaxRuleId'] ?? null);
        self::assertSame('7', $checkpointContexts['document_type_selected']['targetSevUserId'] ?? null);
        self::assertSame('8', $checkpointContexts['document_type_selected']['targetUnityId'] ?? null);
        self::assertSame(
            $effectiveInvoiceNumber,
            $checkpointContexts['document_type_selected']['selectedInvoiceNumber'] ?? null,
        );
        self::assertContains('invoice_write_requested', $checkpoints);
        self::assertContains('invoice_opened', $checkpoints);
        $createPayload = json_decode(
            (string) $history[1]['request']->getBody(),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($effectiveInvoiceNumber, $createPayload['invoice']['invoiceNumber'] ?? null);
        self::assertSame($remoteInvoiceDate, $createPayload['invoice']['invoiceDate'] ?? null);
        self::assertSame(1, $createPayload['invoice']['taxRule']['id'] ?? null);
        self::assertFalse($createPayload['invoice']['smallSettlement'] ?? true);
        self::assertFalse($createPayload['takeDefaultAddress'] ?? true);
        self::assertSame('Synthetic Company', $createPayload['invoice']['addressName'] ?? null);
        self::assertSame('Example Street 1', $createPayload['invoice']['addressStreet'] ?? null);
        self::assertSame('12345', $createPayload['invoice']['addressZip'] ?? null);
        self::assertSame('Example City', $createPayload['invoice']['addressCity'] ?? null);
        self::assertSame(
            ['id' => 1, 'objectName' => 'StaticCountry'],
            $createPayload['invoice']['addressCountry'] ?? null,
        );
        self::assertSame(19.0, $createPayload['invoicePosSave'][0]['taxRate'] ?? null);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) ($checkpointContexts['invoice_write_requested']['invoiceAddressHash'] ?? ''),
        );
        self::assertSame(
            '1',
            $checkpointContexts['invoice_write_requested']['invoiceAddressCountryId'] ?? null,
        );
        self::assertSame('/api/v1/Invoice/99/sendBy', $history[6]['request']->getUri()->getPath());
        $mapping = $mappings->findCompleteByInvoice(10);
        self::assertSame('invoice', $mapping?->document_type);
        self::assertSame($effectiveInvoiceNumber, $mapping->document_number);
        self::assertNotNull($mapping->document_ready_at);
    }

    public function testAddFundsUsesRuleElevenInsideTheSmallBusinessPeriod(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('invoice_canary_confirmed', true);
        $config->set('small_business_invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_until', '31-12-2025');
        $config->set('small_business_confirmed', true);
        $config->set('accountingTypeSmallBusinessOwner', '500');
        $config->set('taxRuleSmallBusinessOwner', '11');
        $config->set('add_funds_confirmed', true);
        $config->set('accountingTypeCredit', '600');
        $config->set('taxRuleCredit', '1');
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway(
            $config,
            static function (string $command, array $parameters): array {
                if ($command === 'GetInvoice') {
                    self::assertSame(10, $parameters['invoiceid']);

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2025-12-31',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '100.00',
                        'tax' => '0.00',
                        'tax2' => '0.00',
                        'total' => '100.00',
                        'credit' => '0.00',
                        'taxrate' => '0',
                        'taxrate2' => '0',
                        'items' => ['item' => [[
                            'id' => 1,
                            'type' => 'AddFunds',
                            'relid' => 0,
                            'description' => 'Synthetic account credit',
                            'amount' => '100.00',
                            'taxed' => false,
                        ]]],
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
                            'customfields' => ['customfield' => [[
                                'id' => 123,
                                'value' => '42',
                            ]]],
                        ],
                    ];
                }

                self::fail('Unexpected WHMCS local API command: ' . $command);
            },
        );
        $invoiceExporter = new InvoiceExporter(
            $client,
            static fn (): null => null,
            static fn (): bool => true,
            '7',
            '8',
            static fn (): string => '1',
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => new TaxPolicy(
                [
                    'small_business' => [
                        'accountDatev' => '500',
                        'taxRule' => '11',
                        'confirmed' => true,
                    ],
                ],
                TaxPolicy::EU_B2C_BLOCKED,
                [[
                    'accountDatevId' => 500,
                    'allowedReceiptTypes' => ['REVENUE'],
                    'allowedTaxRules' => [['id' => 11, 'taxRates' => ['ZERO']]],
                ]],
                TaxPolicy::OSS_BLOCKED,
                true,
            ),
            $invoiceExporter,
            new InvoiceReconciliationService(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
            ),
            new InvoicePdf($client),
            static fn (): DocumentTargetResolver => new DocumentTargetResolver(
                DocumentTargetResolver::MODE_INVOICE_ONLY,
                DocumentTargetResolver::AUTHORITY_WHMCS,
                DocumentTargetResolver::OSS_BLOCKED,
            ),
        );
        $selectedContext = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'delivery_requested' => false,
                    'requestedExportMode' => DocumentTargetResolver::MODE_INVOICE_ONLY,
                    'requestedDocumentAuthority' => DocumentTargetResolver::AUTHORITY_WHMCS,
                    'requestedOssProfile' => DocumentTargetResolver::OSS_BLOCKED,
                    'requestedEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
                    'requestedDeliveryChannel' => null,
                ], JSON_THROW_ON_ERROR),
            ],
            static function (string $checkpoint, array $context = []) use (&$selectedContext): bool {
                if ($checkpoint !== 'document_type_selected') {
                    return true;
                }
                $selectedContext = $context;

                return false;
            },
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('document_target_checkpoint_failed', $outcome->errorCode);
        self::assertSame('invoice', $selectedContext['targetDocumentType'] ?? null);
        self::assertSame('invoice_selected_global', $selectedContext['targetCode'] ?? null);
        self::assertSame('11', $selectedContext['targetTaxRuleId'] ?? null);
        self::assertSame([], $history, 'No sevdesk call may precede the frozen Rule-11 decision.');
    }

    /** @return iterable<string, array{string,string,string,bool}> */
    public static function effectiveInvoiceNumberProvider(): iterable
    {
        yield 'stored WHMCS number' => ['RE-10', 'RE-10', '2026-07-01', false];
        yield 'empty legacy number falls back to immutable ID' => ['', '10', '2026-07-01', false];
        yield 'day after small-business cutoff uses Rule 1 and 19 percent' => [
            'RE-10',
            'RE-10',
            '2026-01-01',
            true,
        ];
    }

    public function testSevdeskAuthorityMailFreeInvoiceCompletesThroughTheRealHandler(): void
    {
        $history = [];
        $pdfContents = "%PDF-1.7\nsynthetic sevdesk Invoice\n%%EOF";
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact"}]}'),
            new Response(201, [], '{"objects":{"invoice":{"id":"99"}}}'),
            $this->invoiceResponse(100),
            $this->invoicePositionResponse(),
            $this->invoiceResponse(100),
            $this->invoicePositionResponse(),
            new Response(200, [], '{"objects":{"id":"501","objectName":"InvoiceLog"}}'),
            $this->invoiceResponse(200),
            $this->invoicePositionResponse(),
            new Response(200, [], json_encode([
                'objects' => [
                    'mimeType' => 'application/pdf',
                    'base64encoded' => true,
                    'content' => base64_encode($pdfContents),
                    'filename' => 'RE-10.pdf',
                ],
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_SEVDESK);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('invoice_delivery_channel', 'sevdesk');
        $config->set('theme_adapter_confirmed', true);
        $GLOBALS['CONFIG']['EnableProformaInvoicing'] = true;
        $GLOBALS['CONFIG']['Template'] = 'twenty-one';
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
                $response['total'] = '119.00';
            }
            if ($command === 'GetClientsDetails') {
                $response['client']['customfields'] = ['customfield' => [[
                    'id' => 123,
                    'value' => '42',
                ]]];
            }

            return $response;
        });
        $persistMapping = static function (
            int $invoiceId,
            string $remoteId,
            string $type,
            string $number,
            bool $isEInvoice = false,
            ?string $xmlSha256 = null,
            string $documentAuthority = MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
        ) use ($mappings): void {
            $mappings->linkDocument(
                $invoiceId,
                $remoteId,
                $type,
                $number,
                $isEInvoice,
                $xmlSha256,
                $documentAuthority,
            );
        };
        $mappingId = static fn (int $invoiceId): ?string =>
            ($mapping = $mappings->findCompleteByInvoice($invoiceId)) === null
                ? null
                : (string) $mapping->sevdesk_id;
        $invoiceExporter = new InvoiceExporter(
            $client,
            $mappingId,
            $persistMapping,
            '7',
            '8',
            static fn (): string => '1',
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            $invoiceExporter,
            new InvoiceReconciliationService($client, $mappingId, $persistMapping, '7', '8'),
            new InvoicePdf($client),
            static fn (): DocumentTargetResolver => new DocumentTargetResolver(
                DocumentTargetResolver::MODE_INVOICE_ONLY,
                DocumentTargetResolver::AUTHORITY_SEVDESK,
                DocumentTargetResolver::OSS_BLOCKED,
            ),
        );
        $checkpoints = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'delivery_requested' => false,
                    'requestedExportMode' => DocumentTargetResolver::MODE_INVOICE_ONLY,
                    'requestedDocumentAuthority' => DocumentTargetResolver::AUTHORITY_SEVDESK,
                    'requestedOssProfile' => DocumentTargetResolver::OSS_BLOCKED,
                    'requestedEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
                    'requestedDeliveryChannel' => 'sevdesk',
                ], JSON_THROW_ON_ERROR),
            ],
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
        );

        self::assertSame('succeeded', $outcome->status);
        self::assertSame('ready_not_delivered', $outcome->candidate['deliveryState'] ?? null);
        self::assertContains('invoice_opened', $checkpoints);
        self::assertNotContains('invoice_delivery_write_requested', $checkpoints);
        self::assertNotContains('whmcs_email_write_requested', $checkpoints);
        self::assertSame(
            ['GET', 'POST', 'GET', 'GET', 'GET', 'GET', 'PUT', 'GET', 'GET', 'GET'],
            array_map(static fn (array $entry): string => $entry['request']->getMethod(), $history),
        );
        self::assertSame('/api/v1/Invoice/99/getPdf', $history[9]['request']->getUri()->getPath());
        $mapping = $mappings->findCompleteByInvoice(10);
        self::assertSame(MappingRepository::DOCUMENT_AUTHORITY_SEVDESK, $mapping?->document_authority);
        self::assertNotNull($mapping?->document_ready_at);
        self::assertNull($mapping?->delivered_at);
        self::assertSame(hash('sha256', $pdfContents), $mapping?->pdf_sha256);
    }

    public function testConfirmedWhmcsMailHandoffFinishesLocallyWithoutSendingAgain(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $mappings = new MappingRepository();
        $mappings->linkDocument(10, '99', MappingRepository::DOCUMENT_TYPE_INVOICE, 'RE-10');
        $mappings->enrichDocumentMetadata(
            10,
            '99',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'RE-10',
            new \DateTimeImmutable('2026-07-18 12:00:00'),
            null,
            str_repeat('a', 64),
        );
        $localCommands = [];
        $whmcs = new WhmcsGateway(
            $config,
            function (string $command, array $parameters) use (&$localCommands): array {
                $localCommands[] = $command;
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
                    $response['total'] = '119.00';
                }

                return $response;
            },
        );
        $invoiceExporter = new InvoiceExporter(
            $client,
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            $invoiceExporter,
            new InvoiceReconciliationService(
                $client,
                static fn (): string => '99',
                static fn (): bool => true,
                '7',
                '8',
            ),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'delivery_requested' => true,
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'sevdesk',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'targetDeliveryChannel' => 'whmcs_template',
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'whmcs_email_handed_off',
                'attempts' => 2,
                'sevdesk_id' => '99',
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('succeeded', $outcome->status);
        self::assertSame('99', $outcome->sevdeskId);
        self::assertNotNull($mappings->findCompleteByInvoice(10)?->delivered_at);
        self::assertSame([], $localCommands, 'Confirmed handoff recovery must be local-only.');
        self::assertNotContains('SendEmail', $localCommands);
        self::assertCount(0, $history, 'Handoff recovery must not call sevdesk or fetch the PDF again.');
    }

    public function testWhmcsMailHandoffSucceedsOnlyAfterTheAttachmentContextWasConsumed(): void
    {
        $outcome = $this->invokeWhmcsMailHandoff(true);

        self::assertSame('succeeded', $outcome->status);
        self::assertSame('99', $outcome->sevdeskId);
        self::assertNotNull((new MappingRepository())->findCompleteByInvoice(10)?->delivered_at);
    }

    public function testWhmcsMailHandoffRemainsAmbiguousWhenTheAttachmentContextWasNotConsumed(): void
    {
        $outcome = $this->invokeWhmcsMailHandoff(false);

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('whmcs_email_attachment_not_consumed', $outcome->errorCode);
        self::assertSame('whmcs_email_write_requested', $outcome->checkpoint);
        self::assertNull((new MappingRepository())->findCompleteByInvoice(10)?->delivered_at);
    }

    public function testWhmcs813StopsBeforePdfFetchOrMailWhenHookAttachmentsAreUnsupported(): void
    {
        $outcome = $this->invokeWhmcsMailHandoff(false, '8.13.4');

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('whmcs_email_attachment_unsupported', $outcome->errorCode);
        self::assertNull((new MappingRepository())->findCompleteByInvoice(10)?->delivered_at);
    }

    public function testFrozenInvoiceTargetThatIsNoLongerPaidStopsBeforeRemoteWrites(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['status'] = 'Unpaid';
                    $response['credit'] = '0.00';
                    $response['total'] = '119.00';
                }

                return $response;
            }),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
                static fn (): string => '1',
            ),
            new InvoiceReconciliationService($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'document_type_selected',
                'attempts' => 1,
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('skipped', $outcome->status);
        self::assertCount(0, $history);
        self::assertNull($mappings->findByInvoice(10));
    }

    public function testConflictingEuB2cProfilesFailClosedBeforeRemoteWrites(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_FOR_OSS);
        $config->set('oss_profile', DocumentTargetResolver::OSS_RULE_19_CONFIRMED);
        $config->set('eu_b2c_mode', TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED);
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
                $response['total'] = '119.00';
            }

            return $response;
        });
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            targetResolver: static fn (): DocumentTargetResolver => new DocumentTargetResolver(
                DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
                DocumentTargetResolver::AUTHORITY_WHMCS,
                DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            ),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'requestedExportMode' => DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
                    'requestedDocumentAuthority' => DocumentTargetResolver::AUTHORITY_WHMCS,
                    'requestedOssProfile' => DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
                    'requestedEuB2cMode' => TaxPolicy::EU_B2C_DOMESTIC_CONFIRMED,
                    'requestedDeliveryChannel' => null,
                    'delivery_requested' => false,
                ], JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('conflicting_eu_b2c_profiles', $outcome->errorCode);
        self::assertCount(0, $history);
        self::assertNull($mappings->findByInvoice(10));
    }

    public function testUnpaidRuleNineteenWaitsWithoutFreezingARejectedTarget(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_FOR_OSS);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('oss_profile', DocumentTargetResolver::OSS_RULE_19_CONFIRMED);
        $config->set('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED);
        $config->set('invoice_canary_confirmed', true);
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['status'] = 'Unpaid';
                    $response['total'] = '121.00';
                    $response['credit'] = '0.00';
                    $response['taxrate'] = '21';
                    $response['tax'] = '21.00';
                }
                if ($command === 'GetClientsDetails') {
                    $response['client']['companyname'] = '';
                    $response['client']['countrycode'] = 'BE';
                    $response['client']['customfields'] = ['customfield' => [[
                        'id' => 123,
                        'value' => '42',
                    ]]];
                }

                return $response;
            }),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
        );
        $checkpoints = [];
        $contexts = [];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'trigger' => 'InvoiceCreated',
                    'requestedExportMode' => DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
                    'requestedDocumentAuthority' => DocumentTargetResolver::AUTHORITY_WHMCS,
                    'requestedOssProfile' => DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
                    'requestedEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
                    'requestedDeliveryChannel' => null,
                    'delivery_requested' => false,
                ], JSON_THROW_ON_ERROR),
            ],
            static function (string $name, array $context = []) use (&$checkpoints, &$contexts): bool {
                $checkpoints[] = $name;
                $contexts[$name] = $context;

                return true;
            },
        );

        self::assertSame('skipped', $outcome->status);
        self::assertSame(['queued', 'queued', 'invoice_payment_pending'], $checkpoints);
        self::assertTrue($contexts['invoice_payment_pending']['invoicePaymentPending']);
        self::assertArrayNotHasKey('targetAllowed', $contexts['invoice_payment_pending']);
        self::assertSame([], $history);
        self::assertNull($mappings->findByInvoice(10));
    }

    public function testAuthenticationAlarmBlocksRunnerEvenWhenSecondarySyncDisableFails(): void
    {
        $history = [];
        $client = $this->client([
            new Response(401, [], '{"error":{"code":"AUTHENTICATION_FAILED"}}'),
        ], $history);
        $config = new Config();
        $config->set('sync_enabled', true);
        $config->set('custom_field_id', 123);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        Capsule::connection()->unprepared(<<<'SQL'
CREATE TRIGGER fail_sync_enabled_update
BEFORE UPDATE ON tbladdonmodules
WHEN OLD.module = 'sevdesk' AND OLD.setting = 'sync_enabled'
BEGIN
    SELECT RAISE(ABORT, 'synthetic sync setting failure');
END
SQL);
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
                $response['total'] = '119.00';
            }
            if ($command === 'GetClientsDetails') {
                $response['client']['customfields'] = ['customfield' => [[
                    'id' => 123,
                    'value' => '42',
                ]]];
            }

            return $response;
        });
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
                static fn (): string => '1',
            ),
            new InvoiceReconciliationService($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'whmcsInvoiceContractFingerprint' =>
                $whmcs->invoiceExportContract(10)['fingerprint'],
            'whmcsContactLinkId' => '42',
            'remoteContactId' => '42',
            'invoiceAddressCountryId' => '1',
            'invoiceAddressHash' => \WHMCS\Module\Addon\SevDesk\Domain\InvoiceAddressContext::addressHash(
                'Synthetic Company',
                'Example Street 1',
                '12345',
                'Example City',
                'DE',
            ),
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => 'invoice_write_requested',
                'attempts' => 1,
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame(
            'retry_wait',
            $outcome->status,
            (string) $outcome->errorCode . ': ' . $outcome->message,
        );
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('api_authentication_failed', $outcome->errorCode);
        self::assertTrue($config->bool('sync_enabled'), 'The synthetic secondary write must have failed.');
        self::assertSame('api_authentication_failed', $config->get('health_alarm'));
        self::assertSame(['GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    #[DataProvider('invoiceCreateRecoveryCheckpointProvider')]
    public function testExhaustedInvoiceCreateRecoveryRemainsAmbiguousAndNeverPostsAgain(string $checkpoint): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact"}]}'),
            new Response(500, [], '{"error":{"code":"SERVER_ERROR"}}'),
        ], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('import_after', '01-01-2099');
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
                    $response['total'] = '119.00';
                }
                if ($command === 'GetClientsDetails') {
                    $response['client']['customfields'] = ['customfield' => [[
                        'id' => 123,
                        'value' => '42',
                    ]]];
                }

                return $response;
            }),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoiceReconciliationService($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => $checkpoint,
                'attempts' => 4,
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame($checkpoint, $outcome->checkpoint);
        self::assertSame('whmcs_invoice_contract_snapshot_missing_after_write', $outcome->errorCode);
        self::assertNull($mappings->findByInvoice(10));
        self::assertSame([], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testFrozenRuleElevenWriteBecomesAmbiguousWhenTheNewCanaryIsOff(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_confirmed', true);
        $config->set('taxRuleSmallBusinessOwner', '11');
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['subtotal'] = '100.00';
                $response['credit'] = '0.00';
                $response['tax'] = '0.00';
                $response['tax2'] = '0.00';
                $response['total'] = '100.00';
                $response['taxrate'] = '0';
                $response['taxrate2'] = '0';
                $response['items']['item'][0]['amount'] = '100.00';
                $response['items']['item'][0]['taxed'] = false;
            }
            if ($command === 'GetClientsDetails') {
                $response['client']['customfields'] = ['customfield' => [[
                    'id' => 123,
                    'value' => '42',
                ]]];
            }

            return $response;
        });
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'The disabled Rule-11 canary must stop before Receipt Guidance.',
            ),
            new InvoiceExporter(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
            ),
            new InvoiceReconciliationService(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
            ),
            new InvoicePdf($client),
        );
        $candidate = [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '11',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen Rule-11 target.',
            'selectedInvoiceNumber' => 'RE-10',
            'whmcsInvoiceContractFingerprint' => $whmcs->invoiceExportContract(10)['fingerprint'],
            'whmcsContactLinkId' => '42',
            'remoteContactId' => '42',
            'targetIsEInvoice' => false,
        ];

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => 'invoice_write_requested',
                'attempts' => 1,
                'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('small_business_invoice_canary_not_confirmed', $outcome->errorCode);
        self::assertSame([], $history, 'Recovery must not call sevdesk or repeat a write.');
    }

    #[DataProvider('invoiceAddressRecoverySnapshotProvider')]
    public function testInvoiceRecoveryRejectsMissingOrChangedAddressSnapshotWithoutAWrite(
        ?string $addressHash,
        string $expectedCode,
    ): void {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact","customerNumber":"20"}]}'),
        ], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
                $response['total'] = '119.00';
            }
            if ($command === 'GetClientsDetails') {
                $response['client']['customfields'] = ['customfield' => [[
                    'id' => 123,
                    'value' => '42',
                ]]];
            }

            return $response;
        });
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
                static fn (): string => '1',
            ),
            new InvoiceReconciliationService(
                $client,
                static fn (): null => null,
                static fn (): bool => true,
                '7',
                '8',
            ),
            new InvoicePdf($client),
        );
        $candidate = [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'whmcsInvoiceContractFingerprint' => $whmcs->invoiceExportContract(10)['fingerprint'],
            'whmcsContactLinkId' => '42',
            'remoteContactId' => '42',
            'targetIsEInvoice' => false,
        ];
        if ($addressHash !== null) {
            $candidate['invoiceAddressCountryId'] = '1';
            $candidate['invoiceAddressHash'] = $addressHash;
        }

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => 'invoice_write_requested',
                'attempts' => 1,
                'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame($expectedCode, $outcome->errorCode);
        self::assertSame([], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    /** @return iterable<string, array{string|null,string}> */
    public static function invoiceAddressRecoverySnapshotProvider(): iterable
    {
        yield 'missing snapshot' => [null, 'invoice_address_snapshot_missing_after_write'];
        yield 'changed snapshot' => [str_repeat('0', 64), 'invoice_address_snapshot_changed'];
    }

    public function testRiskyInvoiceRecoveryWithChangedWhmcsContractStopsBeforeRemoteIo(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
                $response['total'] = '119.00';
            }
            if ($command === 'GetClientsDetails') {
                $response['client']['customfields'] = ['customfield' => [[
                    'id' => 123,
                    'value' => '42',
                ]]];
            }

            return $response;
        });
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoiceReconciliationService($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'whmcsInvoiceContractFingerprint' => str_repeat('0', 64),
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => 'invoice_write_requested',
                'attempts' => 1,
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('whmcs_invoice_contract_changed_after_write', $outcome->errorCode);
        self::assertNull($mappings->findByInvoice(10));
        self::assertSame([], $history);
    }

    public function testRiskyInvoiceRecoveryNeverReinterpretsAChangedContactLink(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $configuredContactId = '42';
        $whmcs = new WhmcsGateway(
            $config,
            function (string $command, array $parameters) use (&$configuredContactId): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
                    $response['total'] = '119.00';
                }
                if ($command === 'GetClientsDetails') {
                    $response['client']['customfields'] = ['customfield' => [[
                        'id' => 123,
                        'value' => $configuredContactId,
                    ]]];
                }

                return $response;
            },
        );
        $fingerprint = $whmcs->invoiceExportContract(10)['fingerprint'];
        $configuredContactId = '84';
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoiceReconciliationService($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'whmcsInvoiceContractFingerprint' => $fingerprint,
            'whmcsContactLinkId' => '42',
            'remoteContactId' => '42',
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => 'invoice_write_requested',
                'attempts' => 1,
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('whmcs_contact_link_changed_after_write', $outcome->errorCode);
        self::assertNull($mappings->findByInvoice(10));
        self::assertSame([], $history);
    }

    public function testChangedImportDateCannotSkipAnUnknownVoucherWrite(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[]}'),
        ], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('import_after', '01-01-2099');
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, $this->localApi(...));
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
        );
        $candidate = json_encode([
            'targetDocumentType' => 'voucher',
            'remoteContactId' => '1',
            'targetTaxRuleId' => '1',
            'targetAccountDatevId' => '100',
            'whmcsInvoiceContractFingerprint' =>
                $whmcs->invoiceExportContract(10)['fingerprint'],
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => 'voucher_write_requested',
                'attempts' => 1,
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('voucher_write_requested', $outcome->checkpoint);
        self::assertSame('reconciliation_no_match', $outcome->errorCode);
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('/api/v1/Voucher', $history[0]['request']->getUri()->getPath());
    }

    public function testUnknownVoucherWriteWithoutFrozenAccountNeverUsesCurrentTaxConfiguration(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, $this->localApi(...));
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'Risky Voucher recovery must not use current tax configuration.',
            ),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => 'voucher_write_requested',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'targetDocumentType' => 'voucher',
                    'remoteContactId' => '1',
                    'targetTaxRuleId' => '1',
                    'whmcsInvoiceContractFingerprint' =>
                        $whmcs->invoiceExportContract(10)['fingerprint'],
                ], JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('voucher_write_requested', $outcome->checkpoint);
        self::assertSame('voucher_verification_snapshot_missing', $outcome->errorCode);
        self::assertNull($mappings->findByInvoice(10));
        self::assertSame([], $history);
    }

    /** @return iterable<string, array{string}> */
    public static function invoiceCreateRecoveryCheckpointProvider(): iterable
    {
        yield 'create requested' => ['invoice_write_requested'];
        yield 'created' => ['invoice_created'];
        yield 'mapping checkpoint without mapping' => ['mapping_persisted'];
    }

    #[DataProvider('postMappingInvoiceCheckpointProvider')]
    public function testMissingMappingAfterLaterInvoiceWriteNeverFallsBackToCreate(string $checkpoint): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $mappings = new MappingRepository();
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
                $response['total'] = '119.00';
            }
            if ($command === 'GetClientsDetails') {
                $response['client']['customfields'] = ['customfield' => [[
                    'id' => 123,
                    'value' => '42',
                ]]];
            }

            return $response;
        });
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoiceReconciliationService($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'delivery_requested' => false,
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'targetDeliveryChannel' => null,
            'whmcsInvoiceContractFingerprint' =>
                $whmcs->invoiceExportContract(10)['fingerprint'],
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 0,
                'action' => 'export_document',
                'checkpoint' => $checkpoint,
                'attempts' => 1,
                'sevdesk_id' => '99',
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame($checkpoint, $outcome->checkpoint);
        self::assertSame('invoice_mapping_missing_after_write', $outcome->errorCode);
        self::assertNull($mappings->findByInvoice(10));
        self::assertSame([], $history, 'No remote request, especially no saveInvoice POST, is allowed.');
    }

    /** @return iterable<string, array{string}> */
    public static function postMappingInvoiceCheckpointProvider(): iterable
    {
        yield 'open requested' => ['invoice_open_write_requested'];
        yield 'opened' => ['invoice_opened'];
        yield 'delivery requested' => ['invoice_delivery_write_requested'];
        yield 'delivered' => ['invoice_delivered'];
        yield 'WHMCS email requested' => ['whmcs_email_write_requested'];
    }

    public function testSevdeskAuthorityPrerequisiteDriftStopsBeforeAnyRemoteWrite(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $config->set('theme_adapter_confirmed', true);
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_SEVDESK);
        $config->set('oss_profile', DocumentTargetResolver::OSS_BLOCKED);
        $config->set('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED);
        $config->set('invoice_delivery_channel', 'sevdesk');
        $config->set('e_invoice_mode', 'zugferd_domestic_b2b');
        $config->set('e_invoice_canary_confirmed', false);
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
                    $response['total'] = '119.00';
                }

                return $response;
            }),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            new InvoiceExporter($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoiceReconciliationService($client, static fn (): null => null, static fn (): bool => true, '7', '8'),
            new InvoicePdf($client),
        );
        $candidate = json_encode([
            'delivery_requested' => true,
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'sevdesk',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => TaxPolicy::EU_B2C_BLOCKED,
            'targetTaxRuleId' => '1',
            'targetSevUserId' => '7',
            'targetUnityId' => '8',
            'targetCode' => 'invoice_selected_global',
            'targetMessage' => 'Frozen target.',
            'selectedInvoiceNumber' => 'RE-10',
            'targetDeliveryChannel' => 'sevdesk',
            'targetEInvoiceMode' => 'zugferd_domestic_b2b',
            'targetIsEInvoice' => false,
        ], JSON_THROW_ON_ERROR);

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => 'document_type_selected',
                'attempts' => 1,
                'candidate_json' => $candidate,
            ],
            static fn (): bool => true,
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('sevdesk_authority_prerequisites_missing', $outcome->errorCode);
        self::assertCount(0, $history);
        self::assertNull($mappings->findByInvoice(10));
    }

    public function testOldVoucherJobCannotWriteAfterSwitchToInvoiceOnly(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $config = new Config();
        $config->set('export_mode', DocumentTargetResolver::MODE_INVOICE_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('oss_profile', DocumentTargetResolver::OSS_BLOCKED);
        $config->set('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED);
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
                    $response['total'] = '119.00';
                }

                return $response;
            }),
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_voucher',
                'checkpoint' => 'queued',
                'attempts' => 2,
                'candidate_json' => null,
            ],
            static fn (): bool => true,
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('stale_export_context_requeue_required', $outcome->errorCode);
        self::assertSame([], $history);
        self::assertNull($mappings->findByInvoice(10));
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
        $whmcs = new WhmcsGateway($config, $this->localApi(...));
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
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
                'candidate_json' => json_encode([
                    'whmcsClientId' => 20,
                    'whmcsInvoiceContractFingerprint' =>
                        $whmcs->invoiceExportContract(10)['fingerprint'],
                ], JSON_THROW_ON_ERROR),
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

    #[DataProvider('legacyContactLinkProvider')]
    public function testLegacyContactLinkedResumeKeepsItsCheckpointedRecipient(
        string $currentContactId,
        string $expectedStatus,
        ?string $expectedErrorCode,
        int $expectedRemoteReads,
    ): void {
        $history = [];
        $responses = $expectedRemoteReads === 1
            ? [new Response(200, [], '{"objects":{"id":"42","objectName":"Contact"}}')]
            : [];
        $client = $this->client($responses, $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $mappings = new MappingRepository();
        $mappings->linkDocument(10, '77', MappingRepository::DOCUMENT_TYPE_VOUCHER, 'RE-10');
        $whmcs = new WhmcsGateway(
            $config,
            function (string $command, array $parameters) use ($currentContactId): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetClientsDetails') {
                    $response['client']['customfields'] = ['customfield' => [[
                        'id' => 123,
                        'value' => $currentContactId,
                    ]]];
                }

                return $response;
            },
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException('Mapped contact recovery must stop first.'),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_voucher',
                'checkpoint' => 'contact_linked',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'whmcsClientId' => 20,
                    'remoteContactId' => '42',
                ], JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame($expectedStatus, $outcome->status);
        self::assertSame($expectedErrorCode, $outcome->errorCode);
        self::assertCount($expectedRemoteReads, $history);
        if ($expectedRemoteReads === 1) {
            self::assertSame('/api/v1/Contact/42', $history[0]['request']->getUri()->getPath());
        }
    }

    /** @return iterable<string,array{string,string,?string,int}> */
    public static function legacyContactLinkProvider(): iterable
    {
        yield 'unchanged recipient A to A' => ['42', 'skipped', null, 1];
        yield 'changed recipient A to B' => ['84', 'permanent_failed', 'whmcs_contact_link_changed', 0];
    }

    public function testEmptyContactLinkRecoveryNeverAdoptsAnotherCustomerNumberMatch(): void
    {
        $history = [];
        $persistedContactIds = [];
        $client = $this->client([
            new Response(
                200,
                [],
                '{"objects":[{"id":"84","objectName":"Contact","customerNumber":"20"}]}',
            ),
        ], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $mappings = new MappingRepository();
        $mappings->linkDocument(10, '77', MappingRepository::DOCUMENT_TYPE_VOUCHER, 'RE-10');
        $whmcs = new WhmcsGateway(
            $config,
            function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetClientsDetails') {
                    $response['client']['customfields'] = ['customfield' => []];
                }

                return $response;
            },
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService(
                $client,
                static function (
                    int $_clientId,
                    string $contactId,
                ) use (
                    &$persistedContactIds,
                ): bool {
                    $persistedContactIds[] = $contactId;

                    return true;
                },
                static fn (): string => '1',
            ),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            static fn (): TaxPolicy => throw new \RuntimeException(
                'A mismatching recovery contact must stop before tax resolution.',
            ),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_voucher',
                'checkpoint' => 'contact_linked',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'whmcsClientId' => 20,
                    'remoteContactId' => '42',
                ], JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('contact_recovery_snapshot_missing', $outcome->errorCode);
        self::assertSame([], $persistedContactIds);
        self::assertCount(1, $history);
        self::assertSame('/api/v1/Contact/42', $history[0]['request']->getUri()->getPath());
        self::assertSame('', $history[0]['request']->getUri()->getQuery());
    }

    /**
     * @return array{
     *     outcome:JobOutcome,
     *     checkpoints:list<string>,
     *     history:list<array{request:RequestInterface}>,
     *     pdfRenderCalls:int,
     *     getInvoiceCalls:int
     * }
     */
    private function runMassPaymentPreWriteRevalidation(
        string $drift,
        bool $risky,
        ?int $hookParentInvoiceId = null,
        bool $hookParentConflict = false,
    ): array {
        self::assertContains(
            $drift,
            ['none', 'database', 'snapshot', 'after_io', 'ordinary_before_worker'],
        );
        $this->resetPaymentStructureTables();
        Capsule::table('tblinvoices')->insert([
            [
                'id' => 100,
                'userid' => 20,
                'status' => 'Paid',
                'subtotal' => '20.00',
                'credit' => '0.00',
                'tax' => '0.00',
                'tax2' => '0.00',
                'total' => '20.00',
            ],
            [
                'id' => 10,
                'userid' => 20,
                'status' => 'Paid',
                'subtotal' => '100.00',
                'credit' => '20.00',
                'tax' => '19.00',
                'tax2' => '0.00',
                'total' => '99.00',
            ],
        ]);
        Capsule::table('tblinvoiceitems')->insert([
            ['invoiceid' => 100, 'type' => 'Invoice', 'relid' => 10, 'amount' => '20.00'],
            ['invoiceid' => 10, 'type' => 'Hosting', 'relid' => 42, 'amount' => '100.00'],
        ]);
        Capsule::table('tblaccounts')->insert([
            [
                'invoiceid' => 100,
                'amountin' => '20.00',
                'amountout' => '0.00',
                'refundid' => 0,
            ],
            [
                'invoiceid' => 10,
                'amountin' => '99.00',
                'amountout' => '0.00',
                'refundid' => 0,
            ],
        ]);

        $paymentStructure = new WhmcsPaymentStructureService();
        $initialStructure = $paymentStructure->classify(10);
        self::assertSame(
            WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET,
            $initialStructure['code'],
        );
        if ($drift === 'ordinary_before_worker') {
            Capsule::table('tblinvoices')->where('id', 10)->update([
                'credit' => '0.00',
                'total' => '119.00',
            ]);
            Capsule::table('tblaccounts')->where('invoiceid', 10)->update([
                'amountin' => '119.00',
            ]);
        }
        $history = [];
        $clientResponses = match ($drift) {
            'none' => [new Response(500, [], '{}')],
            'after_io' => [
                new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact","customerNumber":"20"}]}'),
                new Response(201, [], '{"objects":{"filename":"temporary.pdf"}}'),
            ],
            default => [],
        };
        $client = $this->client($clientResponses, $history);
        $config = new Config();
        $config->set('export_mode', DocumentTargetResolver::MODE_VOUCHER_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('oss_profile', DocumentTargetResolver::OSS_BLOCKED);
        $config->set('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED);
        $getInvoiceCalls = 0;
        $whmcs = new WhmcsGateway(
            $config,
            static function (
                string $command,
                array $parameters,
            ) use (
                $drift,
                &$getInvoiceCalls,
            ): array {
                if ($command === 'GetInvoice') {
                    self::assertSame(10, $parameters['invoiceid']);
                    ++$getInvoiceCalls;
                    if ($getInvoiceCalls === 2 && $drift === 'database') {
                        Capsule::table('tblinvoiceitems')
                            ->where('invoiceid', 100)
                            ->where('type', 'Invoice')
                            ->update(['amount' => '21.00']);
                    }
                    $snapshotDrift = $getInvoiceCalls === 2 && $drift === 'snapshot';
                    $ordinaryBeforeWorker = $drift === 'ordinary_before_worker';

                    return [
                        'result' => 'success',
                        'userid' => 20,
                        'status' => 'Paid',
                        'date' => '2026-07-01',
                        'invoicenum' => 'RE-10',
                        'currencycode' => 'EUR',
                        'subtotal' => '100.00',
                        'tax' => '19.00',
                        'tax2' => '0.00',
                        'total' => $ordinaryBeforeWorker
                            ? '119.00'
                            : ($snapshotDrift ? '98.00' : '99.00'),
                        'credit' => $ordinaryBeforeWorker
                            ? '0.00'
                            : ($snapshotDrift ? '21.00' : '20.00'),
                        'taxrate' => '19',
                        'taxrate2' => '0',
                        'items' => ['item' => [[
                            'id' => 1,
                            'type' => 'Hosting',
                            'relid' => 42,
                            'description' => 'Synthetic hosting item',
                            'amount' => '100.00',
                            'taxed' => true,
                        ]]],
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
                            'customfields' => ['customfield' => [[
                                'id' => 123,
                                'value' => '42',
                            ]]],
                        ],
                    ];
                }

                self::fail('Unexpected WHMCS local API command: ' . $command);
            },
        );
        $mappings = new MappingRepository();
        $pdfRenderCalls = 0;
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static function () use (&$pdfRenderCalls, $drift): string {
                ++$pdfRenderCalls;
                if ($drift === 'after_io') {
                    Capsule::table('tblinvoiceitems')
                        ->where('invoiceid', 100)
                        ->where('type', 'Invoice')
                        ->update(['amount' => '21.00']);
                }

                return "%PDF-1.7\nsynthetic invoice document";
            }),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            paymentStructure: $paymentStructure,
        );
        $candidate = $risky
            ? [
                'targetAllowed' => true,
                'targetDocumentType' => 'invoice',
                'targetDocumentAuthority' => 'whmcs',
                'targetExportMode' => 'invoice_only',
                'targetOssProfile' => 'blocked',
                'targetEuB2cMode' => 'blocked',
                'targetTaxRuleId' => '1',
                'targetCode' => 'invoice_selected',
                'targetMessage' => 'Synthetic frozen target.',
                'selectedInvoiceNumber' => 'RE-10',
                'targetSevUserId' => '7',
                'targetUnityId' => '8',
                'targetEInvoiceMode' => 'off',
                'massPaymentFingerprint' => $initialStructure['fingerprint'],
                'massPaymentParentInvoiceId' => $initialStructure['parentInvoiceId'],
                'massPaymentExact' => true,
            ]
            : [
                'requestedExportMode' => 'voucher_only',
                'requestedDocumentAuthority' => 'whmcs',
                'requestedOssProfile' => 'blocked',
                'requestedEuB2cMode' => 'blocked',
                'requestedEInvoiceMode' => 'off',
            ];
        if ($hookParentInvoiceId !== null) {
            $candidate['massPaymentContainerInvoiceId'] = $hookParentInvoiceId;
        }
        if ($hookParentConflict) {
            $candidate['massPaymentContainerConflict'] = true;
        }
        $checkpoints = [];
        $outcome = $handler(
            (object) [
                'invoice_id' => 10,
                'job_id' => 1,
                'action' => 'export_document',
                'checkpoint' => $risky ? 'invoice_write_requested' : 'queued',
                'attempts' => 1,
                'sevdesk_id' => $risky ? '99' : null,
                'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            ],
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
        );

        return [
            'outcome' => $outcome,
            'checkpoints' => $checkpoints,
            'history' => $history,
            'pdfRenderCalls' => $pdfRenderCalls,
            'getInvoiceCalls' => $getInvoiceCalls,
        ];
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
                'subtotal' => '100.00',
                'tax' => '19.00',
                'tax2' => '0.00',
                'total' => '99.00',
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

    private function resetPaymentStructureTables(): void
    {
        foreach (['tblaccounts', 'tblinvoiceitems', 'tblinvoices'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::schema()->create('tblinvoices', static function ($table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('userid');
            $table->string('status');
            $table->decimal('subtotal', 18, 4);
            $table->decimal('credit', 18, 4);
            $table->decimal('tax', 18, 4);
            $table->decimal('tax2', 18, 4);
            $table->decimal('total', 18, 4);
        });
        Capsule::schema()->create('tblinvoiceitems', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->string('type');
            $table->unsignedInteger('relid')->nullable();
            $table->decimal('amount', 18, 4);
        });
        Capsule::schema()->create('tblaccounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->decimal('amountin', 18, 4)->default(0);
            $table->decimal('amountout', 18, 4)->default(0);
            $table->unsignedInteger('refundid')->default(0);
        });
    }

    private function domesticTaxPolicy(): TaxPolicy
    {
        return new TaxPolicy(
            ['domestic' => ['accountDatev' => '100', 'taxRule' => '1']],
            TaxPolicy::EU_B2C_BLOCKED,
            [[
                'accountDatevId' => 100,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 1, 'taxRates' => ['NINETEEN']]],
            ], [
                'accountDatevId' => 500,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 11, 'taxRates' => ['ZERO']]],
            ]],
        );
    }

    private function invokeWhmcsMailHandoff(
        bool $consumeAttachment,
        string $whmcsVersion = '9.0.0',
    ): JobOutcome {
        $pdf = "%PDF-1.7\nsynthetic sevdesk invoice\n%%EOF";
        $history = [];
        $client = $this->client([
            new Response(200, [], json_encode([
                'filename' => 'RE-10.pdf',
                'mimeType' => 'application/pdf',
                'base64encoded' => true,
                'content' => base64_encode($pdf),
            ], JSON_THROW_ON_ERROR)),
        ], $history);
        $config = new Config();
        $config->set('whmcs_invoice_email_template', 'Final sevdesk Invoice');
        Capsule::table('tblemailtemplates')->insert([
            'type' => 'invoice',
            'name' => 'Final sevdesk Invoice',
            'custom' => 1,
            'disabled' => 0,
        ]);
        $mappings = new MappingRepository();
        $mappings->linkDocument(10, '99', MappingRepository::DOCUMENT_TYPE_INVOICE, 'RE-10');
        $whmcs = new WhmcsGateway(
            $config,
            static function (string $command, array $parameters) use ($consumeAttachment): array {
                self::assertSame('SendEmail', $command);
                self::assertIsString($parameters['customvars'] ?? null);
                $variables = unserialize(
                    base64_decode((string) $parameters['customvars'], true),
                    ['allowed_classes' => false],
                );
                self::assertIsArray($variables);
                $token = (string) ($variables['sevdesk_attachment_token'] ?? '');
                if ($consumeAttachment) {
                    self::assertNotNull(EmailAttachmentContext::consume(
                        $token,
                        10,
                        'Final sevdesk Invoice',
                    ));
                }

                return ['result' => 'success'];
            },
            static fn (): string => $whmcsVersion,
        );
        $invoiceExporter = new InvoiceExporter(
            $client,
            static fn (): string => '99',
            static fn (): bool => true,
            '7',
            '8',
        );
        $handler = new ExportJobHandler(
            $config,
            $whmcs,
            $mappings,
            new JobRepository(),
            new ContactService($client, static fn (): bool => true, static fn (): string => '1'),
            new PdfRenderer(static fn (): string => "%PDF-1.7\nnot expected"),
            new VoucherExporter($client, static fn (): null => null, static fn (): bool => true),
            new ReconciliationService($client, $mappings),
            fn (): TaxPolicy => $this->domesticTaxPolicy(),
            $invoiceExporter,
            new InvoiceReconciliationService(
                $client,
                static fn (): string => '99',
                static fn (): bool => true,
                '7',
                '8',
            ),
            new InvoicePdf($client),
        );
        $item = (object) [
            'invoice_id' => 10,
            'checkpoint' => 'invoice_opened',
        ];
        $candidate = [
            'delivery_requested' => true,
            'targetDeliveryChannel' => 'whmcs_template',
        ];
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new \DateTimeImmutable('2026-07-01'),
            'EUR',
            '119.00',
            '0.00',
            [new LineItem('Synthetic hosting item', '100.00', '19', true)],
        );
        $contact = new ContactData(
            20,
            '42',
            'Synthetic Company',
            'Synthetic',
            'Customer',
            'synthetic@example.invalid',
            'Example Street 1',
            '',
            '12345',
            'Example City',
            'DE',
            null,
            false,
        );
        $checkpoints = [];
        $method = new \ReflectionMethod($handler, 'completeSevdeskAuthority');
        $outcome = $method->invoke(
            $handler,
            $item,
            $candidate,
            $invoice,
            $contact,
            '42',
            TaxDecision::allowInvoice('domestic', '1', 'Synthetic domestic tax.', ['19']),
            '99',
            static function (string $checkpoint) use (&$checkpoints): bool {
                $checkpoints[] = $checkpoint;

                return true;
            },
            $invoiceExporter,
            null,
        );
        self::assertInstanceOf(JobOutcome::class, $outcome);
        if (WhmcsGateway::versionSupportsEmailPreSendAttachments($whmcsVersion)) {
            self::assertSame(['whmcs_email_write_requested'], array_slice($checkpoints, 0, 1));
            if ($consumeAttachment) {
                self::assertContains('whmcs_email_handed_off', $checkpoints);
            } else {
                self::assertNotContains('whmcs_email_handed_off', $checkpoints);
            }
            self::assertCount(1, $history);
        } else {
            self::assertSame([], $checkpoints);
            self::assertCount(0, $history);
        }

        return $outcome;
    }

    private function invoiceResponse(
        int $status,
        string $invoiceNumber = 'RE-10',
        string $invoiceDate = '01.07.2026',
    ): Response {
        return new Response(200, [], json_encode(['objects' => [[
            'id' => '99',
            'objectName' => 'Invoice',
            'invoiceType' => 'RE',
            'invoiceNumber' => $invoiceNumber,
            'invoiceDate' => $invoiceDate,
            'currency' => 'EUR',
            'status' => (string) $status,
            'taxRule' => ['id' => '1', 'objectName' => 'TaxRule'],
            'contact' => ['id' => '42', 'objectName' => 'Contact'],
            'contactPerson' => ['id' => '7', 'objectName' => 'SevUser'],
            'showNet' => true,
            'deliveryAddressCountry' => 'DE',
            'addressName' => 'Synthetic Company',
            'addressStreet' => 'Example Street 1',
            'addressZip' => '12345',
            'addressCity' => 'Example City',
            'addressCountry' => ['id' => '1', 'objectName' => 'StaticCountry', 'code' => 'DE'],
            'customerInternalNote' => '[WHMCS-INVOICE:10]',
            'sumGross' => '119.00',
        ]]], JSON_THROW_ON_ERROR));
    }

    private function invoicePositionResponse(): Response
    {
        return new Response(200, [], json_encode(['objects' => [[
            'id' => '901',
            'objectName' => 'InvoicePos',
            'invoice' => ['id' => '99', 'objectName' => 'Invoice'],
            'unity' => ['id' => '8', 'objectName' => 'Unity'],
            'positionNumber' => '1',
            'quantity' => '1',
            'name' => 'Synthetic hosting item',
            'text' => 'Synthetic hosting item',
            'price' => '100.00',
            'taxRate' => '19',
        ]]], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<Response> $responses
     * @param list<array{request:RequestInterface}> $history
     */
    private function client(array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(static function (callable $handler) use (&$history): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$history) {
                $history[] = ['request' => $request];

                return $handler($request, $options);
            };
        });

        return new SevdeskClient(new Client(['handler' => $stack]), 'synthetic-token');
    }
}
