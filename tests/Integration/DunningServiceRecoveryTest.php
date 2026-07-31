<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\RelatedDocumentRepository;
use WHMCS\Module\Addon\SevDesk\Service\DunningService;
use WHMCS\Module\Addon\SevDesk\Service\InvoicePdf;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceXml;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class DunningServiceRecoveryTest extends MariaDbTestCase
{
    public function testReminderCreateIsVerifiedMappedAndNeverMailedWhenNotRequested(): void
    {
        Migrator::up();
        $history = new ArrayObject();
        $invoice = $this->reminder('7001');
        $service = $this->service([
            $this->json(['objects' => []]),
            $this->json(['objects' => [$invoice]]),
            $this->json(['objects' => [$invoice]]),
            new Response(200, ['Content-Type' => 'application/pdf'], "%PDF-1.7\nsynthetic\n%%EOF"),
        ], $history);
        $checkpoints = [];

        $result = $service->createReminder(
            $this->reminderRequest(createAllowed: true),
            static function (string $name) use (&$checkpoints): bool {
                $checkpoints[] = $name;

                return true;
            },
        );

        self::assertSame('succeeded', $result['status']);
        self::assertSame('reminder_created_mail_free', $result['code']);
        self::assertSame([
            'reminder_create_requested',
            'reminder_created',
            'reminder_verified',
            'reminder_mapping_persisted',
        ], $checkpoints);
        self::assertCount(4, $history);
        $factoryRequest = self::historyRequest($history, 1);
        self::assertSame('/api/v1/Invoice/Factory/createInvoiceReminder', $factoryRequest->getUri()->getPath());
        self::assertSame(
            ['invoice' => ['id' => 6001, 'objectName' => 'Invoice']],
            json_decode((string) $factoryRequest->getBody(), true, 32, JSON_THROW_ON_ERROR),
        );
        $stored = (new RelatedDocumentRepository())->find(
            42,
            RelatedDocumentRepository::ROLE_REMINDER,
            1,
        );
        self::assertSame('7001', (string) $stored?->sevdesk_id);
        self::assertSame('not_requested', (string) $stored?->delivery_status);
        self::assertNotNull($stored?->document_ready_at);
    }

    public function testUnknownReminderCreateOutcomeStaysReadOnlyWhenSearchFindsNothing(): void
    {
        Migrator::up();
        $history = new ArrayObject();
        $service = $this->service([$this->json(['objects' => []])], $history);

        $result = $service->createReminder(
            $this->reminderRequest(createAllowed: false),
            static fn (): bool => throw new \LogicException('Recovery must not emit another checkpoint.'),
        );

        self::assertSame('ambiguous', $result['status']);
        self::assertSame('reminder_create_outcome_unproven', $result['code']);
        self::assertCount(1, $history);
        self::assertSame('GET', self::historyRequest($history, 0)->getMethod());
    }

    public function testKnownReminderIdUsesDirectReadbackWithoutListOrSecondCreate(): void
    {
        Migrator::up();
        $history = new ArrayObject();
        $invoice = $this->reminder('7001');
        $service = $this->service([
            $this->json(['objects' => [$invoice]]),
            $this->json(['objects' => [$invoice]]),
            new Response(200, ['Content-Type' => 'application/pdf'], "%PDF-1.7\nsynthetic\n%%EOF"),
        ], $history);
        $request = $this->reminderRequest(createAllowed: false);
        $request['expectedRemoteId'] = '7001';

        $result = $service->createReminder($request, static fn (): bool => true);

        self::assertSame('succeeded', $result['status']);
        self::assertCount(3, $history);
        self::assertSame('/api/v1/Invoice/7001', self::historyRequest($history, 0)->getUri()->getPath());
        self::assertSame('GET', self::historyRequest($history, 0)->getMethod());
    }

    public function testCancellationAndRule22UnknownCreatesAlsoStayReadOnly(): void
    {
        Migrator::up();
        $history = new ArrayObject();
        $service = $this->service([
            $this->json(['objects' => []]),
            $this->json(['objects' => []]),
        ], $history);
        $fingerprint = hash('sha256', 'synthetic-related-contract');

        $cancellation = $service->cancelInvoice([
            'invoiceId' => 42,
            'parentRemoteId' => '6001',
            'contactId' => '5001',
            'serviceGrossMinor' => 10_000,
            'fingerprint' => $fingerprint,
            'toEmail' => '',
            'subject' => '',
            'text' => '',
            'deliver' => false,
            'isEInvoice' => false,
            'createAllowed' => false,
            'expectedRemoteId' => null,
            'resumeDeliverySafe' => false,
        ], static fn (): bool => throw new \LogicException('No cancellation write is allowed.'));
        $lateFee = $service->exportLateFeeVoucher([
            'invoiceId' => 42,
            'parentRemoteId' => '6001',
            'contactId' => '5001',
            'invoiceNumber' => 'INV-42',
            'voucherDate' => '2026-07-29',
            'lateFeeMinor' => 500,
            'fingerprint' => hash('sha256', 'synthetic-late-fee'),
            'accountDatevId' => '4001',
            'receiptGuidance' => [[
                'accountDatevId' => 4001,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 22, 'taxRates' => [0]]],
            ]],
            'createAllowed' => false,
            'expectedRemoteId' => null,
        ], static fn (): bool => throw new \LogicException('No voucher write is allowed.'));

        self::assertSame('cancellation_create_outcome_unproven', $cancellation['code']);
        self::assertSame('late_fee_voucher_create_outcome_unproven', $lateFee['code']);
        self::assertCount(2, $history);
        self::assertSame('GET', self::historyRequest($history, 0)->getMethod());
        self::assertSame('GET', self::historyRequest($history, 1)->getMethod());
    }

    public function testCancellationIsVerifiedMappedAndRemainsMailFree(): void
    {
        Migrator::up();
        $history = new ArrayObject();
        $cancellation = [
            'id' => 7101,
            'invoiceType' => 'SR',
            'contact' => ['id' => 5001, 'objectName' => 'Contact'],
            'origin' => ['id' => 6001, 'objectName' => 'Invoice'],
            'sumGross' => '-100.00',
            'invoiceNumber' => 'SR-42',
        ];
        $service = $this->service([
            $this->json(['objects' => []]),
            new Response(
                201,
                ['Content-Type' => 'application/json'],
                json_encode(['objects' => [$cancellation]], JSON_THROW_ON_ERROR),
            ),
            $this->json(['objects' => [$cancellation]]),
            new Response(200, ['Content-Type' => 'application/pdf'], "%PDF-1.7\nsynthetic SR\n%%EOF"),
        ], $history);
        $checkpoints = [];

        $result = $service->cancelInvoice([
            'invoiceId' => 42,
            'parentRemoteId' => '6001',
            'contactId' => '5001',
            'serviceGrossMinor' => 10_000,
            'fingerprint' => hash('sha256', 'synthetic-cancellation'),
            'toEmail' => '',
            'subject' => '',
            'text' => '',
            'deliver' => false,
            'isEInvoice' => false,
            'createAllowed' => true,
            'expectedRemoteId' => null,
            'resumeDeliverySafe' => false,
        ], static function (string $name) use (&$checkpoints): bool {
            $checkpoints[] = $name;

            return true;
        });

        self::assertSame('succeeded', $result['status']);
        self::assertSame('cancellation_created_mail_free', $result['code']);
        self::assertSame([
            'cancellation_write_requested',
            'cancellation_created',
            'cancellation_verified',
            'cancellation_mapping_persisted',
        ], $checkpoints);
        self::assertSame(
            '/api/v1/Invoice/6001/cancelInvoice',
            self::historyRequest($history, 1)->getUri()->getPath(),
        );
        self::assertSame('POST', self::historyRequest($history, 1)->getMethod());
        $stored = (new RelatedDocumentRepository())->find(
            42,
            RelatedDocumentRepository::ROLE_CANCELLATION,
        );
        self::assertSame('7101', (string) $stored?->sevdesk_id);
        self::assertSame(-10_000, (int) $stored?->amount_minor);
        self::assertSame('not_requested', (string) $stored?->delivery_status);
    }

    public function testRule22VoucherIsVerifiedAndLinkedExactlyOnce(): void
    {
        Migrator::up();
        $history = new ArrayObject();
        $fingerprint = hash('sha256', 'synthetic-late-fee');
        $marker = '[WHMCS-LATE-FEE:42:' . substr($fingerprint, 0, 24) . ']';
        $voucher = [
            'id' => 7201,
            'objectName' => 'Voucher',
            'description' => 'Late Fee INV-42 ' . $marker,
            'supplier' => ['id' => 5001, 'objectName' => 'Contact'],
            'taxRule' => ['id' => 22, 'objectName' => 'TaxRule'],
            'creditDebit' => 'D',
            'sumGross' => '5.00',
        ];
        $service = $this->service([
            $this->json(['objects' => []]),
            new Response(
                201,
                ['Content-Type' => 'application/json'],
                json_encode(['objects' => [$voucher]], JSON_THROW_ON_ERROR),
            ),
            $this->json(['objects' => [$voucher]]),
            $this->json(['objects' => [[
                'id' => 7301,
                'objectName' => 'VoucherPos',
                'accountDatev' => ['id' => 4001, 'objectName' => 'AccountDatev'],
                'sumGross' => '5.00',
                'taxRate' => '0.00',
            ]]]),
        ], $history);
        $checkpoints = [];

        $result = $service->exportLateFeeVoucher([
            'invoiceId' => 42,
            'parentRemoteId' => '6001',
            'contactId' => '5001',
            'invoiceNumber' => 'INV-42',
            'voucherDate' => '2026-07-29',
            'lateFeeMinor' => 500,
            'fingerprint' => $fingerprint,
            'accountDatevId' => '4001',
            'receiptGuidance' => [[
                'accountDatevId' => 4001,
                'allowedReceiptTypes' => ['REVENUE'],
                'allowedTaxRules' => [['id' => 22, 'taxRates' => [0]]],
            ]],
            'createAllowed' => true,
            'expectedRemoteId' => null,
        ], static function (string $name) use (&$checkpoints): bool {
            $checkpoints[] = $name;

            return true;
        });

        self::assertSame('succeeded', $result['status']);
        self::assertSame('late_fee_voucher_created', $result['code']);
        self::assertSame([
            'late_fee_voucher_write_requested',
            'late_fee_voucher_created',
            'late_fee_voucher_verified',
            'late_fee_voucher_mapping_persisted',
        ], $checkpoints);
        $payload = json_decode(
            (string) self::historyRequest($history, 1)->getBody(),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(22, $payload['voucher']['taxRule']['id']);
        self::assertSame(4001, $payload['voucherPosSave'][0]['accountDatev']['id']);
        self::assertSame(0.0, $payload['voucherPosSave'][0]['taxRate']);
        self::assertSame(5.0, $payload['voucherPosSave'][0]['sumGross']);
        $positionRequest = self::historyRequest($history, 3);
        self::assertSame('/api/v1/VoucherPos', $positionRequest->getUri()->getPath());
        parse_str($positionRequest->getUri()->getQuery(), $positionQuery);
        self::assertSame('7201', (string) ($positionQuery['voucher']['id'] ?? ''));
        self::assertSame('Voucher', $positionQuery['voucher']['objectName'] ?? null);
        $stored = (new RelatedDocumentRepository())->find(
            42,
            RelatedDocumentRepository::ROLE_LATE_FEE_VOUCHER,
        );
        self::assertSame('7201', (string) $stored?->sevdesk_id);
        self::assertSame(500, (int) $stored?->amount_minor);
    }

    /** @return array<string,mixed> */
    private function reminderRequest(bool $createAllowed): array
    {
        return [
            'invoiceId' => 42,
            'parentRemoteId' => '6001',
            'contactId' => '5001',
            'dunningLevel' => 1,
            'serviceGrossMinor' => 10_000,
            'lateFeeMinor' => 500,
            'fingerprint' => hash('sha256', 'synthetic-reminder-contract'),
            'reminderDeadline' => '2026-08-05',
            'toEmail' => '',
            'subject' => '',
            'text' => '',
            'deliver' => false,
            'createAllowed' => $createAllowed,
            'expectedRemoteId' => null,
            'resumeDeliverySafe' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function reminder(string $id): array
    {
        return [
            'id' => $id,
            'invoiceType' => 'MA',
            'contact' => ['id' => 5001, 'objectName' => 'Contact'],
            'origin' => ['id' => 6001, 'objectName' => 'Invoice'],
            'dunningLevel' => 1,
            'reminderDebit' => '100.00',
            'reminderCharge' => '5.00',
            'reminderTotal' => '105.00',
            'reminderDeadline' => '2026-08-05',
            'invoiceNumber' => 'MA-42-1',
        ];
    }

    /**
     * @param list<Response> $responses
     * @param ArrayObject<int,array{request:RequestInterface}> $history
     */
    private function service(array $responses, ArrayObject $history): DunningService
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create(
            static function (RequestInterface $request, array $options) use ($mock, $history): PromiseInterface {
                $history->append(['request' => $request]);

                return $mock($request, $options);
            },
        );
        $client = new SevdeskClient(new Client(['handler' => $stack]), 'test-token');

        return new DunningService(
            $client,
            new RelatedDocumentRepository(),
            new InvoicePdf($client),
            new InvoiceXml($client),
        );
    }

    /** @param ArrayObject<int,array{request:RequestInterface}> $history */
    private static function historyRequest(ArrayObject $history, int $index): RequestInterface
    {
        $entry = $history[$index] ?? null;
        if (!is_array($entry) || !($entry['request'] ?? null) instanceof RequestInterface) {
            throw new \RuntimeException('The synthetic HTTP history is incomplete.');
        }

        return $entry['request'];
    }

    /** @param array<array-key,mixed> $payload */
    private function json(array $payload): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
