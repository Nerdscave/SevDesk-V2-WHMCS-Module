<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Repository;

use RuntimeException;
use Illuminate\Database\QueryException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

final class MappingRepository
{
    public function findCompleteByInvoice(int $invoiceId): ?object
    {
        return Capsule::table(Migrator::MAPPING_TABLE)
            ->where('invoice_id', $invoiceId)
            ->whereNotNull('sevdesk_id')
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

    public function link(int $invoiceId, string $sevdeskId): void
    {
        if ($invoiceId < 1 || preg_match('/^\d+$/', $sevdeskId) !== 1) {
            throw new \InvalidArgumentException('Mapping IDs must be positive numeric values.');
        }

        Capsule::connection()->transaction(static function () use ($invoiceId, $sevdeskId): void {
            $existing = Capsule::table(Migrator::MAPPING_TABLE)
                ->where('invoice_id', $invoiceId)
                ->lockForUpdate()
                ->first();
            if ($existing === null) {
                try {
                    Capsule::table(Migrator::MAPPING_TABLE)->insert([
                        'invoice_id' => $invoiceId,
                        'sevdesk_id' => $sevdeskId,
                    ]);
                    return;
                } catch (QueryException) {
                    // A concurrent writer may have won the unique insert. Read
                    // and validate its result instead of overwriting it.
                    $existing = Capsule::table(Migrator::MAPPING_TABLE)
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();
                }
            }

            if ($existing === null) {
                throw new RuntimeException('The mapping could not be persisted atomically.');
            }
            $currentRemoteId = trim((string) ($existing->sevdesk_id ?? ''));
            if ($currentRemoteId === $sevdeskId) {
                return;
            }
            if ($currentRemoteId !== '') {
                throw new RuntimeException('A different complete sevdesk mapping already exists for this invoice.');
            }

            $updated = Capsule::table(Migrator::MAPPING_TABLE)
                ->where('id', $existing->id)
                ->whereNull('sevdesk_id')
                ->update(['sevdesk_id' => $sevdeskId]);
            if ($updated !== 1) {
                throw new RuntimeException('The legacy NULL mapping changed during reconciliation.');
            }
        });
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
            ->count();
        $ambiguous = Capsule::table(Migrator::MAPPING_TABLE . ' as m')
            ->join('tblinvoices as i', 'm.invoice_id', '=', 'i.id')
            ->whereNull('m.sevdesk_id')
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
            $query->whereNotNull('m.sevdesk_id')->whereNotNull('i.id');
        } elseif ($status === 'incomplete') {
            $query->whereNull('m.sevdesk_id');
        } elseif ($status === 'orphan') {
            $query->whereNull('i.id');
        }
        $total = (clone $query)->count();
        $items = $query
            ->select([
                'm.id as mapping_id', 'm.id', 'm.invoice_id', 'm.sevdesk_id',
                'i.id as existing_invoice_id', 'i.invoicenum', 'i.date', 'i.status',
            ])
            ->orderByDesc('m.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->all();
        foreach ($items as $item) {
            $item->invoice_exists = $item->existing_invoice_id !== null;
        }

        return [
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'page' => $page,
        ];
    }
}
