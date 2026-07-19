<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use ReflectionProperty;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class ApplicationCorrectionPersistenceTest extends MariaDbTestCase
{
    public function testApplicationCorrectionCallbackUsesIdempotentDatabaseCompareAndSet(): void
    {
        Migrator::up();
        $application = new Application();
        $clientProperty = new ReflectionProperty(Application::class, 'client');
        $clientProperty->setValue(
            $application,
            new SevdeskClient(
                new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
                'test-token',
            ),
        );
        $jobId = $application->jobs->create('refund_correction', [[
            'invoice_id' => 42,
            'action' => 'correction_voucher',
            'dedupe_key' => 'correction:test',
            'transaction_reference' => 'correction:test',
            'candidate' => [],
        ]]);
        $item = $application->jobs->items($jobId)[0];
        $persistProperty = new ReflectionProperty(CorrectionService::class, 'persistReference');
        $persist = $persistProperty->getValue($application->corrections());
        self::assertInstanceOf(Closure::class, $persist);

        self::assertTrue($persist('correction:test', '88'));
        self::assertSame('88', $this->remoteId((int) $item->id));
        self::assertTrue($persist('correction:test', '88'));
        self::assertFalse($persist('correction:test', '99'));
        self::assertSame('88', $this->remoteId((int) $item->id));
    }

    private function remoteId(int $itemId): string
    {
        return (string) Capsule::table(Migrator::ITEMS_TABLE)
            ->where('id', $itemId)
            ->value('sevdesk_id');
    }
}
