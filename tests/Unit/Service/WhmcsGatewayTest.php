<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

final class WhmcsGatewayTest extends TestCase
{
    public function testEmailPreSendBinaryAttachmentsRemainUnavailableOnTargetWhmcs(): void
    {
        self::assertFalse(WhmcsGateway::versionSupportsEmailPreSendAttachments('8.13.4'));
        self::assertFalse(WhmcsGateway::versionSupportsEmailPreSendAttachments('8.13.4-release.1'));
        self::assertFalse(WhmcsGateway::versionSupportsEmailPreSendAttachments('9.0.0-rc.1'));
        self::assertTrue(WhmcsGateway::versionSupportsEmailPreSendAttachments('9.0.0'));
        self::assertTrue(WhmcsGateway::versionSupportsEmailPreSendAttachments('9.0.0-release.1'));
    }

    public function testRuntimeEmailAttachmentCapabilityUsesTheDetectedWhmcsVersion(): void
    {
        $target = new WhmcsGateway(new Config(), static fn (): array => [], static fn (): string => '8.13.4');
        $future = new WhmcsGateway(new Config(), static fn (): array => [], static fn (): string => '9.0.0');

        self::assertFalse($target->supportsEmailPreSendAttachments());
        self::assertTrue($future->supportsEmailPreSendAttachments());
    }

    public function testEffectiveInvoiceNumberUsesStoredNumberOrImmutableIdWithoutWriting(): void
    {
        self::assertSame('INV-42', WhmcsGateway::effectiveInvoiceNumber(42, ' INV-42 '));
        self::assertSame('42', WhmcsGateway::effectiveInvoiceNumber(42, ''));
        self::assertSame('42', WhmcsGateway::effectiveInvoiceNumber(42, '   '));
        self::assertSame('42', WhmcsGateway::effectiveInvoiceNumber(42, null));
    }

    public function testDocumentGrossAddsAppliedCreditToTheDirectCashAmount(): void
    {
        self::assertSame(
            '119.00',
            WhmcsGateway::documentGrossTotal('100.00', '19.00', '0.00', '99.00', '20.00'),
        );
        self::assertSame(
            '119.00',
            WhmcsGateway::documentGrossTotal('100.00', '19.00', '0.00', '0.00', '119.00'),
        );
        self::assertSame(
            '0.10',
            WhmcsGateway::documentGrossTotal('0.10', '0.00', '0.00', '0.10', '0.00'),
        );
    }

    public function testDocumentGrossRejectsAnInconsistentWhmcsHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subtotal + tax + tax2 = total + credit');

