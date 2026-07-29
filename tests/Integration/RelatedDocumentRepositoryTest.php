<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration;

use InvalidArgumentException;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\RelatedDocumentRepository;
use WHMCS\Module\Addon\SevDesk\Tests\Integration\Support\MariaDbTestCase;

final class RelatedDocumentRepositoryTest extends MariaDbTestCase
{
    public function testVerifiedDocumentCanBeLinkedIdempotentlyAndMarkedDelivered(): void
    {
        Migrator::up();
        $repository = new RelatedDocumentRepository();
        $fingerprint = hash('sha256', 'synthetic-reminder-contract');
        $pdfHash = hash('sha256', 'synthetic-pdf');

        $repository->linkVerified(
            42,
            RelatedDocumentRepository::ROLE_REMINDER,
            2,
            '7001',
            '6001',
            500,
            $fingerprint,
            'MA-42-2',
            $pdfHash,
            null,
            'pending',
        );
        $repository->linkVerified(
            42,
            RelatedDocumentRepository::ROLE_REMINDER,
            2,
            '7001',
            '6001',
            500,
            $fingerprint,
            'MA-42-2',
            $pdfHash,
            null,
            'pending',
        );

        self::assertSame(1, Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)->count());
        self::assertTrue($repository->hasChargedReminder(42));
        self::assertFalse($repository->hasChargedReminder(43));
        self::assertSame(
            'pending',
            (string) $repository->find(
                42,
                RelatedDocumentRepository::ROLE_REMINDER,
                2,
            )?->delivery_status,
        );

        $repository->markDelivered(
            42,
            RelatedDocumentRepository::ROLE_REMINDER,
            2,
            '7001',
        );
        $repository->markDelivered(
            42,
            RelatedDocumentRepository::ROLE_REMINDER,
            2,
            '7001',
        );

        $stored = $repository->find(42, RelatedDocumentRepository::ROLE_REMINDER, 2);
        self::assertSame('delivered', (string) $stored?->delivery_status);
        self::assertNotNull($stored?->delivered_at);
    }

    public function testUnknownMailOutcomeIsStoredWithoutPretendingDelivery(): void
    {
        Migrator::up();
        $repository = new RelatedDocumentRepository();
        $repository->linkVerified(
            44,
            RelatedDocumentRepository::ROLE_CANCELLATION,
            0,
            '7004',
            '6004',
            -11_900,
            hash('sha256', 'synthetic-cancellation-contract'),
            'SR-44',
            hash('sha256', 'synthetic-cancellation-pdf'),
            null,
            'pending',
        );

        $repository->markDeliveryAmbiguous(
            44,
            RelatedDocumentRepository::ROLE_CANCELLATION,
            0,
            '7004',
        );
        $repository->markDeliveryAmbiguous(
            44,
            RelatedDocumentRepository::ROLE_CANCELLATION,
            0,
            '7004',
        );

        $stored = $repository->find(44, RelatedDocumentRepository::ROLE_CANCELLATION);
        self::assertSame('ambiguous', $stored?->delivery_status);
        self::assertNull($stored?->delivered_at);
    }

    public function testDifferentDocumentCannotReplaceAnOwnedRoleOrReuseRemoteId(): void
    {
        Migrator::up();
        $repository = new RelatedDocumentRepository();
        $repository->linkVerified(
            42,
            RelatedDocumentRepository::ROLE_CANCELLATION,
            0,
            '7001',
            '6001',
            -11_900,
            hash('sha256', 'synthetic-cancellation-contract'),
        );

        try {
            $repository->linkVerified(
                42,
                RelatedDocumentRepository::ROLE_CANCELLATION,
                0,
                '7002',
                '6001',
                -11_900,
                hash('sha256', 'different-cancellation-contract'),
            );
            self::fail('An existing role must not be replaced by another sevdesk document.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('already owns', $error->getMessage());
        }

        $this->expectException(\Illuminate\Database\QueryException::class);
        $repository->linkVerified(
            43,
            RelatedDocumentRepository::ROLE_CANCELLATION,
            0,
            '7001',
            '6002',
            -11_900,
            hash('sha256', 'other-invoice-contract'),
        );
    }

    public function testOnlyReminderMayCarryDunningLevel(): void
    {
        Migrator::up();
        $repository = new RelatedDocumentRepository();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only reminders');
        $repository->find(
            42,
            RelatedDocumentRepository::ROLE_LATE_FEE_VOUCHER,
            1,
        );
    }
}
