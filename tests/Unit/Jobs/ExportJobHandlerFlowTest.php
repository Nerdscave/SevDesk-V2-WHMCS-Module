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
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceExporter;
use WHMCS\Module\Addon\SevDesk\Service\InvoicePdf;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceReconciliationService;
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
        Capsule::schema()->dropIfExists('tblconfiguration');
        Capsule::schema()->dropIfExists('tblcustomfields');
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->unique();
            $table->string('sevdesk_id')->nullable()->unique();
            $table->string('document_type', 16)->nullable();
            $table->string('document_number')->nullable();
            $table->dateTime('document_ready_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
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

    public function testInvoiceOnlyJobSelectsCreatesVerifiesAndOpensWithoutRenderingWhmcsPdf(): void
    {
        $history = [];
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
        ], $history);
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('export_mode', DocumentTargetResolver::MODE_VOUCHER_ONLY);
        $config->set('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS);
        $config->set('invoice_canary_confirmed', true);
        $config->set('invoice_sev_user_id', '7');
        $config->set('invoice_unity_id', '8');
        $mappings = new MappingRepository();
        $pdfRenderCalls = 0;
        $whmcs = new WhmcsGateway($config, function (string $command, array $parameters): array {
            $response = $this->localApi($command, $parameters);
            if ($command === 'GetInvoice') {
                $response['credit'] = '0.00';
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
                DocumentTargetResolver::MODE_VOUCHER_ONLY,
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
        self::assertSame(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            $checkpointContexts['document_type_selected']['targetExportMode'] ?? null,
        );
        self::assertSame(
            TaxPolicy::EU_B2C_BLOCKED,
            $checkpointContexts['document_type_selected']['targetEuB2cMode'] ?? null,
        );
        self::assertSame('7', $checkpointContexts['document_type_selected']['targetSevUserId'] ?? null);
        self::assertSame('8', $checkpointContexts['document_type_selected']['targetUnityId'] ?? null);
        self::assertContains('invoice_write_requested', $checkpoints);
        self::assertContains('invoice_opened', $checkpoints);
        $mapping = $mappings->findCompleteByInvoice(10);
        self::assertSame('invoice', $mapping?->document_type);
        self::assertSame('RE-10', $mapping?->document_number);
        self::assertNotNull($mapping?->document_ready_at);
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
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
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
        self::assertSame(['invoice_payment_pending'], $checkpoints);
        self::assertTrue($contexts['invoice_payment_pending']['invoicePaymentPending']);
        self::assertArrayNotHasKey('targetAllowed', $contexts['invoice_payment_pending']);
        self::assertSame([], $history);
        self::assertNull($mappings->findByInvoice(10));
    }

    public function testAuthenticationAlarmBlocksRunnerEvenWhenSecondarySyncDisableFails(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], '{"objects":[{"id":"42","objectName":"Contact"}]}'),
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

        self::assertSame('retry_wait', $outcome->status);
        self::assertSame('invoice_write_requested', $outcome->checkpoint);
        self::assertSame('api_authentication_failed', $outcome->errorCode);
        self::assertTrue($config->bool('sync_enabled'), 'The synthetic secondary write must have failed.');
        self::assertSame('api_authentication_failed', $config->get('health_alarm'));
        self::assertSame(['GET', 'GET'], array_map(
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
        self::assertSame('invoice_reconciliation_lookup_failed', $outcome->errorCode);
        self::assertNull($mappings->findByInvoice(10));
        self::assertSame(['GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
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
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, $this->localApi(...)),
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
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
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
        $mappings = new MappingRepository();
        $handler = new ExportJobHandler(
            $config,
            new WhmcsGateway($config, function (string $command, array $parameters): array {
                $response = $this->localApi($command, $parameters);
                if ($command === 'GetInvoice') {
                    $response['credit'] = '0.00';
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

    private function domesticTaxPolicy(): TaxPolicy
    {
        return new TaxPolicy(
            ['domestic' => ['accountDatev' => '100', 'taxRule' => '1']],
            TaxPolicy::EU_B2C_BLOCKED,
            [[
                'accountDatevId' => 100,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 1, 'taxRates' => ['NINETEEN']]],
            ]],
        );
    }

    private function invoiceResponse(int $status): Response
    {
        return new Response(200, [], json_encode(['objects' => [[
            'id' => '99',
            'objectName' => 'Invoice',
            'invoiceType' => 'RE',
            'invoiceNumber' => 'RE-10',
            'invoiceDate' => '01.07.2026',
            'currency' => 'EUR',
            'status' => (string) $status,
            'taxRule' => ['id' => '1', 'objectName' => 'TaxRule'],
            'contact' => ['id' => '42', 'objectName' => 'Contact'],
            'contactPerson' => ['id' => '7', 'objectName' => 'SevUser'],
            'showNet' => true,
            'deliveryAddressCountry' => 'DE',
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
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new SevdeskClient(new Client(['handler' => $stack]), 'synthetic-token');
    }
}
