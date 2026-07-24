<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsPaymentStructureService;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class WhmcsPaymentStructureServiceTest extends TestCase
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
        $GLOBALS['CONFIG']['TaxType'] = 'Exclusive';

        foreach (['mod_sevdesk', 'tblaccounts', 'tblinvoiceitems', 'tblinvoices'] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
        Capsule::schema()->create('tblinvoices', static function ($table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('userid');
            $table->string('status');
            $table->decimal('subtotal', 18, 4);
            $table->decimal('credit', 18, 4);
            $table->decimal('tax', 18, 4);
            $table->decimal('tax2', 18, 4);
            $table->decimal('total', 18, 4);
        });
        Capsule::schema()->create('tblinvoiceitems', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->string('type');
            $table->unsignedInteger('relid')->nullable();
            $table->decimal('amount', 18, 4);
            $table->text('description')->nullable();
        });
        Capsule::schema()->create('tblaccounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->decimal('amountin', 18, 4)->default(0);
            $table->decimal('amountout', 18, 4)->default(0);
            $table->unsignedInteger('refundid')->default(0);
            $table->string('transid')->nullable();
            $table->text('description')->nullable();
        });
        Capsule::schema()->create('mod_sevdesk', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->nullable();
            $table->string('sevdesk_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['CONFIG']['TaxType']);

        parent::tearDown();
    }

    public function testOrdinaryRevenueInvoiceRemainsOrdinary(): void
    {
        $this->invoice(10, total: '10.00');
        $this->item(10, 'Hosting', '10.00');

        $result = $this->service()->classify(10);

        self::assertSame(WhmcsPaymentStructureService::ORDINARY_INVOICE, $result['code']);
        self::assertTrue($result['revenueDocument']);
        self::assertFalse($result['requiresReview']);
        self::assertNull($result['parentInvoiceId']);
    }

    public function testMergeTypeWithoutInvoiceReferenceRemainsOrdinary(): void
    {
        $this->invoice(11, total: '10.00');
        $this->item(11, 'Merge', '10.00', 123);

        $result = $this->service()->classify(11);

        self::assertSame(WhmcsPaymentStructureService::ORDINARY_INVOICE, $result['code']);
        self::assertTrue($result['revenueDocument']);
    }

    public function testAddFundsInvoiceIsAnExplicitReviewCase(): void
    {
        $this->invoice(12, total: '10.00');
        $this->item(12, 'AddFunds', '10.00');

        $result = $this->service()->classify(12);

        self::assertSame(WhmcsPaymentStructureService::SPECIAL_INVOICE_REQUIRES_REVIEW, $result['code']);
        self::assertFalse($result['revenueDocument']);
        self::assertTrue($result['requiresReview']);
        self::assertSame('add_funds_invoice', $result['context']['reasonCode']);
    }

    public function testExactMassPaymentContainerAndTargetAreDistinguished(): void
    {
        $this->validMassPayment();

        $container = $this->service()->classify(100);
        $target = $this->service()->classify(200);

        self::assertSame(WhmcsPaymentStructureService::CONTAINER_NOT_REVENUE, $container['code']);
        self::assertFalse($container['revenueDocument']);
        self::assertFalse($container['requiresReview']);
        self::assertSame([200, 201], $container['targetInvoiceIds']);
        self::assertSame(2, $container['context']['linkedInvoiceCount']);
        self::assertSame(3_000, $container['context']['parentPaidMinor']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $container['fingerprint']);

        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $target['code']);
        self::assertTrue($target['revenueDocument']);
        self::assertFalse($target['requiresReview']);
        self::assertSame(100, $target['parentInvoiceId']);
        self::assertSame(1_000, $target['context']['linkAmountMinor']);
        self::assertSame(1_000, $target['context']['invoiceCreditMinor']);
    }

    public function testDescriptionsAndTransactionReferencesAreNotClassificationInputs(): void
    {
        $this->validMassPayment();
        $before = $this->service()->classify(200);

        Capsule::table('tblinvoiceitems')->where('invoiceid', 200)->update([
            'description' => 'SYNTHETIC-PRIVATE-DESCRIPTION',
        ]);
        Capsule::table('tblaccounts')->where('invoiceid', 100)->update([
            'transid' => 'SYNTHETIC-PRIVATE-TRANSACTION',
            'description' => 'SYNTHETIC-PRIVATE-PAYMENT',
        ]);

        $after = $this->service()->classify(200);
        $serialised = json_encode($after, JSON_THROW_ON_ERROR);

        self::assertSame($before['fingerprint'], $after['fingerprint']);
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE', $serialised);
        foreach ($after['context'] as $value) {
            self::assertTrue(is_scalar($value) || $value === null);
        }
    }

    public function testFingerprintChangesWhenStructuralEvidenceChanges(): void
    {
        $this->validMassPayment();
        $before = $this->service()->classify(200);

        Capsule::table('tblinvoices')->where('id', 200)->update(['credit' => '9.00']);
        $after = $this->service()->classify(200);

        self::assertNotSame($before['fingerprint'], $after['fingerprint']);
        self::assertSame(WhmcsPaymentStructureService::STRUCTURE_REQUIRES_REVIEW, $after['code']);
    }

    public function testSameServiceStartsEveryClassificationWithFreshDatabaseReads(): void
    {
        $this->validMassPayment();
        $service = $this->service();
        $before = $service->classify(200);

        Capsule::table('tblinvoices')->where('id', 200)->update(['credit' => '9.00']);
        $after = $service->classify(200);

        self::assertNotSame($before['fingerprint'], $after['fingerprint']);
        self::assertSame(WhmcsPaymentStructureService::STRUCTURE_REQUIRES_REVIEW, $after['code']);
        self::assertSame('invoice_totals_inconsistent', $after['context']['reasonCode']);

        Capsule::table('tblinvoices')->where('id', 200)->update(['credit' => '10.00']);
        Capsule::table('mod_sevdesk')->insert([
            'invoice_id' => 200,
            'sevdesk_id' => null,
        ]);
        $mapped = $service->classify(200);

        self::assertSame(WhmcsPaymentStructureService::MAPPING_REQUIRES_REVIEW, $mapped['code']);
        self::assertSame('incomplete', $mapped['context']['mappingState']);
    }

    public function testOneLargeTargetClassificationUsesBatchedSiblingReads(): void
    {
        $targetIds = $this->largeMassPayment(500, 1_000, 40);
        $service = $this->service();

        $queryCount = $this->measureQueries(function () use ($service, $targetIds): void {
            $result = $service->classify($targetIds[0]);

            self::assertSame(
                WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET,
                $result['code'],
            );
        });

        self::assertLessThanOrEqual(
            20,
            $queryCount,
            'Sibling invoices must be read in batches, not with three queries per target.',
        );
    }

    public function testRepeatedTargetClassificationsOfOneChainRemainLinear(): void
    {
        $targetIds = $this->largeMassPayment(500, 1_000, 40);
        $service = $this->service();

        $queryCount = $this->measureQueries(function () use ($service, $targetIds): void {
            foreach ($targetIds as $targetId) {
                $result = $service->classify($targetId);
                self::assertSame(
                    WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET,
                    $result['code'],
                );
            }
        });

        self::assertLessThanOrEqual(
            count($targetIds) * 20,
            $queryCount,
            'Repeated target classifications must stay O(N) for one Pay-All chain.',
        );
    }

    public function testHookClassificationReusesOneExactGraphAcrossPaidEvents(): void
    {
        $targetIds = $this->largeMassPayment(500, 1_000, 40);
        $service = $this->service();

        $queryCount = $this->measureQueries(function () use ($service, $targetIds): void {
            self::assertSame($targetIds, $service->massPaymentTargetIdsForHook(500));
            self::assertSame($targetIds, $service->massPaymentTargetIdsForHook(500));
            foreach ($targetIds as $targetId) {
                self::assertSame([], $service->massPaymentTargetIdsForHook($targetId));
            }
        });

        self::assertLessThanOrEqual(
            20,
            $queryCount,
            'One exact Pay-All graph must be reused across all paid hooks in the request.',
        );
    }

    public function testTargetFirstAndContainerFirstHookContextsFreezeTheSameParent(): void
    {
        $this->validMassPayment();
        $service = $this->service();

        self::assertSame([
            'containerInvoiceId' => 100,
            'targetInvoiceIds' => [],
        ], $service->massPaymentContextForHook(200));
        self::assertSame([
            'containerInvoiceId' => 100,
            'targetInvoiceIds' => [200, 201],
        ], $service->massPaymentContextForHook(100));
        self::assertSame([
            'containerInvoiceId' => 100,
            'targetInvoiceIds' => [],
        ], $service->massPaymentContextForHook(201));
    }

    public function testHookGraphCacheNeverReplacesFreshWorkerClassification(): void
    {
        $this->validMassPayment();
        $service = $this->service();
        self::assertSame([200, 201], $service->massPaymentTargetIdsForHook(100));

        Capsule::table('tblinvoiceitems')
            ->where('invoiceid', 100)
            ->where('relid', 200)
            ->update(['amount' => '9.00']);

        $fresh = $service->classify(100);

        self::assertSame(
            WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW,
            $fresh['code'],
        );
        self::assertSame(
            'mass_payment_parent_line_total_mismatch',
            $fresh['context']['reasonCode'],
        );
    }

    public function testOversizedMassPaymentGraphFailsClosedBeforeTargetReads(): void
    {
        $targetIds = $this->largeMassPayment(500, 1_000, 251);
        $service = $this->service();

        $queryCount = $this->measureQueries(function () use ($service): void {
            $result = $service->classify(500);

            self::assertSame(
                WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW,
                $result['code'],
            );
            self::assertSame(
                'mass_payment_structure_limit_exceeded',
                $result['context']['reasonCode'],
            );
        });

        self::assertCount(251, $targetIds);
        self::assertLessThanOrEqual(
            10,
            $queryCount,
            'An oversized Pay-All chain must stop before reading every target invoice.',
        );
    }

    public function testMixedInvoiceReferenceContainerIsNeverOrdinary(): void
    {
        $this->validMassPayment();
        $this->item(100, 'Hosting', '1.00');

        $result = $this->service()->classify(100);

        self::assertSame(WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW, $result['code']);
        self::assertSame('mixed_invoice_reference_items', $result['context']['reasonCode']);
        self::assertFalse($result['revenueDocument']);
    }

    public function testMissingOrCrossClientTargetBlocksTheContainer(): void
    {
        $this->validMassPayment();
        Capsule::table('tblinvoices')->where('id', 200)->delete();

        $missing = $this->service()->classify(100);
        self::assertSame(WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW, $missing['code']);
        self::assertSame('mass_payment_target_missing', $missing['context']['reasonCode']);

        $this->invoice(200, clientId: 2, subtotal: '10.00', credit: '10.00', total: '0.00');
        $crossClient = $this->service()->classify(100);
        self::assertSame(WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW, $crossClient['code']);
        self::assertSame('mass_payment_target_client_mismatch', $crossClient['context']['reasonCode']);
    }

    public function testInvalidRelidAndDuplicateTargetBlockTheContainer(): void
    {
        $this->validMassPayment();
        Capsule::table('tblinvoiceitems')
            ->where('invoiceid', 100)
            ->where('relid', 200)
            ->update(['relid' => 0]);

        $invalid = $this->service()->classify(100);
        self::assertSame(WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW, $invalid['code']);
        self::assertSame('mass_payment_link_invalid', $invalid['context']['reasonCode']);

        Capsule::table('tblinvoiceitems')->where('invoiceid', 100)->where('relid', 0)->delete();
        $this->item(100, 'Invoice', '5.00', 201);
        $duplicate = $this->service()->classify(100);
        self::assertSame(WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW, $duplicate['code']);
        self::assertSame('mass_payment_target_duplicated', $duplicate['context']['reasonCode']);
    }

    public function testUnpaidCreditedOrPartiallyPaidParentBlocksTheChain(): void
    {
        $this->validMassPayment();
        Capsule::table('tblinvoices')->where('id', 100)->update(['status' => 'Unpaid']);

        $unpaid = $this->service()->classify(200);
        self::assertSame(WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW, $unpaid['code']);
        self::assertSame('mass_payment_parent_not_paid', $unpaid['context']['reasonCode']);

        Capsule::table('tblinvoices')->where('id', 100)->update(['status' => 'Paid', 'credit' => '1.00']);
        $credited = $this->service()->classify(200);
        self::assertSame('mass_payment_parent_has_credit', $credited['context']['reasonCode']);

        Capsule::table('tblinvoices')->where('id', 100)->update(['credit' => '0.00']);
        Capsule::table('tblaccounts')->where('invoiceid', 100)->update(['amountin' => '29.99']);
        $partial = $this->service()->classify(200);
        self::assertSame('mass_payment_parent_payment_mismatch', $partial['context']['reasonCode']);
    }

    public function testRefundOrChargebackOnParentOrTargetBlocksTheChain(): void
    {
        $this->validMassPayment();
        Capsule::table('tblaccounts')->where('invoiceid', 100)->update(['amountout' => '1.00']);

        $parentRefund = $this->service()->classify(100);
        self::assertSame(
            'mass_payment_parent_refund_or_chargeback',
            $parentRefund['context']['reasonCode'],
        );

        Capsule::table('tblaccounts')->where('invoiceid', 100)->update(['amountout' => '0.00']);
        $this->account(200, '0.00', '1.00', 999);
        $targetRefund = $this->service()->classify(200);
        self::assertSame(
            'credited_target_refund_or_chargeback',
            $targetRefund['context']['reasonCode'],
        );
    }

    public function testReverseLinkedRefundOutsideTheInvoiceStillBlocksTheChain(): void
    {
        $this->validMassPayment();
        $paymentId = (int) Capsule::table('tblaccounts')
            ->where('invoiceid', 100)
            ->value('id');
        self::assertGreaterThan(0, $paymentId);
        $this->account(999, '0.00', '30.00', $paymentId);

        $parent = $this->service()->classify(100);
        $target = $this->service()->classify(200);

        self::assertSame(
            'mass_payment_parent_refund_or_chargeback',
            $parent['context']['reasonCode'],
        );
        self::assertSame(
            'mass_payment_parent_refund_or_chargeback',
            $target['context']['reasonCode'],
        );
    }

    public function testMultipleParentsAndLinkMismatchRequireReview(): void
    {
        $this->validMassPayment();
        $this->invoice(101, subtotal: '10.00', total: '10.00');
        $this->item(101, 'Invoice', '10.00', 200);
        $this->account(101, '10.00');

        $multiple = $this->service()->classify(200);
        self::assertSame(WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW, $multiple['code']);
        self::assertSame('multiple_mass_payment_parents', $multiple['context']['reasonCode']);
        foreach ([100, 101] as $parentInvoiceId) {
            $parent = $this->service()->classify($parentInvoiceId);
            self::assertSame(
                WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW,
                $parent['code'],
            );
            self::assertSame('multiple_mass_payment_parents', $parent['context']['reasonCode']);
            self::assertSame(
                [],
                $this->service()->massPaymentTargetIdsForHook($parentInvoiceId),
            );
        }
        $unsharedSibling = $this->service()->classify(201);
        self::assertSame(
            WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW,
            $unsharedSibling['code'],
        );
        self::assertSame(
            'multiple_mass_payment_parents',
            $unsharedSibling['context']['reasonCode'],
        );

        Capsule::table('tblinvoiceitems')->where('invoiceid', 101)->delete();
        Capsule::table('tblinvoices')->where('id', 101)->delete();
        Capsule::table('tblaccounts')->where('invoiceid', 101)->delete();
        Capsule::table('tblinvoiceitems')
            ->where('invoiceid', 100)
            ->where('relid', 200)
            ->update(['amount' => '9.00']);
        Capsule::table('tblinvoiceitems')
            ->where('invoiceid', 100)
            ->where('relid', 201)
            ->update(['amount' => '21.00']);

        $mismatch = $this->service()->classify(200);
        self::assertSame('mass_payment_link_credit_mismatch', $mismatch['context']['reasonCode']);
    }

    public function testProvenInactiveParentDoesNotBlockOneLaterExactMassPayment(): void
    {
        $this->validMassPayment();
        $before = $this->service()->classify(200);
        $this->invoice(
            101,
            status: 'Cancelled',
            subtotal: '10.00',
            total: '10.00',
        );
        $this->item(101, 'Invoice', '10.00', 200);

        $result = $this->service()->classify(200);
        $parent = $this->service()->classify(100);

        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $result['code']);
        self::assertSame(100, $result['parentInvoiceId']);
        self::assertSame(2, $result['context']['referencingParentCount']);
        self::assertSame(1, $result['context']['ignoredInactiveParentCount']);
        self::assertNotSame($before['fingerprint'], $result['fingerprint']);
        self::assertSame(WhmcsPaymentStructureService::CONTAINER_NOT_REVENUE, $parent['code']);
    }

    public function testRefundedParentWithoutAccountRowsIsNeverAnInactiveAttempt(): void
    {
        $this->validMassPayment();
        $this->invoice(
            101,
            status: 'Refunded',
            subtotal: '10.00',
            total: '10.00',
        );
        $this->item(101, 'Invoice', '10.00', 200);

        $target = $this->service()->classify(200);
        $currentParent = $this->service()->classify(100);

        self::assertSame(
            WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW,
            $target['code'],
        );
        self::assertSame('multiple_mass_payment_parents', $target['context']['reasonCode']);
        self::assertSame(
            WhmcsPaymentStructureService::MASS_PAYMENT_REQUIRES_REVIEW,
            $currentParent['code'],
        );
        self::assertSame(
            'multiple_mass_payment_parents',
            $currentParent['context']['reasonCode'],
        );
    }

    public function testPaidOrMappedStaleParentStillBlocksAnOtherwiseExactChain(): void
    {
        $this->validMassPayment();
        $this->invoice(101, status: 'Unpaid', subtotal: '10.00', total: '10.00');
        $this->item(101, 'Invoice', '10.00', 200);
        $this->account(101, '1.00');

        $paidEvidence = $this->service()->classify(200);
        self::assertSame(WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW, $paidEvidence['code']);
        self::assertSame('multiple_mass_payment_parents', $paidEvidence['context']['reasonCode']);

        Capsule::table('tblaccounts')->where('invoiceid', 101)->delete();
        Capsule::table('mod_sevdesk')->insert([
            'invoice_id' => 101,
            'sevdesk_id' => 'REMOTE-101',
        ]);
        $mapped = $this->service()->classify(200);
        self::assertSame('multiple_mass_payment_parents', $mapped['context']['reasonCode']);
    }

    public function testTargetPositionSumMustEqualInvoiceTotal(): void
    {
        $this->validMassPayment();
        Capsule::table('tblinvoiceitems')
            ->where('invoiceid', 200)
            ->update(['amount' => '9.99']);

        $result = $this->service()->classify(200);

        self::assertSame(WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW, $result['code']);
        self::assertSame('credited_target_total_mismatch', $result['context']['reasonCode']);
    }

    public function testParentAndIncompleteTargetMappingsBlockTheChain(): void
    {
        $this->validMassPayment();
        Capsule::table('mod_sevdesk')->insert([
            'invoice_id' => 100,
            'sevdesk_id' => 'REMOTE-100',
        ]);

        $parent = $this->service()->classify(100);
        $targetViaParent = $this->service()->classify(200);

        self::assertSame(WhmcsPaymentStructureService::MAPPING_REQUIRES_REVIEW, $parent['code']);
        self::assertSame('mass_payment_parent_mapping_present', $targetViaParent['context']['reasonCode']);

        Capsule::table('mod_sevdesk')->where('invoice_id', 100)->delete();
        Capsule::table('mod_sevdesk')->insert([
            'invoice_id' => 200,
            'sevdesk_id' => null,
        ]);
        $target = $this->service()->classify(200);

        self::assertSame(WhmcsPaymentStructureService::MAPPING_REQUIRES_REVIEW, $target['code']);
        self::assertSame('incomplete', $target['context']['mappingState']);
    }

    public function testCompletedTargetsDoNotChangeSiblingOrRecoveryFingerprint(): void
    {
        $this->validMassPayment();
        $beforeFirst = $this->service()->classify(200);
        $beforeSecond = $this->service()->classify(201);

        Capsule::table('mod_sevdesk')->insert([
            'invoice_id' => 200,
            'sevdesk_id' => 'REMOTE-200',
        ]);

        $afterFirst = $this->service()->classify(200);
        $afterSecond = $this->service()->classify(201);

        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $afterFirst['code']);
        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $afterSecond['code']);
        self::assertSame($beforeFirst['fingerprint'], $afterFirst['fingerprint']);
        self::assertSame($beforeSecond['fingerprint'], $afterSecond['fingerprint']);
        self::assertSame('complete', $afterFirst['context']['mappingState']);
    }

    public function testBrokenSiblingMappingDoesNotBlockAnotherExactTarget(): void
    {
        $this->validMassPayment();
        $before = $this->service()->classify(201);
        Capsule::table('mod_sevdesk')->insert([
            'invoice_id' => 200,
            'sevdesk_id' => null,
        ]);

        $currentBroken = $this->service()->classify(200);
        $sibling = $this->service()->classify(201);

        self::assertSame(WhmcsPaymentStructureService::MAPPING_REQUIRES_REVIEW, $currentBroken['code']);
        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $sibling['code']);
        self::assertSame($before['fingerprint'], $sibling['fingerprint']);
    }

    public function testPartialCreditRequiresTheRemainingDirectPayment(): void
    {
        $this->invoice(110, subtotal: '4.00', total: '4.00');
        $this->item(110, 'Invoice', '4.00', 210);
        $this->account(110, '4.00');
        $this->invoice(210, subtotal: '10.00', credit: '4.00', total: '6.00');
        $this->item(210, 'Hosting', '10.00');
        $this->account(210, '6.00');

        $exact = $this->service()->classify(210);
        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $exact['code']);
        self::assertSame(600, $exact['context']['targetPaidMinor']);

        Capsule::table('tblaccounts')->where('invoiceid', 210)->delete();
        $missing = $this->service()->classify(210);
        self::assertSame('credited_target_payment_mismatch', $missing['context']['reasonCode']);

        $this->account(210, '6.01');
        $extra = $this->service()->classify(210);
        self::assertSame('credited_target_payment_mismatch', $extra['context']['reasonCode']);
    }

    public function testTaxedTargetUsesSubtotalForItemsAndGrossForCreditMath(): void
    {
        $this->invoice(120, subtotal: '19.00', total: '19.00');
        $this->item(120, 'Invoice', '19.00', 220);
        $this->account(120, '19.00');
        $this->invoice(
            220,
            subtotal: '100.00',
            credit: '19.00',
            tax: '19.00',
            total: '100.00',
        );
        $this->item(220, 'Hosting', '100.00');
        $this->account(220, '100.00');

        $result = $this->service()->classify(220);

        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $result['code']);
        self::assertSame(11_900, $result['context']['targetDocumentGrossMinor']);
        self::assertSame(10_000, $result['context']['targetDirectCashMinor']);
        self::assertSame(10_000, $result['context']['targetPaidMinor']);
    }

    public function testTaxTypeDoesNotChangeTheWhmcsHeaderContract(): void
    {
        $GLOBALS['CONFIG']['TaxType'] = 'Inclusive';
        $this->invoice(121, subtotal: '19.00', total: '19.00');
        $this->item(121, 'Invoice', '19.00', 221);
        $this->account(121, '19.00');
        $this->invoice(
            221,
            subtotal: '100.00',
            credit: '19.00',
            tax: '19.00',
            total: '100.00',
        );
        $this->item(221, 'Hosting', '119.00');
        $this->account(221, '100.00');

        $result = $this->service()->classify(221);

        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $result['code']);
        self::assertSame(11_900, $result['context']['targetDocumentGrossMinor']);
        self::assertSame(10_000, $result['context']['targetDirectCashMinor']);
        self::assertSame(10_000, $result['context']['targetPaidMinor']);
    }

    public function testUnsupportedTaxTypeFailsClosed(): void
    {
        $GLOBALS['CONFIG']['TaxType'] = 'Unexpected';
        $this->invoice(300, subtotal: '10.00', credit: '10.00', total: '0.00');
        $this->item(300, 'Hosting', '10.00');

        $result = $this->service()->classify(300);

        self::assertSame(WhmcsPaymentStructureService::STRUCTURE_REQUIRES_REVIEW, $result['code']);
        self::assertSame('invalid_numeric_structure', $result['context']['reasonCode']);
    }

    public function testFullCreditTargetNeedsNoSeparateDirectPayment(): void
    {
        $this->invoice(130, subtotal: '119.00', total: '119.00');
        $this->item(130, 'Invoice', '119.00', 230);
        $this->account(130, '119.00');
        $this->invoice(
            230,
            subtotal: '100.00',
            credit: '119.00',
            tax: '19.00',
            total: '0.00',
        );
        $this->item(230, 'Hosting', '100.00');

        $result = $this->service()->classify(230);

        self::assertSame(WhmcsPaymentStructureService::EXACT_MASS_PAYMENT_TARGET, $result['code']);
        self::assertSame(0, $result['context']['targetDirectCashMinor']);
        self::assertSame(0, $result['context']['targetPaidMinor']);
    }

    public function testInconsistentTaxTotalsRequireReview(): void
    {
        $this->validMassPayment();
        Capsule::table('tblinvoices')->where('id', 200)->update(['tax' => '0.01']);

        $result = $this->service()->classify(200);

        self::assertSame(
            'invoice_totals_inconsistent',
            $result['context']['reasonCode'],
        );
    }

    public function testMissingParentLeavesCreditedInvoiceForReview(): void
    {
        $this->invoice(300, subtotal: '10.00', credit: '10.00', total: '0.00');
        $this->item(300, 'Hosting', '10.00');

        $result = $this->service()->classify(300);

        self::assertSame(WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW, $result['code']);
        self::assertSame('mass_payment_parent_missing', $result['context']['reasonCode']);
        self::assertSame(1_000, $result['context']['targetDocumentGrossMinor']);
        self::assertSame(0, $result['context']['targetDirectCashMinor']);
    }

    public function testOrdinaryPartialCreditKeepsTheExactDirectCashAmountForReview(): void
    {
        $this->invoice(301, subtotal: '10.00', credit: '4.00', total: '6.00');
        $this->item(301, 'Hosting', '10.00');
        $this->account(301, '6.00');

        $result = $this->service()->classify(301);

        self::assertSame(WhmcsPaymentStructureService::CREDIT_REQUIRES_REVIEW, $result['code']);
        self::assertSame('mass_payment_parent_missing', $result['context']['reasonCode']);
        self::assertSame(1_000, $result['context']['targetDocumentGrossMinor']);
        self::assertSame(600, $result['context']['targetDirectCashMinor']);
    }

    public function testInconsistentOrdinaryInvoiceHeaderIsBlocked(): void
    {
        $this->invoice(302, subtotal: '10.00', total: '9.99');
        $this->item(302, 'Hosting', '10.00');

        $result = $this->service()->classify(302);

        self::assertSame(WhmcsPaymentStructureService::STRUCTURE_REQUIRES_REVIEW, $result['code']);
        self::assertSame('invoice_totals_inconsistent', $result['context']['reasonCode']);
    }

    private function validMassPayment(): void
    {
        $this->invoice(100, subtotal: '30.00', total: '30.00');
        $this->item(100, 'Invoice', '10.00', 200);
        $this->item(100, 'Invoice', '20.00', 201);
        $this->account(100, '30.00');

        $this->invoice(200, subtotal: '10.00', credit: '10.00', total: '0.00');
        $this->item(200, 'Hosting', '10.00');

        $this->invoice(201, subtotal: '20.00', credit: '20.00', total: '0.00');
        $this->item(201, 'Hosting', '25.00');
        $this->item(201, 'PromoHosting', '-5.00');
    }

    /** @return list<int> */
    private function largeMassPayment(int $parentInvoiceId, int $firstTargetInvoiceId, int $count): array
    {
        $total = number_format($count, 2, '.', '');
        $this->invoice($parentInvoiceId, subtotal: $total, total: $total);
        $this->account($parentInvoiceId, $total);

        $targetInvoiceIds = [];
        for ($offset = 0; $offset < $count; ++$offset) {
            $targetInvoiceId = $firstTargetInvoiceId + $offset;
            $targetInvoiceIds[] = $targetInvoiceId;
            $this->item($parentInvoiceId, 'Invoice', '1.00', $targetInvoiceId);
            $this->invoice(
                $targetInvoiceId,
                subtotal: '1.00',
                credit: '1.00',
                total: '0.00',
            );
            $this->item($targetInvoiceId, 'Hosting', '1.00');
        }

        return $targetInvoiceIds;
    }

    /** @param callable():void $callback */
    private function measureQueries(callable $callback): int
    {
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $callback();

            return count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    private function invoice(
        int $id,
        int $clientId = 1,
        string $status = 'Paid',
        string $subtotal = '10.00',
        string $credit = '0.00',
        string $tax = '0.00',
        string $tax2 = '0.00',
        string $total = '10.00',
    ): void {
        Capsule::table('tblinvoices')->insert([
            'id' => $id,
            'userid' => $clientId,
            'status' => $status,
            'subtotal' => $subtotal,
            'credit' => $credit,
            'tax' => $tax,
            'tax2' => $tax2,
            'total' => $total,
        ]);
    }

    private function item(
        int $invoiceId,
        string $type,
        string $amount,
        ?int $relatedInvoiceId = null,
    ): void {
        Capsule::table('tblinvoiceitems')->insert([
            'invoiceid' => $invoiceId,
            'type' => $type,
            'relid' => $relatedInvoiceId,
            'amount' => $amount,
            'description' => 'Synthetic line',
        ]);
    }

    private function account(
        int $invoiceId,
        string $amountIn,
        string $amountOut = '0.00',
        int $refundId = 0,
    ): void {
        Capsule::table('tblaccounts')->insert([
            'invoiceid' => $invoiceId,
            'amountin' => $amountIn,
            'amountout' => $amountOut,
            'refundid' => $refundId,
            'transid' => 'SYNTHETIC-' . $invoiceId,
            'description' => 'Synthetic payment',
        ]);
    }

    private function service(): WhmcsPaymentStructureService
    {
        return new WhmcsPaymentStructureService();
    }
}
