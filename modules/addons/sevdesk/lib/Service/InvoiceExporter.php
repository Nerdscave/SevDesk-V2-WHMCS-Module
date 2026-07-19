<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\EInvoiceContext;
use WHMCS\Module\Addon\SevDesk\Domain\ExportResult;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;

/** Creates and verifies one normal sevdesk Invoice without uploading the WHMCS PDF. */
final class InvoiceExporter
{
    /** @var list<string> */
    private const SUPPORTED_TAX_RULES = ['1', '2', '3', '4', '5', '11', '17', '19'];

    /** @var Closure(int): (int|string|null) */
    private readonly Closure $findMapping;

    /** @var Closure(int, string, string, string, bool=, string|null=): (bool|null) */
    private readonly Closure $persistMapping;

    private readonly InvoiceRemoteVerifier $remoteVerifier;

    private readonly InvoiceXml $invoiceXml;

    /**
     * Collaborator signatures:
     *
     * - $findMapping(int $whmcsInvoiceId): int|string|null
     * - $persistMapping(int $whmcsInvoiceId, string $remoteId, string $type,
     *   string $number, bool $isEInvoice = false, ?string $xmlSha256 = null): bool|null
     */
    public function __construct(
        private readonly SevdeskClient $client,
        callable $findMapping,
        callable $persistMapping,
        private readonly string $sevUserId,
        private readonly string $unityId,
    ) {
        $this->findMapping = Closure::fromCallable($findMapping);
        $this->persistMapping = Closure::fromCallable($persistMapping);
        $this->remoteVerifier = new InvoiceRemoteVerifier($sevUserId, $unityId);
        $this->invoiceXml = new InvoiceXml($client);
    }

    public function withReferences(string $sevUserId, string $unityId): self
    {
        if ($sevUserId === $this->sevUserId && $unityId === $this->unityId) {
            return $this;
        }

        return new self(
            $this->client,
            $this->findMapping,
            $this->persistMapping,
            $sevUserId,
            $unityId,
        );
    }

