<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use ReflectionMethod;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Jobs\CorrectionJobHandler;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class CorrectionJobHandlerTest extends TestCase
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

        foreach (['mod_sevdesk', 'tblaccounts', 'tblcurrencies', 'tblcustomfields', 'tbladdonmodules'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module');
            $table->string('setting');
            $table->text('value')->nullable();
            $table->unique(['module', 'setting']);
        });
        Capsule::schema()->create('tblcustomfields', static function ($table): void {
            $table->increments('id');
            $table->string('type');
            $table->string('fieldname');
        });
        Capsule::schema()->create('tblcurrencies', static function ($table): void {
            $table->increments('id');
            $table->string('code', 3);
        });
        Capsule::schema()->create('tblaccounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->decimal('amountin', 16, 2)->default(0);
            $table->decimal('amountout', 16, 2)->default(0);
            $table->unsignedInteger('refundid')->default(0);
            $table->unsignedInteger('currency');
            $table->dateTime('date');
            $table->text('description')->nullable();
        });
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->unique();
            $table->string('sevdesk_id')->nullable()->unique();
            $table->string('document_type', 16)->nullable();
        });

        Capsule::table('tblcustomfields')->insert([
            'id' => 123,
            'type' => 'client',
            'fieldname' => 'sevdesk ID',
        ]);
        Capsule::table('tblcurrencies')->insert(['id' => 1, 'code' => 'EUR']);
        Capsule::table('tblaccounts')->insert([
            [
                'id' => 10,
                'invoiceid' => 42,
                'amountin' => 100,
                'amountout' => 0,
                'refundid' => 0,
                'currency' => 1,
                'date' => '2026-07-10 12:00:00',
                'description' => 'Synthetic payment',
            ],
            [
                'id' => 11,
                'invoiceid' => 42,
                'amountin' => 0,
                'amountout' => 10,
                'refundid' => 10,
                'currency' => 1,
                'date' => '2026-07-10 12:05:00',
                'description' => 'Synthetic refund',
            ],
        ]);
        Capsule::table('mod_sevdesk')->insert([
            'invoice_id' => 42,
            'sevdesk_id' => '88',
            'document_type' => MappingRepository::DOCUMENT_TYPE_VOUCHER,
        ]);

        $GLOBALS['CONFIG']['TaxType'] = 'Exclusive';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['CONFIG']['TaxType']);

        parent::tearDown();
    }

    #[DataProvider('originalInvoiceDateProvider')]
    public function testCorrectionUsesOriginalInvoiceDateForSmallBusinessCutoff(
        string $invoiceDate,
        string $taxRate,
        string $expectedRule,
        int $expectedAccount,
    ): void {
        $config = new Config();
        $config->set('custom_field_id', 123);
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_until', '31-12-2025');
        $history = [];
        $service = new CorrectionService(
            $this->sevdeskClient([
                new Response(200, [], '{"objects":[]}'),
                new Response(200, [], json_encode([
                    'objects' => [[
                        'id' => '88',
                        'objectName' => 'Voucher',
                        'currency' => 'EUR',
                        'creditDebit' => 'D',
                        'sumGross' => '100.00',
                        'supplier' => ['id' => '42', 'objectName' => 'Contact'],
                        'taxRule' => ['id' => $expectedRule, 'objectName' => 'TaxRule'],
                    ]],
                ], JSON_THROW_ON_ERROR)),
                new Response(201, [], '{"objects":{"voucher":{"id":"99","sumGross":"-10.00"}}}'),
            ], $history),
            static fn (): null => null,
            static fn (): bool => true,
        );
        $whmcs = new WhmcsGateway(
            $config,
            $this->localApi($invoiceDate, $taxRate),
        );
        $handler = new CorrectionJobHandler(
            $service,
            $whmcs,
            new MappingRepository(),
            new JobRepository(),
            $config,
            fn (): TaxPolicy => $this->taxPolicy(),
        );

        $outcome = $handler(
            (object) [
                'invoice_id' => 42,
                'job_id' => 1,
                'checkpoint' => 'queued',
                'attempts' => 1,
                'candidate_json' => json_encode([
                    'whmcsAccountId' => 11,
                    'request' => [
                        'kind' => 'refund',
                        'whmcsRefundTransactionId' => 'WHMCS-ACCOUNT:11',
                        'invoiceId' => 42,
                        'invoiceNumber' => 'INV-42',
                        'originalVoucherId' => '88',
                        'contactId' => '42',
                        'refundAmount' => '10.00',
                        'currency' => 'EUR',
                        // The refund always occurs in 2026. Only the original
                        // invoice date may select the small-business profile.
                        'voucherDate' => '2026-07-10',
                    ],
                    'positions' => [[
                        'description' => 'Synthetic refund allocation',
                        'amount' => '10.00',
                        'taxRate' => $taxRate,
                        'net' => false,
                    ]],
                ], JSON_THROW_ON_ERROR),
            ],
            static fn (): bool => true,
        );

        self::assertSame('succeeded', $outcome->status);
        self::assertCount(3, $history);
        self::assertSame('POST', $history[2]['request']->getMethod());
        $payload = json_decode((string) $history[2]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((int) $expectedRule, $payload['voucher']['taxRule']['id']);
        self::assertSame($expectedAccount, $payload['voucherPosSave'][0]['accountDatev']['id']);
        self::assertSame((float) $taxRate, $payload['voucherPosSave'][0]['taxRate']);
    }

    /** @return iterable<string, array{string,string,string,int}> */
    public static function originalInvoiceDateProvider(): iterable
    {
        yield '2025 original remains Rule 11 after a 2026 refund' => ['2025-12-31', '0', '11', 500];
        yield '2026 original uses regular domestic Rule 1' => ['2026-01-01', '19', '1', 100];
    }

    public function testMalformedCandidateAfterCorrectionWriteRemainsAmbiguous(): void
    {
        $handler = (new ReflectionClass(CorrectionJobHandler::class))->newInstanceWithoutConstructor();

        $outcome = $handler(
            (object) [
                'checkpoint' => 'correction_voucher_write_requested',
                'candidate_json' => '{invalid',
                'sevdesk_id' => '8001',
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('correction_voucher_write_requested', $outcome->checkpoint);
        self::assertSame('8001', $outcome->sevdeskId);
        self::assertSame('invalid_correction_candidate', $outcome->errorCode);
    }

    public function testPermanentRemotePreflightFailureKeepsRiskyCorrectionCheckpoint(): void
    {
        $method = new ReflectionMethod(CorrectionJobHandler::class, 'preflightFailure');

        $outcome = $method->invoke(
            null,
            (object) [
                'checkpoint' => 'correction_voucher_write_requested',
                'sevdesk_id' => '8002',
            ],
            'Synthetic HTTP 422 preflight failure.',
            'correction_preflight_failed',
            422,
            'synthetic-uuid',
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('correction_voucher_write_requested', $outcome->checkpoint);
        self::assertSame('8002', $outcome->sevdeskId);
        self::assertSame(422, $outcome->httpStatus);
        self::assertSame('correction_preflight_failed', $outcome->errorCode);
    }

    #[DataProvider('transientPreflightCheckpointProvider')]
    public function testTransientPreflightRetryKeepsRiskyCorrectionCheckpoint(int $httpStatus): void
    {
        $method = new ReflectionMethod(CorrectionJobHandler::class, 'preflightResumeCheckpoint');

        self::assertSame(
            'correction_voucher_write_requested',
            $method->invoke(null, 'correction_voucher_write_requested'),
            'HTTP ' . $httpStatus . ' must retry the read-only recovery checkpoint.',
        );
    }

    /** @return iterable<string, array{int}> */
    public static function transientPreflightCheckpointProvider(): iterable
    {
        yield 'rate limited read' => [429];
        yield 'server read failure' => [503];
    }

    #[DataProvider('riskyCheckpointProvider')]
    public function testRiskyCorrectionCheckpointsRequireReadOnlyRecovery(string $checkpoint): void
    {
        self::assertTrue(CorrectionJobHandler::readOnlyRecoveryRequired($checkpoint));
    }

    /** @return iterable<string, array{string}> */
    public static function riskyCheckpointProvider(): iterable
    {
        yield 'legacy write requested' => ['correction_write_requested'];
        yield 'legacy created' => ['correction_created'];
        yield 'voucher write requested' => ['correction_voucher_write_requested'];
        yield 'voucher created' => ['correction_voucher_created'];
        yield 'mapping persisted' => ['correction_mapping_persisted'];
    }

    public function testFreshCorrectionDoesNotEnterReadOnlyRecovery(): void
    {
        self::assertFalse(CorrectionJobHandler::readOnlyRecoveryRequired('queued'));
    }

    /**
     * @param list<Response> $responses
     * @param list<array{request:RequestInterface}> $history
     */
    private function sevdeskClient(array $responses, array &$history): SevdeskClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(static function (callable $handler) use (&$history): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$history) {
                $history[] = ['request' => $request];

                return $handler($request, $options);
            };
        });

        return new SevdeskClient(new Client(['handler' => $stack]), 'test-token');
    }

    /** @return callable(string, array<string, mixed>): array<string, mixed> */
    private function localApi(string $invoiceDate, string $taxRate): callable
    {
        return static function (string $command) use ($invoiceDate, $taxRate): array {
            if ($command === 'GetInvoice') {
                return [
                    'result' => 'success',
                    'userid' => 7,
                    'invoicenum' => 'INV-42',
                    'date' => $invoiceDate,
                    'currencycode' => 'EUR',
                    'subtotal' => '100.00',
                    'tax' => $taxRate === '0' ? '0.00' : '19.00',
                    'tax2' => '0.00',
                    'total' => $taxRate === '0' ? '100.00' : '119.00',
                    'credit' => '0.00',
                    'taxrate' => $taxRate,
                    'taxrate2' => '0',
                    'items' => ['item' => [[
                        'id' => 1,
                        'description' => 'Synthetic hosting service',
                        'amount' => '100.00',
                        'taxed' => true,
                        'type' => 'Hosting',
                        'relid' => 1,
                    ]]],
                ];
            }

            return [
                'result' => 'success',
                'client' => [
                    'id' => 7,
                    'firstname' => 'Synthetic',
                    'lastname' => 'Customer',
                    'email' => 'synthetic@example.invalid',
                    'country' => 'DE',
                    'taxexempt' => false,
                    'currency_code' => 'EUR',
                    'customfields' => ['customfield' => [[
                        'id' => 123,
                        'value' => '42',
                    ]]],
                ],
            ];
        };
    }

    private function taxPolicy(): TaxPolicy
    {
        return new TaxPolicy(
            [
                'domestic' => ['accountDatev' => '100', 'taxRule' => '1'],
                'eu_b2b' => ['accountDatev' => '200', 'taxRule' => '3', 'confirmed' => false],
                'eu_b2c_domestic' => ['accountDatev' => '300', 'taxRule' => '1', 'confirmed' => false],
                'third_country' => ['accountDatev' => '400', 'taxRule' => '2', 'confirmed' => false],
                'small_business' => ['accountDatev' => '500', 'taxRule' => '11', 'confirmed' => true],
                'add_funds' => ['accountDatev' => '600', 'taxRule' => '1', 'confirmed' => false],
            ],
            TaxPolicy::EU_B2C_BLOCKED,
            [
                [
                    'accountDatevId' => 100,
                    'allowedReceiptTypes' => ['REVENUE'],
                    'allowedTaxRules' => [['id' => 1, 'taxRates' => ['NINETEEN']]],
                ],
                [
                    'accountDatevId' => 500,
                    'allowedReceiptTypes' => ['REVENUE'],
                    'allowedTaxRules' => [['id' => 11, 'taxRates' => ['ZERO']]],
                ],
            ],
        );
    }
}
