<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\ClientDocumentPresenter;

final class ClientDocumentPresenterTest extends TestCase
{
    public function testUnpaidInvoiceRemainsAVisibleProforma(): void
    {
        $result = ClientDocumentPresenter::present(
            'Unpaid',
            'PRO-44',
            null,
            null,
            'index.php?m=sevdesk&a=download&id=44',
        );

        self::assertSame([
            'authority' => 'sevdesk',
            'state' => 'proforma',
            'invoiceNumber' => 'PRO-44',
            'downloadUrl' => '',
        ], $result);
    }

    public function testPaidInvoiceBecomesReadyOnlyWithTypedReadyAndHashedMapping(): void
    {
        $mapping = (object) [
            'sevdesk_id' => '701',
            'document_type' => 'invoice',
            'document_number' => 'RE-2026-44',
            'document_ready_at' => '2026-07-18 12:34:56',
            'pdf_sha256' => str_repeat('a', 64),
        ];

        $result = ClientDocumentPresenter::present(
            'Paid',
            '44',
            $mapping,
            'succeeded',
            'index.php?m=sevdesk&a=download&id=44',
        );

        self::assertSame('ready', $result['state']);
        self::assertSame('RE-2026-44', $result['invoiceNumber']);
        self::assertSame('index.php?m=sevdesk&a=download&id=44', $result['downloadUrl']);
    }

    public function testUnpaidInvoiceCannotExposeAnAlreadyReadyMapping(): void
    {
        $mapping = (object) [
            'sevdesk_id' => '701',
            'document_type' => 'invoice',
            'document_number' => 'RE-2026-44',
            'document_ready_at' => '2026-07-18 12:34:56',
            'pdf_sha256' => str_repeat('a', 64),
        ];

        $result = ClientDocumentPresenter::present('Unpaid', 'PRO-44', $mapping, 'succeeded', '/download');

        self::assertSame('proforma', $result['state']);
        self::assertSame('', $result['downloadUrl']);
    }

    public function testFinalInvoiceRemainsAvailableAfterWhmcsStatusChangesToRefunded(): void
    {
        $mapping = (object) [
            'sevdesk_id' => '701',
            'document_type' => 'invoice',
            'document_number' => 'RE-2026-44',
            'document_ready_at' => '2026-07-18 12:34:56',
            'pdf_sha256' => str_repeat('b', 64),
        ];

        $result = ClientDocumentPresenter::present('Refunded', '44', $mapping, 'succeeded', '/download');

        self::assertSame('ready', $result['state']);
        self::assertSame('/download', $result['downloadUrl']);
    }

    #[DataProvider('notReadyMappingProvider')]
    public function testIncompleteOrWrongTypeMappingsRemainPending(object $mapping): void
    {
        $result = ClientDocumentPresenter::present('Paid', '44', $mapping, 'running', '/download');

        self::assertSame('pending', $result['state']);
        self::assertSame('', $result['downloadUrl']);
    }

    /** @return iterable<string, array{object}> */
    public static function notReadyMappingProvider(): iterable
    {
        $base = [
            'sevdesk_id' => '701',
            'document_type' => 'invoice',
            'document_ready_at' => '2026-07-18 12:34:56',
            'pdf_sha256' => str_repeat('a', 64),
        ];

        yield 'voucher mapping' => [(object) array_replace($base, ['document_type' => 'voucher'])];
        yield 'legacy mapping' => [(object) array_replace($base, ['document_type' => null])];
        yield 'not ready' => [(object) array_replace($base, ['document_ready_at' => null])];
        yield 'missing hash' => [(object) array_replace($base, ['pdf_sha256' => null])];
        yield 'invalid remote id' => [(object) array_replace($base, ['sevdesk_id' => '../701'])];
    }

    #[DataProvider('failureStatusProvider')]
    public function testTerminalOrAmbiguousJobStateIsShownAsFailure(string $status): void
    {
        $result = ClientDocumentPresenter::present('Paid', '44', null, $status, '/download');

        self::assertSame('failure', $result['state']);
        self::assertSame('', $result['downloadUrl']);
    }

    /** @return iterable<string, array{string}> */
    public static function failureStatusProvider(): iterable
    {
        yield ['succeeded'];
        yield ['skipped'];
        yield ['ambiguous'];
        yield ['permanent_failed'];
        yield ['cancelled'];
    }
}
