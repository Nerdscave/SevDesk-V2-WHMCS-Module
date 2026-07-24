<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceAddressContext;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceDiscount;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceExporter;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceRemoteVerifier;

final class InvoiceRemoteVerifierTest extends TestCase
{
    public function testExactHeaderAcceptsSupportedDateAndBooleanRepresentations(): void
    {
        $remote = $this->remoteInvoice();
        $remote['invoiceDate'] = (new DateTimeImmutable('2026-07-01'))->getTimestamp();
        $remote['showNet'] = '1';

        self::assertNull($this->verifier()->invoiceMismatch(
            $remote,
            $this->invoice(),
            '42',
            '1',
            100,
            '99',
            'de',
        ));
    }

    public function testHeaderMismatchCodesRemainCallerNeutralAndDeterministic(): void
    {
        $invalidDate = $this->remoteInvoice();
        $invalidDate['invoiceDate'] = '31.06.2026';
        self::assertSame(
            'date_mismatch',
            $this->verifier()->invoiceMismatch($invalidDate, $this->invoice(), '42', '1', 100),
        );

        $invalidBoolean = $this->remoteInvoice();
        $invalidBoolean['showNet'] = 'true';
        self::assertSame(
            'net_mode_mismatch',
            $this->verifier()->invoiceMismatch($invalidBoolean, $this->invoice(), '42', '1', 100),
        );

        $wrongTotal = $this->remoteInvoice();
        $wrongTotal['sumGross'] = '118.99';
        self::assertSame(
            'total_mismatch',
            $this->verifier()->invoiceMismatch($wrongTotal, $this->invoice(), '42', '1', 100),
        );
    }

    public function testNormalInvoiceAcceptsOmittedCountryButRejectsReportedMismatch(): void
    {
        $omittedCountry = $this->remoteInvoice();
        unset($omittedCountry['deliveryAddressCountry']);
        self::assertNull($this->verifier()->invoiceMismatch(
            $omittedCountry,
            $this->invoice(),
            '42',
            '1',
            100,
            '99',
            'DE',
        ));

        $wrongCountry = $omittedCountry;
        $wrongCountry['addressCountry'] = ['code' => 'FR'];
        self::assertSame(
            'delivery_country_mismatch',
            $this->verifier()->invoiceMismatch(
                $wrongCountry,
                $this->invoice(),
                '42',
                '1',
                100,
                '99',
                'DE',
            ),
        );
    }

    public function testFrozenInvoiceAddressRejectsCountryAndAddressHashMismatches(): void
    {
        $context = InvoiceAddressContext::fromContact(new ContactData(
            20,
            '42',
            'Synthetic Company',
            'Synthetic',
            'Customer',
            'synthetic@example.invalid',
            'Example Street 1',
            '',
            '12345',
            'Example City',
            'DE',
            null,
            false,
        ), '1');
        $remote = array_merge($this->remoteInvoice(), [
            'addressName' => 'Synthetic Company',
            'addressStreet' => 'Example Street 1',
            'addressZip' => '12345',
            'addressCity' => 'Example City',
            'addressCountry' => ['id' => '1', 'code' => 'DE'],
        ]);
        self::assertNull($this->verifier()->invoiceMismatch(
            $remote,
            $this->invoice(),
            '42',
            '1',
            100,
            '99',
            'DE',
            invoiceAddressContext: $context,
        ));

        $wrongCountry = $remote;
        $wrongCountry['addressCountry'] = ['id' => '2', 'code' => 'DE'];
        self::assertSame(
            'invoice_address_country_mismatch',
            $this->verifier()->invoiceMismatch(
                $wrongCountry,
                $this->invoice(),
                '42',
                '1',
                100,
                '99',
                'DE',
                invoiceAddressContext: $context,
            ),
        );

        $wrongStreet = $remote;
        $wrongStreet['addressStreet'] = 'Changed Street 9';
        self::assertSame(
            'invoice_address_hash_mismatch',
            $this->verifier()->invoiceMismatch(
                $wrongStreet,
                $this->invoice(),
                '42',
                '1',
                100,
                '99',
                'DE',
                invoiceAddressContext: $context,
            ),
        );
    }

    public function testFixedDiscountTotalMustBeReportedExactly(): void
    {
        $invoice = new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2025-07-01'),
            'EUR',
            '80.00',
            '0',
            [new LineItem('Hosting', '100.00', '0', false)],
            [new InvoiceDiscount('Promotion', '20.00', '0', false, 42)],
        );
        $remote = $this->remoteInvoice();
        $remote['invoiceDate'] = '01.07.2025';
        $remote['taxRule']['id'] = '11';
        $remote['showNet'] = false;
        $remote['sumGross'] = '80.00';
        $remote['sumDiscounts'] = '20.00';
        $remote['customerInternalNote'] = InvoiceExporter::documentMarker($invoice);

        self::assertNull($this->verifier()->invoiceMismatch(
            $remote,
            $invoice,
            '42',
            '11',
            100,
        ));

        $remote['customerInternalNote'] = InvoiceExporter::marker(10)
            . ' [WHMCS-DISCOUNT:' . str_repeat('0', 64) . ']';
        self::assertSame('discount_marker_mismatch', $this->verifier()->invoiceMismatch(
            $remote,
            $invoice,
            '42',
            '11',
            100,
        ));
        $remote['customerInternalNote'] = InvoiceExporter::documentMarker($invoice);

