<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Service;

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class WhmcsGatewayContactConfigurationTest extends TestCase
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

        Capsule::schema()->dropIfExists('tblcustomfieldsvalues');
        Capsule::schema()->dropIfExists('tblclients');
        Capsule::schema()->dropIfExists('tblcustomfields');
        Capsule::schema()->dropIfExists('tbladdonmodules');
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
            $table->string('fieldname')->default('sevdesk ID');
            $table->string('fieldtype')->default('text');
            $table->boolean('adminonly')->default(false);
        });
        Capsule::schema()->create('tblclients', static function ($table): void {
            $table->increments('id');
        });
        Capsule::schema()->create('tblcustomfieldsvalues', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('fieldid');
            $table->unsignedInteger('relid');
            $table->text('value')->nullable();
            $table->index(['fieldid', 'relid']);
        });
    }

    public function testMissingContactFieldConfigurationFailsBeforeLoadingTheClient(): void
    {
        $localApiCalls = 0;
        $gateway = new WhmcsGateway(
            new Config(),
            static function () use (&$localApiCalls): array {
                ++$localApiCalls;

                return ['result' => 'success', 'client' => ['id' => 7]];
            },
        );

        try {
            $gateway->contactData(7);
            self::fail('A missing contact field must block contact resolution.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('contact ID field is missing', $error->getMessage());
        }

        self::assertSame(0, $localApiCalls);
    }

    public function testDeletedOrNonClientContactFieldFailsBeforeLoadingTheClient(): void
    {
        Capsule::table('tblcustomfields')->insert(['id' => 9, 'type' => 'product']);
        (new Config())->set('custom_field_id', 9);
        $localApiCalls = 0;
        $gateway = new WhmcsGateway(
            new Config(),
            static function () use (&$localApiCalls): array {
                ++$localApiCalls;

                return ['result' => 'success', 'client' => ['id' => 7]];
            },
        );

        $this->expectException(RuntimeException::class);
        try {
            $gateway->contactData(7);
        } finally {
            self::assertSame(0, $localApiCalls);
        }
    }

    public function testValidClientContactFieldAllowsTheConfiguredIdToBeRead(): void
    {
        Capsule::table('tblcustomfields')->insert(['id' => 9, 'type' => 'client']);
        (new Config())->set('custom_field_id', 9);
        $gateway = new WhmcsGateway(new Config(), static fn (): array => [
            'result' => 'success',
            'client' => [
                'id' => 7,
                'firstname' => 'Synthetic',
                'lastname' => 'Customer',
                'country' => 'DE',
                'customfields' => ['customfield' => [['id' => 9, 'value' => '55123']]],
            ],
        ]);

        self::assertSame('55123', $gateway->contactData(7)->sevdeskContactId);
    }

    public function testConcurrentExistingContactIdIsNeverOverwritten(): void
    {
        Capsule::table('tblcustomfields')->insert(['id' => 9, 'type' => 'client']);
        Capsule::table('tblclients')->insert(['id' => 7]);
        Capsule::table('tblcustomfieldsvalues')->insert([
            'fieldid' => 9,
            'relid' => 7,
            'value' => '8877',
        ]);
        (new Config())->set('custom_field_id', 9);
        $commands = [];
        $gateway = new WhmcsGateway(new Config(), static function (string $command) use (&$commands): array {
            $commands[] = $command;

            return ['result' => 'success'];
        });

        try {
            $gateway->storeContactId(7, '55123');
            self::fail('A conflicting contact link must block the worker.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('another sevdesk contact', $error->getMessage());
        }

        self::assertSame([], $commands);
        self::assertSame('8877', Capsule::table('tblcustomfieldsvalues')->value('value'));
    }

    public function testSameContactIdIsAnIdempotentStoreWithoutUpdateClient(): void
    {
        Capsule::table('tblcustomfields')->insert(['id' => 9, 'type' => 'client']);
        Capsule::table('tblclients')->insert(['id' => 7]);
        Capsule::table('tblcustomfieldsvalues')->insert([
            'fieldid' => 9,
            'relid' => 7,
            'value' => '55123',
        ]);
        (new Config())->set('custom_field_id', 9);
        $commands = [];
        $gateway = new WhmcsGateway(new Config(), static function (string $command) use (&$commands): array {
            $commands[] = $command;

            return ['result' => 'success'];
        });

        $gateway->storeContactId(7, '55123');

        self::assertSame([], $commands);
        self::assertSame('55123', Capsule::table('tblcustomfieldsvalues')->value('value'));
    }

    public function testEmptyContactFieldIsFilledWithoutCallingUpdateClient(): void
    {
        Capsule::table('tblcustomfields')->insert(['id' => 9, 'type' => 'client']);
        Capsule::table('tblclients')->insert(['id' => 7]);
        Capsule::table('tblcustomfieldsvalues')->insert([
            'fieldid' => 9,
            'relid' => 7,
            'value' => '',
        ]);
        (new Config())->set('custom_field_id', 9);
        $commands = [];
        $gateway = new WhmcsGateway(new Config(), static function (string $command) use (&$commands): array {
            $commands[] = $command;

            return ['result' => 'success'];
        });

        $gateway->storeContactId(7, '55123');

        self::assertSame([], $commands);
        self::assertSame('55123', Capsule::table('tblcustomfieldsvalues')->value('value'));
    }

    public function testMissingContactFieldValueRowIsCreatedOnce(): void
    {
        Capsule::table('tblcustomfields')->insert(['id' => 9, 'type' => 'client']);
        Capsule::table('tblclients')->insert(['id' => 7]);
        (new Config())->set('custom_field_id', 9);
        $gateway = new WhmcsGateway(new Config(), static fn (): array => ['result' => 'success']);

        $gateway->storeContactId(7, '55123');

        self::assertSame(1, Capsule::table('tblcustomfieldsvalues')->count());
        self::assertSame('55123', Capsule::table('tblcustomfieldsvalues')->value('value'));
    }

    public function testDuplicateContactFieldRowsBlockTheLink(): void
    {
        Capsule::table('tblcustomfields')->insert(['id' => 9, 'type' => 'client']);
        Capsule::table('tblclients')->insert(['id' => 7]);
        Capsule::table('tblcustomfieldsvalues')->insert([
            ['fieldid' => 9, 'relid' => 7, 'value' => ''],
            ['fieldid' => 9, 'relid' => 7, 'value' => ''],
        ]);
        (new Config())->set('custom_field_id', 9);
        $gateway = new WhmcsGateway(new Config(), static fn (): array => ['result' => 'success']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate sevdesk contact field rows');

        $gateway->storeContactId(7, '55123');
    }

    public function testOnlyAnAdminClientTickboxCanBeUsedForEInvoiceOptIn(): void
    {
        Capsule::table('tblcustomfields')->insert([
            ['id' => 10, 'type' => 'client', 'fieldname' => 'Admin opt-in', 'fieldtype' => 'tickbox', 'adminonly' => 1],
            ['id' => 11, 'type' => 'client', 'fieldname' => 'Public opt-in', 'fieldtype' => 'tickbox', 'adminonly' => 0],
            ['id' => 12, 'type' => 'client', 'fieldname' => 'Admin text', 'fieldtype' => 'text', 'adminonly' => 1],
        ]);
        $gateway = new WhmcsGateway(new Config(), static fn (): array => ['result' => 'success']);

        self::assertSame([['id' => 10, 'label' => 'Admin opt-in']], $gateway->eInvoiceOptInFields());
        self::assertTrue($gateway->isEInvoiceOptInField(10));
        self::assertFalse($gateway->isEInvoiceOptInField(11));
        self::assertFalse($gateway->isEInvoiceOptInField(12));
    }

    public function testEInvoiceOptInReadsTheExistingFieldWithoutUpdatingTheClient(): void
    {
        Capsule::table('tblcustomfields')->insert([
            'id' => 10,
            'type' => 'client',
            'fieldname' => 'Admin opt-in',
            'fieldtype' => 'tickbox',
            'adminonly' => 1,
        ]);
        (new Config())->set('e_invoice_client_field_id', 10);
        $commands = [];
        $gateway = new WhmcsGateway(new Config(), static function (string $command) use (&$commands): array {
            $commands[] = $command;

            return [
                'result' => 'success',
                'client' => [
                    'id' => 7,
                    'customfields' => ['customfield' => [['id' => 10, 'value' => 'on']]],
                ],
            ];
        });

        self::assertTrue($gateway->eInvoiceOptedIn(7));
        self::assertSame(['GetClientsDetails'], $commands);
    }
}
