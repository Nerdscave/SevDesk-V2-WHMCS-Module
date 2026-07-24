<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceAddressContext;

final class InvoiceAddressContextTest extends TestCase
{
    public function testSecondAddressLineRemainsASeparatePayloadLineWhileHashIsNormalised(): void
    {
        $context = InvoiceAddressContext::fromContact($this->contact(), '1');
        $payload = $context->invoicePayloadFields();

        self::assertSame("Example Street 1\nBuilding B", $payload['addressStreet']);
        self::assertSame(
            "Synthetic Company\nExample Street 1\nBuilding B\n12345 Example City\nDE",
            $payload['address'],
        );
        self::assertSame(
            InvoiceAddressContext::addressHash(
                'Synthetic Company',
                'Example Street 1 Building B',
                '12345',
                'Example City',
                'DE',
            ),
            $context->expectedAddressHash,
        );
    }

    private function contact(): ContactData
    {
        return new ContactData(
            20,
            '42',
            'Synthetic Company',
            'Synthetic',
            'Customer',
            'synthetic@example.invalid',
            'Example Street 1',
            'Building B',
            '12345',
            'Example City',
            'DE',
            null,
            false,
        );
    }
}
