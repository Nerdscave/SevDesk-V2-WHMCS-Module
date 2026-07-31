<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use DateTimeImmutable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Repository\RelatedDocumentRepository;

/**
 * Narrow sevDesk integration for MA, SR and the separate Rule-22 fee voucher.
 * Every method either proves one exact object or refuses to write.
 */
final class DunningService
{
    public function __construct(
        private readonly SevdeskClient $client,
        private readonly RelatedDocumentRepository $documents,
        private readonly InvoicePdf $invoicePdf,
        private readonly InvoiceXml $invoiceXml,
    ) {
    }

    public function primaryInvoiceMismatch(
        string $remoteId,
        string $contactId,
        string $invoiceNumber,
        int $serviceGrossMinor,
        bool $requireOpen,
    ): ?string {
        $invoice = self::one(
            $this->client->get('/Invoice/' . rawurlencode($remoteId)),
            'Invoice',
        );
        if (self::numericId($invoice['id'] ?? null) !== $remoteId) {
            return 'primary_invoice_id_mismatch';
        }
        if (self::numericId($invoice['contact']['id'] ?? null) !== $contactId) {
            return 'primary_invoice_contact_mismatch';
        }
        if (trim((string) ($invoice['invoiceNumber'] ?? '')) !== $invoiceNumber) {
            return 'primary_invoice_number_mismatch';
        }
        if (!self::sameMinor($invoice['sumGross'] ?? null, $serviceGrossMinor)) {
            return 'primary_invoice_total_mismatch';
        }
        $status = (int) ($invoice['status'] ?? 0);
        if ($requireOpen && $status !== 200) {
            return 'primary_invoice_not_open';
        }
        if ($requireOpen && !self::sameMinor($invoice['paidAmount'] ?? null, 0)) {
            return 'primary_invoice_payment_state_mismatch';
        }
        if (!$requireOpen && !in_array($status, [200, 1000], true)) {
            return 'primary_invoice_status_invalid';
        }

        return null;
    }

    /**
     * @param array{
     *   invoiceId:int,parentRemoteId:string,contactId:string,dunningLevel:int,
     *   serviceGrossMinor:int,lateFeeMinor:int,fingerprint:string,
     *   reminderDeadline:string,toEmail:string,subject:string,text:string,
     *   deliver:bool,createAllowed:bool,resumeDeliverySafe:bool,expectedRemoteId:?string
     * } $request
     * @param callable(string,array<string,scalar|null>):bool $checkpoint
     * @return array{status:string,code:string,message:string,remoteId?:string}
     */
    public function createReminder(array $request, callable $checkpoint): array
    {
        $request = $this->validateReminderRequest($request);
        $mapped = $this->documents->find(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_REMINDER,
            $request['dunningLevel'],
        );
        if ($mapped !== null) {
            return $this->mappedInvoiceOutcome(
                $mapped,
                $request,
                'reminder_delivery',
                'reminder_already_mapped',
                'Die Mahnstufe ist bereits eindeutig zugeordnet.',
                $checkpoint,
            );
        }

        $checkpoint = Closure::fromCallable($checkpoint);
        $existing = $request['expectedRemoteId'] === null
            ? $this->findRelatedInvoice(
                'MA',
                $request['parentRemoteId'],
                $request['contactId'],
                $request['dunningLevel'],
            )
            : self::one(
                $this->client->get(
                    '/Invoice/' . rawurlencode($request['expectedRemoteId']),
                    ['embed' => 'origin,contact'],
                ),
                'Invoice',
            );
        if ($existing === null) {
            if (!$request['createAllowed']) {
                return self::result(
                    'ambiguous',
                    'reminder_create_outcome_unproven',
                    'Nach dem früheren Create-Checkpoint ist keine eindeutige Mahnung auffindbar; '
                        . 'es erfolgt kein zweiter Create.',
                );
            }
            if (!$checkpoint('reminder_create_requested', self::checkpointContext($request))) {
                return self::result('failed', 'checkpoint_persist_failed', 'Der Mahnungs-Checkpoint fehlt.');
            }
            $response = $this->client->post(
                '/Invoice/Factory/createInvoiceReminder',
                ['invoice' => ['id' => (int) $request['parentRemoteId'], 'objectName' => 'Invoice']],
                true,
                [200],
            );
            $created = self::one($response, 'Invoice');
            $remoteId = self::numericId($created['id'] ?? null);
            $existing = $created;
            if ($remoteId === null) {
                return self::result(
                    'ambiguous',
                    'reminder_id_missing',
                    'sevDesk hat keine eindeutige Mahnungs-ID geliefert.',
                );
            }
            if (
                !$checkpoint('reminder_created', [
                ...self::checkpointContext($request),
                'remoteId' => $remoteId,
                ])
            ) {
                return self::result(
                    'ambiguous',
                    'checkpoint_persist_failed_after_reminder',
                    'Die Mahnung existiert, ihr Ergebnis konnte aber nicht gespeichert werden.',
                    $remoteId,
                );
            }
        }
        if (
            $request['expectedRemoteId'] !== null
            && self::numericId($existing['id'] ?? null) !== $request['expectedRemoteId']
        ) {
            return self::result(
                'ambiguous',
                'reminder_recovery_id_mismatch',
                'Der direkte Mahnungs-Readback lieferte eine andere ID als der gespeicherte Checkpoint.',
            );
        }
        $remoteId = self::numericId($existing['id'] ?? null);
        if ($remoteId !== null) {
            $existing = self::one(
                $this->client->get(
                    '/Invoice/' . rawurlencode($remoteId),
                    ['embed' => 'origin,contact'],
                ),
                'Invoice',
            );
        }

        $mismatch = $this->reminderMismatch($existing, $request);
        if ($mismatch !== null) {
            return self::result(
                'ambiguous',
                $mismatch,
                'Die sevDesk-Mahnung entspricht nicht centgenau dem eingefrorenen WHMCS-Vertrag.',
                self::numericId($existing['id'] ?? null),
            );
        }
        $remoteId = self::numericId($existing['id'] ?? null);
        if ($remoteId === null) {
            return self::result('ambiguous', 'reminder_id_invalid', 'Die Mahnungs-ID ist ungültig.');
        }
        $pdf = $this->invoicePdf->fetch($remoteId);
        if (
            !$checkpoint('reminder_verified', [
            ...self::checkpointContext($request),
            'remoteId' => $remoteId,
            'pdfSha256' => $pdf['sha256'],
            ])
        ) {
            return self::result(
                'ambiguous',
                'reminder_verification_checkpoint_failed',
                'Die Mahnung und PDF sind geprüft, der Checkpoint fehlt jedoch.',
                $remoteId,
            );
        }
        $this->documents->linkVerified(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_REMINDER,
            $request['dunningLevel'],
            $remoteId,
            $request['parentRemoteId'],
            $request['lateFeeMinor'],
            $request['fingerprint'],
            self::documentNumber($existing),
            $pdf['sha256'],
            null,
            $request['deliver'] ? 'pending' : 'not_requested',
        );
        if (
            !$checkpoint('reminder_mapping_persisted', [
            ...self::checkpointContext($request),
            'remoteId' => $remoteId,
            ])
        ) {
            return self::result(
                'ambiguous',
                'reminder_mapping_checkpoint_failed',
                'Die Mahnungszuordnung wurde gespeichert, ihr Checkpoint fehlt jedoch.',
                $remoteId,
            );
        }

        if (!$request['deliver']) {
            return self::result(
                'succeeded',
                'reminder_created_mail_free',
                'Die Mahnung wurde geprüft und mailfrei zugeordnet.',
                $remoteId,
            );
        }

        $delivery = $this->deliver(
            $remoteId,
            $request['toEmail'],
            $request['subject'],
            $request['text'],
            'reminder_delivery',
            $checkpoint,
        );
        if ($delivery !== null) {
            return $delivery;
        }
        $this->documents->markDelivered(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_REMINDER,
            $request['dunningLevel'],
            $remoteId,
        );

        return self::result('succeeded', 'reminder_delivered', 'Die Mahnung wurde einmal versandt.', $remoteId);
    }

