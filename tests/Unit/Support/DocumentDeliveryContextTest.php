<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\DocumentDeliveryContext;

final class DocumentDeliveryContextTest extends TestCase
{
    public function testFrozenConfirmedDocumentExposesOnlyAConsistentIdentity(): void
    {
        $context = self::context('frozen', true, 'invoice', 'sevdesk', 'invoice_only', 'succeeded');
        self::assertSame([
            'documentType' => 'invoice',
            'documentAuthority' => 'sevdesk',
        ], DocumentDeliveryContext::frozenConfirmedDocument($context));

        $context['allowed'] = false;
        self::assertNull(DocumentDeliveryContext::frozenConfirmedDocument($context));
        $context['allowed'] = true;
        $context['documentType'] = 'voucher';
        self::assertNull(DocumentDeliveryContext::frozenConfirmedDocument($context));
    }

    /** @return iterable<string, array{array<string,mixed>,object|null,bool}> */
    public static function contextProvider(): iterable
    {
        $requested = self::context('requested', null, 'invoice', 'sevdesk', 'invoice_only', 'pending');
        $frozenInvoice = self::context('frozen', true, 'invoice', 'sevdesk', 'invoice_only', 'succeeded');

        yield 'new paid Invoice request is pending' => [$requested, null, true];
        yield 'skipped requested-only job remains a sevdesk authority failure' => [
            self::context('requested', null, 'invoice', 'sevdesk', 'invoice_only', 'skipped'),
            null,
            true,
        ];
        yield 'blocked frozen Invoice-only decision remains a customer failure' => [
            self::context('frozen', false, null, 'sevdesk', 'invoice_only', 'permanent_failed'),
            null,
            true,
        ];
        yield 'completed export without its mapping falls back to WHMCS' => [
            $frozenInvoice,
            null,
            false,
        ];
        yield 'Voucher mapping always remains WHMCS-owned' => [
            $requested,
            (object) ['document_type' => 'voucher'],
            false,
        ];
        yield 'legacy untyped mapping remains WHMCS-owned' => [
            $frozenInvoice,
            (object) ['document_type' => null],
            false,
        ];
        yield 'existing Invoice needs its frozen sevdesk decision' => [
            $frozenInvoice,
            (object) ['document_type' => 'invoice'],
            true,
        ];
        yield 'requested snapshot cannot reinterpret an existing Invoice' => [
            $requested,
            (object) ['document_type' => 'invoice'],
            false,
        ];
        yield 'frozen WHMCS Invoice remains WHMCS-owned' => [
            self::context('frozen', true, 'invoice', 'whmcs', 'invoice_only', 'succeeded'),
            (object) ['document_type' => 'invoice'],
            false,
        ];
    }

    /** @param array<string,mixed> $context */
    #[DataProvider('contextProvider')]
    public function testAuthorityIsScopedToTheValidatedInvoiceContext(
        array $context,
        ?object $mapping,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            DocumentDeliveryContext::usesSevdeskInvoiceAuthority($context, $mapping),
        );
    }

    public function testDurableSevdeskAuthoritySurvivesMissingJobContext(): void
    {
        $mapping = (object) [
            'document_type' => 'invoice',
            'document_authority' => 'sevdesk',
        ];

        self::assertTrue(DocumentDeliveryContext::usesSevdeskInvoiceAuthority(null, $mapping));
        self::assertFalse(DocumentDeliveryContext::usesWhmcsInvoiceAuthority(null, $mapping));
    }

    public function testDurableWhmcsAuthoritySurvivesMissingJobContext(): void
    {
        $mapping = (object) [
            'document_type' => 'invoice',
            'document_authority' => 'whmcs',
        ];

        self::assertTrue(DocumentDeliveryContext::usesWhmcsInvoiceAuthority(null, $mapping));
        self::assertFalse(DocumentDeliveryContext::usesSevdeskInvoiceAuthority(null, $mapping));
    }

    public function testConflictingDurableAndFrozenAuthoritiesFailClosed(): void
    {
        $mapping = (object) [
            'document_type' => 'invoice',
            'document_authority' => 'sevdesk',
        ];
        $context = self::context('frozen', true, 'invoice', 'whmcs', 'invoice_only', 'succeeded');

        self::assertFalse(DocumentDeliveryContext::usesWhmcsInvoiceAuthority($context, $mapping));
        self::assertFalse(DocumentDeliveryContext::usesSevdeskInvoiceAuthority($context, $mapping));
    }

    /** @return array<string,mixed> */
    private static function context(
        string $source,
        ?bool $allowed,
        ?string $documentType,
        string $authority,
        string $mode,
        string $status,
    ): array {
        return [
            'itemId' => 1,
            'itemStatus' => $status,
            'checkpoint' => $source === 'frozen' ? 'document_type_selected' : 'queued',
            'source' => $source,
            'allowed' => $allowed,
            'documentType' => $documentType,
            'documentAuthority' => $authority,
            'exportMode' => $mode,
            'ossProfile' => 'blocked',
            'euB2cMode' => 'blocked',
            'deliveryChannel' => $authority === 'sevdesk' ? 'sevdesk' : null,
        ];
    }
}
