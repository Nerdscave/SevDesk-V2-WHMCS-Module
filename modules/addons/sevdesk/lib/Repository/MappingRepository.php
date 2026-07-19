<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Repository;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

final class MappingRepository
{
    public const DOCUMENT_TYPE_VOUCHER = 'voucher';
    public const DOCUMENT_TYPE_INVOICE = 'invoice';

    /** @var list<string> */
    private const DOCUMENT_TYPES = [self::DOCUMENT_TYPE_VOUCHER, self::DOCUMENT_TYPE_INVOICE];

    public function findCompleteByInvoice(int $invoiceId): ?object
    {
        return Capsule::table(Migrator::MAPPING_TABLE)
            ->where('invoice_id', $invoiceId)
            ->whereNotNull('sevdesk_id')
            ->whereRaw("TRIM(sevdesk_id) <> ''")
            ->first();
    }

    public function findByInvoice(int $invoiceId): ?object
    {
        return Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', $invoiceId)->first();
    }

    public function findBySevdeskId(string $sevdeskId): ?object
    {
        return Capsule::table(Migrator::MAPPING_TABLE)->where('sevdesk_id', $sevdeskId)->first();
    }

    public function findCompleteByInvoiceAndType(int $invoiceId, string $documentType): ?object
    {
        $documentType = self::validateDocumentType($documentType);

        return Capsule::table(Migrator::MAPPING_TABLE)
            ->where('invoice_id', $invoiceId)
            ->whereNotNull('sevdesk_id')
            ->whereRaw("TRIM(sevdesk_id) <> ''")
            ->where('document_type', $documentType)
            ->first();
    }

    public function link(int $invoiceId, string $sevdeskId): void
    {
        self::validateMappingIds($invoiceId, $sevdeskId);
        $this->persistLink($invoiceId, $sevdeskId, null, null);
    }

    public function linkDocument(
        int $invoiceId,
        string $sevdeskId,
        string $documentType,
        ?string $documentNumber = null,
    ): void {
        self::validateMappingIds($invoiceId, $sevdeskId);
        $documentType = self::validateDocumentType($documentType);
        $documentNumber = self::validateDocumentNumber($documentNumber);

        $this->persistLink($invoiceId, $sevdeskId, $documentType, $documentNumber);
    }

    public function enrichDocumentMetadata(
        int $invoiceId,
        string $sevdeskId,
        string $documentType,
        ?string $documentNumber = null,
        ?DateTimeInterface $documentReadyAt = null,
        ?DateTimeInterface $deliveredAt = null,
        ?string $pdfSha256 = null,
    ): void {
        self::validateMappingIds($invoiceId, $sevdeskId);
        $documentType = self::validateDocumentType($documentType);
        $documentNumber = self::validateDocumentNumber($documentNumber);
        $pdfSha256 = self::validatePdfSha256($pdfSha256);
        $readyAt = $documentReadyAt?->format('Y-m-d H:i:s');
        $deliveryAt = $deliveredAt?->format('Y-m-d H:i:s');

        Capsule::connection()->transaction(static function () use (
            $invoiceId,
            $sevdeskId,
            $documentType,
            $documentNumber,
            $readyAt,
            $deliveryAt,
            $pdfSha256,
        ): void {
            $existing = Capsule::table(Migrator::MAPPING_TABLE)
                ->where('invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                throw new RuntimeException('A complete mapping is required before document metadata can be stored.');
            }
            if (trim((string) ($existing->sevdesk_id ?? '')) !== $sevdeskId) {
                throw new RuntimeException('The complete sevdesk mapping changed before metadata enrichment.');
            }

            $updates = self::compatibleDocumentUpdates($existing, $documentType, $documentNumber);
            if ($readyAt !== null && $existing->document_ready_at === null) {
                $updates['document_ready_at'] = $readyAt;
            }
            if ($deliveryAt !== null && $existing->delivered_at === null) {
                if ($readyAt === null && $existing->document_ready_at === null) {
                    throw new RuntimeException('A document must be ready before it can be marked as delivered.');
                }
                $updates['delivered_at'] = $deliveryAt;
            }
            $currentHash = strtolower(trim((string) ($existing->pdf_sha256 ?? '')));
            if ($pdfSha256 !== null) {
                if ($currentHash !== '' && $currentHash !== $pdfSha256) {
                    throw new RuntimeException('A different PDF checksum already exists for this mapping.');
                }
                if ($currentHash === '') {
                    $updates['pdf_sha256'] = $pdfSha256;
                }
            }

            if ($updates !== []) {
                $updated = Capsule::table(Migrator::MAPPING_TABLE)->where('id', $existing->id)->update($updates);
                if ($updated !== 1) {
                    throw new RuntimeException('The document metadata changed during atomic persistence.');
                }
            }
        });
    }

