<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use Throwable;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;

/** Read-only type detection followed by one explicit, freshly verified confirmation. */
final class LegacyMappingTypeService
{
    /** @var Closure(int, string, string, string, string): (void|bool) */
    private readonly Closure $persistMetadata;

    /**
     * @param callable(int, string, string, string, string): (void|bool) $persistMetadata
     *     Receives WHMCS invoice ID, unchanged sevdesk ID, type, document number
     *     and the authority explicitly selected by the administrator.
     */
    public function __construct(
        private readonly SevdeskClient $client,
        callable $persistMetadata,
    ) {
        $this->persistMetadata = Closure::fromCallable($persistMetadata);
    }

    /**
     * @return array{
     *     status: 'suggested'|'blocked'|'failed',
     *     code: string,
     *     message: string,
     *     suggestedType?: 'voucher'|'invoice',
     *     documentNumber?: string,
     *     context?: array<string, scalar|null>
     * }
     */
    public function inspect(int $invoiceId, string $invoiceNumber, string $remoteId): array
    {
        $inputFailure = self::validateInput($invoiceId, $invoiceNumber, $remoteId);
        if ($inputFailure !== null) {
            return $inputFailure;
        }
        $invoiceNumber = WhmcsGateway::effectiveInvoiceNumber($invoiceId, $invoiceNumber);
        $remoteId = trim($remoteId);

        $voucher = null;
        $invoice = null;
        $voucherFailure = null;
        $invoiceFailure = null;
        try {
            $voucher = $this->readDocument('Voucher', $remoteId);
        } catch (Throwable $exception) {
            $voucherFailure = $exception;
        }
        try {
            $invoice = $this->readDocument('Invoice', $remoteId);
        } catch (Throwable $exception) {
            $invoiceFailure = $exception;
        }

        foreach ([$voucherFailure, $invoiceFailure] as $failure) {
            if ($failure instanceof ApiException && $failure->isAuthenticationFailure()) {
                return self::apiFailure($failure);
            }
        }
        $lookupFailure = $voucherFailure ?? $invoiceFailure;
        if ($lookupFailure instanceof ApiException) {
            return self::apiFailure($lookupFailure);
        }
        if ($lookupFailure !== null) {
            return self::result(
                'failed',
                'legacy_mapping_type_check_failed',
                'sevdesk returned no safely verifiable document response.',
            );
        }

        $voucherEvidence = $voucher !== null
            ? self::voucherEvidence($voucher, $remoteId, $invoiceNumber, $invoiceId)
            : self::emptyEvidence();
        $invoiceEvidence = $invoice !== null
            ? self::invoiceEvidence($invoice, $remoteId, $invoiceNumber, $invoiceId)
            : self::emptyEvidence();
        $voucherMatches = $voucherEvidence['valid'];
        $invoiceMatches = $invoiceEvidence['valid'];

        if ($voucher !== null && $invoice !== null) {
            return self::result(
                'blocked',
                'legacy_mapping_type_collision',
                'The same sevdesk ID exists as both Voucher and Invoice. No type can be proposed safely.',
                context: ['matchCount' => (int) $voucherMatches + (int) $invoiceMatches],
            );
        }
        if (!$voucherMatches && !$invoiceMatches) {
            return self::result(
                'blocked',
                'legacy_mapping_type_no_match',
                'Neither sevdesk document type has an exact reference without contradictory marker evidence.',
                context: ['matchCount' => 0],
            );
        }

        $type = $invoiceMatches
            ? MappingRepository::DOCUMENT_TYPE_INVOICE
            : MappingRepository::DOCUMENT_TYPE_VOUCHER;
        $evidence = $invoiceMatches ? $invoiceEvidence : $voucherEvidence;
        $markerEvidence = $evidence['markerMatched'];

        return self::result(
            'suggested',
            $type === MappingRepository::DOCUMENT_TYPE_INVOICE
                ? 'legacy_mapping_invoice_suggested'
                : 'legacy_mapping_voucher_suggested',
            $markerEvidence
                ? 'Exactly one remote document type matches the document number and Rewrite marker.'
                : 'Exactly one remote document type and number match, but the legacy document has no Rewrite marker.',
            $type,
            $invoiceNumber,
            [
                'matchCount' => 1,
                'numberEvidence' => $evidence['numberMatched'],
                'markerEvidence' => $markerEvidence,
                'legacyMarkerMissing' => $evidence['markerMissing'],
                'deliveryReady' => $evidence['deliveryReady'],
            ],
        );
    }