    public function export(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        string $deliveryCountryCode,
        DocumentTargetDecision $target,
        ?callable $checkpoint = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ExportResult {
        $checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);

        try {
            $existingMapping = ($this->findMapping)($invoice->invoiceId);
        } catch (Throwable) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'mapping_lookup_failed',
                'The existing sevdesk mapping could not be checked.',
            );
        }
        if ($existingMapping !== null && trim((string) $existingMapping) !== '') {
            return ExportResult::skipped($invoice->invoiceId, (string) $existingMapping);
        }

        $preflight = $this->preflight(
            $invoice,
            $sevdeskContactId,
            $taxDecision,
            $deliveryCountryCode,
            $target,
            $eInvoiceContext,
        );
        if ($preflight !== null) {
            return $preflight;
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'invoice_write_requested', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentNumber' => $invoice->invoiceNumber,
                ],
                $eInvoiceContext?->frozenContext() ?? ['isEInvoice' => false],
            ))
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'checkpoint_persist_failed',
                'The Invoice write checkpoint could not be stored.',
            );
        }

        try {
            $response = $this->client->post(
                '/Invoice/Factory/saveInvoice',
                $this->buildPayload(
                    $invoice,
                    $sevdeskContactId,
                    $taxDecision,
                    $deliveryCountryCode,
                    $target,
                    $eInvoiceContext,
                ),
                true,
                [201],
            );
        } catch (ApiException $exception) {
            if (
                $eInvoiceContext !== null
                && $exception->httpStatus === 422
                && !$exception->outcomeUnknown
            ) {
                $context = $exception->context();
                $context['definiteWriteRejected'] = true;

                return ExportResult::failed(
                    $invoice->invoiceId,
                    'e_invoice_required_data_rejected',
                    'sevdesk rejected required native E-Invoice data. Check the confirmed issuer, recipient, '
                        . 'payment method and address prerequisites; no normal-Invoice fallback was attempted.',
                    $context,
                );
            }

            return $this->apiFailure($invoice->invoiceId, $exception, 'invoice_create_failed');
        }

        $created = self::unwrapInvoice($response);
        $remoteId = self::remoteId($created);
        if ($remoteId === null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_id_missing',
                'sevdesk accepted the Invoice request but returned no Invoice ID. Reconcile before retrying.',
            );
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'invoice_created', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentNumber' => $invoice->invoiceNumber,
                ],
                $eInvoiceContext?->frozenContext() ?? ['isEInvoice' => false],
            ))
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_create',
                'The Invoice exists, but its creation checkpoint could not be stored.',
                $remoteId,
            );
        }

        // The create response is not the recovery source of truth. Read the
        // object back and verify the fields which define the accounting document.
        try {
            $remote = self::oneInvoice($this->client->get('/Invoice/' . rawurlencode($remoteId)));
        } catch (ApiException $exception) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_verification_failed',
                'The created Invoice could not be read back safely. Reconcile before retrying.',
                $remoteId,
                $exception->context(),
            );
        }

        $verification = $this->verifyRemoteInvoice(
            $remote,
            $invoice,
            $sevdeskContactId,
            $taxDecision,
            100,
            $remoteId,
            $deliveryCountryCode,
            $eInvoiceContext,
        );
        if ($verification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $verification,
                'The created sevdesk Invoice does not exactly match the frozen WHMCS document.',
                $remoteId,
            );
        }
        try {
            $remotePositions = $this->client->get(
                '/Invoice/' . rawurlencode($remoteId) . '/getPositions',
                ['limit' => 1000, 'offset' => 0],
            );
        } catch (ApiException $exception) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_position_verification_failed',
                'The created Invoice positions could not be read back safely. Reconcile before retrying.',
                $remoteId,
                $exception->context(),
            );
        }
        $positionVerification = $this->verifyRemotePositions($remotePositions, $invoice, $remoteId);
        if ($positionVerification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $positionVerification,
                'The created sevdesk Invoice positions do not exactly match the frozen WHMCS document.',
                $remoteId,
            );
        }

        $xmlSha256 = $this->verifiedXmlHash(
            $invoice,
            $remoteId,
            $eInvoiceContext,
            'invoice_xml_verification',
        );
        if ($xmlSha256 instanceof ExportResult) {
            return $xmlSha256;
        }
        if (
            $eInvoiceContext !== null
            && !$this->emitCheckpoint($checkpoint, 'invoice_xml_verified', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                ],
                $eInvoiceContext->frozenContext($xmlSha256),
            ))
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_xml',
                'The E-Invoice XML was verified, but its hash checkpoint could not be stored.',
                $remoteId,
                $eInvoiceContext->frozenContext($xmlSha256),
            );
        }

        try {
            $persisted = ($this->persistMapping)(
                $invoice->invoiceId,
                $remoteId,
                DocumentTargetDecision::DOCUMENT_INVOICE,
                $invoice->invoiceNumber,
                $eInvoiceContext !== null,
                $xmlSha256,
            );
            if ($persisted === false) {
                throw new \RuntimeException('Mapping callback returned false.');
            }
        } catch (Throwable) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'mapping_persist_failed',
                'The Invoice exists in sevdesk, but its typed WHMCS mapping could not be stored.',
                $remoteId,
            );
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'mapping_persisted', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentNumber' => $invoice->invoiceNumber,
                ],
                $eInvoiceContext?->frozenContext($xmlSha256) ?? [
                    'isEInvoice' => false,
                    'xmlSha256' => null,
                ],
            ))
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_mapping',
                'Invoice and mapping exist, but the mapping checkpoint could not be stored.',
                $remoteId,
            );
        }

        if ($target->documentAuthority === DocumentTargetResolver::AUTHORITY_WHMCS) {
            return $this->openForWhmcsAuthority(
                $invoice,
                $remoteId,
                $taxDecision,
                $sevdeskContactId,
                $deliveryCountryCode,
                $checkpoint,
                $eInvoiceContext,
            );
        }

        // The job handler immediately continues with the explicitly configured
        // sevdesk delivery path. This core only promises a verified mapped draft.
        return ExportResult::succeeded(
            $invoice->invoiceId,
            $remoteId,
            context: $eInvoiceContext?->frozenContext($xmlSha256) ?? ['isEInvoice' => false],
        );
    }

    /**
     * Resume the non-idempotent sendBy/open step without creating another Invoice.
     * The historic method name is retained for callers; sevdesk-authority flows
     * also use this step before WHMCS-template delivery or a mail-free backfill.
     */
    public function openForWhmcsAuthority(
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $taxDecision,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        ?callable $checkpoint = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ExportResult {
        $checkpoint = $checkpoint === null || $checkpoint instanceof Closure
            ? $checkpoint
            : Closure::fromCallable($checkpoint);
        if (self::numericId($remoteId) === null) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_invoice_id',
                'The mapped sevdesk Invoice ID is invalid.',
            );
        }

        $draftVerification = $this->verifyDraftBeforeWrite(
            $invoice,
            $remoteId,
            $taxDecision,
            $sevdeskContactId,
            $deliveryCountryCode,
            'invoice_open_prewrite',
            $eInvoiceContext,
        );
        if ($draftVerification !== null) {
            return $draftVerification;
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'invoice_open_write_requested', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                ],
                $eInvoiceContext?->frozenContext() ?? ['isEInvoice' => false],
            ))
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'checkpoint_persist_failed',
                'The Invoice open checkpoint could not be stored.',
            );
        }

        try {
            $this->client->put(
                '/Invoice/' . rawurlencode($remoteId) . '/sendBy',
                ['sendType' => 'VPDF', 'sendDraft' => false],
                true,
                [200],
            );
        } catch (ApiException $exception) {
            if ($exception->outcomeUnknown) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'invoice_open_failed_ambiguous',
                    'The Invoice open outcome is unknown. Verify the remote status before retrying.',
                    $remoteId,
                    $exception->context(),
                );
            }

            return $this->apiFailure($invoice->invoiceId, $exception, 'invoice_open_failed');
        }

        try {
            $remote = self::oneInvoice($this->client->get('/Invoice/' . rawurlencode($remoteId)));
        } catch (ApiException $exception) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_open_verification_failed',
                'The Invoice open request completed, but its state could not be verified.',
                $remoteId,
                $exception->context(),
            );
        }

        $verification = $this->verifyRemoteInvoice(
            $remote,
            $invoice,
            $sevdeskContactId,
            $taxDecision,
            200,
            $remoteId,
            $deliveryCountryCode,
            $eInvoiceContext,
        );
        if ($verification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_open_' . $verification,
                'The opened sevdesk Invoice no longer exactly matches the frozen WHMCS document.',
                $remoteId,
            );
        }
        try {
            $remotePositions = $this->client->get(
                '/Invoice/' . rawurlencode($remoteId) . '/getPositions',
                ['limit' => 1000, 'offset' => 0],
            );
        } catch (ApiException $exception) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_open_position_verification_failed',
                'The opened Invoice positions could not be verified.',
                $remoteId,
                $exception->context(),
            );
        }
        $positionVerification = $this->verifyRemotePositions($remotePositions, $invoice, $remoteId);
        if ($positionVerification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'invoice_open_' . $positionVerification,
                'The opened sevdesk Invoice positions no longer match the frozen WHMCS document.',
                $remoteId,
            );
        }

        $xmlSha256 = $this->verifiedXmlHash(
            $invoice,
            $remoteId,
            $eInvoiceContext,
            'invoice_open_xml_verification',
        );
        if ($xmlSha256 instanceof ExportResult) {
            return $xmlSha256;
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'invoice_opened', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentNumber' => $invoice->invoiceNumber,
                ],
                $eInvoiceContext?->frozenContext($xmlSha256) ?? ['isEInvoice' => false],
            ))
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_open',
                'The Invoice is open, but its final checkpoint could not be stored.',
                $remoteId,
            );
        }

        return ExportResult::succeeded(
            $invoice->invoiceId,
            $remoteId,
            context: $eInvoiceContext?->frozenContext($xmlSha256) ?? ['isEInvoice' => false],
        );
    }

    /**
     * Prove an earlier sendBy write by reads only. An empty or inconsistent
     * response is never treated as permission to execute sendBy again.
     */
    public function reconcileOpened(
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $taxDecision,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        ?callable $checkpoint = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ExportResult {
        $checkpoint = $checkpoint === null || $checkpoint instanceof Closure
            ? $checkpoint
            : Closure::fromCallable($checkpoint);

        $verified = $this->readAndVerifyFinalState(
            $invoice,
            $remoteId,
            $taxDecision,
            $sevdeskContactId,
            $deliveryCountryCode,
            expectedSendType: 'VPDF',
            requireSendDate: false,
            codePrefix: 'invoice_open_reconciliation',
            eInvoiceContext: $eInvoiceContext,
        );
        if ($verified !== null) {
            return $verified;
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'invoice_opened', [
                'invoiceId' => $invoice->invoiceId,
                'remoteId' => $remoteId,
                'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                'documentNumber' => $invoice->invoiceNumber,
            ])
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_open_reconciliation',
                'The opened Invoice was proven, but its checkpoint could not be stored.',
                $remoteId,
            );
        }

        return ExportResult::succeeded(
            $invoice->invoiceId,
            $remoteId,
            context: $eInvoiceContext?->frozenContext() ?? ['isEInvoice' => false],
        );
    }

    /** Send a draft Invoice through sevdesk and prove the final remote state. */
    public function deliverViaSevdesk(
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $taxDecision,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        string $toEmail,
        string $subject,
        string $text,
        ?callable $checkpoint = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ExportResult {
        $checkpoint = $checkpoint === null || $checkpoint instanceof Closure
            ? $checkpoint
            : Closure::fromCallable($checkpoint);
        $toEmail = trim($toEmail);
        $subject = trim($subject);
        $text = trim($text);
        if (
            filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false
            || $subject === ''
            || mb_strlen($subject) > 255
            || $text === ''
            || mb_strlen($text) > 10_000
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_invoice_delivery_message',
                'A valid recipient, subject and bounded message text are required.',
            );
        }

        $draftVerification = $this->verifyDraftBeforeWrite(
            $invoice,
            $remoteId,
            $taxDecision,
            $sevdeskContactId,
            $deliveryCountryCode,
            'invoice_delivery_prewrite',
            $eInvoiceContext,
        );
        if ($draftVerification !== null) {
            return $draftVerification;
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'invoice_delivery_write_requested', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                ],
                $eInvoiceContext?->frozenContext() ?? ['isEInvoice' => false],
            ))
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'checkpoint_persist_failed',
                'The Invoice delivery checkpoint could not be stored.',
            );
        }

        try {
            $payload = [
                'toEmail' => $toEmail,
                'subject' => $subject,
                'text' => $text,
                'copy' => false,
            ];
            if ($eInvoiceContext !== null) {
                // A ZUGFeRD PDF already embeds the authoritative XML. Sending a
                // second loose XML file is intentionally disabled.
                $payload['sendXml'] = false;
            }
            $this->client->post(
                '/Invoice/' . rawurlencode($remoteId) . '/sendViaEmail',
                $payload,
                true,
                [201],
            );
        } catch (ApiException $exception) {
            if ($exception->outcomeUnknown) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    'invoice_delivery_failed_ambiguous',
                    'The sevdesk email outcome is unknown. Reconcile by reads before any manual resend.',
                    $remoteId,
                    $exception->context(),
                );
            }

            return $this->apiFailure($invoice->invoiceId, $exception, 'invoice_delivery_failed');
        }

        return $this->verifyDelivered(
            $invoice,
            $remoteId,
            $taxDecision,
            $sevdeskContactId,
            $deliveryCountryCode,
            $checkpoint,
            $eInvoiceContext,
        );
    }

    /** Prove an earlier sendViaEmail call by reads only. */
    public function reconcileDelivered(
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $taxDecision,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        ?callable $checkpoint = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ExportResult {
        $checkpoint = $checkpoint === null || $checkpoint instanceof Closure
            ? $checkpoint
            : Closure::fromCallable($checkpoint);

        return $this->verifyDelivered(
            $invoice,
            $remoteId,
            $taxDecision,
            $sevdeskContactId,
            $deliveryCountryCode,
            $checkpoint,
            $eInvoiceContext,
        );
    }

    private function verifyDelivered(
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $taxDecision,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        ?Closure $checkpoint,
        ?EInvoiceContext $eInvoiceContext,
    ): ExportResult {
        $verified = $this->readAndVerifyFinalState(
            $invoice,
            $remoteId,
            $taxDecision,
            $sevdeskContactId,
            $deliveryCountryCode,
            expectedSendType: 'VM',
            requireSendDate: true,
            codePrefix: 'invoice_delivery_reconciliation',
            eInvoiceContext: $eInvoiceContext,
        );
        if ($verified !== null) {
            return $verified;
        }

        if (
            !$this->emitCheckpoint($checkpoint, 'invoice_delivered', array_merge(
                [
                    'invoiceId' => $invoice->invoiceId,
                    'remoteId' => $remoteId,
                    'documentType' => DocumentTargetDecision::DOCUMENT_INVOICE,
                    'documentNumber' => $invoice->invoiceNumber,
                ],
                $eInvoiceContext?->frozenContext() ?? ['isEInvoice' => false],
            ))
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                'checkpoint_persist_failed_after_delivery',
                'The delivered Invoice was proven, but its checkpoint could not be stored.',
                $remoteId,
            );
        }

        return ExportResult::succeeded(
            $invoice->invoiceId,
            $remoteId,
            context: $eInvoiceContext?->frozenContext() ?? ['isEInvoice' => false],
        );
    }

    private function verifyDraftBeforeWrite(
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $taxDecision,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        string $codePrefix,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ?ExportResult {
        try {
            $remote = self::oneInvoice($this->client->get('/Invoice/' . rawurlencode($remoteId)));
            $remotePositions = $this->client->get(
                '/Invoice/' . rawurlencode($remoteId) . '/getPositions',
                ['limit' => 1000, 'offset' => 0],
            );
        } catch (ApiException $exception) {
            return ExportResult::failed(
                $invoice->invoiceId,
                $exception->isAuthenticationFailure()
                    ? 'api_authentication_failed'
                    : $codePrefix . '_lookup_failed',
                'The mapped sevdesk Invoice draft could not be verified before the remote write.',
                $exception->context(),
            );
        }

        $verification = $this->verifyRemoteInvoice(
            $remote,
            $invoice,
            $sevdeskContactId,
            $taxDecision,
            100,
            $remoteId,
            $deliveryCountryCode,
            $eInvoiceContext,
        );
        if ($verification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_' . $verification,
                'The mapped sevdesk Invoice draft no longer exactly matches the frozen WHMCS document.',
                $remoteId,
            );
        }

        $positionVerification = $this->verifyRemotePositions($remotePositions, $invoice, $remoteId);
        if ($positionVerification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_' . $positionVerification,
                'The mapped sevdesk Invoice draft positions no longer match the frozen WHMCS document.',
                $remoteId,
            );
        }

        $xmlSha256 = $this->verifiedXmlHash($invoice, $remoteId, $eInvoiceContext, $codePrefix . '_xml');
        if ($xmlSha256 instanceof ExportResult) {
            return $xmlSha256;
        }

        return null;
    }

    private function readAndVerifyFinalState(
        InvoiceSnapshot $invoice,
        string $remoteId,
        TaxDecision $taxDecision,
        string $sevdeskContactId,
        string $deliveryCountryCode,
        string $expectedSendType,
        bool $requireSendDate,
        string $codePrefix,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ?ExportResult {
        if (self::numericId($remoteId) === null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_invalid_id',
                'The mapped sevdesk Invoice ID is invalid.',
                $remoteId,
            );
        }

        try {
            $remote = self::oneInvoice($this->client->get('/Invoice/' . rawurlencode($remoteId)));
            $remotePositions = $this->client->get(
                '/Invoice/' . rawurlencode($remoteId) . '/getPositions',
                ['limit' => 1000, 'offset' => 0],
            );
        } catch (ApiException $exception) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_lookup_failed',
                'The remote Invoice state could not be proven by reads.',
                $remoteId,
                $exception->context(),
            );
        }

        $verification = $this->verifyRemoteInvoice(
            $remote,
            $invoice,
            $sevdeskContactId,
            $taxDecision,
            200,
            $remoteId,
            $deliveryCountryCode,
            $eInvoiceContext,
        );
        if ($verification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_' . $verification,
                'The final sevdesk Invoice no longer exactly matches the frozen WHMCS document.',
                $remoteId,
            );
        }
        if (strtoupper(trim((string) ($remote['sendType'] ?? ''))) !== $expectedSendType) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_send_type_mismatch',
                'The expected sevdesk delivery channel cannot be proven.',
                $remoteId,
            );
        }
        if ($requireSendDate) {
            $sendDate = trim((string) ($remote['sendDate'] ?? ''));
            if ($sendDate === '') {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    $codePrefix . '_send_date_missing',
                    'sevdesk does not expose a send date for the possibly delivered Invoice.',
                    $remoteId,
                );
            }
            if (!self::isExplicitIsoDateTime($sendDate)) {
                return ExportResult::ambiguous(
                    $invoice->invoiceId,
                    $codePrefix . '_send_date_invalid',
                    'The sevdesk send date is not a valid ISO-8601 timestamp with an explicit timezone.',
                    $remoteId,
                );
            }
        }

        $positionVerification = $this->verifyRemotePositions($remotePositions, $invoice, $remoteId);
        if ($positionVerification !== null) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_' . $positionVerification,
                'The final sevdesk Invoice positions no longer match the frozen WHMCS document.',
                $remoteId,
            );
        }

        $xmlSha256 = $this->verifiedXmlHash($invoice, $remoteId, $eInvoiceContext, $codePrefix . '_xml');
        if ($xmlSha256 instanceof ExportResult) {
            return $xmlSha256;
        }

        return null;
    }

    private static function isExplicitIsoDateTime(string $value): bool
    {
        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?'
                . '(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/',
                $value,
            ) !== 1
        ) {
            return false;
        }

        $withoutFraction = (string) preg_replace(
            '/\.\d+(?=Z|[+-]\d{2}:\d{2}$)/',
            '',
            $value,
        );
        if (\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $withoutFraction) === false) {
            return false;
        }

        $errors = \DateTimeImmutable::getLastErrors();

        return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
    }

    /** @return array<string, mixed> */
    public function buildPayload(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        string $deliveryCountryCode,
        DocumentTargetDecision $target,
        ?EInvoiceContext $eInvoiceContext = null,
    ): array {
        $preflight = $this->preflight(
            $invoice,
            $sevdeskContactId,
            $taxDecision,
            $deliveryCountryCode,
            $target,
            $eInvoiceContext,
        );
        if ($preflight !== null) {
            throw new \InvalidArgumentException($preflight->code . ': ' . $preflight->message);
        }

        $taxRuleId = $taxDecision->taxRuleId ?? '';
        $showNet = $invoice->lineItems[0]->net;
        $positions = [];
        foreach ($invoice->lineItems as $index => $lineItem) {
            $positions[] = [
                'objectName' => 'InvoicePos',
                'mapAll' => true,
                'quantity' => 1,
                'unity' => [
                    'id' => self::payloadId($this->unityId),
                    'objectName' => 'Unity',
                ],
                'positionNumber' => $index + 1,
                'name' => mb_substr($lineItem->description, 0, 255),
                'text' => mb_substr($lineItem->description, 0, 1000),
                'price' => Decimal::toFloat($lineItem->amount),
                'taxRate' => Decimal::toFloat($lineItem->taxRate),
            ];
        }

        $invoicePayload = [
                'objectName' => 'Invoice',
                'mapAll' => true,
                'invoiceNumber' => $invoice->invoiceNumber,
                'invoiceDate' => $invoice->invoiceDate->format('d.m.Y'),
                'deliveryDate' => $invoice->invoiceDate->format('d.m.Y'),
                'header' => 'Rechnung ' . $invoice->invoiceNumber,
                'headText' => '',
                'footText' => '',
                'timeToPay' => 0,
                'invoiceType' => 'RE',
                'status' => 100,
                'contact' => [
                    'id' => self::payloadId($sevdeskContactId),
                    'objectName' => 'Contact',
                ],
                'contactPerson' => [
                    'id' => self::payloadId($this->sevUserId),
                    'objectName' => 'SevUser',
                ],
                'currency' => $invoice->currency,
                'discount' => 0,
                'showNet' => $showNet,
                'taxRate' => 0,
                'taxRule' => [
                    'id' => self::payloadId($taxRuleId),
                    'objectName' => 'TaxRule',
                ],
                'taxText' => self::taxText($taxRuleId),
                'smallSettlement' => $taxRuleId === '11',
                'customerInternalNote' => self::marker($invoice->invoiceId),
                'deliveryAddressCountry' => strtoupper($deliveryCountryCode),
                'propertyIsEInvoice' => false,
        ];
        if ($eInvoiceContext !== null) {
            $invoicePayload = array_merge($invoicePayload, $eInvoiceContext->invoicePayloadFields());
        }

        return [
            'invoice' => $invoicePayload,
            'invoicePosSave' => $positions,
            // sevdesk documents that these four members must be present and in
            // this order even when a new Invoice has nothing to delete.
            'invoicePosDelete' => null,
            'discountSave' => null,
            'discountDelete' => null,
            'takeDefaultAddress' => $eInvoiceContext === null,
        ];
    }

    public static function marker(int $invoiceId): string
    {
        return '[WHMCS-INVOICE:' . $invoiceId . ']';
    }

    public static function markerMatches(string $note, int $invoiceId): bool
    {
        return InvoiceRemoteVerifier::markerMatches($note, $invoiceId);
    }

    private function preflight(
        InvoiceSnapshot $invoice,
        string $sevdeskContactId,
        TaxDecision $taxDecision,
        string $deliveryCountryCode,
        DocumentTargetDecision $target,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ?ExportResult {
        if (!$target->allowed || $target->documentType !== DocumentTargetDecision::DOCUMENT_INVOICE) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invoice_target_not_selected',
                'The frozen document target does not allow an Invoice write.',
            );
        }
        if ($invoice->currency !== 'EUR') {
            return ExportResult::failed(
                $invoice->invoiceId,
                'foreign_currency_requires_review',
                'Foreign-currency Invoices are not enabled in this release.',
            );
        }
        if ($invoice->appliedCreditMinorUnits() > 0) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'credit_applied_requires_review',
                'Invoices with applied WHMCS credit require individual review.',
            );
        }
        if ($invoice->totalMinorUnits() <= 0) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'non_positive_total_requires_review',
                'Zero or negative Invoices are not automatically exported.',
            );
        }
        if ($invoice->hasMixedNetModes()) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'mixed_net_gross_modes',
                'All sevdesk Invoice positions must use the same net/gross mode.',
            );
        }
        if (count($invoice->lineItems) >= 1000) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invoice_position_limit_exceeded',
                'Invoices with 1000 or more positions cannot be verified safely through the sevdesk API.',
            );
        }
        foreach ($invoice->lineItems as $lineItem) {
            if (Decimal::toMinorUnits($lineItem->amount) <= 0) {
                return ExportResult::failed(
                    $invoice->invoiceId,
                    'non_positive_line_requires_review',
                    'sevdesk Invoice positions must be positive.',
                );
            }
        }
        if ($invoice->lineGrossMinorUnits() !== $invoice->totalMinorUnits()) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invoice_total_mismatch',
                'The calculated line total differs from the WHMCS Invoice total.',
            );
        }
        if (
            self::numericId($sevdeskContactId) === null
            || self::numericId($this->sevUserId) === null
            || self::numericId($this->unityId) === null
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_invoice_reference',
                'Contact, SevUser and Unity must be valid sevdesk IDs.',
            );
        }
        $deliveryCountryCode = strtoupper(trim($deliveryCountryCode));
        if (preg_match('/^[A-Z]{2}$/', $deliveryCountryCode) !== 1) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'invalid_delivery_country',
                'A two-letter delivery country is required for Invoice export.',
            );
        }
        if (!$taxDecision->allowed || $taxDecision->taxRuleId === null) {
            return ExportResult::failed($invoice->invoiceId, $taxDecision->code, $taxDecision->message);
        }
        if (
            !in_array($taxDecision->taxRuleId, self::SUPPORTED_TAX_RULES, true)
            || $target->taxRuleId !== $taxDecision->taxRuleId
        ) {
            return ExportResult::failed(
                $invoice->invoiceId,
                'unsupported_invoice_tax_rule',
                'The Invoice tax rule is unsupported or differs from the frozen document target.',
            );
        }
        if ($eInvoiceContext !== null) {
            if (
                $target->exportMode !== DocumentTargetResolver::MODE_INVOICE_ONLY
                || $target->documentAuthority !== DocumentTargetResolver::AUTHORITY_SEVDESK
            ) {
                return ExportResult::failed(
                    $invoice->invoiceId,
                    'e_invoice_requires_invoice_only_sevdesk_authority',
                    'ZUGFeRD requires invoice_only with sevdesk document authority.',
                );
            }
            if ($taxDecision->taxRuleId !== '1') {
                return ExportResult::failed(
                    $invoice->invoiceId,
                    'e_invoice_tax_rule_not_supported',
                    'ZUGFeRD v1 only supports domestic Tax Rule 1.',
                );
            }
            if (
                $deliveryCountryCode !== 'DE'
                || $sevdeskContactId !== $eInvoiceContext->contactId
                || $this->unityId !== $eInvoiceContext->unityId
            ) {
                return ExportResult::failed(
                    $invoice->invoiceId,
                    'e_invoice_frozen_context_mismatch',
                    'The current Invoice references differ from the frozen E-Invoice decision.',
                );
            }
        }
        if ($taxDecision->taxRuleId === '19') {
            if ($target->ossProfile !== DocumentTargetResolver::OSS_RULE_19_CONFIRMED) {
                return ExportResult::failed(
                    $invoice->invoiceId,
                    'oss_profile_not_confirmed',
                    'Rule 19 requires the confirmed digital-services profile.',
                );
            }
            foreach ($invoice->lineItems as $lineItem) {
                if (!self::taxRateAllowed($lineItem, $taxDecision->allowedTaxRates)) {
                    return ExportResult::failed(
                        $invoice->invoiceId,
                        'oss_tax_rate_mismatch',
                        'A Rule 19 position rate differs from the confirmed WHMCS tax decision.',
                    );
                }
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $remote */
    private function verifyRemoteInvoice(
        array $remote,
        InvoiceSnapshot $invoice,
        ?string $sevdeskContactId,
        TaxDecision $taxDecision,
        int $expectedStatus,
        ?string $expectedRemoteId = null,
        ?string $deliveryCountryCode = null,
        ?EInvoiceContext $eInvoiceContext = null,
    ): ?string {
        $mismatch = $this->remoteVerifier->invoiceMismatch(
            $remote,
            $invoice,
            $sevdeskContactId,
            $taxDecision->taxRuleId ?? '',
            $expectedStatus,
            $expectedRemoteId,
            $deliveryCountryCode,
            $eInvoiceContext,
        );

        return $mismatch === null ? null : 'remote_' . $mismatch;
    }

    /** @return ExportResult|string|null */
    private function verifiedXmlHash(
        InvoiceSnapshot $invoice,
        string $remoteId,
        ?EInvoiceContext $eInvoiceContext,
        string $codePrefix,
    ): ExportResult|string|null {
        if ($eInvoiceContext === null) {
            return null;
        }

        try {
            $xml = $this->invoiceXml->fetch($remoteId);
        } catch (ApiException $exception) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $exception->isAuthenticationFailure()
                    ? 'api_authentication_failed'
                    : $codePrefix . '_failed',
                'The native sevdesk E-Invoice XML could not be verified safely.',
                $remoteId,
                $exception->context(),
            );
        } catch (Throwable) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_invalid',
                'sevdesk did not return a structurally valid native E-Invoice XML.',
                $remoteId,
            );
        }

        $sha256 = $xml['sha256'];
        if (
            $eInvoiceContext->expectedXmlSha256 !== null
            && !hash_equals($eInvoiceContext->expectedXmlSha256, $sha256)
        ) {
            return ExportResult::ambiguous(
                $invoice->invoiceId,
                $codePrefix . '_hash_mismatch',
                'The native E-Invoice XML differs from the previously verified document.',
                $remoteId,
                array_merge(
                    $eInvoiceContext->frozenContext(),
                    ['observedXmlSha256' => $sha256],
                ),
            );
        }

        return $sha256;
    }

    /** @param array<array-key, mixed> $positions */
    private function verifyRemotePositions(array $positions, InvoiceSnapshot $invoice, string $remoteId): ?string
    {
        $mismatch = $this->remoteVerifier->positionsMismatch($positions, $invoice, $remoteId);

        return $mismatch === null ? null : 'remote_' . $mismatch;
    }

    private function apiFailure(int $invoiceId, ApiException $exception, string $code): ExportResult
    {
        if ($exception->outcomeUnknown) {
            return ExportResult::ambiguous(
                $invoiceId,
                $code . '_ambiguous',
                'The sevdesk write outcome is unknown. Reconcile before retrying.',
                null,
                $exception->context(),
            );
        }

        if ($exception->isAuthenticationFailure()) {
            $code = 'api_authentication_failed';
        } elseif ($exception->isRateLimit()) {
            $code = 'api_rate_limited';
        } elseif ($exception->isPermanentClientFailure()) {
            $code .= '_permanent';
        }

        $context = $exception->context();
        $context['definiteWriteRejected'] = true;

        return ExportResult::failed(
            $invoiceId,
            $code,
            'The sevdesk API operation failed.',
            $context,
        );
    }

    /**
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>
     */
    private static function unwrapInvoice(array $response): array
    {
        return isset($response['invoice']) && is_array($response['invoice'])
            ? $response['invoice']
            : $response;
    }

    /**
     * @param array<array-key, mixed> $response
     * @return array<array-key, mixed>
     */
    private static function oneInvoice(array $response): array
    {
        if (array_is_list($response)) {
            if (count($response) !== 1 || !is_array($response[0])) {
                return [];
            }

            return self::unwrapInvoice($response[0]);
        }

        return self::unwrapInvoice($response);
    }

    /** @param array<array-key, mixed> $invoice */
    private static function remoteId(array $invoice): ?string
    {
        return self::numericId($invoice['id'] ?? null);
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }

    private static function payloadId(string $id): int|string
    {
        return ctype_digit($id) && strlen($id) < 19 ? (int) $id : $id;
    }

    /** @param list<string> $allowedRates */
    private static function taxRateAllowed(LineItem $lineItem, array $allowedRates): bool
    {
        $actual = round(Decimal::toFloat($lineItem->taxRate), 4);
        foreach ($allowedRates as $allowedRate) {
            if (round(Decimal::toFloat($allowedRate), 4) === $actual) {
                return true;
            }
        }

        return false;
    }

    private static function taxText(string $taxRuleId): string
    {
        return match ($taxRuleId) {
            '1' => 'Umsatzsteuerpflichtige Umsätze',
            '2' => 'Ausfuhren',
            '3' => 'Innergemeinschaftliche Lieferung',
            '4' => 'Steuerfreie Umsätze nach § 4 UStG',
            '5' => 'Reverse Charge nach § 13b UStG',
            '11' => 'Kleinunternehmerregelung nach § 19 UStG',
            '17' => 'Nicht im Inland steuerbare Leistung',
            '19' => 'OSS – elektronisch erbrachte Leistung',
            default => 'Umsatzsteuer',
        };
    }

    /**
     * @param Closure(string, array<string, scalar|null>): (bool|null)|null $checkpoint
     * @param array<string, scalar|null> $context
     */
    private function emitCheckpoint(?Closure $checkpoint, string $name, array $context): bool
    {
        if ($checkpoint === null) {
            return true;
        }

        try {
            return $checkpoint($name, $context) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
