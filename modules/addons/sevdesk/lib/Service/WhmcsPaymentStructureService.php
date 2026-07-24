<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use InvalidArgumentException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;

/**
 * Classifies WHMCS mass-payment containers and their credited revenue invoices.
 *
 * The service deliberately reads only structural billing data. Descriptions,
 * transaction references and tblcredit are not classification inputs.
 */
final class WhmcsPaymentStructureService
{
    public const CONTAINER_NOT_REVENUE = 'container_not_revenue';
    public const EXACT_MASS_PAYMENT_TARGET = 'exact_mass_payment_target';
    public const CREDIT_REQUIRES_REVIEW = 'credit_requires_review';
    public const ORDINARY_INVOICE = 'ordinary_invoice';
    public const MASS_PAYMENT_REQUIRES_REVIEW = 'mass_payment_requires_review';
    public const SPECIAL_INVOICE_REQUIRES_REVIEW = 'special_invoice_requires_review';
    public const MAPPING_REQUIRES_REVIEW = 'mapping_requires_review';
    public const STRUCTURE_REQUIRES_REVIEW = 'structure_requires_review';
    public const INVOICE_MISSING = 'invoice_missing';

    private const CONTEXT_VERSION = 'whmcs_mass_payment_v1';
    private const MAX_MASS_PAYMENT_PARENTS = 10;
    private const MAX_MASS_PAYMENT_TARGETS = 250;

    private bool $readScopeActive = false;

    /** @var array<int, object|null> */
    private array $invoiceCache = [];

    /** @var array<int, list<object>> */
    private array $itemCache = [];

    /** @var array<int, list<object>> */
    private array $accountCache = [];

    /** @var array<int, string> */
    private array $mappingStateCache = [];

    private ?bool $mappingTableAvailable = null;

    /** @var array<int, list<int>> */
    private array $hookContainerTargets = [];

    /** @var array<int, true> */
    private array $hookKnownTargets = [];

    /** @var array<int, int> */
    private array $hookParentByTarget = [];

