<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

final class WhmcsGatewayTest extends TestCase
{
    public function testClientUsesCanonicalNestedResponseShape(): void
    {
        $gateway = new WhmcsGateway(new Config(), static fn (string $command, array $parameters): array => [
            'result' => 'success',
            'client' => ['id' => 42, 'firstname' => 'Synthetic'],
            'firstname' => 'Deprecated top-level value',
        ]);

        $client = $gateway->client(42);

        self::assertSame(42, $client['id']);
        self::assertSame('Synthetic', $client['firstname']);
    }

    public function testMalformedLocalApiResponseIsNeverTreatedAsSuccess(): void
    {
        $gateway = new WhmcsGateway(new Config(), static fn (string $command, array $parameters): array => []);

        $this->expectException(RuntimeException::class);
        $gateway->invoice(42);
    }

    public function testTwoSimultaneousWhmcsTaxesRequireManualReview(): void
    {
        $gateway = new WhmcsGateway(new Config(), static function (string $command, array $parameters): array {
            if ($command === 'GetClientsDetails') {
                return [
                    'result' => 'success',
                    'client' => ['id' => 7, 'currency_code' => 'EUR'],
                ];
            }

            return [
                'result' => 'success',
                'userid' => 7,
                'invoicenum' => 'SYN-42',
                'date' => '2026-07-10',
                'currencycode' => 'EUR',
                'total' => '119.00',
                'credit' => '0.00',
                'taxrate' => '19.00',
                'taxrate2' => '7.00',
                'items' => ['item' => [[
                    'description' => 'Synthetic service',
                    'amount' => '100.00',
                    'taxed' => true,
                ]]],
            ];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('two simultaneous WHMCS taxes');
        $gateway->invoiceSnapshot(42);
    }
}
