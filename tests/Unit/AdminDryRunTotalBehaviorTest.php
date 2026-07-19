<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Controllers\AdminController;
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
        foreach (['mod_sevdesk_job_items', 'mod_sevdesk', 'tblinvoiceitems', 'tbladdonmodules'] as $table) {
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
        });
        Capsule::schema()->create('tblinvoiceitems', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->string('type');
            $table->string('description');
            $table->decimal('amount', 18, 4);
            $table->unsignedTinyInteger('taxed');
        });
        Capsule::table('tblinvoiceitems')->insert([
            'invoiceid' => 10,
            'type' => 'Hosting',
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
}
