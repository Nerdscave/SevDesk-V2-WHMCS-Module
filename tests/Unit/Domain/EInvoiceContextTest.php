<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;

final class EInvoiceContextTest extends TestCase
{
    public function testFrozenContextContainsNoRecipientData(): void
    {
        $hash = EInvoiceContext::addressHash('Example GmbH', 'Musterstr. 1', '12345', 'Berlin', 'DE');
        $context = EInvoiceContext::zugferd(
            '42',
            '9',
            '8',
            '1',
            $hash,
            'Example GmbH',
            'Musterstr. 1',
            '12345',
            'Berlin',
            'DE',
        );

        self::assertSame([
            'isEInvoice' => true,
            'eInvoiceContactId' => '42',
            'eInvoicePaymentMethodId' => '9',
            'eInvoiceUnityId' => '8',
            'eInvoiceCountryId' => '1',
            'eInvoiceAddressHash' => $hash,
            'xmlSha256' => null,
        ], $context->frozenContext());
        self::assertStringNotContainsString('Muster', json_encode(
            $context->frozenContext(),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testChangedAddressCannotRestoreFrozenContext(): void
    {
        $hash = EInvoiceContext::addressHash('Example GmbH', 'Old Street 1', '12345', 'Berlin', 'DE');

        $this->expectException(\InvalidArgumentException::class);
        EInvoiceContext::zugferd(
            '42',
            '9',
            '8',
            '1',
            $hash,
            'Example GmbH',
            'New Street 2',
            '12345',
            'Berlin',
            'DE',
        );
    }

    public function testChangedRecipientNameCannotRestoreFrozenContext(): void
    {
        $hash = EInvoiceContext::addressHash('Example GmbH', 'Musterstr. 1', '12345', 'Berlin', 'DE');

        $this->expectException(\InvalidArgumentException::class);
        EInvoiceContext::zugferd(
            '42',
            '9',
            '8',
            '1',
            $hash,
            'Renamed GmbH',
            'Musterstr. 1',
            '12345',
            'Berlin',
            'DE',
        );
    }

    public function testVerifiedXmlHashCanOnlyBeFrozenOnceWithoutChanging(): void
    {
        $hash = EInvoiceContext::addressHash('Example GmbH', 'Musterstr. 1', '12345', 'Berlin', 'DE');
        $context = EInvoiceContext::zugferd(
            '42',
            '9',
            '8',
            '1',
            $hash,
            'Example GmbH',
            'Musterstr. 1',
            '12345',
            'Berlin',
            'DE',
        )->withExpectedXmlSha256(hash('sha256', 'xml-v1'));

        self::assertSame(hash('sha256', 'xml-v1'), $context->expectedXmlSha256);
        self::assertSame($context, $context->withExpectedXmlSha256(hash('sha256', 'xml-v1')));

        $this->expectException(\InvalidArgumentException::class);
        $context->withExpectedXmlSha256(hash('sha256', 'xml-v2'));
    }

    public function testRuleScopeCannotBeExpandedThroughCountryInput(): void
    {
        $hash = EInvoiceContext::addressHash('Example SARL', 'Rue 1', '75001', 'Paris', 'FR');

        $this->expectException(\InvalidArgumentException::class);
        EInvoiceContext::zugferd(
            '42',
            '9',
            '8',
            '2',
            $hash,
            'Example SARL',
            'Rue 1',
            '75001',
            'Paris',
            'FR',
        );
    }
}
