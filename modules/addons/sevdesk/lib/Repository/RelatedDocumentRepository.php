<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

/**
 * Stores documents related to an invoice without replacing the
 * Becker-compatible primary mapping in mod_sevdesk.
 */
final class RelatedDocumentRepository
{
    public const ROLE_REMINDER = 'reminder';
    public const ROLE_CANCELLATION = 'cancellation';
    public const ROLE_LATE_FEE_VOUCHER = 'late_fee_voucher';

    /** @var list<string> */
    private const ROLES = [
        self::ROLE_REMINDER,
        self::ROLE_CANCELLATION,
        self::ROLE_LATE_FEE_VOUCHER,
    ];

    public function find(int $invoiceId, string $role, int $dunningLevel = 0): ?object
    {
        self::assertIdentity($invoiceId, $role, $dunningLevel);

        return Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)
            ->where('invoice_id', $invoiceId)
            ->where('role', $role)
            ->where('dunning_level', $dunningLevel)
            ->orderByDesc('id')
            ->first();
    }

    public function hasChargedReminder(int $invoiceId): bool
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('A positive WHMCS invoice ID is required.');
        }

        return Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)
            ->where('invoice_id', $invoiceId)
            ->where('role', self::ROLE_REMINDER)
            ->where('amount_minor', '>', 0)
            ->exists();
    }

    /** @return list<object> */
    public function forInvoice(int $invoiceId, ?string $role = null): array
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('A positive WHMCS invoice ID is required.');
        }

        if ($role !== null && !in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Invalid related-document role.');
        }
        $query = Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)
            ->where('invoice_id', $invoiceId)
            ->orderBy('id');
        if ($role !== null) {
            $query->where('role', $role);
        }

        return $query->get()->all();
    }

    /**
     * @param list<int> $invoiceIds
     * @return array<int,list<object>>
     */
    public function forInvoices(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique(array_filter(
            $invoiceIds,
            static fn (int $invoiceId): bool => $invoiceId > 0,
        )));
        if ($invoiceIds === []) {
            return [];
        }
        $grouped = [];
        foreach (
            Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)
                ->whereIn('invoice_id', $invoiceIds)
                ->orderBy('id')
                ->get() as $document
        ) {
            $grouped[(int) $document->invoice_id][] = $document;
        }

        return $grouped;
    }

    public function linkVerified(
        int $invoiceId,
        string $role,
        int $dunningLevel,
        string $remoteId,
        string $parentRemoteId,
        int $amountMinor,
        string $fingerprint,
        ?string $documentNumber = null,
        ?string $pdfSha256 = null,
        ?string $xmlSha256 = null,
        string $deliveryStatus = 'not_requested',
    ): void {
        self::assertIdentity($invoiceId, $role, $dunningLevel);
        self::assertRemoteId($remoteId, 'document');
        self::assertRemoteId($parentRemoteId, 'parent document');
        $fingerprint = self::assertHash($fingerprint, 'fingerprint');
        $pdfSha256 = $pdfSha256 === null ? null : self::assertHash($pdfSha256, 'PDF checksum');
        $xmlSha256 = $xmlSha256 === null ? null : self::assertHash($xmlSha256, 'XML checksum');
        $documentNumber = self::documentNumber($documentNumber);
        if (!in_array($deliveryStatus, ['not_requested', 'pending', 'delivered', 'ambiguous'], true)) {
            throw new InvalidArgumentException('Invalid related-document delivery status.');
        }

        Capsule::connection()->transaction(static function () use (
            $invoiceId,
            $role,
            $dunningLevel,
            $remoteId,
            $parentRemoteId,
            $amountMinor,
            $fingerprint,
            $documentNumber,
            $pdfSha256,
            $xmlSha256,
            $deliveryStatus,
        ): void {
            $existing = Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)
                ->where('invoice_id', $invoiceId)
                ->where('role', $role)
                ->where('dunning_level', $dunningLevel)
                ->lockForUpdate()
                ->get();
            foreach ($existing as $candidate) {
                if (
                    trim((string) $candidate->fingerprint) !== $fingerprint
                    || trim((string) $candidate->sevdesk_id) !== $remoteId
                    || trim((string) $candidate->parent_sevdesk_id) !== $parentRemoteId
                    || (int) $candidate->amount_minor !== $amountMinor
                    || (
                        $documentNumber !== null
                        && trim((string) ($candidate->document_number ?? '')) !== $documentNumber
                    )
                    || (
                        $pdfSha256 !== null
                        && strtolower(trim((string) ($candidate->pdf_sha256 ?? ''))) !== $pdfSha256
                    )
                    || (
                        $xmlSha256 !== null
                        && strtolower(trim((string) ($candidate->xml_sha256 ?? ''))) !== $xmlSha256
                    )
                ) {
                    throw new RuntimeException(
                        'A different related sevdesk document already owns this invoice role.',
                    );
                }

                return;
            }

            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)->insert([
                'invoice_id' => $invoiceId,
                'role' => $role,
                'dunning_level' => $dunningLevel,
                'sevdesk_id' => $remoteId,
                'parent_sevdesk_id' => $parentRemoteId,
                'document_number' => $documentNumber,
                'amount_minor' => $amountMinor,
                'fingerprint' => $fingerprint,
                'pdf_sha256' => $pdfSha256,
                'xml_sha256' => $xmlSha256,
                'delivery_status' => $deliveryStatus,
                'document_ready_at' => $pdfSha256 !== null ? $now : null,
                'delivered_at' => $deliveryStatus === 'delivered' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function markDelivered(
        int $invoiceId,
        string $role,
        int $dunningLevel,
        string $remoteId,
    ): void {
        self::assertIdentity($invoiceId, $role, $dunningLevel);
        self::assertRemoteId($remoteId, 'document');
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)
            ->where('invoice_id', $invoiceId)
            ->where('role', $role)
            ->where('dunning_level', $dunningLevel)
            ->where('sevdesk_id', $remoteId)
            ->whereNotNull('document_ready_at')
            ->where('delivery_status', '!=', 'delivered')
            ->update([
                'delivery_status' => 'delivered',
                'delivered_at' => $now,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            $existing = $this->find($invoiceId, $role, $dunningLevel);
            if (
                $existing === null
                || trim((string) $existing->sevdesk_id) !== $remoteId
                || trim((string) $existing->delivery_status) !== 'delivered'
            ) {
                throw new RuntimeException('The related document could not be marked as delivered.');
            }
        }
    }

    public function markDeliveryAmbiguous(
        int $invoiceId,
        string $role,
        int $dunningLevel,
        string $remoteId,
    ): void {
        self::assertIdentity($invoiceId, $role, $dunningLevel);
        self::assertRemoteId($remoteId, 'document');
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $updated = Capsule::table(Migrator::RELATED_DOCUMENTS_TABLE)
            ->where('invoice_id', $invoiceId)
            ->where('role', $role)
            ->where('dunning_level', $dunningLevel)
            ->where('sevdesk_id', $remoteId)
            ->whereNotNull('document_ready_at')
            ->whereIn('delivery_status', ['pending', 'ambiguous'])
            ->update([
                'delivery_status' => 'ambiguous',
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            $existing = $this->find($invoiceId, $role, $dunningLevel);
            if (
                $existing === null
                || trim((string) $existing->sevdesk_id) !== $remoteId
                || trim((string) $existing->delivery_status) !== 'ambiguous'
            ) {
                throw new RuntimeException('The related delivery could not be marked as ambiguous.');
            }
        }
    }

    private static function assertIdentity(int $invoiceId, string $role, int $dunningLevel): void
    {
        if ($invoiceId < 1 || !in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Invalid related-document identity.');
        }
        if ($dunningLevel < 0 || $dunningLevel > 65535) {
            throw new InvalidArgumentException('Invalid dunning level.');
        }
        if ($role !== self::ROLE_REMINDER && $dunningLevel !== 0) {
            throw new InvalidArgumentException('Only reminders may carry a dunning level.');
        }
    }

    private static function assertRemoteId(string $value, string $field): void
    {
        if (preg_match('/^[1-9]\d*$/', trim($value)) !== 1 || strlen($value) > 255) {
            throw new InvalidArgumentException('A valid sevdesk ' . $field . ' ID is required.');
        }
    }

    private static function assertHash(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid ' . $field . '.');
        }

        return $value;
    }

    private static function documentNumber(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 191) {
            throw new InvalidArgumentException('Document number is too long.');
        }

        return $value;
    }
}