    /**
     * @param array{
     *   invoiceId:int,parentRemoteId:string,contactId:string,serviceGrossMinor:int,
     *   fingerprint:string,toEmail:string,subject:string,text:string,deliver:bool,isEInvoice:bool,
     *   createAllowed:bool,resumeDeliverySafe:bool,expectedRemoteId:?string
     * } $request
     * @param callable(string,array<string,scalar|null>):bool $checkpoint
     * @return array{status:string,code:string,message:string,remoteId?:string}
     */
    public function cancelInvoice(array $request, callable $checkpoint): array
    {
        $request = $this->validateCancellationRequest($request);
        $mapped = $this->documents->find(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_CANCELLATION,
        );
        if ($mapped !== null) {
            return $this->mappedInvoiceOutcome(
                $mapped,
                $request,
                'cancellation_delivery',
                'cancellation_already_mapped',
                'Die Stornorechnung ist bereits eindeutig zugeordnet.',
                $checkpoint,
            );
        }

        $checkpoint = Closure::fromCallable($checkpoint);
        $existing = $request['expectedRemoteId'] === null
            ? $this->findRelatedInvoice(
                'SR',
                $request['parentRemoteId'],
                $request['contactId'],
                0,
            )
            : self::one(
                $this->client->get(
                    '/Invoice/' . rawurlencode($request['expectedRemoteId']),
                    ['embed' => 'origin,contact'],
                ),
                'Invoice',
            );
        if ($existing === null) {
            if (!$request['createAllowed']) {
                return self::result(
                    'ambiguous',
                    'cancellation_create_outcome_unproven',
                    'Nach dem früheren Storno-Checkpoint ist keine eindeutige SR auffindbar; '
                        . 'es erfolgt kein zweiter Create.',
                );
            }
            if (!$checkpoint('cancellation_write_requested', self::checkpointContext($request))) {
                return self::result('failed', 'checkpoint_persist_failed', 'Der Storno-Checkpoint fehlt.');
            }
            $response = $this->client->post(
                '/Invoice/' . rawurlencode($request['parentRemoteId']) . '/cancelInvoice',
                [],
                true,
                [201],
            );
            $created = self::one($response, 'Invoice');
            $remoteId = self::numericId($created['id'] ?? null);
            $existing = $created;
            if ($remoteId === null) {
                return self::result(
                    'ambiguous',
                    'cancellation_id_missing',
                    'sevDesk hat keine eindeutige Stornorechnungs-ID geliefert.',
                );
            }
            if (
                !$checkpoint('cancellation_created', [
                ...self::checkpointContext($request),
                'remoteId' => $remoteId,
                ])
            ) {
                return self::result(
                    'ambiguous',
                    'checkpoint_persist_failed_after_cancellation',
                    'Die Stornorechnung existiert, ihr Ergebnis konnte aber nicht gespeichert werden.',
                    $remoteId,
                );
            }
        }
        if (
            $request['expectedRemoteId'] !== null
            && self::numericId($existing['id'] ?? null) !== $request['expectedRemoteId']
        ) {
            return self::result(
                'ambiguous',
                'cancellation_recovery_id_mismatch',
                'Der direkte Storno-Readback lieferte eine andere ID als der gespeicherte Checkpoint.',
            );
        }
        $remoteId = self::numericId($existing['id'] ?? null);
        if ($remoteId !== null) {
            $existing = self::one(
                $this->client->get(
                    '/Invoice/' . rawurlencode($remoteId),
                    ['embed' => 'origin,contact'],
                ),
                'Invoice',
            );
        }

        $mismatch = $this->cancellationMismatch($existing, $request);
        if ($mismatch !== null) {
            return self::result(
                'ambiguous',
                $mismatch,
                'Die sevDesk-Stornorechnung entspricht nicht dem eingefrorenen Ausgangsbeleg.',
                self::numericId($existing['id'] ?? null),
            );
        }
        $remoteId = self::numericId($existing['id'] ?? null);
        if ($remoteId === null) {
            return self::result('ambiguous', 'cancellation_id_invalid', 'Die Storno-ID ist ungültig.');
        }
        $pdf = $this->invoicePdf->fetch($remoteId);
        $xmlSha256 = $request['isEInvoice']
            ? $this->invoiceXml->fetch($remoteId)['sha256']
            : null;
        if (
            !$checkpoint('cancellation_verified', [
            ...self::checkpointContext($request),
            'remoteId' => $remoteId,
            'pdfSha256' => $pdf['sha256'],
            'xmlSha256' => $xmlSha256,
            ])
        ) {
            return self::result(
                'ambiguous',
                'cancellation_verification_checkpoint_failed',
                'Die Stornorechnung und PDF sind geprüft, der Checkpoint fehlt jedoch.',
                $remoteId,
            );
        }
        $this->documents->linkVerified(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_CANCELLATION,
            0,
            $remoteId,
            $request['parentRemoteId'],
            -$request['serviceGrossMinor'],
            $request['fingerprint'],
            self::documentNumber($existing),
            $pdf['sha256'],
            $xmlSha256,
            $request['deliver'] ? 'pending' : 'not_requested',
        );
        if (
            !$checkpoint('cancellation_mapping_persisted', [
            ...self::checkpointContext($request),
            'remoteId' => $remoteId,
            ])
        ) {
            return self::result(
                'ambiguous',
                'cancellation_mapping_checkpoint_failed',
                'Die Stornozuordnung wurde gespeichert, ihr Checkpoint fehlt jedoch.',
                $remoteId,
            );
        }

        if (!$request['deliver']) {
            return self::result(
                'succeeded',
                'cancellation_created_mail_free',
                'Die Stornorechnung wurde geprüft und mailfrei zugeordnet.',
                $remoteId,
            );
        }
        $delivery = $this->deliver(
            $remoteId,
            $request['toEmail'],
            $request['subject'],
            $request['text'],
            'cancellation_delivery',
            $checkpoint,
        );
        if ($delivery !== null) {
            return $delivery;
        }
        $this->documents->markDelivered(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_CANCELLATION,
            0,
            $remoteId,
        );

        return self::result(
            'succeeded',
            'cancellation_delivered',
            'Die Stornorechnung wurde einmal versandt.',
            $remoteId,
        );
    }

