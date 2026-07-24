<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use ReflectionClass;
use ReflectionMethod;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Jobs\BookingJobHandler;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\BookingService;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class WhmcsRuntimeCompatibilityTest extends MariaDbTestCase
{
    public function testInvoiceQueriesUseTheClientCurrencyOnTheWhmcsSchema(): void
    {
        $this->insertInvoiceWithClientCurrency();
        $gateway = new WhmcsGateway(new Config());

        $invoices = $gateway->invoicesBetween(
            new DateTimeImmutable('2026-07-01'),
            new DateTimeImmutable('2026-07-31'),
            true,
        );
        $dryRunInvoice = $gateway->invoiceForDryRun(42);

        self::assertCount(1, $invoices);
        self::assertSame(1, (int) $invoices[0]->currency);
        self::assertSame('EUR', $invoices[0]->currencycode);
        self::assertNotNull($dryRunInvoice);
        self::assertSame(1, (int) $dryRunInvoice->currency);
        self::assertSame('EUR', $dryRunInvoice->currencycode);
    }

    public function testBookingFallsBackToClientCurrencyForZeroOrUnknownTransactionCurrency(): void
    {
        $this->insertInvoiceWithClientCurrency();
        $this->insertAccount(0);

        self::assertNull($this->validatePayment());

        Capsule::table('tblaccounts')->where('id', 81)->update(['currency' => 999]);

        self::assertNull($this->validatePayment());
    }

    public function testBookingStillBlocksAnExplicitlyDifferentTransactionCurrency(): void
    {
        $this->insertInvoiceWithClientCurrency();
        Capsule::table('tblcurrencies')->insert(['id' => 2, 'code' => 'USD']);
        $this->insertAccount(2);

        $outcome = $this->validatePayment();

        self::assertInstanceOf(JobOutcome::class, $outcome);
        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('whmcs_payment_changed', $outcome->errorCode);
    }

    public function testBookingAllowsAPositivePaymentWhileInvoiceIsStillUnpaid(): void
    {
        $this->insertInvoiceWithClientCurrency();
        Capsule::table('tblinvoices')->where('id', 42)->update([
            'status' => 'Unpaid',
            'datepaid' => null,
        ]);
        $this->insertAccount(0);

        self::assertNull($this->validatePayment());
    }

    public function testBookingPaymentPaginationUsesTransactionDateAndIncludesUnpaidInvoices(): void
    {
        Migrator::up();
        Capsule::table('tblcurrencies')->insert(['id' => 1, 'code' => 'EUR']);
        Capsule::table('tblclients')->insert([
            'id' => 7,
            'currency' => 1,
            'firstname' => 'Synthetic',
            'lastname' => 'Customer',
            'country' => 'DE',
        ]);
        for ($offset = 0; $offset < 12; ++$offset) {
            $invoiceId = 100 + $offset;
            Capsule::table('tblinvoices')->insert([
                'id' => $invoiceId,
                'userid' => 7,
                'invoicenum' => 'PART-' . $invoiceId,
                'date' => '2025-01-01',
                'datepaid' => null,
                'total' => '100.00',
                'status' => 'Unpaid',
            ]);
            Capsule::table(Migrator::MAPPING_TABLE)->insert([
                'invoice_id' => $invoiceId,
                'sevdesk_id' => (string) (8_000 + $offset),
                'document_type' => MappingRepository::DOCUMENT_TYPE_VOUCHER,
                'document_number' => 'PART-' . $invoiceId,
            ]);
            Capsule::table('tblaccounts')->insert([
                'id' => 1_000 + $offset,
                'invoiceid' => $invoiceId,
                'date' => '2026-07-10 12:00:00',
                'amountin' => '25.00',
                'amountout' => '0.00',
                'transid' => 'PART-PAY-' . $invoiceId,
                'gateway' => 'banktransfer',
                'refundid' => 0,
                'currency' => 0,
            ]);
        }
        Capsule::table('tblinvoices')->insert([
            'id' => 999,
            'userid' => 7,
            'invoicenum' => 'MASS-999',
            'date' => '2025-01-01',
            'datepaid' => '2026-07-10 12:00:00',
            'subtotal' => '25.00',
            'total' => '25.00',
            'status' => 'Paid',
        ]);
        Capsule::table('tblinvoiceitems')->insert([
            'invoiceid' => 999,
            'type' => 'Invoice',
            'relid' => 100,
            'description' => 'Synthetic mass-payment reference',
            'amount' => '25.00',
            'taxed' => false,
        ]);
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 999,
            'sevdesk_id' => '8999',
            'document_type' => MappingRepository::DOCUMENT_TYPE_VOUCHER,
            'document_number' => 'MASS-999',
        ]);
        Capsule::table('tblaccounts')->insert([
            'id' => 1_999,
            'invoiceid' => 999,
            'date' => '2026-07-10 12:00:00',
            'amountin' => '25.00',
            'amountout' => '0.00',
            'transid' => 'MASS-PAY-999',
            'gateway' => 'banktransfer',
            'refundid' => 0,
            'currency' => 0,
        ]);
        $gateway = new WhmcsGateway(new Config());

        $first = $gateway->bookingPaymentsBetween(
            new DateTimeImmutable('2026-07-10'),
            new DateTimeImmutable('2026-07-10'),
            1,
            10,
        );
        $second = $gateway->bookingPaymentsBetween(
            new DateTimeImmutable('2026-07-10'),
            new DateTimeImmutable('2026-07-10'),
            2,
            10,
        );

        self::assertSame(12, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertCount(10, $first['items']);
        self::assertSame(1_000, (int) $first['items'][0]->whmcs_account_id);
        self::assertSame('Unpaid', $first['items'][0]->invoice_status);
        self::assertSame('EUR', $first['items'][0]->invoice_currency);
        self::assertSame(MappingRepository::DOCUMENT_TYPE_VOUCHER, $first['items'][0]->document_type);
        self::assertCount(2, $second['items']);
        self::assertSame(1_010, (int) $second['items'][0]->whmcs_account_id);
    }

    public function testBookingWorkerBlocksAStaleInvoiceMappingBeforeRemoteReads(): void
    {
        Migrator::up();
        $this->insertInvoiceWithClientCurrency();
        $this->insertAccount(0);
        $mappings = new MappingRepository();
        $mappings->linkDocument(42, '99', MappingRepository::DOCUMENT_TYPE_VOUCHER, 'SYN-42');
        $handler = new BookingJobHandler(
            new BookingService(new SevdeskClient(
                new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
                'synthetic-token',
            )),
            new JobRepository(),
            new Config(),
            $mappings,
        );
        $candidate = [
            'whmcsAccountId' => 81,
            'whmcsInvoiceId' => 42,
            'whmcsTransactionId' => 'PAY-42',
            'documentType' => MappingRepository::DOCUMENT_TYPE_VOUCHER,
            'voucherId' => '88',
            'amount' => '119.00',
            'bookingDate' => '2026-07-09',
            'currency' => 'EUR',
        ];

        $outcome = $handler((object) [
            'invoice_id' => 42,
            'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            'checkpoint' => 'queued',
        ], static fn (string $checkpoint, array $context = []): bool => true);

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('booking_mapping_changed', $outcome->errorCode);
    }

    private function insertInvoiceWithClientCurrency(): void
    {
        Capsule::table('tblcurrencies')->insert(['id' => 1, 'code' => 'EUR']);
        Capsule::table('tblclients')->insert([
            'id' => 7,
            'currency' => 1,
            'firstname' => 'Synthetic',
            'lastname' => 'Customer',
            'country' => 'DE',
        ]);
        Capsule::table('tblinvoices')->insert([
            'id' => 42,
            'userid' => 7,
            'invoicenum' => 'SYN-42',
            'date' => '2026-07-09',
            'datepaid' => '2026-07-09 12:00:00',
            'subtotal' => '100.00',
            'tax' => '19.00',
            'taxrate' => '19.0000',
            'total' => '119.00',
            'status' => 'Paid',
        ]);
    }

    private function insertAccount(int $currencyId): void
    {
        Capsule::table('tblaccounts')->insert([
            'id' => 81,
            'invoiceid' => 42,
            'date' => '2026-07-09 12:00:00',
            'amountin' => '119.00',
            'amountout' => '0.00',
            'transid' => 'PAY-42',
            'refundid' => 0,
            'currency' => $currencyId,
        ]);
    }

    private function validatePayment(): ?JobOutcome
    {
        $reflection = new ReflectionClass(BookingJobHandler::class);
        /** @var BookingJobHandler $handler */
        $handler = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($handler, 'validateWhmcsPayment');
        $result = $method->invoke($handler, [
            'whmcsAccountId' => 81,
            'whmcsInvoiceId' => 42,
            'whmcsTransactionId' => 'PAY-42',
            'amount' => '119.00',
            'bookingDate' => '2026-07-09',
            'currency' => 'EUR',
        ], (object) ['invoice_id' => 42]);

        self::assertTrue($result === null || $result instanceof JobOutcome);

        return $result;
    }
}
