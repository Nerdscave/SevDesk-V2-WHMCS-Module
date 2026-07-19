<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ApplicationCorrectionPersistenceTest extends TestCase
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

        Capsule::schema()->dropIfExists(Migrator::ITEMS_TABLE);
        Capsule::schema()->create(Migrator::ITEMS_TABLE, static function ($table): void {
            $table->increments('id');
            $table->string('action', 64);
            $table->string('transaction_reference', 191)->nullable();
            $table->string('status', 32);
            $table->string('sevdesk_id', 255)->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function testApplicationWiringPersistsCorrectionRemoteIdWithCompareAndSet(): void
    {
        $itemId = (int) Capsule::table(Migrator::ITEMS_TABLE)->insertGetId([
            'action' => 'correction_voucher',
            'transaction_reference' => 'correction:abc',
            'status' => 'running',
            'sevdesk_id' => null,
        ]);
        $persist = $this->persistCallback();

        self::assertTrue($persist('correction:abc', '88'));
        self::assertSame('88', $this->remoteId($itemId));

        self::assertTrue($persist('correction:abc', '88'));
        self::assertSame('88', $this->remoteId($itemId));

        self::assertFalse($persist('correction:abc', '99'));
        self::assertSame('88', $this->remoteId($itemId));

        Capsule::table(Migrator::ITEMS_TABLE)->where('id', $itemId)->update(['sevdesk_id' => '']);
        self::assertTrue($persist('correction:abc', '77'));
        self::assertSame('77', $this->remoteId($itemId));
    }

    public function testApplicationWiringDoesNotPersistWithoutAnActiveCorrectionItem(): void
    {
        Capsule::table(Migrator::ITEMS_TABLE)->insert([
            'action' => 'correction_voucher',
            'transaction_reference' => 'correction:done',
            'status' => 'succeeded',
            'sevdesk_id' => null,
        ]);

        self::assertFalse(($this->persistCallback())('correction:done', '88'));
        self::assertNull(Capsule::table(Migrator::ITEMS_TABLE)->value('sevdesk_id'));
    }

    private function persistCallback(): Closure
    {
        $application = new Application();
        $clientProperty = new ReflectionProperty(Application::class, 'client');
        $clientProperty->setValue(
            $application,
            new SevdeskClient(
                new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
                'test-token',
            ),
        );

        $service = $application->corrections();
        $persistProperty = new ReflectionProperty(CorrectionService::class, 'persistReference');
        $persist = $persistProperty->getValue($service);
        self::assertInstanceOf(Closure::class, $persist);

        return $persist;
    }

    private function remoteId(int $itemId): ?string
    {
        $value = Capsule::table(Migrator::ITEMS_TABLE)->where('id', $itemId)->value('sevdesk_id');

        return $value === null ? null : (string) $value;
    }
}
