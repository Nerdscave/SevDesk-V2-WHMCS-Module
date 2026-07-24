<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Controllers\AdminController;
use WHMCS\Module\Addon\SevDesk\Service\ReferenceData;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsPaymentStructureService;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;
use WHMCS\Module\Addon\SevDesk\View;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class AdminDryRunTotalBehaviorTest extends TestCase
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
        foreach (
            [
                'mod_sevdesk_job_items',
                'mod_sevdesk',
                'tblaccounts',
                'tblinvoiceitems',
                'tblinvoices',
                'tbladdonmodules',
            ] as $table
        ) {
            Capsule::schema()->dropIfExists($table);
        }
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
            $table->string('sevdesk_id')->nullable();
        });
        Capsule::schema()->create('mod_sevdesk_job_items', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->nullable();
            $table->string('action');
            $table->string('status');
            $table->string('checkpoint')->nullable();
        });
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
            $table->string('description');
            $table->decimal('amount', 18, 4);
            $table->unsignedTinyInteger('taxed');
        });
        Capsule::schema()->create('tblaccounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->decimal('amountin', 18, 4)->default(0);
            $table->decimal('amountout', 18, 4)->default(0);
            $table->unsignedInteger('refundid')->default(0);
        });
        Capsule::table('tblinvoiceitems')->insert([
            'invoiceid' => 10,
            'type' => 'Hosting',
            'relid' => 42,
            'description' => 'Hosting',
            'amount' => '100.00',
            'taxed' => 1,
        ]);
        $GLOBALS['CONFIG']['TaxType'] = 'Exclusive';
    }

    public function testInvoiceOnlyPreviewRejectsAOneCentLineTotalDifference(): void
    {
        $application = new Application();
        $application->config->set('export_mode', 'invoice_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('invoice_canary_confirmed', true);
        $csrf = new Csrf();
        $controller = new AdminController(
            $application,
            new View($csrf),
            $csrf,
            'addonmodules.php?module=sevdesk',
        );

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $controller,
            [(object) [
                'id' => 10,
                'userid' => 20,
                'invoicenum' => 'RE-10',
                'date' => '2026-07-01',
                'datepaid' => '2026-07-02',
                'status' => 'Paid',
                'currencycode' => 'EUR',
                'subtotal' => '100.00',
                'tax' => '19.00',
                'tax2' => '0.00',
                'total' => '119.01',
                'credit' => '0.00',
                'taxrate' => '19.00',
                'taxrate2' => '0.00',
                'taxexempt' => '0',
                'tax_id' => '',
                'country' => 'DE',
                'companyname' => 'Example GmbH',
                'firstname' => 'Erika',
                'lastname' => 'Beispiel',
            ]],
        );

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['exportable']);
        self::assertSame('invoice_total_mismatch', $rows[0]['reason_code']);
    }

    public function testInvoiceOnlyPreviewAcceptsAnEmptyLegacyNumberThroughTheReadOnlyIdFallback(): void
    {
        $application = new Application();
        $application->config->set('export_mode', 'invoice_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('invoice_canary_confirmed', true);
        $csrf = new Csrf();
        $controller = new AdminController(
            $application,
            new View($csrf),
            $csrf,
            'addonmodules.php?module=sevdesk',
        );
        $invoice = (object) [
            'id' => 10,
            'userid' => 20,
            'invoicenum' => '',
            'date' => '2026-07-01',
            'datepaid' => '2026-07-02',
            'status' => 'Paid',
            'currencycode' => 'EUR',
            'subtotal' => '100.00',
            'tax' => '19.00',
            'tax2' => '0.00',
            'total' => '119.00',
            'credit' => '0.00',
            'taxrate' => '19.00',
            'taxrate2' => '0.00',
            'taxexempt' => '0',
            'tax_id' => '',
            'country' => 'DE',
            'companyname' => 'Example GmbH',
            'firstname' => 'Erika',
            'lastname' => 'Beispiel',
        ];

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $controller,
            [$invoice],
        );

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['exportable']);
        self::assertSame('invoice', $rows[0]['document_type']);
        self::assertSame('10', $rows[0]['invoicenum']);
        self::assertSame('', $invoice->invoicenum, 'The preview must not backfill the WHMCS row.');
    }

    public function testPreviewAppliesTheSmallBusinessProfileOnlyThroughTheConfiguredCutoff(): void
    {
        Capsule::table('tblinvoiceitems')->where('invoiceid', 10)->update([
            'amount' => '100.00',
        ]);
        $application = new Application();
        $application->config->set('export_mode', 'invoice_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('invoice_canary_confirmed', true);
        $application->config->set('small_business_invoice_canary_confirmed', true);
        $application->config->set('smallBusinessOwner', true);
        $application->config->set('small_business_confirmed', true);
        $application->config->set('small_business_until', '31-12-2025');
        $this->setInvoiceRuleElevenGuidance($application, true);
        $controller = $this->controller($application);
        $invoice = $this->zeroTaxInvoice('2025-12-31');

        $rows2025 = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $controller,
            [$invoice],
        );
        $invoice->date = '2026-01-01';
        $rows2026 = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $controller,
            [$invoice],
        );

        self::assertTrue($rows2025[0]['exportable']);
        self::assertSame('small_business', $rows2025[0]['tax_profile']);
        self::assertSame('11', $rows2025[0]['tax_rule']);
        self::assertTrue($rows2026[0]['exportable']);
        self::assertSame('domestic', $rows2026[0]['tax_profile']);
        self::assertSame('1', $rows2026[0]['tax_rule']);
    }

    public function testPreviewDoesNotLetAddFundsBypassTheSmallBusinessPeriod(): void
    {
        Capsule::table('tblinvoiceitems')->where('invoiceid', 10)->update([
            'type' => 'AddFunds',
            'amount' => '100.00',
            'taxed' => 0,
        ]);
        $application = new Application();
        $application->config->set('export_mode', 'invoice_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('invoice_canary_confirmed', true);
        $application->config->set('small_business_invoice_canary_confirmed', true);
        $application->config->set('smallBusinessOwner', true);
        $application->config->set('small_business_confirmed', true);
        $application->config->set('small_business_until', '31-12-2025');
        $application->config->set('add_funds_confirmed', true);
        $application->config->set('taxRuleCredit', '1');
        $this->setInvoiceRuleElevenGuidance($application, true);
        $controller = $this->controller($application);
        $invoice = $this->zeroTaxInvoice('2025-12-31');

        $rows2025 = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $controller,
            [$invoice],
        );
        $invoice->date = '2026-01-01';
        $rows2026 = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $controller,
            [$invoice],
        );

        self::assertTrue($rows2025[0]['exportable']);
        self::assertSame('small_business', $rows2025[0]['tax_profile']);
        self::assertSame('11', $rows2025[0]['tax_rule']);
        self::assertTrue($rows2026[0]['exportable']);
        self::assertSame('add_funds', $rows2026[0]['tax_profile']);
        self::assertSame('1', $rows2026[0]['tax_rule']);
    }

    public function testPreviewBlocksRuleElevenWhenItsCanaryIsNotConfirmed(): void
    {
        $application = new Application();
        $application->config->set('export_mode', 'invoice_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('invoice_canary_confirmed', true);
        $application->config->set('smallBusinessOwner', true);
        $application->config->set('small_business_confirmed', true);
        $application->config->set('small_business_until', '31-12-2025');

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $this->controller($application),
            [$this->zeroTaxInvoice('2025-12-31')],
        );

        self::assertFalse($rows[0]['exportable']);
        self::assertSame(
            'small_business_invoice_canary_not_confirmed',
            $rows[0]['reason_code'],
        );
    }

    public function testPreviewBlocksRuleElevenWhenRevenueGuidanceDoesNotSupportIt(): void
    {
        $application = new Application();
        $application->config->set('export_mode', 'invoice_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('invoice_canary_confirmed', true);
        $application->config->set('small_business_invoice_canary_confirmed', true);
        $application->config->set('smallBusinessOwner', true);
        $application->config->set('small_business_confirmed', true);
        $application->config->set('small_business_until', '31-12-2025');
        $this->setInvoiceRuleElevenGuidance($application, false);

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $this->controller($application),
            [$this->zeroTaxInvoice('2025-12-31')],
        );

        self::assertFalse($rows[0]['exportable']);
        self::assertSame('invoice_rule11_tenant_scope_unsupported', $rows[0]['reason_code']);
    }

    public function testPreviewBlocksAnInvalidPersistedSmallBusinessCutoff(): void
    {
        Capsule::table('tblinvoiceitems')->where('invoiceid', 10)->update([
            'amount' => '100.00',
        ]);
        $application = new Application();
        $application->config->set('export_mode', 'invoice_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('invoice_canary_confirmed', true);
        $application->config->set('smallBusinessOwner', true);
        $application->config->set('small_business_confirmed', true);
        $application->config->set('small_business_until', '31-02-2025');

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $this->controller($application),
            [$this->zeroTaxInvoice('2025-01-01')],
        );

        self::assertFalse($rows[0]['exportable']);
        self::assertSame('small_business_period_invalid', $rows[0]['reason_code']);
    }

    public function testVoucherPreviewOffersTheExplicitFullGrossConfirmationForOrdinaryCredit(): void
    {
        $this->insertPaymentInvoice(10, subtotal: '100.00', credit: '20.00', total: '80.00');
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => 10,
            'amountin' => '80.00',
            'amountout' => '0.00',
            'refundid' => 0,
        ]);
        $invoice = $this->zeroTaxInvoice('2025-07-01');
        $invoice->total = '80.00';
        $invoice->credit = '20.00';

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $this->controller($this->voucherApplication()),
            [$invoice],
        );

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['exportable']);
        self::assertSame('credit_applied_requires_review', $rows[0]['reason_code']);
        self::assertSame(
            WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW,
            $rows[0]['payment_structure'],
        );
        self::assertSame('100,00', $rows[0]['gross_formatted']);
        self::assertSame('20,00', $rows[0]['credit_formatted']);
        self::assertSame('80,00', $rows[0]['payable_formatted']);
        self::assertTrue($rows[0]['credit_voucher_confirmation_allowed']);
    }

    public function testVoucherPreviewShowsFullCreditAsGrossWithoutDirectCash(): void
    {
        $this->insertPaymentInvoice(10, subtotal: '100.00', credit: '100.00', total: '0.00');
        $invoice = $this->zeroTaxInvoice('2025-07-01');
        $invoice->total = '0.00';
        $invoice->credit = '100.00';

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $this->controller($this->voucherApplication()),
            [$invoice],
        );

        self::assertFalse($rows[0]['exportable']);
        self::assertSame('credit_applied_requires_review', $rows[0]['reason_code']);
        self::assertSame('100,00', $rows[0]['gross_formatted']);
        self::assertSame('100,00', $rows[0]['credit_formatted']);
        self::assertSame('0,00', $rows[0]['payable_formatted']);
        self::assertTrue($rows[0]['credit_voucher_confirmation_allowed']);
    }

    public function testVoucherPreviewNeverOffersTheCreditConfirmationForAMalformedContainer(): void
    {
        $this->insertPaymentInvoice(100, subtotal: '25.00', credit: '0.00', total: '25.00');
        Capsule::table('tblinvoiceitems')->insert([
            [
                'invoiceid' => 100,
                'type' => 'Invoice',
                'relid' => 10,
                'description' => 'Synthetic invoice reference',
                'amount' => '20.00',
                'taxed' => 0,
            ],
            [
                'invoiceid' => 100,
                'type' => 'Hosting',
                'relid' => 99,
                'description' => 'Unexpected revenue item',
                'amount' => '5.00',
                'taxed' => 0,
            ],
        ]);
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => 100,
            'amountin' => '25.00',
            'amountout' => '0.00',
            'refundid' => 0,
        ]);
        $invoice = $this->zeroTaxInvoice('2025-07-01');
        $invoice->id = 100;
        $invoice->invoicenum = 'MASS-100';
        $invoice->total = '25.00';

        $rows = (new ReflectionMethod(AdminController::class, 'decorateDryRun'))->invoke(
            $this->controller($this->voucherApplication()),
            [$invoice],
        );

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['exportable']);
        self::assertSame('mixed_invoice_reference_items', $rows[0]['reason_code']);
        self::assertSame(
            WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW,
            $rows[0]['payment_structure'],
        );
        self::assertFalse($rows[0]['credit_voucher_confirmation_allowed']);
    }

    private function controller(Application $application): AdminController
    {
        $csrf = new Csrf();

        return new AdminController(
            $application,
            new View($csrf),
            $csrf,
            'addonmodules.php?module=sevdesk',
        );
    }

    private function voucherApplication(): Application
    {
        $application = new Application();
        $application->config->set('export_mode', 'voucher_only');
        $application->config->set('document_authority', 'whmcs');
        $application->config->set('oss_profile', 'blocked');
        $application->config->set('eu_b2c_mode', 'blocked');
        $client = new SevdeskClient(
            new Client([
                'handler' => HandlerStack::create(new MockHandler([
                    new Response(200, [], '[]'),
                ])),
            ]),
            'synthetic-token',
        );
        (new ReflectionProperty(Application::class, 'referenceData'))->setValue(
            $application,
            new ReferenceData($client),
        );

        return $application;
    }

    private function setInvoiceRuleElevenGuidance(Application $application, bool $supported): void
    {
        $guidance = json_encode([
            'objects' => [[
                'accountDatevId' => 500,
                'allowedReceiptTypes' => [$supported ? 'REVENUE' : 'REGULAR'],
                'allowedTaxRules' => [[
                    'id' => 11,
                    'taxRates' => ['ZERO'],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $client = new SevdeskClient(
            new Client([
                'handler' => HandlerStack::create(new MockHandler([
                    new Response(200, [], $guidance),
                    new Response(200, [], $guidance),
                ])),
            ]),
            'synthetic-token',
        );
        (new ReflectionProperty(Application::class, 'referenceData'))->setValue(
            $application,
            new ReferenceData($client),
        );
    }

    private function insertPaymentInvoice(
        int $invoiceId,
        string $subtotal,
        string $credit,
        string $total,
    ): void {
        Capsule::table('tblinvoices')->insert([
            'id' => $invoiceId,
            'userid' => 20,
            'status' => 'Paid',
            'subtotal' => $subtotal,
            'credit' => $credit,
            'tax' => '0.00',
            'tax2' => '0.00',
            'total' => $total,
        ]);
    }

    private function zeroTaxInvoice(string $date): object
    {
        return (object) [
            'id' => 10,
            'userid' => 20,
            'invoicenum' => 'RE-10',
            'date' => $date,
            'datepaid' => $date,
            'status' => 'Paid',
            'currencycode' => 'EUR',
            'subtotal' => '100.00',
            'tax' => '0.00',
            'tax2' => '0.00',
            'total' => '100.00',
            'credit' => '0.00',
            'taxrate' => '0.00',
            'taxrate2' => '0.00',
            'taxexempt' => '0',
            'tax_id' => '',
            'country' => 'DE',
            'companyname' => 'Example GmbH',
            'firstname' => 'Erika',
            'lastname' => 'Beispiel',
        ];
    }
}
