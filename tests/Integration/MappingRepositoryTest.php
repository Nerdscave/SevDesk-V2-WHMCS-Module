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
        );

        $mapping = $this->mappings->findByInvoice(42);
        self::assertNotNull($mapping);
        self::assertSame(1, (int) $mapping->is_e_invoice);
        self::assertSame($xmlHash, $mapping->xml_sha256);

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
}
