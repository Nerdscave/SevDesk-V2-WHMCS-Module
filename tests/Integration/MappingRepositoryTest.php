<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use InvalidArgumentException;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class MappingRepositoryTest extends MariaDbTestCase
{
    private MappingRepository $mappings;

    protected function setUp(): void
    {
        parent::setUp();
        Migrator::up();
        $this->mappings = new MappingRepository();
    }

    public function testCompleteMappingCanOnlyBeRemovedWithMatchingRemoteAbsenceProof(): void
    {
        $this->mappings->linkDocument(41, '90041', MappingRepository::DOCUMENT_TYPE_INVOICE, 'RE-41');
        $mapping = $this->mappings->findByInvoice(41);
        self::assertNotNull($mapping);

        self::assertFalse($this->mappings->unlink(41));
        self::assertFalse($this->mappings->unlinkById((int) $mapping->id));
        self::assertFalse($this->mappings->unlinkById(
            (int) $mapping->id,
            true,
            'different-id',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
        ));
        self::assertFalse($this->mappings->unlinkById(
            (int) $mapping->id,
            true,
            '90041',
            MappingRepository::DOCUMENT_TYPE_VOUCHER,
        ));
        self::assertNotNull($this->mappings->findByInvoice(41));

        self::assertTrue($this->mappings->unlinkById(
            (int) $mapping->id,
            true,
            '90041',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
        ));
        self::assertNull($this->mappings->findByInvoice(41));
    }

    public function testLegacyNullMappingCanStillBeRemovedWithoutRemoteProof(): void
    {
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 44,
            'sevdesk_id' => null,
        ]);

        self::assertTrue($this->mappings->unlink(44));
        self::assertNull($this->mappings->findByInvoice(44));
    }

    public function testMalformedNonEmptyRemoteIdRemainsProtected(): void
    {
        Capsule::table(Migrator::MAPPING_TABLE)->insert([
            'invoice_id' => 45,
            'sevdesk_id' => 'legacy-invalid-id',
        ]);

        self::assertFalse($this->mappings->unlink(45));
        self::assertNotNull($this->mappings->findByInvoice(45));
    }

    public function testEInvoiceFlagAndXmlHashAreStoredAndImmutable(): void
    {
        $xmlHash = hash('sha256', '<synthetic/>');
        $this->mappings->linkDocument(
            42,
            '90042',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'RE-42',
            true,
            $xmlHash,
            MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
        );

        $mapping = $this->mappings->findByInvoice(42);
        self::assertNotNull($mapping);
        self::assertSame(1, (int) $mapping->is_e_invoice);
        self::assertSame($xmlHash, $mapping->xml_sha256);
        self::assertSame(MappingRepository::DOCUMENT_AUTHORITY_SEVDESK, $mapping->document_authority);

        $this->expectException(RuntimeException::class);
        $this->mappings->enrichDocumentMetadata(
            42,
            '90042',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'RE-42',
            isEInvoice: false,
        );
    }

    public function testXmlHashRequiresAnExplicitInvoiceEInvoiceFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mappings->linkDocument(
            43,
            '90043',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'RE-43',
            false,
            hash('sha256', '<synthetic/>'),
        );
    }

    public function testLegacyCustomerDeliveryMetadataRequiresPaidStatusAtomically(): void
    {
        Capsule::table('tblinvoices')->insert([
            'id' => 46,
            'status' => 'Unpaid',
        ]);
        $this->mappings->linkDocument(
            46,
            '90046',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'RE-46',
        );

        try {
            $this->mappings->enrichDocumentMetadata(
                46,
                '90046',
                MappingRepository::DOCUMENT_TYPE_INVOICE,
                'RE-46',
                new \DateTimeImmutable('2030-01-01 12:00:00'),
                pdfSha256: hash('sha256', 'synthetic-pdf'),
                documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
                requiredWhmcsInvoiceStatus: 'Paid',
            );
            self::fail('An unpaid WHMCS invoice must not receive customer-delivery metadata.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('status', $error->getMessage());
        }

        $mapping = $this->mappings->findByInvoice(46);
        self::assertNotNull($mapping);
        self::assertNull($mapping->document_ready_at);
        self::assertNull($mapping->document_authority);

        Capsule::table('tblinvoices')->where('id', 46)->update(['status' => 'Paid']);
        $this->mappings->enrichDocumentMetadata(
            46,
            '90046',
            MappingRepository::DOCUMENT_TYPE_INVOICE,
            'RE-46',
            new \DateTimeImmutable('2030-01-01 12:00:00'),
            pdfSha256: hash('sha256', 'synthetic-pdf'),
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
            requiredWhmcsInvoiceStatus: 'Paid',
        );

        $mapping = $this->mappings->findByInvoice(46);
        self::assertSame(MappingRepository::DOCUMENT_AUTHORITY_SEVDESK, $mapping->document_authority);
        self::assertSame('2030-01-01 12:00:00', $mapping->document_ready_at);
    }
}