    /**
     * Repeats both remote reads before persisting metadata. The stored remote ID
     * is an input to the callback and can therefore never be replaced here.
     *
     * @return array{
     *     status: 'confirmed'|'blocked'|'failed',
     *     code: string,
     *     message: string,
     *     suggestedType?: 'voucher'|'invoice',
     *     documentNumber?: string,
     *     context?: array<string, scalar|null>
     * }
     */
    public function confirm(
        int $invoiceId,
        string $invoiceNumber,
        string $remoteId,
        string $confirmedType,
        string $confirmedAuthority,
    ): array {
        $confirmedType = strtolower(trim($confirmedType));
        $confirmedAuthority = strtolower(trim($confirmedAuthority));
        if (
            !in_array(
                $confirmedType,
                [
                    MappingRepository::DOCUMENT_TYPE_VOUCHER,
                    MappingRepository::DOCUMENT_TYPE_INVOICE,
                ],
                true,
            )
        ) {
            return self::result(
                'blocked',
                'legacy_mapping_confirmation_invalid',
                'The requested document type is invalid.',
            );
        }
        if (
            !in_array($confirmedAuthority, [
                MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
                MappingRepository::DOCUMENT_AUTHORITY_SEVDESK,
            ], true)
        ) {
            return self::result(
                'blocked',
                'legacy_mapping_authority_invalid',
                'The requested document authority is invalid.',
            );
        }
        if (
            $confirmedType === MappingRepository::DOCUMENT_TYPE_VOUCHER
            && $confirmedAuthority !== MappingRepository::DOCUMENT_AUTHORITY_WHMCS
        ) {
            return self::result(
                'blocked',
                'legacy_mapping_authority_invalid',
                'A Voucher can only keep WHMCS document authority.',
            );
        }

        $inspection = $this->inspect($invoiceId, $invoiceNumber, $remoteId);
        if (($inspection['status'] ?? '') !== 'suggested') {
            return $inspection;
        }
        if (($inspection['suggestedType'] ?? null) !== $confirmedType) {
            return self::result(
                'blocked',
                'legacy_mapping_confirmation_changed',
                'The freshly verified document type differs from the submitted confirmation.',
                context: ['matchCount' => 1],
            );
        }
        if (
            $confirmedAuthority === MappingRepository::DOCUMENT_AUTHORITY_SEVDESK
            && ($inspection['context']['deliveryReady'] ?? false) !== true
        ) {
            return self::result(
                'blocked',
                'legacy_mapping_delivery_not_ready',
                'The legacy Invoice is not finalized and cannot become the customer-facing document.',
                context: ['matchCount' => 1],
            );
        }

        try {
            $persisted = ($this->persistMetadata)(
                $invoiceId,
                trim($remoteId),
                $confirmedType,
                WhmcsGateway::effectiveInvoiceNumber($invoiceId, $invoiceNumber),
                $confirmedAuthority,
            );
            if ($persisted === false) {
                throw new \RuntimeException('Mapping metadata callback returned false.');
            }
        } catch (Throwable) {
            return self::result(
                'failed',
                'legacy_mapping_metadata_persist_failed',
                'The remote document was verified, but its local type metadata could not be stored.',
            );
        }

        return self::result(
            'confirmed',
            'legacy_mapping_type_confirmed',
            'The legacy mapping received its explicitly confirmed document type and authority.',
            $confirmedType,
            WhmcsGateway::effectiveInvoiceNumber($invoiceId, $invoiceNumber),
            ['documentAuthority' => $confirmedAuthority],
        );
    }