    /**
     * @param array{
     *   invoiceId:int,parentRemoteId:string,contactId:string,invoiceNumber:string,
     *   voucherDate:string,lateFeeMinor:int,fingerprint:string,accountDatevId:string,
     *   receiptGuidance:array<array-key,mixed>,createAllowed:bool,expectedRemoteId:?string
     * } $request
     * @param callable(string,array<string,scalar|null>):bool $checkpoint
     * @return array{status:string,code:string,message:string,remoteId?:string}
     */
    public function exportLateFeeVoucher(array $request, callable $checkpoint): array
    {
        $request = $this->validateLateFeeVoucherRequest($request);
        if (!self::guidanceAllowsRule22($request['receiptGuidance'], $request['accountDatevId'])) {
            return self::result(
                'failed',
                'late_fee_rule22_guidance_missing',
                'ReceiptGuidance bestätigt das gewählte Erlöskonto nicht für Rule 22 mit 0 %.',
            );
        }
        $mapped = $this->documents->find(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_LATE_FEE_VOUCHER,
        );
        if ($mapped !== null) {
            $mappedRemoteId = self::numericId($mapped->sevdesk_id ?? null);
            if (
                $mappedRemoteId === null
                || trim((string) ($mapped->parent_sevdesk_id ?? ''))
                    !== $request['parentRemoteId']
                || (int) ($mapped->amount_minor ?? 0) !== $request['lateFeeMinor']
                || !hash_equals(
                    trim((string) ($mapped->fingerprint ?? '')),
                    $request['fingerprint'],
                )
            ) {
                return self::result(
                    'ambiguous',
                    'mapped_late_fee_contract_mismatch',
                    'Die gespeicherte Gebührenbeleg-Zuordnung weicht vom eingefrorenen Vertrag ab.',
                    $mappedRemoteId,
                );
            }
            $marker = self::lateFeeMarker($request['invoiceId'], $request['fingerprint']);
            $remote = self::one(
                $this->client->get(
                    '/Voucher/' . rawurlencode($mappedRemoteId),
                    ['embed' => 'supplier,taxRule'],
                ),
                'Voucher',
            );
            $mismatch = $this->lateFeeVoucherMismatch($remote, $request, $marker);
            if ($mismatch !== null) {
                return self::result(
                    'ambiguous',
                    $mismatch,
                    'Der zugeordnete Gebührenbeleg entspricht nicht mehr dem Rule-22-Vertrag.',
                    $mappedRemoteId,
                );
            }

            return self::result(
                'succeeded',
                'late_fee_voucher_already_mapped',
                'Der Gebührenbeleg ist bereits eindeutig zugeordnet.',
                $mappedRemoteId,
            );
        }

        $checkpoint = Closure::fromCallable($checkpoint);
        $marker = self::lateFeeMarker($request['invoiceId'], $request['fingerprint']);
        $existing = $request['expectedRemoteId'] === null
            ? $this->findVoucherByMarker($marker)
            : self::one(
                $this->client->get(
                    '/Voucher/' . rawurlencode($request['expectedRemoteId']),
                    ['embed' => 'supplier,taxRule'],
                ),
                'Voucher',
            );
        if ($existing === null) {
            if (!$request['createAllowed']) {
                return self::result(
                    'ambiguous',
                    'late_fee_voucher_create_outcome_unproven',
                    'Nach dem früheren Gebühren-Checkpoint ist kein eindeutiger Marker-Treffer auffindbar; '
                        . 'es erfolgt kein zweiter Create.',
                );
            }
            if (!$checkpoint('late_fee_voucher_write_requested', self::checkpointContext($request))) {
                return self::result('failed', 'checkpoint_persist_failed', 'Der Gebühren-Checkpoint fehlt.');
            }
            $response = $this->client->post(
                '/Voucher/Factory/saveVoucher',
                $this->lateFeeVoucherPayload($request, $marker),
                true,
                [201],
            );
            $created = self::one($response, 'Voucher');
            $remoteId = self::numericId($created['id'] ?? null);
            $existing = $created;
            if ($remoteId === null) {
                return self::result(
                    'ambiguous',
                    'late_fee_voucher_id_missing',
                    'sevDesk hat keine eindeutige Gebührenbeleg-ID geliefert.',
                );
            }
            if (
                !$checkpoint('late_fee_voucher_created', [
                ...self::checkpointContext($request),
                'remoteId' => $remoteId,
                ])
            ) {
                return self::result(
                    'ambiguous',
                    'checkpoint_persist_failed_after_late_fee_voucher',
                    'Der Gebührenbeleg existiert, sein Ergebnis konnte aber nicht gespeichert werden.',
                    $remoteId,
                );
            }
        }
        if (
            $request['expectedRemoteId'] !== null
            && self::numericId($existing['id'] ?? null) !== $request['expectedRemoteId']
        ) {
            return self::result(
                'ambiguous',
                'late_fee_voucher_recovery_id_mismatch',
                'Der direkte Gebührenbeleg-Readback lieferte eine andere ID als der gespeicherte Checkpoint.',
            );
        }
        $remoteId = self::numericId($existing['id'] ?? null);
        if ($remoteId !== null) {
            $existing = self::one(
                $this->client->get(
                    '/Voucher/' . rawurlencode($remoteId),
                    ['embed' => 'supplier,taxRule'],
                ),
                'Voucher',
            );
        }

        $mismatch = $this->lateFeeVoucherMismatch($existing, $request, $marker);
        if ($mismatch !== null) {
            return self::result(
                'ambiguous',
                $mismatch,
                'Der sevDesk-Gebührenbeleg entspricht nicht dem eingefrorenen Rule-22-Vertrag.',
                self::numericId($existing['id'] ?? null),
            );
        }
        $remoteId = self::numericId($existing['id'] ?? null);
        if ($remoteId === null) {
            return self::result('ambiguous', 'late_fee_voucher_id_invalid', 'Die Voucher-ID ist ungültig.');
        }
        if (
            !$checkpoint('late_fee_voucher_verified', [
                ...self::checkpointContext($request),
                'remoteId' => $remoteId,
            ])
        ) {
            return self::result(
                'ambiguous',
                'late_fee_voucher_verification_checkpoint_failed',
                'Der Gebührenbeleg ist geprüft, sein Verifikations-Checkpoint fehlt jedoch.',
                $remoteId,
            );
        }
        $this->documents->linkVerified(
            $request['invoiceId'],
            RelatedDocumentRepository::ROLE_LATE_FEE_VOUCHER,
            0,
            $remoteId,
            $request['parentRemoteId'],
            $request['lateFeeMinor'],
            $request['fingerprint'],
        );
        if (
            !$checkpoint('late_fee_voucher_mapping_persisted', [
            ...self::checkpointContext($request),
            'remoteId' => $remoteId,
            ])
        ) {
            return self::result(
                'ambiguous',
                'late_fee_voucher_mapping_checkpoint_failed',
                'Der Gebührenbeleg ist zugeordnet, sein Checkpoint fehlt jedoch.',
                $remoteId,
            );
        }

        return self::result(
            'succeeded',
            'late_fee_voucher_created',
            'Die Mahngebühr wurde genau einmal als mailfreier Rule-22-Beleg erfasst.',
            $remoteId,
        );
    }