        WhmcsGateway::documentGrossTotal('100.00', '19.00', '0.00', '119.00', '20.00');
    }

    public function testInvoiceSnapshotUsesTheReadOnlyIdFallbackForAnEmptyLegacyNumber(): void
    {
        $commands = [];
        $gateway = new WhmcsGateway(
            new Config(),
            static function (string $command, array $parameters) use (&$commands): array {
                $commands[] = $command;
                if ($command === 'GetClientsDetails') {
                    return [
                        'result' => 'success',
                        'client' => ['id' => 7, 'currency_code' => 'EUR'],
                    ];
                }

                return [
                    'result' => 'success',
                    'userid' => 7,
                    'invoicenum' => '',
                    'date' => '2026-07-10',
                    'currencycode' => 'EUR',
                    'subtotal' => '100.00',
                    'tax' => '19.00',
                    'tax2' => '0.00',
                    'total' => '119.00',
                    'credit' => '0.00',
                    'taxrate' => '19.00',
                    'taxrate2' => '0.00',
                    'items' => ['item' => [[
                        'description' => 'Synthetic service',
                        'amount' => '100.00',
                        'taxed' => true,
                    ]]],
                ];
            },
        );

        $snapshot = $gateway->invoiceSnapshot(42);

        self::assertSame('42', $snapshot->invoiceNumber);
        self::assertSame(
            ['GetInvoice', 'GetClientsDetails'],
            $commands,
            'Resolving the effective number must not call a WHMCS write command.',
        );
    }

    public function testInvoiceSnapshotNormalizesMatchedPromoHostingIntoADiscount(): void
    {
        $gateway = new WhmcsGateway(
            new Config(),
            static function (string $command): array {
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
                    'date' => '2025-07-10',
                    'currencycode' => 'EUR',
                    'subtotal' => '80.00',
                    'tax' => '0.00',
                    'tax2' => '0.00',
                    'total' => '80.00',
                    'credit' => '0.00',
                    'taxrate' => '0.00',
                    'taxrate2' => '0.00',
                    'items' => ['item' => [
                        [
                            'type' => 'Hosting',
                            'relid' => 99,
                            'description' => 'Synthetic service',
                            'amount' => '100.00',
                            'taxed' => false,
                        ],
                        [
                            'type' => 'PromoHosting',
                            'relid' => 99,
                            'description' => 'Synthetic promotion',
                            'amount' => '-20.00',
                            'taxed' => false,
                        ],
                    ]],
                ];
            },
        );

        $snapshot = $gateway->invoiceSnapshot(42);

        self::assertCount(1, $snapshot->lineItems);
        self::assertCount(1, $snapshot->discounts);
        self::assertSame(8_000, $snapshot->calculatedDocumentGrossMinorUnits());
        self::assertSame(8_000, $snapshot->totalMinorUnits());
    }

    public function testInvoiceSnapshotUsesTotalAsDirectCashAndAddsCreditForTheDocument(): void
    {
        $gateway = new WhmcsGateway(
            new Config(),
            static function (string $command): array {
                if ($command === 'GetClientsDetails') {
                    return [
                        'result' => 'success',
                        'client' => ['id' => 7, 'currency_code' => 'EUR'],
                    ];
                }

                return [
                    'result' => 'success',
                    'userid' => 7,
                    'invoicenum' => 'SYN-43',
                    'date' => '2025-07-10',
                    'currencycode' => 'EUR',
                    'subtotal' => '100.00',
                    'tax' => '19.00',
                    'tax2' => '0.00',
                    'total' => '99.00',
                    'credit' => '20.00',
                    'taxrate' => '19.00',
                    'taxrate2' => '0.00',
                    'items' => ['item' => [[
                        'description' => 'Synthetic service',
                        'amount' => '100.00',
                        'taxed' => true,
                    ]]],
                ];
            },
        );

        $snapshot = $gateway->invoiceSnapshot(43);

        self::assertSame(11_900, $snapshot->totalMinorUnits());
        self::assertSame(2_000, $snapshot->appliedCreditMinorUnits());
        self::assertSame(9_900, $snapshot->directCashMinorUnits());
    }

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

    public function testInvoiceExportContractFingerprintCoversPayloadAndTaxInputsWithoutPersistingPii(): void
    {
        $baseInvoice = [
            'result' => 'success',
            'userid' => 7,
            'status' => 'Paid',
            'invoicenum' => 'SYN-42',
            'date' => '2025-12-31',
            'currencycode' => 'EUR',
            'subtotal' => '100.00',
            'credit' => '0.00',
            'tax' => '0.00',
            'tax2' => '0.00',
            'total' => '100.00',
            'taxrate' => '0.00',
            'taxrate2' => '0.00',
            'items' => ['item' => [[
                'id' => 501,
                'type' => 'Hosting',
                'relid' => 99,
                'description' => 'Synthetic service',
                'amount' => '100.00',
                'taxed' => false,
            ]]],
        ];
        $baseClient = [
            'result' => 'success',
            'client' => [
                'id' => 7,
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
            ],
        ];
        $fingerprint = static function (array $invoice, array $client): string {
            $gateway = new WhmcsGateway(
                new Config(),
                static fn (string $command): array =>
                    $command === 'GetClientsDetails' ? $client : $invoice,
            );

            return $gateway->invoiceExportContract(42)['fingerprint'];
        };
        $expected = $fingerprint($baseInvoice, $baseClient);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected);
        self::assertStringNotContainsString('Synthetic', $expected);

        $mutations = [
            'number' => static function (array &$invoice): void {
                $invoice['invoicenum'] = 'SYN-43';
            },
            'date' => static function (array &$invoice): void {
                $invoice['date'] = '2026-01-01';
            },
            'tax rate' => static function (array &$invoice): void {
                $invoice['taxrate'] = '19.00';
            },
            'item taxed' => static function (array &$invoice): void {
                $invoice['items']['item'][0]['taxed'] = true;
            },
            'description' => static function (array &$invoice): void {
                $invoice['items']['item'][0]['description'] = 'Changed service';
            },
            'country' => static function (array &$invoice, array &$client): void {
                $client['client']['countrycode'] = 'AT';
            },
            'tax exempt' => static function (array &$invoice, array &$client): void {
                $client['client']['taxexempt'] = true;
            },
        ];
        foreach ($mutations as $label => $mutate) {
            $invoice = $baseInvoice;
            $client = $baseClient;
            $mutate($invoice, $client);
            self::assertNotSame($expected, $fingerprint($invoice, $client), $label);
        }

        $formattedInvoice = $baseInvoice;
        $formattedInvoice['subtotal'] = '0100.0000';
        $formattedInvoice['total'] = '0100.0';
        self::assertSame($expected, $fingerprint($formattedInvoice, $baseClient));
    }

    public function testThemeAdapterManifestContractIsExactAndThemeBound(): void
    {
        $manifest = [
            'module' => 'sevdesk',
            'contractVersion' => 1,
            'theme' => 'twenty-one',
            'partial' => 'sevdesk-invoice-authority.tpl',
            'contract' => ['authority', 'state', 'invoiceNumber', 'downloadUrl'],
        ];

        self::assertTrue(WhmcsGateway::validThemeAdapterManifest($manifest, 'twenty-one'));
        self::assertFalse(WhmcsGateway::validThemeAdapterManifest($manifest, 'custom-theme'));
        $manifest['theme'] = '*';
        self::assertTrue(WhmcsGateway::validThemeAdapterManifest($manifest, 'custom-theme'));
        $manifest['contract'][] = 'remoteId';
        self::assertFalse(WhmcsGateway::validThemeAdapterManifest($manifest, 'custom-theme'));
        $manifest['contract'] = ['authority', 'state', 'invoiceNumber', 'authority'];
        self::assertFalse(WhmcsGateway::validThemeAdapterManifest($manifest, 'custom-theme'));
    }

    public function testThemeAdapterManifestRejectsUnsafePartialPath(): void
    {
        self::assertFalse(WhmcsGateway::validThemeAdapterManifest([
            'module' => 'sevdesk',
            'contractVersion' => 1,
            'theme' => '*',
            'partial' => '../viewinvoice.tpl',
            'contract' => ['authority', 'state', 'invoiceNumber', 'downloadUrl'],
        ], 'twenty-one'));
    }
}