    /** @return array<string, mixed>|null */
    private function readDocument(string $resource, string $remoteId): ?array
    {
        try {
            $response = $this->client->get('/' . $resource . '/' . rawurlencode($remoteId));
        } catch (ApiException $exception) {
            // The versioned sevdesk contract documents 400 as the definitive
            // by-ID absence response for both Invoice and Voucher. Some
            // tenants also return the conventional 404.
            $absenceStatuses = [400, 404];
            if (in_array($exception->httpStatus, $absenceStatuses, true)) {
                return null;
            }

            throw $exception;
        }

        if ($response === []) {
            throw new \RuntimeException('Remote document lookup returned no document in a successful response.');
        }
        $records = array_is_list($response) ? $response : [$response];
        if (count($records) !== 1 || !is_array($records[0])) {
            throw new \RuntimeException('Remote document lookup was not unique.');
        }
        $nestedKey = strtolower($resource);
        $record = $records[0];
        if (isset($record[$nestedKey]) && is_array($record[$nestedKey])) {
            $record = $record[$nestedKey];
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $voucher
     * @return array{valid:bool,numberMatched:bool,markerMatched:bool,markerMissing:bool,deliveryReady:bool}
     */
    private static function voucherEvidence(
        array $voucher,
        string $remoteId,
        string $invoiceNumber,
        int $invoiceId,
    ): array {
        $description = trim((string) ($voucher['description'] ?? ''));
        $numberMatched = $description === $invoiceNumber
            || str_starts_with($description, $invoiceNumber . ' ');
        $marker = self::markerEvidence($description, $invoiceId);
        $identityMatched = self::numericId($voucher['id'] ?? null) === $remoteId
            && (string) ($voucher['objectName'] ?? '') === 'Voucher';

        return [
            'valid' => $identityMatched && $numberMatched && !$marker['contradictory'],
            'numberMatched' => $numberMatched,
            'markerMatched' => $marker['matched'],
            'markerMissing' => $marker['missing'],
            'deliveryReady' => false,
        ];
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array{valid:bool,numberMatched:bool,markerMatched:bool,markerMissing:bool,deliveryReady:bool}
     */
    private static function invoiceEvidence(
        array $invoice,
        string $remoteId,
        string $invoiceNumber,
        int $invoiceId,
    ): array {
        $numberMatched = (string) ($invoice['invoiceNumber'] ?? '') === $invoiceNumber;
        $marker = self::markerEvidence(
            (string) ($invoice['customerInternalNote'] ?? ''),
            $invoiceId,
        );
        $identityMatched = self::numericId($invoice['id'] ?? null) === $remoteId
            && (string) ($invoice['objectName'] ?? '') === 'Invoice'
            && (string) ($invoice['invoiceType'] ?? '') === 'RE';

        return [
            'valid' => $identityMatched && $numberMatched && !$marker['contradictory'],
            'numberMatched' => $numberMatched,
            'markerMatched' => $marker['matched'],
            'markerMissing' => $marker['missing'],
            'deliveryReady' => in_array((int) ($invoice['status'] ?? 0), [200, 750, 1000], true),
        ];
    }

    /** @return array{matched:bool,missing:bool,contradictory:bool} */
    private static function markerEvidence(string $value, int $invoiceId): array
    {
        $markerPresent = str_contains($value, '[WHMCS-INVOICE:');
        $markerMatched = InvoiceExporter::markerMatches($value, $invoiceId);

        return [
            'matched' => $markerMatched,
            'missing' => !$markerPresent,
            'contradictory' => $markerPresent && !$markerMatched,
        ];
    }

    /** @return array{valid:false,numberMatched:false,markerMatched:false,markerMissing:false,deliveryReady:false} */
    private static function emptyEvidence(): array
    {
        return [
            'valid' => false,
            'numberMatched' => false,
            'markerMatched' => false,
            'markerMissing' => false,
            'deliveryReady' => false,
        ];
    }

    /**
     * @return array{status:'blocked',code:string,message:string}|null
     */
    private static function validateInput(int $invoiceId, string $invoiceNumber, string $remoteId): ?array
    {
        if (
            $invoiceId < 1
            || mb_strlen(WhmcsGateway::effectiveInvoiceNumber($invoiceId, $invoiceNumber)) > 191
            || self::numericId($remoteId) === null
        ) {
            return self::result(
                'blocked',
                'legacy_mapping_reference_invalid',
                'A complete WHMCS invoice and numeric sevdesk mapping are required.',
            );
        }

        return null;
    }

    private static function numericId(mixed $value): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }

    /**
     * @param 'suggested'|'confirmed'|'blocked'|'failed' $status
     * @param 'voucher'|'invoice'|null $suggestedType
     * @param array<string, scalar|null> $context
     * @return array<string, mixed>
     */
    private static function result(
        string $status,
        string $code,
        string $message,
        ?string $suggestedType = null,
        ?string $documentNumber = null,
        array $context = [],
    ): array {
        $result = ['status' => $status, 'code' => $code, 'message' => $message];
        if ($suggestedType !== null) {
            $result['suggestedType'] = $suggestedType;
        }
        if ($documentNumber !== null) {
            $result['documentNumber'] = $documentNumber;
        }
        if ($context !== []) {
            $result['context'] = $context;
        }

        return $result;
    }

    /** @return array{status:'failed',code:string,message:string,context:array<string,scalar|null>} */
    private static function apiFailure(ApiException $exception): array
    {
        return [
            'status' => 'failed',
            'code' => $exception->isAuthenticationFailure()
                ? 'api_authentication_failed'
                : 'legacy_mapping_type_check_failed',
            'message' => 'The read-only sevdesk document type check failed.',
            'context' => $exception->context(),
        ];
    }
}
