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
}