    /** @return null|array{status:string,code:string,message:string,remoteId?:string} */
    private function deliver(
        string $remoteId,
        string $toEmail,
        string $subject,
        string $text,
        string $prefix,
        Closure $checkpoint,
    ): ?array {
        if (!$checkpoint($prefix . '_write_requested', ['remoteId' => $remoteId])) {
            return self::result('failed', 'checkpoint_persist_failed', 'Der Versand-Checkpoint fehlt.');
        }
        $this->client->post(
            '/Invoice/' . rawurlencode($remoteId) . '/sendViaEmail',
            [
                'toEmail' => $toEmail,
                'subject' => $subject,
                'text' => $text,
                'copy' => false,
                'sendXml' => false,
            ],
            true,
            [201],
        );
        $remote = self::one($this->client->get('/Invoice/' . rawurlencode($remoteId)), 'Invoice');
        if (
            strtoupper(trim((string) ($remote['sendType'] ?? ''))) !== 'VM'
            || trim((string) ($remote['sendDate'] ?? '')) === ''
        ) {
            return self::result(
                'ambiguous',
                $prefix . '_not_proven',
                'Der Mailversand lässt sich durch den sevDesk-Readback nicht beweisen.',
                $remoteId,
            );
        }
        if (!$checkpoint($prefix . '_delivered', ['remoteId' => $remoteId])) {
            return self::result(
                'ambiguous',
                $prefix . '_checkpoint_failed',
                'Der Versand ist bewiesen, sein Abschluss-Checkpoint fehlt jedoch.',
                $remoteId,
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $request
     * @param callable(string,array<string,scalar|null>):bool $checkpoint
     * @return array{status:string,code:string,message:string,remoteId?:string}
     */
    private function mappedInvoiceOutcome(
        object $mapped,
        array $request,
        string $deliveryPrefix,
        string $successCode,
        string $successMessage,
        callable $checkpoint,
    ): array {
        $remoteId = self::numericId($mapped->sevdesk_id ?? null);
        if ($remoteId === null) {
            return self::result('ambiguous', 'mapped_related_id_invalid', 'Die gespeicherte Remote-ID ist ungültig.');
        }
        $isReminder = $deliveryPrefix === 'reminder_delivery';
        $expectedRole = $isReminder
            ? RelatedDocumentRepository::ROLE_REMINDER
            : RelatedDocumentRepository::ROLE_CANCELLATION;
        $expectedLevel = $isReminder ? $request['dunningLevel'] : 0;
        $expectedAmount = $isReminder
            ? $request['lateFeeMinor']
            : -$request['serviceGrossMinor'];
        if (
            trim((string) ($mapped->role ?? '')) !== $expectedRole
            || (int) ($mapped->dunning_level ?? -1) !== $expectedLevel
            || trim((string) ($mapped->parent_sevdesk_id ?? '')) !== $request['parentRemoteId']
            || (int) ($mapped->amount_minor ?? 0) !== $expectedAmount
            || !hash_equals(
                trim((string) ($mapped->fingerprint ?? '')),
                $request['fingerprint'],
            )
            || preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string) (
                $mapped->pdf_sha256
                ?? ''
            )))) !== 1
            || (
                !$isReminder
                && ($request['isEInvoice'] ?? false) === true
                && preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string) (
                    $mapped->xml_sha256
                    ?? ''
                )))) !== 1
            )
        ) {
            return self::result(
                'ambiguous',
                'mapped_related_contract_mismatch',
                'Die gespeicherte Zusatzdokument-Zuordnung weicht vom eingefrorenen Vertrag ab.',
                $remoteId,
            );
        }
        if (!$request['deliver']) {
            return self::result('succeeded', $successCode, $successMessage, $remoteId);
        }
        if (trim((string) ($mapped->delivery_status ?? '')) === 'delivered') {
            return self::result('succeeded', $successCode, $successMessage, $remoteId);
        }
        $remote = self::one(
            $this->client->get(
                '/Invoice/' . rawurlencode($remoteId),
                ['embed' => 'origin,contact'],
            ),
            'Invoice',
        );
        $mismatch = $isReminder
            ? $this->reminderMismatch($remote, $request)
            : $this->cancellationMismatch($remote, $request);
        $pdf = $this->invoicePdf->fetch($remoteId);
        $xmlMatches = true;
        if (!$isReminder && ($request['isEInvoice'] ?? false) === true) {
            $xmlMatches = hash_equals(
                strtolower(trim((string) $mapped->xml_sha256)),
                $this->invoiceXml->fetch($remoteId)['sha256'],
            );
        }
        if (
            $mismatch !== null
            || !hash_equals(
                strtolower(trim((string) $mapped->pdf_sha256)),
                $pdf['sha256'],
            )
            || !$xmlMatches
        ) {
            return self::result(
                'ambiguous',
                $mismatch ?? 'mapped_related_pdf_changed',
                'Das zugeordnete Zusatzdokument hat sich vor dem Versand verändert.',
                $remoteId,
            );
        }
        if (
            strtoupper(trim((string) ($remote['sendType'] ?? ''))) === 'VM'
            && trim((string) ($remote['sendDate'] ?? '')) !== ''
        ) {
            $this->documents->markDelivered(
                $request['invoiceId'],
                $expectedRole,
                $expectedLevel,
                $remoteId,
            );

            return self::result('succeeded', $successCode, $successMessage, $remoteId);
        }
        if (!$request['resumeDeliverySafe']) {
            return self::result(
                'ambiguous',
                $deliveryPrefix . '_outcome_unproven',
                'Der frühere Mailausgang ist nicht beweisbar; es erfolgt kein automatischer Doppelversand.',
                $remoteId,
            );
        }
        $delivery = $this->deliver(
            $remoteId,
            $request['toEmail'],
            $request['subject'],
            $request['text'],
            $deliveryPrefix,
            Closure::fromCallable($checkpoint),
        );
        if ($delivery !== null) {
            return $delivery;
        }
        $this->documents->markDelivered(
            $request['invoiceId'],
            $expectedRole,
            $expectedLevel,
            $remoteId,
        );

        return self::result('succeeded', $successCode, $successMessage, $remoteId);
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function validateReminderRequest(array $request): array
    {
        $this->validateBaseRequest($request);
        if (
            !is_int($request['dunningLevel'] ?? null)
            || $request['dunningLevel'] < 1
            || $request['dunningLevel'] > 3
            || !is_int($request['serviceGrossMinor'] ?? null)
            || $request['serviceGrossMinor'] <= 0
            || !is_int($request['lateFeeMinor'] ?? null)
            || $request['lateFeeMinor'] < 0
            || !self::validDate((string) ($request['reminderDeadline'] ?? ''))
            || !is_bool($request['deliver'] ?? null)
            || !is_bool($request['createAllowed'] ?? null)
            || !is_bool($request['resumeDeliverySafe'] ?? null)
        ) {
            throw new \InvalidArgumentException('Invalid reminder contract.');
        }
        if ($request['deliver']) {
            $this->validateDeliveryText($request);
        }

        return $request;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function validateCancellationRequest(array $request): array
    {
        $this->validateBaseRequest($request);
        if (
            !is_int($request['serviceGrossMinor'] ?? null)
            || $request['serviceGrossMinor'] <= 0
            || !is_bool($request['deliver'] ?? null)
            || !is_bool($request['isEInvoice'] ?? null)
            || !is_bool($request['createAllowed'] ?? null)
            || !is_bool($request['resumeDeliverySafe'] ?? null)
        ) {
            throw new \InvalidArgumentException('Invalid cancellation contract.');
        }
        if ($request['deliver']) {
            $this->validateDeliveryText($request);
        }

        return $request;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function validateLateFeeVoucherRequest(array $request): array
    {
        $this->validateBaseRequest($request);
        if (
            !is_int($request['lateFeeMinor'] ?? null)
            || $request['lateFeeMinor'] <= 0
            || preg_match('/^[1-9]\d*$/', (string) ($request['accountDatevId'] ?? '')) !== 1
            || !self::validDate((string) ($request['voucherDate'] ?? ''))
            || trim((string) ($request['invoiceNumber'] ?? '')) === ''
            || !is_array($request['receiptGuidance'] ?? null)
            || !is_bool($request['createAllowed'] ?? null)
        ) {
            throw new \InvalidArgumentException('Invalid late-fee voucher contract.');
        }

        return $request;
    }

    /** @param array<string,mixed> $request */
    private function validateBaseRequest(array $request): void
    {
        if (
            !is_int($request['invoiceId'] ?? null)
            || $request['invoiceId'] < 1
            || self::numericId($request['parentRemoteId'] ?? null) === null
            || self::numericId($request['contactId'] ?? null) === null
            || preg_match('/^[a-f0-9]{64}$/', (string) ($request['fingerprint'] ?? '')) !== 1
            || (
                ($request['expectedRemoteId'] ?? null) !== null
                && self::numericId($request['expectedRemoteId']) === null
            )
        ) {
            throw new \InvalidArgumentException('Invalid dunning document contract.');
        }
    }

    /** @param array<string,mixed> $request */
    private function validateDeliveryText(array $request): void
    {
        if (
            filter_var((string) ($request['toEmail'] ?? ''), FILTER_VALIDATE_EMAIL) === false
            || trim((string) ($request['subject'] ?? '')) === ''
            || trim((string) ($request['text'] ?? '')) === ''
            || mb_strlen((string) $request['subject']) > 200
            || mb_strlen((string) $request['text']) > 5000
        ) {
            throw new \InvalidArgumentException('Invalid dunning delivery contract.');
        }
    }

    /**
     * @param array<string,mixed> $remote
     * @param array<string,mixed> $request
     */
    private function reminderMismatch(array $remote, array $request): ?string
    {
        $base = $this->relatedInvoiceMismatch(
            $remote,
            'MA',
            $request['parentRemoteId'],
            $request['contactId'],
        );
        if ($base !== null) {
            return $base;
        }
        if ((int) ($remote['dunningLevel'] ?? 0) !== $request['dunningLevel']) {
            return 'reminder_level_mismatch';
        }
        if (!self::sameMinor($remote['reminderDebit'] ?? null, $request['serviceGrossMinor'])) {
            return 'reminder_debit_mismatch';
        }
        if (!self::sameMinor($remote['reminderCharge'] ?? null, $request['lateFeeMinor'])) {
            return 'reminder_charge_mismatch';
        }
        if (
            !self::sameMinor(
                $remote['reminderTotal'] ?? null,
                $request['serviceGrossMinor'] + $request['lateFeeMinor'],
            )
        ) {
            return 'reminder_total_mismatch';
        }
        $deadline = self::apiDate($remote['reminderDeadline'] ?? null);
        if ($deadline !== $request['reminderDeadline']) {
            return 'reminder_deadline_mismatch';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $remote
     * @param array<string,mixed> $request
     */
    private function cancellationMismatch(array $remote, array $request): ?string
    {
        $base = $this->relatedInvoiceMismatch(
            $remote,
            'SR',
            $request['parentRemoteId'],
            $request['contactId'],
        );
        if ($base !== null) {
            return $base;
        }
        $gross = self::minor($remote['sumGross'] ?? null);
        if ($gross === null || abs($gross) !== $request['serviceGrossMinor']) {
            return 'cancellation_total_mismatch';
        }
        if (
            ($request['isEInvoice'] ?? false) === true && !self::truthy(
                $remote['propertyIsEInvoice']
                ?? false,
            )
        ) {
            return 'cancellation_e_invoice_flag_mismatch';
        }

        return null;
    }

    /** @param array<string,mixed> $remote */
    private function relatedInvoiceMismatch(
        array $remote,
        string $type,
        string $parentRemoteId,
        string $contactId,
    ): ?string {
        if (strtoupper(trim((string) ($remote['invoiceType'] ?? ''))) !== $type) {
            return 'related_invoice_type_mismatch';
        }
        if (self::numericId($remote['contact']['id'] ?? null) !== $contactId) {
            return 'related_invoice_contact_mismatch';
        }
        $originId = self::numericId(
            $remote['origin']['id']
            ?? $remote['invoice']['id']
            ?? $remote['parentInvoice']['id']
            ?? null,
        );
        if ($originId !== $parentRemoteId) {
            return 'related_invoice_parent_mismatch';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $voucher
     * @param array<string,mixed> $request
     */
    private function lateFeeVoucherMismatch(array $voucher, array $request, string $marker): ?string
    {
        if (!str_contains((string) ($voucher['description'] ?? ''), $marker)) {
            return 'late_fee_marker_mismatch';
        }
        if (self::numericId($voucher['supplier']['id'] ?? null) !== $request['contactId']) {
            return 'late_fee_contact_mismatch';
        }
        if (self::numericId($voucher['taxRule']['id'] ?? null) !== '22') {
            return 'late_fee_tax_rule_mismatch';
        }
        if (strtoupper(trim((string) ($voucher['creditDebit'] ?? ''))) !== 'D') {
            return 'late_fee_credit_debit_mismatch';
        }
        if (!self::sameMinor($voucher['sumGross'] ?? $voucher['total'] ?? null, $request['lateFeeMinor'])) {
            return 'late_fee_total_mismatch';
        }
        $remoteId = self::numericId($voucher['id'] ?? null);
        if ($remoteId === null) {
            return 'late_fee_id_invalid';
        }
        $positionResponse = $this->client->get(
            '/VoucherPos',
            [
                'voucher[id]' => $remoteId,
                'voucher[objectName]' => 'Voucher',
                'limit' => 1000,
                'offset' => 0,
            ],
        );
        $positions = self::records($positionResponse, 'VoucherPos');
        self::assertCompletePage($positionResponse, count($positions), 1000);
        if (count($positions) !== 1) {
            return 'late_fee_position_count_mismatch';
        }
        $position = $positions[0];
        if (
            self::numericId($position['accountDatev']['id'] ?? null) !== $request['accountDatevId']
            || !self::sameMinor($position['sumGross'] ?? null, $request['lateFeeMinor'])
            || !self::sameMinor($position['taxRate'] ?? null, 0)
        ) {
            return 'late_fee_position_mismatch';
        }

        return null;
    }

    /** @return null|array<string,mixed> */
    private function findRelatedInvoice(
        string $type,
        string $parentRemoteId,
        string $contactId,
        int $level,
    ): ?array {
        $matches = [];
        $response = $this->client->get('/Invoice', [
            'invoiceType' => $type,
            'embed' => 'origin,contact',
            'limit' => 1000,
            'offset' => 0,
        ]);
        $records = self::records($response, 'Invoice');
        self::assertCompletePage($response, count($records), 1000);
        foreach ($records as $candidate) {
            if (
                $this->relatedInvoiceMismatch($candidate, $type, $parentRemoteId, $contactId) === null
                && ($type !== 'MA' || (int) ($candidate['dunningLevel'] ?? 0) === $level)
            ) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) > 1) {
            throw new ApiException(
                'sevDesk returned multiple matching related invoices.',
                null,
                'related_invoice_not_unique',
            );
        }

        return $matches[0] ?? null;
    }

    /** @return null|array<string,mixed> */
    private function findVoucherByMarker(string $marker): ?array
    {
        $matches = [];
        $response = $this->client->get('/Voucher', [
            'description' => $marker,
            'embed' => 'supplier,taxRule',
            'limit' => 1000,
            'offset' => 0,
        ]);
        $records = self::records($response, 'Voucher');
        self::assertCompletePage($response, count($records), 1000);
        foreach ($records as $candidate) {
            if (str_contains((string) ($candidate['description'] ?? ''), $marker)) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) > 1) {
            throw new ApiException(
                'sevDesk returned multiple matching late-fee vouchers.',
                null,
                'late_fee_voucher_not_unique',
            );
        }

        return $matches[0] ?? null;
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function lateFeeVoucherPayload(array $request, string $marker): array
    {
        return [
            'voucher' => [
                'objectName' => 'Voucher',
                'mapAll' => true,
                'description' => mb_substr(
                    'Late Fee ' . $request['invoiceNumber'] . ' '
                        . VoucherExporter::marker($request['invoiceId']) . ' '
                        . $marker . ' [SEVDESK-INVOICE:' . $request['parentRemoteId'] . ']',
                    0,
                    255,
                ),
                'currency' => 'EUR',
                'voucherDate' => self::displayDate($request['voucherDate']),
                'propertyForeignCurrencyDeadline' => self::displayDate($request['voucherDate']),
                'payDate' => null,
                'status' => 100,
                'taxRule' => ['id' => 22, 'objectName' => 'TaxRule'],
                'creditDebit' => 'D',
                'voucherType' => 'VOU',
                'supplier' => ['id' => (int) $request['contactId'], 'objectName' => 'Contact'],
            ],
            'voucherPosSave' => [[
                'objectName' => 'VoucherPos',
                'mapAll' => true,
                'accountDatev' => [
                    'id' => (int) $request['accountDatevId'],
                    'objectName' => 'AccountDatev',
                ],
                'taxRate' => 0.0,
                'net' => false,
                'sumGross' => Decimal::toFloat(Decimal::fromMinorUnits($request['lateFeeMinor'])),
                'comment' => mb_substr('Mahngebühr zu ' . $request['invoiceNumber'], 0, 255),
            ]],
            'voucherPosDelete' => null,
        ];
    }

    /** @param array<array-key,mixed> $guidance */
    public static function guidanceAllowsRule22(array $guidance, string $accountDatevId): bool
    {
        $rows = isset($guidance['objects']) && is_array($guidance['objects'])
            ? $guidance['objects']
            : $guidance;
        foreach ($rows as $row) {
            if (
                !is_array($row)
                || self::numericId($row['accountDatevId'] ?? null) === null
                || !is_array($row['allowedReceiptTypes'] ?? null)
                || !is_array($row['allowedTaxRules'] ?? null)
            ) {
                return false;
            }
            foreach ($row['allowedTaxRules'] as $rule) {
                if (
                    !is_array($rule)
                    || self::numericId($rule['id'] ?? null) === null
                    || !is_array($rule['taxRates'] ?? null)
                ) {
                    return false;
                }
            }
        }

        foreach ($rows as $row) {
            if (self::numericId($row['accountDatevId']) !== $accountDatevId) {
                continue;
            }
            $receiptTypes = array_map(
                static fn (mixed $value): string => strtoupper(trim((string) $value)),
                $row['allowedReceiptTypes'],
            );
            if (!in_array('REVENUE', $receiptTypes, true)) {
                return false;
            }
            foreach ($row['allowedTaxRules'] as $rule) {
                if (self::numericId($rule['id'] ?? null) !== '22') {
                    continue;
                }
                foreach ($rule['taxRates'] as $rate) {
                    if (self::sameMinor($rate, 0)) {
                        return true;
                    }
                }
                return false;
            }

            return false;
        }

        return false;
    }

    private static function lateFeeMarker(int $invoiceId, string $fingerprint): string
    {
        return '[WHMCS-LATE-FEE:' . $invoiceId . ':' . substr($fingerprint, 0, 24) . ']';
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,scalar|null>
     */
    private static function checkpointContext(array $request): array
    {
        return array_filter([
            'invoiceId' => $request['invoiceId'] ?? null,
            'parentRemoteId' => $request['parentRemoteId'] ?? null,
            'dunningLevel' => $request['dunningLevel'] ?? null,
            'serviceGrossMinor' => $request['serviceGrossMinor'] ?? null,
            'lateFeeMinor' => $request['lateFeeMinor'] ?? null,
            'fingerprint' => $request['fingerprint'] ?? null,
            'reminderDeadline' => $request['reminderDeadline'] ?? null,
            'voucherDate' => $request['voucherDate'] ?? null,
            'accountDatevId' => $request['accountDatevId'] ?? null,
            'deliveryRequested' => $request['deliver'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value) || $value === null);
    }

    /**
     * @param array<array-key,mixed> $response
     * @return array<string,mixed>
     */
    private static function one(array $response, string $nested): array
    {
        $records = self::records($response, $nested);
        if (count($records) !== 1) {
            throw new ApiException(
                'sevDesk returned no unique object.',
                null,
                'unexpected_related_document_response',
            );
        }

        return $records[0];
    }

    /**
     * @param array<array-key,mixed> $response
     * @return list<array<string,mixed>>
     */
    private static function records(array $response, string $nested): array
    {
        if ($response === []) {
            return [];
        }
        $records = isset($response['objects']) ? $response['objects'] : $response;
        $records = array_is_list($records) ? $records : [$records];
        $result = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new ApiException(
                    'sevDesk returned a malformed list member.',
                    null,
                    'unexpected_related_document_response',
                );
            }
            if (array_key_exists($nested, $record)) {
                if (!is_array($record[$nested])) {
                    throw new ApiException(
                        'sevDesk returned a malformed nested object.',
                        null,
                        'unexpected_related_document_response',
                    );
                }
                $record = $record[$nested];
            }
            if (self::numericId($record['id'] ?? null) === null) {
                throw new ApiException(
                    'sevDesk returned an object without a valid ID.',
                    null,
                    'unexpected_related_document_response',
                );
            }
            $result[] = $record;
        }

        return $result;
    }

    /** @param array<array-key,mixed> $response */
    private static function assertCompletePage(array $response, int $recordCount, int $limit): void
    {
        foreach (['total', 'totalCount', 'countAll'] as $key) {
            if (!array_key_exists($key, $response)) {
                continue;
            }
            $total = $response[$key];
            if (
                (!is_int($total) && !is_string($total))
                || preg_match('/^\d+$/', (string) $total) !== 1
                || (int) $total > $recordCount
            ) {
                throw new ApiException(
                    'sevDesk returned an incomplete or malformed result page.',
                    null,
                    'related_document_page_incomplete',
                );
            }

            return;
        }
        if ($recordCount >= $limit) {
            throw new ApiException(
                'sevDesk result uniqueness cannot be proven at the response limit.',
                null,
                'related_document_page_truncated',
            );
        }
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || (
                is_string($value)
                && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)
            );
    }

    private static function sameMinor(mixed $value, int $expectedMinor): bool
    {
        return self::minor($value) === $expectedMinor;
    }

    private static function minor(mixed $value): ?int
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        try {
            return Decimal::toMinorUnits((string) $value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function validDate(string $date): bool
    {
        return self::apiDate($date) === $date;
    }

    private static function apiDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        foreach (
            [
            ['format' => '!Y-m-d', 'roundTrip' => 'Y-m-d'],
            ['format' => '!Y-m-d\\TH:i:sP', 'roundTrip' => 'Y-m-d\\TH:i:sP'],
            ['format' => '!d.m.Y', 'roundTrip' => 'd.m.Y'],
            ] as $candidate
        ) {
            $format = $candidate['format'];
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if (
                $date instanceof DateTimeImmutable
                && DateTimeImmutable::getLastErrors() === false
                && $date->format($candidate['roundTrip']) === $value
            ) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private static function displayDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed instanceof DateTimeImmutable) {
            throw new \InvalidArgumentException('Invalid voucher date.');
        }

        return $parsed->format('d.m.Y');
    }

    /** @param array<string,mixed> $invoice */
    private static function documentNumber(array $invoice): ?string
    {
        $number = trim((string) ($invoice['invoiceNumber'] ?? ''));

        return $number !== '' ? mb_substr($number, 0, 191) : null;
    }

    /** @return array{status:string,code:string,message:string,remoteId?:string} */
    private static function result(
        string $status,
        string $code,
        string $message,
        ?string $remoteId = null,
    ): array {
        $result = ['status' => $status, 'code' => $code, 'message' => $message];
        if ($remoteId !== null) {
            $result['remoteId'] = $remoteId;
        }

        return $result;
    }
}