    /**
     * @return array{
     *     code:string,
     *     invoiceId:int,
     *     revenueDocument:bool,
     *     requiresReview:bool,
     *     parentInvoiceId:int|null,
     *     targetInvoiceIds:list<int>,
     *     fingerprint:string,
     *     context:array<string, bool|int|string|null>
     * }
     */
    public function classify(int $invoiceId): array
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('The WHMCS invoice ID must be positive.');
        }

        if ($this->readScopeActive) {
            throw new \LogicException('A payment-structure classification cannot be nested.');
        }

        $this->beginReadScope();
        try {
            return $this->classifyFresh($invoiceId);
        } finally {
            $this->endReadScope();
        }
    }

    /**
     * Returns the revenue invoices of an exact Mass Pay container for hooks.
     *
     * WHMCS can invoke the paid hooks once for the container and again for
     * every credited target in the same request. Only exact graphs are kept
     * request-locally; the worker continues to use classify() and therefore
     * always reads a fresh database snapshot.
     *
     * @return list<int>
     */
    public function massPaymentTargetIdsForHook(int $invoiceId): array
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('The WHMCS invoice ID must be positive.');
        }
        if (array_key_exists($invoiceId, $this->hookContainerTargets)) {
            return $this->hookContainerTargets[$invoiceId];
        }
        if (isset($this->hookKnownTargets[$invoiceId])) {
            return [];
        }

        $classification = $this->classify($invoiceId);
        if (($classification['code'] ?? null) === self::CONTAINER_NOT_REVENUE) {
            return $this->rememberExactHookGraph($invoiceId, $classification);
        }
        if (($classification['code'] ?? null) !== self::EXACT_MASS_PAYMENT_TARGET) {
            return [];
        }

        $parentInvoiceId = (int) ($classification['parentInvoiceId'] ?? 0);
        if ($parentInvoiceId < 1) {
            return [];
        }
        $parent = $this->classify($parentInvoiceId);
        if (($parent['code'] ?? null) !== self::CONTAINER_NOT_REVENUE) {
            return [];
        }
        $targets = $this->rememberExactHookGraph($parentInvoiceId, $parent);
        if (!in_array($invoiceId, $targets, true)) {
            throw new \LogicException(
                'The exact mass-payment target is missing from its parent graph.',
            );
        }

        return [];
    }

    /**
     * Returns the request-local graph edge which was proven by the hook read.
     *
     * A target-first InvoicePaid sequence still needs to persist the parent ID,
     * even though only the later container hook expands all revenue invoices.
     *
     * @return array{containerInvoiceId:int|null,targetInvoiceIds:list<int>}
     */
    public function massPaymentContextForHook(int $invoiceId): array
    {
        $targetInvoiceIds = $this->massPaymentTargetIdsForHook($invoiceId);

        return [
            'containerInvoiceId' => $targetInvoiceIds !== []
                ? $invoiceId
                : ($this->hookParentByTarget[$invoiceId] ?? null),
            'targetInvoiceIds' => $targetInvoiceIds,
        ];
    }

    /**
     * @param array<string,mixed> $classification
     * @return list<int>
     */
    private function rememberExactHookGraph(int $parentInvoiceId, array $classification): array
    {
        $rawTargets = $classification['targetInvoiceIds'] ?? null;
        if (!is_array($rawTargets) || $rawTargets === []) {
            return [];
        }

        $targets = [];
        foreach ($rawTargets as $targetInvoiceId) {
            if (
                (!is_int($targetInvoiceId) && !is_string($targetInvoiceId))
                || preg_match('/^[1-9]\d*$/', (string) $targetInvoiceId) !== 1
            ) {
                return [];
            }
            $targetId = (int) $targetInvoiceId;
            if ($targetId === $parentInvoiceId || isset($targets[$targetId])) {
                return [];
            }
            $targets[$targetId] = true;
        }
        if (count($targets) > self::MAX_MASS_PAYMENT_TARGETS) {
            return [];
        }

        $targetIds = array_keys($targets);
        sort($targetIds, SORT_NUMERIC);
        $this->hookContainerTargets[$parentInvoiceId] = $targetIds;
        foreach ($targetIds as $targetId) {
            $knownParentId = $this->hookParentByTarget[$targetId] ?? null;
            if ($knownParentId !== null && $knownParentId !== $parentInvoiceId) {
                throw new \LogicException(
                    'A mass-payment target cannot be bound to two hook parents.',
                );
            }
            $this->hookParentByTarget[$targetId] = $parentInvoiceId;
            $this->hookKnownTargets[$targetId] = true;
        }

        return $targetIds;
    }

    /**
     * @return array{
     *     code:string,
     *     invoiceId:int,
     *     revenueDocument:bool,
     *     requiresReview:bool,
     *     parentInvoiceId:int|null,
     *     targetInvoiceIds:list<int>,
     *     fingerprint:string,
     *     context:array<string, bool|int|string|null>
     * }
     */
    private function classifyFresh(int $invoiceId): array
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null) {
            return $this->result(
                self::INVOICE_MISSING,
                $invoiceId,
                false,
                true,
                null,
                ['invoiceId' => $invoiceId, 'state' => 'missing'],
                ['reasonCode' => 'invoice_missing'],
            );
        }

        try {
            $items = $this->items($invoiceId);
            $accounts = $this->accounts($invoiceId);
            $mappingState = $this->mappingState($invoiceId);
            $invoiceEvidence = $this->invoiceEvidence(
                $invoice,
                $items,
                $accounts,
                $this->stableTargetMappingState(),
            );
            $invoiceTotalMinor = $this->minor($invoice->total);
            $invoiceCreditMinor = $this->minor($invoice->credit);
            $invoiceDocumentGrossMinor = $this->addMinor($invoiceTotalMinor, $invoiceCreditMinor);
            $invoiceCalculatedGrossMinor = $this->calculatedDocumentGrossMinor(
                $this->minor($invoice->subtotal),
                $this->minor($invoice->tax),
                $this->minor($invoice->tax2),
            );
            $baseContext = [
                'invoiceTotalMinor' => $invoiceTotalMinor,
                'invoiceCreditMinor' => $invoiceCreditMinor,
                'invoiceDocumentGrossMinor' => $invoiceDocumentGrossMinor,
                'invoiceItemMinor' => $this->itemSumMinor($items),
                'mappingState' => $mappingState,
            ];

            $completeRevenueMapping = $mappingState === 'complete'
                && $this->invoiceTypeCount($items) === 0;
            if ($mappingState !== 'none' && !$completeRevenueMapping) {
                return $this->result(
                    self::MAPPING_REQUIRES_REVIEW,
                    $invoiceId,
                    false,
                    true,
                    null,
                    ['invoice' => $invoiceEvidence],
                    $baseContext + ['reasonCode' => 'invoice_mapping_present'],
                );
            }

            if ($items === []) {
                return $this->result(
                    self::STRUCTURE_REQUIRES_REVIEW,
                    $invoiceId,
                    false,
                    true,
                    null,
                    ['invoice' => $invoiceEvidence],
                    $baseContext + ['reasonCode' => 'invoice_items_missing'],
                );
            }

            $invoiceTypeCount = $this->invoiceTypeCount($items);
            if ($invoiceTypeCount > 0) {
                if ($invoiceTypeCount !== count($items)) {
                    return $this->result(
                        self::MASS_PAYMENT_REQUIRES_REVIEW,
                        $invoiceId,
                        false,
                        true,
                        null,
                        ['invoice' => $invoiceEvidence],
                        $baseContext + ['reasonCode' => 'mixed_invoice_reference_items'],
                    );
                }

                $container = $this->analyseContainer($invoiceId, true);
                $context = $baseContext + [
                    'reasonCode' => $container['reasonCode'],
                    'parentTotalMinor' => $container['parentTotalMinor'],
                    'parentPaidMinor' => $container['parentPaidMinor'],
                    'linkedInvoiceCount' => $container['linkedInvoiceCount'],
                    'linkedInvoicesHash' => $container['linkedInvoicesHash'],
                ];

                return $this->result(
                    $container['exact']
                        ? self::CONTAINER_NOT_REVENUE
                        : self::MASS_PAYMENT_REQUIRES_REVIEW,
                    $invoiceId,
                    false,
                    !$container['exact'],
                    null,
                    ['container' => $container['evidence']],
                    $context,
                    $container['exact'] ? array_keys($container['links']) : [],
                );
            }

            if ($this->hasAddFundsItem($items)) {
                return $this->result(
                    self::SPECIAL_INVOICE_REQUIRES_REVIEW,
                    $invoiceId,
                    false,
                    true,
                    null,
                    ['invoice' => $invoiceEvidence],
                    $baseContext + ['reasonCode' => 'add_funds_invoice'],
                );
            }

            $creditMinor = $invoiceCreditMinor;
            if ($creditMinor < 0) {
                return $this->result(
                    self::CREDIT_REQUIRES_REVIEW,
                    $invoiceId,
                    false,
                    true,
                    null,
                    ['invoice' => $invoiceEvidence],
                    $baseContext + ['reasonCode' => 'negative_invoice_credit'],
                );
            }
            if (
                $invoiceTotalMinor < 0
                || $invoiceDocumentGrossMinor < 0
                || $invoiceCalculatedGrossMinor !== $invoiceDocumentGrossMinor
            ) {
                return $this->result(
                    self::STRUCTURE_REQUIRES_REVIEW,
                    $invoiceId,
                    false,
                    true,
                    null,
                    ['invoice' => $invoiceEvidence],
                    $baseContext + ['reasonCode' => 'invoice_totals_inconsistent'],
                );
            }

            if ($creditMinor === 0) {
                return $this->result(
                    self::ORDINARY_INVOICE,
                    $invoiceId,
                    true,
                    false,
                    null,
                    ['invoice' => $invoiceEvidence],
                    $baseContext + ['reasonCode' => 'no_applied_credit'],
                );
            }

            return $this->classifyCreditedTarget($invoice, $items, $accounts, $invoiceEvidence, $baseContext);
        } catch (InvalidArgumentException) {
            return $this->result(
                self::STRUCTURE_REQUIRES_REVIEW,
                $invoiceId,
                false,
                true,
                null,
                ['invoiceId' => $invoiceId, 'state' => 'invalid_numeric_structure'],
                ['reasonCode' => 'invalid_numeric_structure'],
            );
        }
    }

    /**
     * @param list<object> $items
     * @param list<object> $accounts
     * @param array<string, mixed> $invoiceEvidence
     * @param array<string, bool|int|string|null> $baseContext
     * @return array{
     *     code:string,
     *     invoiceId:int,
     *     revenueDocument:bool,
     *     requiresReview:bool,
     *     parentInvoiceId:int|null,
     *     targetInvoiceIds:list<int>,
     *     fingerprint:string,
     *     context:array<string, bool|int|string|null>
     * }
     */
    private function classifyCreditedTarget(
        object $invoice,
        array $items,
        array $accounts,
        array $invoiceEvidence,
        array $baseContext,
    ): array {
        $invoiceId = (int) $invoice->id;
        $targetLineMinor = $this->itemSumMinor($items);
        $targetSubtotalMinor = $this->minor($invoice->subtotal);
        $targetTaxMinor = $this->minor($invoice->tax);
        $targetTax2Minor = $this->minor($invoice->tax2);
        $targetTotalMinor = $this->minor($invoice->total);
        $targetCreditMinor = $this->minor($invoice->credit);
        $targetDocumentGrossMinor = $this->addMinor($targetTotalMinor, $targetCreditMinor);
        $targetDirectCashMinor = $targetTotalMinor;
        $targetCalculatedGrossMinor = $this->calculatedDocumentGrossMinor(
            $targetSubtotalMinor,
            $targetTaxMinor,
            $targetTax2Minor,
        );
        $targetPaidMinor = $this->positivePaymentMinor($accounts);
        $parentReferences = $this->referencingParentIds($invoiceId);
        $parentIds = $parentReferences['ids'];
        $targetHasRefund = $this->hasRefundOrChargeback($accounts);

        $review = function (
            string $reasonCode,
            ?int $parentInvoiceId = null,
            array $evidence = [],
        ) use (
            $invoiceId,
            $targetDocumentGrossMinor,
            $targetDirectCashMinor,
            $targetPaidMinor,
            $parentIds,
            $targetHasRefund,
            $invoiceEvidence,
            $baseContext,
        ): array {
            return $this->result(
                self::CREDIT_REQUIRES_REVIEW,
                $invoiceId,
                false,
                true,
                $parentInvoiceId,
                ['invoice' => $invoiceEvidence, 'related' => $evidence],
                $baseContext + [
                    'reasonCode' => $reasonCode,
                    'targetDocumentGrossMinor' => $targetDocumentGrossMinor,
                    'targetDirectCashMinor' => $targetDirectCashMinor,
                    'targetPaidMinor' => $targetPaidMinor,
                    'referencingParentCount' => count($parentIds),
                    'targetHasRefund' => $targetHasRefund,
                ],
            );
        };

        if ($this->normaliseStatus($invoice->status) !== 'paid') {
            return $review('credited_target_not_paid');
        }
        if ($targetHasRefund) {
            return $review('credited_target_refund_or_chargeback');
        }
        if (
            $targetSubtotalMinor <= 0
            || $targetTaxMinor < 0
            || $targetTax2Minor < 0
            || $targetTotalMinor < 0
            || $targetCreditMinor <= 0
            || $targetDocumentGrossMinor <= 0
            || $targetCalculatedGrossMinor !== $targetDocumentGrossMinor
        ) {
            return $review('credited_target_totals_inconsistent');
        }
        if (
            $targetLineMinor
                !== $this->expectedItemSumMinor($targetSubtotalMinor, $targetDocumentGrossMinor)
        ) {
            return $review('credited_target_total_mismatch');
        }
        if ($targetPaidMinor !== $targetDirectCashMinor) {
            return $review('credited_target_payment_mismatch');
        }
        if ($parentReferences['limitExceeded']) {
            return $review('mass_payment_structure_limit_exceeded');
        }
        if ($parentIds === []) {
            return $review('mass_payment_parent_missing');
        }
        if (!$this->primeContainerGraphs($parentIds)) {
            return $review('mass_payment_structure_limit_exceeded');
        }

        $exactParents = [];
        $inactiveParents = [];
        foreach ($parentIds as $parentId) {
            // A target is safe only when its complete parent graph is
            // uncontested. A competing parent on a sibling target invalidates
            // the same payment container and therefore every target in it.
            $candidate = $this->analyseContainer($parentId, true);
            if ($candidate['exact']) {
                $exactParents[$parentId] = $candidate;
                continue;
            }
            if ($candidate['provenInactive']) {
                $inactiveParents[$parentId] = $candidate;
                continue;
            }

            return $review($candidate['reasonCode'], $parentId, $candidate['evidence']);
        }
        if ($exactParents === []) {
            $parentInvoiceId = array_key_first($inactiveParents);
            $inactive = $parentInvoiceId === null ? null : $inactiveParents[$parentInvoiceId];

            return $review(
                $inactive['reasonCode'] ?? 'mass_payment_parent_missing',
                $parentInvoiceId,
                $inactive['evidence'] ?? [],
            );
        }
        if (count($exactParents) !== 1) {
            return $review('multiple_mass_payment_parents', evidence: [
                'exactParentIds' => array_keys($exactParents),
            ]);
        }

        $parentInvoiceId = array_key_first($exactParents);
        if ($parentInvoiceId === null) {
            return $review('mass_payment_parent_missing');
        }
        $container = $exactParents[$parentInvoiceId];

        $linkMinor = $container['links'][$invoiceId] ?? null;
        if (!is_int($linkMinor) || $linkMinor <= 0 || $linkMinor !== $this->minor($invoice->credit)) {
            return $review('mass_payment_link_credit_mismatch', $parentInvoiceId, $container['evidence']);
        }

        return $this->result(
            self::EXACT_MASS_PAYMENT_TARGET,
            $invoiceId,
            true,
            false,
            $parentInvoiceId,
            [
                'invoice' => $invoiceEvidence,
                'container' => $container['evidence'],
                'ignoredInactiveParents' => array_map(
                    static fn (array $inactive): array => $inactive['evidence'],
                    $inactiveParents,
                ),
            ],
            $baseContext + [
                'reasonCode' => 'exact_mass_payment_chain',
                'targetDocumentGrossMinor' => $targetDocumentGrossMinor,
                'targetDirectCashMinor' => $targetDirectCashMinor,
                'targetPaidMinor' => $targetPaidMinor,
                'referencingParentCount' => count($parentIds),
                'ignoredInactiveParentCount' => count($inactiveParents),
                'targetHasRefund' => false,
                'linkAmountMinor' => $linkMinor,
                'parentTotalMinor' => $container['parentTotalMinor'],
                'parentPaidMinor' => $container['parentPaidMinor'],
                'linkedInvoiceCount' => $container['linkedInvoiceCount'],
                'linkedInvoicesHash' => $container['linkedInvoicesHash'],
            ],
        );
    }

    /**
     * @return array{
     *     exact:bool,
     *     provenInactive:bool,
     *     reasonCode:string,
     *     parentTotalMinor:int|null,
     *     parentPaidMinor:int|null,
     *     linkedInvoiceCount:int,
     *     linkedInvoicesHash:string|null,
     *     links:array<int, int>,
     *     evidence:array<string, mixed>
     * }
     */
    private function analyseContainer(int $invoiceId, bool $requireUniqueParent): array
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null) {
            return $this->containerFailure('mass_payment_parent_missing');
        }

        $items = $this->items($invoiceId);
        $accounts = $this->accounts($invoiceId);
        $mappingState = $this->mappingState($invoiceId);
        $evidence = [
            'parent' => $this->invoiceEvidence($invoice, $items, $accounts, $mappingState),
            'targets' => [],
        ];
        $parentTotalMinor = $this->minor($invoice->total);
        $parentPaidMinor = $this->positivePaymentMinor($accounts);
        $links = [];
        $parentStatus = $this->normaliseStatus($invoice->status);
        $provenInactive = $mappingState === 'none'
            && $items !== []
            && $this->invoiceTypeCount($items) === count($items)
            && in_array($parentStatus, ['unpaid', 'cancelled'], true)
            && $this->minor($invoice->credit) === 0
            && $parentPaidMinor === 0
            && !$this->hasRefundOrChargeback($accounts);

        $failure = function (string $reasonCode) use (
            &$evidence,
            $parentTotalMinor,
            $parentPaidMinor,
            &$links,
            $provenInactive,
        ): array {
            return $this->containerFailure(
                $reasonCode,
                $parentTotalMinor,
                $parentPaidMinor,
                $links,
                $evidence,
                $provenInactive,
            );
        };

        if ($mappingState !== 'none') {
            return $failure('mass_payment_parent_mapping_present');
        }
        if ($items === [] || $this->invoiceTypeCount($items) !== count($items)) {
            return $failure('mass_payment_parent_not_pure');
        }
        if (count($items) > self::MAX_MASS_PAYMENT_TARGETS) {
            return $failure('mass_payment_structure_limit_exceeded');
        }
        if ($this->normaliseStatus($invoice->status) !== 'paid') {
            return $failure('mass_payment_parent_not_paid');
        }
        if ($this->minor($invoice->credit) !== 0) {
            return $failure('mass_payment_parent_has_credit');
        }
        if ($parentTotalMinor <= 0) {
            return $failure('mass_payment_parent_total_not_positive');
        }
        if (
            $this->minor($invoice->tax) !== 0
            || $this->minor($invoice->tax2) !== 0
            || $this->minor($invoice->subtotal) !== $parentTotalMinor
        ) {
            return $failure('mass_payment_parent_totals_inconsistent');
        }
        if ($this->hasRefundOrChargeback($accounts)) {
            return $failure('mass_payment_parent_refund_or_chargeback');
        }
        if ($parentPaidMinor <= 0 || $parentPaidMinor !== $parentTotalMinor) {
            return $failure('mass_payment_parent_payment_mismatch');
        }

        foreach ($items as $item) {
            $targetId = $this->positiveId($item->relid ?? null);
            $amountMinor = $this->minor($item->amount ?? null);
            if ($targetId === null || $amountMinor <= 0) {
                return $failure('mass_payment_link_invalid');
            }
            if (array_key_exists($targetId, $links)) {
                return $failure('mass_payment_target_duplicated');
            }
            $links[$targetId] = $amountMinor;
        }
        ksort($links, SORT_NUMERIC);

        if ($this->sumMinor(array_values($links)) !== $parentTotalMinor) {
            return $failure('mass_payment_parent_line_total_mismatch');
        }

        $this->primeInvoiceData(array_keys($links), false);
        foreach ($links as $targetId => $linkMinor) {
            $target = $this->invoice($targetId);
            if ($target === null) {
                return $failure('mass_payment_target_missing');
            }
            $targetItems = $this->items($targetId);
            $targetAccounts = $this->accounts($targetId);
            $targetEvidence = $this->invoiceEvidence(
                $target,
                $targetItems,
                $targetAccounts,
                $this->stableTargetMappingState(),
            );
            $evidence['targets'][] = $targetEvidence;

            if ((int) $target->userid !== (int) $invoice->userid) {
                return $failure('mass_payment_target_client_mismatch');
            }
            if ($this->normaliseStatus($target->status) !== 'paid') {
                return $failure('mass_payment_target_not_paid');
            }
            if ($targetItems === [] || $this->invoiceTypeCount($targetItems) > 0) {
                return $failure('mass_payment_target_structure_invalid');
            }
            if ($this->hasAddFundsItem($targetItems)) {
                return $failure('mass_payment_target_add_funds');
            }
            if ($this->hasRefundOrChargeback($targetAccounts)) {
                return $failure('mass_payment_target_refund_or_chargeback');
            }
            if ($this->minor($target->credit) !== $linkMinor) {
                return $failure('mass_payment_link_credit_mismatch');
            }

            $targetSubtotalMinor = $this->minor($target->subtotal);
            $targetTaxMinor = $this->minor($target->tax);
            $targetTax2Minor = $this->minor($target->tax2);
            $targetTotalMinor = $this->minor($target->total);
            $targetCreditMinor = $this->minor($target->credit);
            $targetDocumentGrossMinor = $this->addMinor($targetTotalMinor, $targetCreditMinor);
            $targetDirectCashMinor = $targetTotalMinor;
            $targetCalculatedGrossMinor = $this->calculatedDocumentGrossMinor(
                $targetSubtotalMinor,
                $targetTaxMinor,
                $targetTax2Minor,
            );
            if (
                $targetSubtotalMinor <= 0
                || $targetTaxMinor < 0
                || $targetTax2Minor < 0
                || $targetTotalMinor < 0
                || $targetDocumentGrossMinor <= 0
                || $targetCalculatedGrossMinor !== $targetDocumentGrossMinor
            ) {
                return $failure('mass_payment_target_totals_inconsistent');
            }
            if (
                $this->itemSumMinor($targetItems)
                    !== $this->expectedItemSumMinor($targetSubtotalMinor, $targetDocumentGrossMinor)
            ) {
                return $failure('mass_payment_target_total_mismatch');
            }
            if ($this->positivePaymentMinor($targetAccounts) !== $targetDirectCashMinor) {
                return $failure('mass_payment_target_payment_mismatch');
            }
        }

        if ($requireUniqueParent) {
            $parentConflict = $this->conflictingParentReason($invoiceId, array_keys($links));
            if ($parentConflict !== null) {
                return $failure($parentConflict);
            }
        }

        return [
            'exact' => true,
            'provenInactive' => false,
            'reasonCode' => 'exact_mass_payment_container',
            'parentTotalMinor' => $parentTotalMinor,
            'parentPaidMinor' => $parentPaidMinor,
            'linkedInvoiceCount' => count($links),
            'linkedInvoicesHash' => $this->linksHash($links),
            'links' => $links,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<int, int> $links
     * @param array<string, mixed> $evidence
     * @return array{
     *     exact:false,
     *     provenInactive:bool,
     *     reasonCode:string,
     *     parentTotalMinor:int|null,
     *     parentPaidMinor:int|null,
     *     linkedInvoiceCount:int,
     *     linkedInvoicesHash:string|null,
     *     links:array<int, int>,
     *     evidence:array<string, mixed>
     * }
     */
    private function containerFailure(
        string $reasonCode,
        ?int $parentTotalMinor = null,
        ?int $parentPaidMinor = null,
        array $links = [],
        array $evidence = [],
        bool $provenInactive = false,
    ): array {
        ksort($links, SORT_NUMERIC);

        return [
            'exact' => false,
            'provenInactive' => $provenInactive,
            'reasonCode' => $reasonCode,
            'parentTotalMinor' => $parentTotalMinor,
            'parentPaidMinor' => $parentPaidMinor,
            'linkedInvoiceCount' => count($links),
            'linkedInvoicesHash' => $links !== [] ? $this->linksHash($links) : null,
            'links' => $links,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param list<object> $items
     * @param list<object> $accounts
     * @return array<string, mixed>
     */
    private function invoiceEvidence(
        object $invoice,
        array $items,
        array $accounts,
        string $mappingState,
    ): array {
        $itemEvidence = [];
        foreach ($items as $item) {
            $itemEvidence[] = [
                'id' => (int) $item->id,
                'type' => $this->normaliseType($item->type ?? null),
                'relid' => $this->positiveId($item->relid ?? null),
                'amountMinor' => $this->minor($item->amount ?? null),
            ];
        }

        $accountEvidence = [];
        foreach ($accounts as $account) {
            $accountEvidence[] = [
                'id' => (int) $account->id,
                'amountInMinor' => $this->minor($account->amountin ?? null),
                'amountOutMinor' => $this->minor($account->amountout ?? null),
                'refundId' => max(0, (int) ($account->refundid ?? 0)),
            ];
        }

        return [
            'id' => (int) $invoice->id,
            'clientId' => (int) $invoice->userid,
            'status' => $this->normaliseStatus($invoice->status),
            'subtotalMinor' => $this->minor($invoice->subtotal),
            'creditMinor' => $this->minor($invoice->credit),
            'taxMinor' => $this->minor($invoice->tax),
            'tax2Minor' => $this->minor($invoice->tax2),
            'totalMinor' => $this->minor($invoice->total),
            'mappingState' => $mappingState,
            'items' => $itemEvidence,
            'accounts' => $accountEvidence,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, bool|int|string|null> $context
     * @param list<int> $targetInvoiceIds
     * @return array{
     *     code:string,
     *     invoiceId:int,
     *     revenueDocument:bool,
     *     requiresReview:bool,
     *     parentInvoiceId:int|null,
     *     targetInvoiceIds:list<int>,
     *     fingerprint:string,
     *     context:array<string, bool|int|string|null>
     * }
     */
    private function result(
        string $code,
        int $invoiceId,
        bool $revenueDocument,
        bool $requiresReview,
        ?int $parentInvoiceId,
        array $evidence,
        array $context,
        array $targetInvoiceIds = [],
    ): array {
        $targetInvoiceIds = array_values(array_unique(array_filter(
            array_map('intval', $targetInvoiceIds),
            static fn (int $targetId): bool => $targetId > 0 && $targetId !== $invoiceId,
        )));
        sort($targetInvoiceIds, SORT_NUMERIC);
        $fingerprintEvidence = [
            'version' => self::CONTEXT_VERSION,
            'code' => $code,
            'invoiceId' => $invoiceId,
            'parentInvoiceId' => $parentInvoiceId,
            'evidence' => $evidence,
        ];
        $encoded = json_encode(
            $this->canonicalise($fingerprintEvidence),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return [
            'code' => $code,
            'invoiceId' => $invoiceId,
            'revenueDocument' => $revenueDocument,
            'requiresReview' => $requiresReview,
            'parentInvoiceId' => $parentInvoiceId,
            'targetInvoiceIds' => $targetInvoiceIds,
            'fingerprint' => hash('sha256', $encoded),
            'context' => [
                'version' => self::CONTEXT_VERSION,
                'invoiceId' => $invoiceId,
                'parentInvoiceId' => $parentInvoiceId,
            ] + $context,
        ];
    }

    private function beginReadScope(): void
    {
        $this->readScopeActive = true;
        $this->invoiceCache = [];
        $this->itemCache = [];
        $this->accountCache = [];
        $this->mappingStateCache = [];
        $this->mappingTableAvailable = null;
    }

    private function endReadScope(): void
    {
        $this->invoiceCache = [];
        $this->itemCache = [];
        $this->accountCache = [];
        $this->mappingStateCache = [];
        $this->mappingTableAvailable = null;
        $this->readScopeActive = false;
    }

    /**
     * Batches every table read needed for one connected payment graph. The
     * caches live only for the current classify() call, so a later worker or
     * hook pass always observes a fresh database snapshot.
     *
     * @param list<int> $invoiceIds
     */
    private function primeInvoiceData(array $invoiceIds, bool $includeMappings): void
    {
        $invoiceIds = $this->normaliseInvoiceIds($invoiceIds);
        if ($invoiceIds === []) {
            return;
        }

        $this->primeInvoices($invoiceIds);
        $this->primeItems($invoiceIds);
        $this->primeAccounts($invoiceIds);
        if ($includeMappings) {
            $this->primeMappingStates($invoiceIds);
        }
    }

    /**
     * Loads all candidate parents and their target invoices in a fixed number
     * of queries. Oversized graphs fail closed before a large whereIn() read.
     *
     * @param list<int> $parentInvoiceIds
     */
    private function primeContainerGraphs(array $parentInvoiceIds): bool
    {
        if (count($parentInvoiceIds) > self::MAX_MASS_PAYMENT_PARENTS) {
            return false;
        }

        $this->primeInvoiceData($parentInvoiceIds, true);
        $targetInvoiceIds = [];
        foreach ($parentInvoiceIds as $parentInvoiceId) {
            $items = $this->items($parentInvoiceId);
            if ($items === [] || $this->invoiceTypeCount($items) !== count($items)) {
                continue;
            }
            if (count($items) > self::MAX_MASS_PAYMENT_TARGETS) {
                return false;
            }
            foreach ($items as $item) {
                $targetInvoiceId = $this->positiveId($item->relid ?? null);
                if ($targetInvoiceId === null) {
                    continue;
                }
                $targetInvoiceIds[$targetInvoiceId] = true;
                if (count($targetInvoiceIds) > self::MAX_MASS_PAYMENT_TARGETS) {
                    return false;
                }
            }
        }

        $this->primeInvoiceData(array_keys($targetInvoiceIds), false);

        return true;
    }

    /** @param list<int> $invoiceIds */
    private function primeInvoices(array $invoiceIds): void
    {
        $missing = array_values(array_filter(
            $this->normaliseInvoiceIds($invoiceIds),
            fn (int $invoiceId): bool => !array_key_exists($invoiceId, $this->invoiceCache),
        ));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $invoiceId) {
            $this->invoiceCache[$invoiceId] = null;
        }
        foreach (
            Capsule::table('tblinvoices')
                ->whereIn('id', $missing)
                ->orderBy('id')
                ->get([
                    'id',
                    'userid',
                    'status',
                    'subtotal',
                    'credit',
                    'tax',
                    'tax2',
                    'total',
                ]) as $invoice
        ) {
            $invoiceId = $this->positiveId($invoice->id ?? null);
            if ($invoiceId !== null && array_key_exists($invoiceId, $this->invoiceCache)) {
                $this->invoiceCache[$invoiceId] = $invoice;
            }
        }
    }

    /** @param list<int> $invoiceIds */
    private function primeItems(array $invoiceIds): void
    {
        $missing = array_values(array_filter(
            $this->normaliseInvoiceIds($invoiceIds),
            fn (int $invoiceId): bool => !array_key_exists($invoiceId, $this->itemCache),
        ));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $invoiceId) {
            $this->itemCache[$invoiceId] = [];
        }
        foreach (
            Capsule::table('tblinvoiceitems')
                ->whereIn('invoiceid', $missing)
                ->orderBy('invoiceid')
                ->orderBy('id')
                ->get(['id', 'invoiceid', 'type', 'relid', 'amount']) as $item
        ) {
            $invoiceId = $this->positiveId($item->invoiceid ?? null);
            if ($invoiceId !== null && array_key_exists($invoiceId, $this->itemCache)) {
                $this->itemCache[$invoiceId][] = $item;
            }
        }
    }

    /** @param list<int> $invoiceIds */
    private function primeAccounts(array $invoiceIds): void
    {
        $missing = array_values(array_filter(
            $this->normaliseInvoiceIds($invoiceIds),
            fn (int $invoiceId): bool => !array_key_exists($invoiceId, $this->accountCache),
        ));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $invoiceId) {
            $this->accountCache[$invoiceId] = [];
        }
        $ownerByAccountId = [];
        $seenAccountIds = [];
        foreach (
            Capsule::table('tblaccounts')
                ->whereIn('invoiceid', $missing)
                ->orderBy('invoiceid')
                ->orderBy('id')
                ->get(['id', 'invoiceid', 'amountin', 'amountout', 'refundid']) as $account
        ) {
            $invoiceId = $this->positiveId($account->invoiceid ?? null);
            $accountId = $this->positiveId($account->id ?? null);
            if (
                $invoiceId !== null
                && $accountId !== null
                && array_key_exists($invoiceId, $this->accountCache)
            ) {
                $this->accountCache[$invoiceId][] = $account;
                $ownerByAccountId[$accountId] = $invoiceId;
                $seenAccountIds[$invoiceId][$accountId] = true;
            }
        }
        if ($ownerByAccountId === []) {
            return;
        }

        // WHMCS may store a refund as a separate account row whose invoiceid
        // is no longer the original invoice. The refundid still points to the
        // original positive payment, so include that reverse edge in the
        // original invoice's immutable payment evidence.
        foreach (
            Capsule::table('tblaccounts')
                ->whereIn('refundid', array_keys($ownerByAccountId))
                ->orderBy('id')
                ->get(['id', 'invoiceid', 'amountin', 'amountout', 'refundid']) as $refund
        ) {
            $refundId = $this->positiveId($refund->id ?? null);
            $originalAccountId = $this->positiveId($refund->refundid ?? null);
            $ownerInvoiceId = $originalAccountId !== null
                ? ($ownerByAccountId[$originalAccountId] ?? null)
                : null;
            if (
                $refundId === null
                || $ownerInvoiceId === null
                || isset($seenAccountIds[$ownerInvoiceId][$refundId])
            ) {
                continue;
            }
            $this->accountCache[$ownerInvoiceId][] = $refund;
            $seenAccountIds[$ownerInvoiceId][$refundId] = true;
        }
        foreach ($missing as $invoiceId) {
            usort(
                $this->accountCache[$invoiceId],
                static fn (object $left, object $right): int =>
                    (int) ($left->id ?? 0) <=> (int) ($right->id ?? 0),
            );
        }
    }

    /** @param list<int> $invoiceIds */
    private function primeMappingStates(array $invoiceIds): void
    {
        $missing = array_values(array_filter(
            $this->normaliseInvoiceIds($invoiceIds),
            fn (int $invoiceId): bool => !array_key_exists($invoiceId, $this->mappingStateCache),
        ));
        if ($missing === []) {
            return;
        }

        $this->mappingTableAvailable ??= Capsule::schema()->hasTable(Migrator::MAPPING_TABLE);
        if (!$this->mappingTableAvailable) {
            foreach ($missing as $invoiceId) {
                $this->mappingStateCache[$invoiceId] = 'unavailable';
            }

            return;
        }

        $rowsByInvoice = [];
        foreach (
            Capsule::table(Migrator::MAPPING_TABLE)
                ->whereIn('invoice_id', $missing)
                ->orderBy('invoice_id')
                ->orderBy('id')
                ->get(['id', 'invoice_id', 'sevdesk_id']) as $mapping
        ) {
            $invoiceId = $this->positiveId($mapping->invoice_id ?? null);
            if ($invoiceId !== null) {
                $rowsByInvoice[$invoiceId][] = $mapping;
            }
        }

        foreach ($missing as $invoiceId) {
            $rows = $rowsByInvoice[$invoiceId] ?? [];
            if ($rows === []) {
                $this->mappingStateCache[$invoiceId] = 'none';
            } elseif (count($rows) !== 1) {
                $this->mappingStateCache[$invoiceId] = 'conflict';
            } else {
                $this->mappingStateCache[$invoiceId] =
                    trim((string) ($rows[0]->sevdesk_id ?? '')) !== ''
                        ? 'complete'
                        : 'incomplete';
            }
        }
    }

    /**
     * @param list<int> $invoiceIds
     * @return list<int>
     */
    private function normaliseInvoiceIds(array $invoiceIds): array
    {
        $normalised = [];
        foreach ($invoiceIds as $invoiceId) {
            if ($invoiceId > 0) {
                $normalised[$invoiceId] = true;
            }
        }
        $ids = array_keys($normalised);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function invoice(int $invoiceId): ?object
    {
        $this->primeInvoices([$invoiceId]);

        return $this->invoiceCache[$invoiceId];
    }

    /** @return list<object> */
    private function items(int $invoiceId): array
    {
        $this->primeItems([$invoiceId]);

        return $this->itemCache[$invoiceId];
    }

    /** @return list<object> */
    private function accounts(int $invoiceId): array
    {
        $this->primeAccounts([$invoiceId]);

        return $this->accountCache[$invoiceId];
    }

    /**
     * @return array{ids:list<int>, limitExceeded:bool}
     */
    private function referencingParentIds(int $targetInvoiceId): array
    {
        $parentIds = [];
        foreach (
            Capsule::table('tblinvoiceitems')
                ->where('relid', $targetInvoiceId)
                ->whereRaw('LOWER(TRIM(type)) = ?', ['invoice'])
                ->distinct()
                ->orderBy('invoiceid')
                ->limit(self::MAX_MASS_PAYMENT_PARENTS + 1)
                ->get(['invoiceid']) as $item
        ) {
            $parentId = $this->positiveId($item->invoiceid ?? null);
            if ($parentId !== null) {
                $parentIds[$parentId] = true;
            }
        }

        $ids = array_keys($parentIds);
        sort($ids, SORT_NUMERIC);

        return [
            'ids' => $ids,
            'limitExceeded' => count($ids) > self::MAX_MASS_PAYMENT_PARENTS,
        ];
    }

    /**
     * A container is hook-safe only when none of its targets belongs to a
     * second active Mass Pay attempt. Inactive, unpaid attempts remain
     * harmless; every other competing parent keeps the whole graph in review.
     *
     * @param list<int> $targetInvoiceIds
     */
    private function conflictingParentReason(int $parentInvoiceId, array $targetInvoiceIds): ?string
    {
        $targetInvoiceIds = $this->normaliseInvoiceIds($targetInvoiceIds);
        if ($targetInvoiceIds === []) {
            return 'mass_payment_parent_missing';
        }

        $maximumReferenceRows = count($targetInvoiceIds)
            * (self::MAX_MASS_PAYMENT_PARENTS + 1);
        $rows = Capsule::table('tblinvoiceitems')
            ->whereIn('relid', $targetInvoiceIds)
            ->whereRaw('LOWER(TRIM(type)) = ?', ['invoice'])
            ->distinct()
            ->orderBy('relid')
            ->orderBy('invoiceid')
            ->limit($maximumReferenceRows + 1)
            ->get(['invoiceid', 'relid']);
        if (count($rows) > $maximumReferenceRows) {
            return 'mass_payment_structure_limit_exceeded';
        }

        $parentsByTarget = [];
        $otherParentIds = [];
        foreach ($rows as $row) {
            $targetId = $this->positiveId($row->relid ?? null);
            $candidateParentId = $this->positiveId($row->invoiceid ?? null);
            if ($targetId === null || $candidateParentId === null) {
                return 'mass_payment_structure_limit_exceeded';
            }
            $parentsByTarget[$targetId][$candidateParentId] = true;
            if (count($parentsByTarget[$targetId]) > self::MAX_MASS_PAYMENT_PARENTS) {
                return 'mass_payment_structure_limit_exceeded';
            }
            if ($candidateParentId !== $parentInvoiceId) {
                $otherParentIds[$candidateParentId] = true;
            }
        }

        $otherIds = array_keys($otherParentIds);
        sort($otherIds, SORT_NUMERIC);
        if ($otherIds === []) {
            return null;
        }
        if (!$this->primeContainerGraphs($otherIds)) {
            return 'mass_payment_structure_limit_exceeded';
        }

        foreach ($otherIds as $otherParentId) {
            $other = $this->analyseContainer($otherParentId, false);
            if (!$other['provenInactive']) {
                return 'multiple_mass_payment_parents';
            }
        }

        return null;
    }

    private function mappingState(int $invoiceId): string
    {
        $this->primeMappingStates([$invoiceId]);

        return $this->mappingStateCache[$invoiceId];
    }

    /**
     * Target mappings are guarded for the invoice currently being exported.
     * A sibling's mapping is not evidence about the WHMCS payment chain and
     * therefore cannot alter or block another target's immutable fingerprint.
     */
    private function stableTargetMappingState(): string
    {
        return 'not_part_of_payment_structure';
    }

    /** @param list<object> $items */
    private function invoiceTypeCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if ($this->normaliseType($item->type ?? null) === 'invoice') {
                ++$count;
            }
        }

        return $count;
    }

    /** @param list<object> $items */
    private function hasAddFundsItem(array $items): bool
    {
        foreach ($items as $item) {
            if ($this->normaliseType($item->type ?? null) === 'addfunds') {
                return true;
            }
        }

        return false;
    }

    /** @param list<object> $accounts */
    private function hasRefundOrChargeback(array $accounts): bool
    {
        foreach ($accounts as $account) {
            if (
                $this->minor($account->amountin ?? null) < 0
                || $this->minor($account->amountout ?? null) !== 0
                || (int) ($account->refundid ?? 0) > 0
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param list<object> $accounts */
    private function positivePaymentMinor(array $accounts): int
    {
        $amounts = [];
        foreach ($accounts as $account) {
            $amount = $this->minor($account->amountin ?? null);
            if ($amount > 0) {
                $amounts[] = $amount;
            }
        }

        return $this->sumMinor($amounts);
    }

    /** @param list<object> $items */
    private function itemSumMinor(array $items): int
    {
        $amounts = [];
        foreach ($items as $item) {
            $amounts[] = $this->minor($item->amount ?? null);
        }

        return $this->sumMinor($amounts);
    }

    /** @param list<int> $amounts */
    private function sumMinor(array $amounts): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            $sum = $this->addMinor($sum, $amount);
        }

        return $sum;
    }

    private function addMinor(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new InvalidArgumentException('The WHMCS amount sum is outside the supported range.');
        }

        return $left + $right;
    }

    private function calculatedDocumentGrossMinor(
        int $subtotalMinor,
        int $taxMinor,
        int $tax2Minor,
    ): int {
        return $this->addMinor($this->addMinor($subtotalMinor, $taxMinor), $tax2Minor);
    }

    private function expectedItemSumMinor(int $subtotalMinor, int $documentGrossMinor): int
    {
        return match (strtolower(trim((string) ($GLOBALS['CONFIG']['TaxType'] ?? 'Exclusive')))) {
            'exclusive' => $subtotalMinor,
            'inclusive' => $documentGrossMinor,
            default => throw new InvalidArgumentException('The WHMCS TaxType is unsupported.'),
        };
    }

    private function minor(mixed $value): int
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('A WHMCS amount is missing.');
        }

        return Decimal::toMinorUnits((string) $value);
    }

    private function positiveId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $normalised = trim((string) $value);
        if (preg_match('/^[1-9]\d*$/', $normalised) !== 1) {
            return null;
        }
        $id = filter_var($normalised, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) ? $id : null;
    }

    private function normaliseType(mixed $value): string
    {
        return is_scalar($value) ? strtolower(trim((string) $value)) : '';
    }

    private function normaliseStatus(mixed $value): string
    {
        return is_scalar($value) ? strtolower(trim((string) $value)) : '';
    }

    /** @param array<int, int> $links */
    private function linksHash(array $links): string
    {
        ksort($links, SORT_NUMERIC);

        return hash('sha256', json_encode($links, JSON_THROW_ON_ERROR));
    }

    private function canonicalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalise($item);
        }

        return $value;
    }
}