        $remote['sumDiscounts'] = '19.99';
        self::assertSame('discount_total_mismatch', $this->verifier()->invoiceMismatch(
            $remote,
            $invoice,
            '42',
            '11',
            100,
        ));
    }

    public function testOssInvoiceRequiresReadableCountryConfirmation(): void
    {
        $omittedCountry = $this->remoteInvoice();
        unset($omittedCountry['deliveryAddressCountry']);
        $omittedCountry['taxRule']['id'] = '19';
        self::assertSame(
            'delivery_country_unverifiable',
            $this->verifier()->invoiceMismatch(
                $omittedCountry,
                $this->invoice(),
                '42',
                '19',
                100,
                '99',
                'DE',
            ),
        );

        $reportedCountry = $omittedCountry;
        $reportedCountry['addressCountry'] = ['code' => 'de'];
        self::assertNull($this->verifier()->invoiceMismatch(
            $reportedCountry,
            $this->invoice(),
            '42',
            '19',
            100,
            '99',
            'DE',
        ));

        $wrongFallback = $omittedCountry;
        $wrongFallback['addressCountry'] = ['code' => 'FR'];
        self::assertSame('delivery_country_mismatch', $this->verifier()->invoiceMismatch(
            $wrongFallback,
            $this->invoice(),
            '42',
            '19',
            100,
            '99',
            'DE',
        ));

        $reportedCountry['deliveryAddressCountry'] = 'DE';
        self::assertNull($this->verifier()->invoiceMismatch(
            $reportedCountry,
            $this->invoice(),
            '42',
            '19',
            100,
            '99',
            'DE',
        ));

        $conflictingCountries = $reportedCountry;
        $conflictingCountries['addressCountry'] = ['code' => 'FR'];
        self::assertNull($this->verifier()->invoiceMismatch(
            $conflictingCountries,
            $this->invoice(),
            '42',
            '19',
            100,
            '99',
            'DE',
        ));

        $malformedDelivery = $reportedCountry;
        $malformedDelivery['deliveryAddressCountry'] = ['id' => '1'];
        self::assertSame('delivery_country_unverifiable', $this->verifier()->invoiceMismatch(
            $malformedDelivery,
            $this->invoice(),
            '42',
            '19',
            100,
            '99',
            'DE',
        ));

        $malformedBilling = $reportedCountry;
        $malformedBilling['addressCountry'] = ['id' => '1'];
        self::assertNull($this->verifier()->invoiceMismatch(
            $malformedBilling,
            $this->invoice(),
            '42',
            '19',
            100,
            '99',
            'DE',
        ));

        $malformedBillingFallback = $omittedCountry;
        $malformedBillingFallback['addressCountry'] = ['id' => '1'];
        self::assertSame('delivery_country_unverifiable', $this->verifier()->invoiceMismatch(
            $malformedBillingFallback,
            $this->invoice(),
            '42',
            '19',
            100,
            '99',
            'DE',
        ));
    }

    public function testListAndAssociativeSinglePositionResponsesShareOneVerifier(): void
    {
        $position = $this->remotePosition();
        $verifier = $this->verifier();

        self::assertNull($verifier->positionsMismatch([$position], $this->invoice(), '99'));
        self::assertNull($verifier->positionsMismatch(
            ['invoicePos' => $position],
            $this->invoice(),
            '99',
        ));
    }

    public function testRecoveryCanRetainItsHistoricalMalformedListClassification(): void
    {
        $verifier = $this->verifier();

        self::assertSame(
            'position_invalid',
            $verifier->positionsMismatch(['malformed'], $this->invoice(), '99'),
        );
        self::assertSame(
            'position_count_mismatch',
            $verifier->positionsMismatch(
                ['malformed'],
                $this->invoice(),
                '99',
                discardNonArrayListMembers: true,
            ),
        );
    }

    private function verifier(): InvoiceRemoteVerifier
    {
        return new InvoiceRemoteVerifier('7', '8');
    }

    private function invoice(): InvoiceSnapshot
    {
        return new InvoiceSnapshot(
            10,
            20,
            'RE-10',
            new DateTimeImmutable('2026-07-01'),
            'EUR',
            '119.00',
            '0',
            [new LineItem('Hosting', '100.00', '19', true)],
        );
    }

    /** @return array<string, mixed> */
    private function remoteInvoice(): array
    {
        return [
            'id' => '99',
            'objectName' => 'Invoice',
            'invoiceType' => 'RE',
            'invoiceNumber' => 'RE-10',
            'invoiceDate' => '01.07.2026',
            'currency' => 'EUR',
            'taxRule' => ['id' => '1'],
            'status' => 100,
            'customerInternalNote' => '[WHMCS-INVOICE:10]',
            'contact' => ['id' => '42'],
            'contactPerson' => ['id' => '7'],
            'showNet' => true,
            'deliveryAddressCountry' => 'DE',
            'sumGross' => '119.00',
        ];
    }

    /** @return array<string, mixed> */
    private function remotePosition(): array
    {
        return [
            'objectName' => 'InvoicePos',
            'invoice' => ['id' => '99'],
            'unity' => ['id' => '8'],
            'positionNumber' => 1,
            'quantity' => 1,
            'name' => 'Hosting',
            'text' => 'Hosting',
            'price' => '100.00',
            'taxRate' => '19',
        ];
    }
}