    private function persistLink(
        int $invoiceId,
        string $sevdeskId,
        ?string $documentType,
        ?string $documentNumber,
    ): void {
        Capsule::connection()->transaction(static function () use (
            $invoiceId,
            $sevdeskId,
            $documentType,
            $documentNumber,
        ): void {
            $existing = Capsule::table(Migrator::MAPPING_TABLE)
                ->where('invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                try {
                    $insert = [
                        'invoice_id' => $invoiceId,
                        'sevdesk_id' => $sevdeskId,
                    ];
                    if ($documentType !== null) {
                        $insert['document_type'] = $documentType;
                        $insert['document_number'] = $documentNumber;
                    }
                    Capsule::table(Migrator::MAPPING_TABLE)->insert($insert);
                    return;
                } catch (QueryException $error) {
                    // A concurrent writer may have won a unique insert. Read
                    // and validate the committed result instead of overwriting it.
                    $existing = Capsule::table(Migrator::MAPPING_TABLE)
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing === null) {
                        throw new RuntimeException(
                            'The remote sevdesk document is already linked to a different invoice.',
                            previous: $error,
                        );
                    }
                }
            }

            $currentRemoteId = trim((string) ($existing->sevdesk_id ?? ''));
            if ($currentRemoteId !== '' && $currentRemoteId !== $sevdeskId) {
                throw new RuntimeException('A different complete sevdesk mapping already exists for this invoice.');
            }

            $updates = $documentType === null
                ? []
                : self::compatibleDocumentUpdates($existing, $documentType, $documentNumber);
            if ($currentRemoteId === '') {
                $updates['sevdesk_id'] = $sevdeskId;
            }
            if ($updates === []) {
                return;
            }

            try {
                $updated = Capsule::table(Migrator::MAPPING_TABLE)
                    ->where('id', $existing->id)
                    ->update($updates);
            } catch (QueryException $error) {
                throw new RuntimeException(
                    'The remote sevdesk document is already linked to a different invoice.',
                    previous: $error,
                );
            }
            if ($updated !== 1) {
                throw new RuntimeException('The mapping changed during atomic persistence.');
            }
        });
    }

    /** @return array<string,string> */
    private static function compatibleDocumentUpdates(
        object $existing,
        string $documentType,
        ?string $documentNumber,
    ): array {
        $updates = [];
        $currentType = trim((string) ($existing->document_type ?? ''));
        if ($currentType !== '' && $currentType !== $documentType) {
            throw new RuntimeException('A different document type already exists for this mapping.');
        }
        if ($currentType === '') {
            $updates['document_type'] = $documentType;
        }

        $currentNumber = trim((string) ($existing->document_number ?? ''));
        if ($documentNumber !== null && $currentNumber !== '' && $currentNumber !== $documentNumber) {
            throw new RuntimeException('A different document number already exists for this mapping.');
        }
        if ($documentNumber !== null && $currentNumber === '') {
            $updates['document_number'] = $documentNumber;
        }

        return $updates;
    }

    private static function validateMappingIds(int $invoiceId, string $sevdeskId): void
    {
        if ($invoiceId < 1 || preg_match('/^[1-9]\d*$/', $sevdeskId) !== 1 || strlen($sevdeskId) > 255) {
            throw new InvalidArgumentException('Mapping IDs must be positive numeric values.');
        }
    }

    private static function validateDocumentType(string $documentType): string
    {
        if (!in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException('Document type must be voucher or invoice.');
        }

        return $documentType;
    }

    private static function validateDocumentNumber(?string $documentNumber): ?string
    {
        if ($documentNumber === null) {
            return null;
        }

        $documentNumber = trim($documentNumber);
        if ($documentNumber === '') {
            return null;
        }
        if (mb_strlen($documentNumber) > 191) {
            throw new InvalidArgumentException('Document number must not exceed 191 characters.');
        }

        return $documentNumber;
    }

    private static function validatePdfSha256(?string $pdfSha256): ?string
    {
        if ($pdfSha256 === null || trim($pdfSha256) === '') {
            return null;
        }

        $pdfSha256 = strtolower(trim($pdfSha256));
        if (preg_match('/^[a-f0-9]{64}$/', $pdfSha256) !== 1) {
            throw new InvalidArgumentException('PDF checksum must be a SHA-256 hex value.');
        }

        return $pdfSha256;
    }

    public function unlink(int $invoiceId): bool
    {
        return Capsule::table(Migrator::MAPPING_TABLE)->where('invoice_id', $invoiceId)->delete() > 0;
    }

    public function unlinkById(int $mappingId): bool
    {
        return Capsule::table(Migrator::MAPPING_TABLE)->where('id', $mappingId)->delete() > 0;
    }

    /** @return array{complete:int,ambiguous:int,orphans:int} */
    public function counts(): array
    {
        $complete = Capsule::table(Migrator::MAPPING_TABLE . ' as m')
            ->join('tblinvoices as i', 'm.invoice_id', '=', 'i.id')
            ->whereNotNull('m.sevdesk_id')
            ->whereRaw("TRIM(m.sevdesk_id) <> ''")
            ->count();
        $ambiguous = Capsule::table(Migrator::MAPPING_TABLE . ' as m')
            ->join('tblinvoices as i', 'm.invoice_id', '=', 'i.id')
            ->where(static function ($query): void {
                $query->whereNull('m.sevdesk_id')->orWhereRaw("TRIM(m.sevdesk_id) = ''");
            })
            ->count();
        $orphans = Capsule::table(Migrator::MAPPING_TABLE . ' as m')
            ->leftJoin('tblinvoices as i', 'm.invoice_id', '=', 'i.id')
            ->whereNull('i.id')
            ->count();

        return ['complete' => $complete, 'ambiguous' => $ambiguous, 'orphans' => $orphans];
    }

    /** @return array{items:array<int,object>,total:int,pages:int,page:int} */
    public function paginate(int $page, int $perPage, string $queryText = '', string $status = ''): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(250, $perPage));
        $query = Capsule::table(Migrator::MAPPING_TABLE . ' as m')
            ->leftJoin('tblinvoices as i', 'm.invoice_id', '=', 'i.id');
        $queryText = trim($queryText);
        if ($queryText !== '') {
            $query->where(static function ($query) use ($queryText): void {
                $query->where('i.invoicenum', 'like', '%' . $queryText . '%')
                    ->orWhere('m.invoice_id', $queryText)
                    ->orWhere('m.sevdesk_id', $queryText);
            });
        }
        if ($status === 'mapped') {
            $query->whereNotNull('m.sevdesk_id')
                ->whereRaw("TRIM(m.sevdesk_id) <> ''")
                ->whereNotNull('i.id');
        } elseif ($status === 'incomplete') {
            $query->where(static function ($query): void {
                $query->whereNull('m.sevdesk_id')->orWhereRaw("TRIM(m.sevdesk_id) = ''");
            });
        } elseif ($status === 'untyped') {
            $query->whereNotNull('m.sevdesk_id')
                ->whereRaw("TRIM(m.sevdesk_id) <> ''")
                ->whereNull('m.document_type')
                ->whereNotNull('i.id');
        } elseif ($status === 'orphan') {
            $query->whereNull('i.id');
        }
        $total = (clone $query)->count();
        $items = $query
            ->select([
                'm.id as mapping_id', 'm.id', 'm.invoice_id', 'm.sevdesk_id',
                'm.document_type', 'm.document_number', 'm.document_ready_at',
                'm.delivered_at', 'm.pdf_sha256',
                'i.id as existing_invoice_id', 'i.invoicenum', 'i.date', 'i.status',
            ])
            ->orderByDesc('m.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->all();
        foreach ($items as $item) {
            $item->invoice_exists = $item->existing_invoice_id !== null;
            if (trim((string) ($item->sevdesk_id ?? '')) === '') {
                $item->sevdesk_id = null;
            }
        }
        $this->decorateDocumentContext($items);

        return [
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'page' => $page,
        ];
    }

    /** @param array<int, object> $mappings */
    private function decorateDocumentContext(array $mappings): void
    {
        $invoiceIds = array_values(array_unique(array_filter(array_map(
            static fn (object $mapping): int => (int) ($mapping->invoice_id ?? 0),
            $mappings,
        ), static fn (int $invoiceId): bool => $invoiceId > 0)));
        $contexts = (new JobRepository())->documentContextsForInvoices($invoiceIds, true);

        foreach ($mappings as $mapping) {
            $context = $contexts[(int) ($mapping->invoice_id ?? 0)] ?? null;
            $type = trim((string) ($mapping->document_type ?? ''));
            $mapping->document_authority = trim((string) ($context['documentAuthority']
                ?? ($type === self::DOCUMENT_TYPE_VOUCHER ? 'whmcs' : '')));
            $mapping->tax_rule = trim((string) ($context['taxRuleId'] ?? ''));
            $mapping->delivery_state = match (true) {
                trim((string) ($mapping->delivered_at ?? '')) !== '' => 'delivered',
                trim((string) ($mapping->document_ready_at ?? '')) !== '' => 'ready',
                trim((string) ($context['deliveryState'] ?? '')) !== '' => (string) $context['deliveryState'],
                $type === self::DOCUMENT_TYPE_INVOICE => 'not_recorded',
                $type === self::DOCUMENT_TYPE_VOUCHER => 'not_requested',
                default => 'unknown',
            };
        }
    }
}
