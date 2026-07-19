<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Jobs\BookingJobHandler;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\BookingService;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class BookingJobHandlerTest extends TestCase
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

        Capsule::schema()->dropIfExists('mod_sevdesk');
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->unique();
            $table->string('sevdesk_id')->nullable()->unique();
            $table->string('document_type', 16)->nullable();
            $table->string('document_number')->nullable();
            $table->dateTime('document_ready_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
        });
    }

    public function testMalformedCandidateAfterBookingWriteRemainsAmbiguous(): void
    {
        $handler = (new ReflectionClass(BookingJobHandler::class))->newInstanceWithoutConstructor();

        $outcome = $handler(
            (object) [
                'checkpoint' => 'booking_write_requested',
                'candidate_json' => '{invalid',
                'sevdesk_id' => '7001',
            ],
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('booking_write_requested', $outcome->checkpoint);
        self::assertSame('7001', $outcome->sevdeskId);
        self::assertSame('invalid_booking_candidate', $outcome->errorCode);
    }

    public function testMalformedFreshCandidateRemainsPermanentFailure(): void
    {
        $handler = (new ReflectionClass(BookingJobHandler::class))->newInstanceWithoutConstructor();

        $outcome = $handler(
            (object) ['checkpoint' => 'queued', 'candidate_json' => '{invalid'],
            static fn (): bool => true,
        );

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('invalid_booking_candidate', $outcome->errorCode);
    }

    public function testWriteRequestedRecoveryKeepsCheckpointWhenRemoteMappingChanged(): void
    {
        $mappings = new MappingRepository();
        $mappings->linkDocument(42, '99', MappingRepository::DOCUMENT_TYPE_VOUCHER, 'SYN-42');
        $handler = $this->handler($mappings);

        $outcome = $handler(
            $this->item('88', MappingRepository::DOCUMENT_TYPE_VOUCHER),
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('booking_mapping_changed', $outcome->errorCode);
        self::assertSame('booking_write_requested', $outcome->checkpoint);
        self::assertSame('88', $outcome->sevdeskId);
    }

    public function testWriteRequestedRecoveryKeepsCheckpointWhenDocumentTypeChanged(): void
    {
        $mappings = new MappingRepository();
        $mappings->linkDocument(42, '88', MappingRepository::DOCUMENT_TYPE_INVOICE, 'SYN-42');
        $handler = $this->handler($mappings);

        $outcome = $handler(
            $this->item('88', MappingRepository::DOCUMENT_TYPE_VOUCHER),
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('booking_mapping_document_type_changed', $outcome->errorCode);
        self::assertSame('booking_write_requested', $outcome->checkpoint);
        self::assertSame('88', $outcome->sevdeskId);
    }

    public function testWriteRequestedRecoveryKeepsCheckpointForUntypedLegacyMapping(): void
    {
        $mappings = new MappingRepository();
        $mappings->link(42, '88');
        $handler = $this->handler($mappings);

        $outcome = $handler(
            $this->item('88', MappingRepository::DOCUMENT_TYPE_VOUCHER),
            static fn (): bool => true,
        );

        self::assertSame('ambiguous', $outcome->status);
        self::assertSame('booking_mapping_document_type_missing', $outcome->errorCode);
        self::assertSame('booking_write_requested', $outcome->checkpoint);
        self::assertSame('88', $outcome->sevdeskId);
    }

    public function testAuthenticBookingV1RiskRecoveryUsesVoucherReadsOnly(): void
    {
        $mappings = new MappingRepository();
        $mappings->link(42, '88');
        $history = [];
        $handler = $this->handler($mappings, [
            new Response(200, [], json_encode(['objects' => [[
                'id' => '88',
                'objectName' => 'Voucher',
                'status' => '100',
                'currency' => 'EUR',
                'sumGross' => '10.00',
                'paidAmount' => '0.00',
            ]]], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['objects' => [[
                'id' => '73',
                'objectName' => 'CheckAccountTransaction',
                'checkAccount' => ['id' => '9'],
                'paymtPurpose' => 'TX-42',
                'amount' => '10.00',
                'status' => 100,
            ]]], JSON_THROW_ON_ERROR)),
        ], $history);
        $candidate = [
            'whmcsInvoiceId' => 42,
            'whmcsTransactionId' => 'TX-42',
            'voucherId' => '88',
            'transactionId' => '73',
            'checkAccountId' => '9',
            'amount' => '10.00',
            'amountMinorUnits' => 1000,
            'currency' => 'EUR',
            'bookingDate' => '2026-07-10',
            'bookingType' => 'FULL_PAYMENT',
            'voucherPaidMinorUnits' => 0,
        ];
        $candidate['reference'] = hash('sha256', implode('|', [
            'booking-v1', 'TX-42', '88', '73', '9', '1000', 'EUR',
            '2026-07-10', 'FULL_PAYMENT', '0',
        ]));

        $outcome = $handler((object) [
            'invoice_id' => 42,
            'job_id' => 1,
            'checkpoint' => 'booking_write_requested',
            'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
        ], static fn (): bool => true);

        self::assertSame('permanent_failed', $outcome->status);
        self::assertSame('booking_not_applied', $outcome->errorCode);
        self::assertSame(['GET', 'GET'], array_map(
            static fn (array $entry): string => $entry['request']->getMethod(),
            $history,
        ));
    }

    public function testBookingCompletedCheckpointFinishesLocallyWithoutRemoteRead(): void
    {
        $history = [];
        $handler = $this->handler(new MappingRepository(), [], $history);
        $candidate = [
            'documentType' => 'voucher',
            'whmcsTransactionId' => 'TX-42',
            'voucherId' => '88',
            'transactionId' => '73',
            'checkAccountId' => '9',
            'amount' => '10.00',
            'amountMinorUnits' => 1000,
            'currency' => 'EUR',
            'bookingDate' => '2026-07-10',
            'bookingType' => 'FULL_PAYMENT',
            'voucherPaidMinorUnits' => 0,
        ];
        $candidate['reference'] = hash('sha256', implode('|', [
            'booking-v2', 'voucher', 'TX-42', '88', '73', '9', '1000', 'EUR',
            '2026-07-10', 'FULL_PAYMENT', '0',
        ]));

        $outcome = $handler((object) [
            'invoice_id' => 42,
            'job_id' => 1,
            'checkpoint' => 'booking_completed',
            'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
        ], static fn (): bool => true);

        self::assertSame('succeeded', $outcome->status);
        self::assertSame('88', $outcome->sevdeskId);
        self::assertSame([], $history);
    }

    public function testUnverifiableBookingV1CompletedCheckpointRemainsAmbiguous(): void
    {
        $handler = $this->handler(new MappingRepository());

        foreach ([null, 'credit-note'] as $documentType) {
            $candidate = [
                'bookingSchema' => 'booking-v1',
                'voucherId' => '88',
            ];
            if ($documentType !== null) {
                $candidate['documentType'] = $documentType;
            }

            $outcome = $handler((object) [
                'invoice_id' => 42,
                'job_id' => 1,
                'checkpoint' => 'booking_completed',
                'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            ], static fn (): bool => true);

            self::assertSame('ambiguous', $outcome->status);
            self::assertSame('booking_completed', $outcome->checkpoint);
            self::assertSame('88', $outcome->sevdeskId);
            self::assertSame('legacy_booking_candidate_unverifiable', $outcome->errorCode);
        }
    }

    /** @param list<Response> $responses @param array<int,array<string,mixed>>|null $history */
    private function handler(MappingRepository $mappings, array $responses = [], ?array &$history = null): BookingJobHandler
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }

        return new BookingJobHandler(
            new BookingService(new SevdeskClient(new Client(['handler' => $stack]), 'test-token')),
            new JobRepository(),
            new Config(),
            $mappings,
        );
    }

    private function item(string $remoteId, string $documentType): object
    {
        return (object) [
            'invoice_id' => 42,
            'job_id' => 1,
            'checkpoint' => 'booking_write_requested',
            'candidate_json' => json_encode([
                'whmcsInvoiceId' => 42,
                'voucherId' => $remoteId,
                'documentType' => $documentType,
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
