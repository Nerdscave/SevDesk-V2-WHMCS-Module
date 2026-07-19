<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Repository;

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class JobRepositoryDocumentContextTest extends TestCase
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
        Capsule::schema()->dropIfExists(Migrator::ITEMS_TABLE);
        Capsule::schema()->create(Migrator::ITEMS_TABLE, static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id');
            $table->string('action');
            $table->string('status');
            $table->string('checkpoint');
            $table->text('candidate_json')->nullable();
            $table->text('message')->nullable();
        });
    }

    public function testRequestedInvoiceOnlySnapshotIsReturnedBeforeTheWorkerFreezesItsTarget(): void
    {
        $this->insertContext(42, 'pending', 'queued', [
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'sevdesk',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => 'whmcs_template',
        ]);

        $context = (new JobRepository())->latestDocumentContextForInvoice(42);

        self::assertNotNull($context);
        self::assertSame('requested', $context['source']);
        self::assertSame('invoice', $context['documentType']);
        self::assertSame('sevdesk', $context['documentAuthority']);
        self::assertSame('blocked', $context['euB2cMode']);
        self::assertSame('whmcs_template', $context['deliveryChannel']);
        self::assertNull($context['allowed']);
    }

    public function testFrozenOnlyReadIgnoresALaterRequestedAttemptAndKeepsTheMappingOwner(): void
    {
        $this->insertContext(42, 'succeeded', 'whmcs_email_handed_off', [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'sevdesk',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetDeliveryChannel' => 'whmcs_template',
        ]);
        $this->insertContext(42, 'skipped', 'queued', [
            'requestedExportMode' => 'voucher_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => null,
        ]);

        $repository = new JobRepository();
        self::assertSame('requested', $repository->latestDocumentContextForInvoice(42)['source'] ?? null);

        $context = $repository->latestDocumentContextForInvoice(42, true);
        self::assertNotNull($context);
        self::assertSame('frozen', $context['source']);
        self::assertSame('invoice', $context['documentType']);
        self::assertSame('sevdesk', $context['documentAuthority']);
    }

    public function testMalformedNewestContextFailsClosed(): void
    {
        $this->insertContext(42, 'pending', 'queued', [
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'sevdesk',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => 'sevdesk',
        ]);
        Capsule::table(Migrator::ITEMS_TABLE)->insert([
            'invoice_id' => 42,
            'action' => 'export_document',
            'status' => 'pending',
            'checkpoint' => 'queued',
            'candidate_json' => '{invalid',
        ]);

        self::assertNull((new JobRepository())->latestDocumentContextForInvoice(42));
    }

    public function testImpossibleFrozenVoucherAuthorityCombinationIsRejected(): void
    {
        $this->insertContext(42, 'running', 'document_type_selected', [
            'targetAllowed' => true,
            'targetDocumentType' => 'voucher',
            'targetDocumentAuthority' => 'sevdesk',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetDeliveryChannel' => 'sevdesk',
        ]);

        self::assertNull((new JobRepository())->latestDocumentContextForInvoice(42));
    }

    public function testDedupeSkippedAttemptDoesNotMaskTheOlderAuthorityOwnerWithoutMapping(): void
    {
        $this->insertContext(42, 'ambiguous', 'invoice_write_requested', [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'sevdesk',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetDeliveryChannel' => 'whmcs_template',
        ]);
        $this->insertContext(
            42,
            'skipped',
            'queued',
            [],
            'Die Rechnung ist bereits in einem anderen aktiven Job eingeplant.',
        );

        $context = (new JobRepository())->latestDocumentContextForInvoice(42);

        self::assertNotNull($context);
        self::assertSame(1, $context['itemId']);
        self::assertSame('ambiguous', $context['itemStatus']);
        self::assertSame('sevdesk', $context['documentAuthority']);
    }

    public function testFrozenOnlyMalformedNewestDecisionDoesNotFallBackToOlderAuthority(): void
    {
        $this->insertContext(42, 'succeeded', 'whmcs_email_handed_off', [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'sevdesk',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetDeliveryChannel' => 'whmcs_template',
        ]);
        $this->insertContext(42, 'succeeded', 'finished', [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            // The latest immutable decision is incomplete and must fail closed.
        ]);

        self::assertNull((new JobRepository())->latestDocumentContextForInvoice(42, true));
    }

    public function testRequestedAndFrozenContextsRequireAnExplicitEuB2cMode(): void
    {
        $this->insertContext(42, 'pending', 'queued', [
            'requestedExportMode' => 'invoice_only',
            'requestedDocumentAuthority' => 'sevdesk',
            'requestedOssProfile' => 'blocked',
            'requestedDeliveryChannel' => 'sevdesk',
        ]);
        self::assertNull((new JobRepository())->latestDocumentContextForInvoice(42));

        Capsule::table(Migrator::ITEMS_TABLE)->truncate();
        $this->insertContext(42, 'succeeded', 'finished', [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
        ]);
        self::assertNull((new JobRepository())->latestDocumentContextForInvoice(42, true));
    }

    public function testPublicItemParserReturnsValidatedDisplayAndReferenceFields(): void
    {
        $item = (object) [
            'id' => 7,
            'status' => 'succeeded',
            'checkpoint' => 'invoice_delivered',
            'message' => null,
            'candidate_json' => json_encode([
                'targetAllowed' => true,
                'targetDocumentType' => 'invoice',
                'targetDocumentAuthority' => 'sevdesk',
                'targetExportMode' => 'invoice_only',
                'targetOssProfile' => 'blocked',
                'targetEuB2cMode' => 'blocked',
                'targetDeliveryChannel' => 'sevdesk',
                'targetTaxRuleId' => '19',
                'targetSevUserId' => '7',
                'targetUnityId' => '8',
                'deliveryState' => 'delivered',
            ], JSON_THROW_ON_ERROR),
        ];

        $context = JobRepository::documentContextFromItem($item, true);

        self::assertNotNull($context);
        self::assertSame('19', $context['taxRuleId']);
        self::assertSame('delivered', $context['deliveryState']);
        self::assertSame('7', $context['sevUserId']);
        self::assertSame('8', $context['unityId']);
    }

    public function testPublicItemParserSkipsRequestedAndDedupeLosingRows(): void
    {
        $requested = (object) [
            'id' => 8,
            'status' => 'pending',
            'checkpoint' => 'queued',
            'message' => null,
            'candidate_json' => json_encode([
                'requestedExportMode' => 'voucher_only',
                'requestedDocumentAuthority' => 'whmcs',
                'requestedOssProfile' => 'blocked',
                'requestedEuB2cMode' => 'blocked',
                'requestedDeliveryChannel' => null,
            ], JSON_THROW_ON_ERROR),
        ];
        self::assertNull(JobRepository::documentContextFromItem($requested, true));

        $requested->status = 'skipped';
        $requested->message = 'Die Rechnung ist bereits in einem anderen aktiven Job eingeplant.';
        self::assertNull(JobRepository::documentContextFromItem($requested));
    }

    public function testBatchContextReadIsolatedPerInvoiceAndKeepsFrozenOwner(): void
    {
        $this->insertContext(41, 'succeeded', 'mapping_persisted', [
            'targetAllowed' => true,
            'targetDocumentType' => 'invoice',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'invoice_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetDeliveryChannel' => null,
        ]);
        $this->insertContext(41, 'pending', 'queued', [
            'requestedExportMode' => 'voucher_only',
            'requestedDocumentAuthority' => 'whmcs',
            'requestedOssProfile' => 'blocked',
            'requestedEuB2cMode' => 'blocked',
            'requestedDeliveryChannel' => null,
        ]);
        $this->insertContext(42, 'succeeded', 'mapping_persisted', [
            'targetAllowed' => true,
            'targetDocumentType' => 'voucher',
            'targetDocumentAuthority' => 'whmcs',
            'targetExportMode' => 'voucher_only',
            'targetOssProfile' => 'blocked',
            'targetEuB2cMode' => 'blocked',
            'targetDeliveryChannel' => null,
        ]);
        Capsule::table(Migrator::ITEMS_TABLE)->insert([
            'invoice_id' => 42,
            'action' => 'export_document',
            'status' => 'succeeded',
            'checkpoint' => 'finished',
            'candidate_json' => '{invalid',
            'message' => null,
        ]);

        $contexts = (new JobRepository())->documentContextsForInvoices([42, 41, 0, 41], true);

        self::assertSame([41], array_keys($contexts));
        self::assertSame('invoice', $contexts[41]['documentType']);
        self::assertSame('frozen', $contexts[41]['source']);
    }

    /** @param array<string, mixed> $candidate */
    private function insertContext(
        int $invoiceId,
        string $status,
        string $checkpoint,
        array $candidate,
        ?string $message = null,
    ): void {
        Capsule::table(Migrator::ITEMS_TABLE)->insert([
            'invoice_id' => $invoiceId,
            'action' => 'export_document',
            'status' => $status,
            'checkpoint' => $checkpoint,
            'candidate_json' => json_encode($candidate, JSON_THROW_ON_ERROR),
            'message' => $message,
        ]);
    }
}
