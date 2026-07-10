<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;

/**
 * Narrow adapter around WHMCS' internal API and invoice tables.
 *
 * Keeping WHMCS array shapes in this class prevents them from leaking into the
 * tax, contact and sevdesk services. The optional callback makes the adapter
 * usable in contract tests without booting a complete WHMCS installation.
 */
final class WhmcsGateway
{
    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private readonly Closure $localApi;

    /** @param null|callable(string, array<string, mixed>): array<string, mixed> $localApi */
    public function __construct(private readonly Config $config, ?callable $localApi = null)
    {
        $this->localApi = $localApi === null
            ? static function (string $command, array $parameters): array {
                if (!function_exists('localAPI')) {
                    throw new RuntimeException('The WHMCS local API is unavailable.');
                }

                $response = localAPI($command, $parameters);

                return is_array($response) ? $response : [];
            }
            : Closure::fromCallable($localApi);
    }

    /** @return array<string, mixed> */
    public function invoice(int $invoiceId): array
    {
        return $this->call('GetInvoice', ['invoiceid' => $this->positiveId($invoiceId, 'invoice')]);
    }

    /** @return array<string, mixed> */
    public function client(int $clientId): array
    {
        $response = $this->call('GetClientsDetails', [
            'clientid' => $this->positiveId($clientId, 'client'),
            'stats' => false,
        ]);
        $client = $response['client'] ?? $response;

        return is_array($client) ? $client : [];
    }

    public function invoiceSnapshot(int $invoiceId): InvoiceSnapshot
    {
        $invoice = $this->invoice($invoiceId);
        $clientId = (int) ($invoice['userid'] ?? 0);
        $client = $this->client($clientId);
        $taxType = strtolower((string) ($GLOBALS['CONFIG']['TaxType'] ?? 'Exclusive'));
        $net = $taxType !== 'inclusive';
        $primaryTaxRate = (float) ($invoice['taxrate'] ?? 0);
        $secondaryTaxRate = (float) ($invoice['taxrate2'] ?? 0);
        if ($primaryTaxRate > 0 && $secondaryTaxRate > 0) {
            throw new RuntimeException('Invoices with two simultaneous WHMCS taxes require manual position review.');
        }
        $taxRate = $primaryTaxRate + $secondaryTaxRate;
        $rawItems = $invoice['items']['item'] ?? [];
        if (isset($rawItems['id']) || isset($rawItems['description'])) {
            $rawItems = [$rawItems];
        }

        $lineItems = [];
        foreach (is_array($rawItems) ? $rawItems : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lineItems[] = new LineItem(
                (string) ($item['description'] ?? 'WHMCS invoice item'),
                (string) ($item['amount'] ?? '0'),
                self::truthy($item['taxed'] ?? false) ? self::decimal($taxRate) : '0',
                $net,
            );
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($invoice['date'] ?? ''));
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('The WHMCS invoice date is invalid.');
        }

        $invoiceNumber = trim((string) ($invoice['invoicenum'] ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = (string) $invoiceId;
        }

        return new InvoiceSnapshot(
            $invoiceId,
            $clientId,
            $invoiceNumber,
            $date,
            (string) ($invoice['currencycode'] ?? $client['currency_code'] ?? ''),
            (string) ($invoice['total'] ?? '0'),
            (string) ($invoice['credit'] ?? '0'),
            $lineItems,
        );
    }

    public function contactData(int $clientId): ContactData
    {
        $client = $this->client($clientId);
        $customFieldId = $this->config->int('custom_field_id');
        $sevdeskId = null;
        foreach (self::normaliseRows($client['customfields']['customfield'] ?? $client['customfields'] ?? []) as $field) {
            if ((int) ($field['id'] ?? 0) === $customFieldId) {
                $sevdeskId = trim((string) ($field['value'] ?? '')) ?: null;
                break;
            }
        }

        $vatNumber = trim((string) (
            $client['tax_id']
            ?? $client['tax_id_number']
            ?? $client['taxid']
            ?? ''
        ));

        return new ContactData(
            $clientId,
            $sevdeskId,
            (string) ($client['companyname'] ?? ''),
            (string) ($client['firstname'] ?? ''),
            (string) ($client['lastname'] ?? ''),
            (string) ($client['email'] ?? ''),
            (string) ($client['address1'] ?? ''),
            (string) ($client['address2'] ?? ''),
            (string) ($client['postcode'] ?? ''),
            (string) ($client['city'] ?? ''),
            (string) ($client['countrycode'] ?? $client['country'] ?? ''),
            $vatNumber !== '' ? $vatNumber : null,
            self::truthy($client['taxexempt'] ?? false),
        );
    }

    public function storeContactId(int $clientId, string $sevdeskId): void
    {
        $customFieldId = $this->config->int('custom_field_id');
        if ($customFieldId < 1) {
            throw new RuntimeException('No WHMCS contact custom field is configured.');
        }

        $this->call('UpdateClient', [
            'clientid' => $this->positiveId($clientId, 'client'),
            'customfields' => base64_encode(serialize([$customFieldId => trim($sevdeskId)])),
            'skipvalidation' => true,
        ]);
    }

    /**
     * @return list<object>
     */
    public function invoicesBetween(
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        bool $onlyPaid,
        int $limit = 5000,
    ): array {
        $statuses = $onlyPaid ? ['Paid'] : ['Paid', 'Unpaid'];

        return Capsule::table('tblinvoices as invoice')
            ->leftJoin('tblclients as client', 'invoice.userid', '=', 'client.id')
            ->leftJoin('tblcurrencies as currency', 'client.currency', '=', 'currency.id')
            ->whereIn('invoice.status', $statuses)
            ->whereBetween('invoice.date', [$from->format('Y-m-d'), $until->format('Y-m-d')])
            ->orderBy('invoice.id')
            ->limit(max(1, min(20_000, $limit)))
            ->get([
                'invoice.id', 'invoice.userid', 'invoice.invoicenum', 'invoice.date',
                'invoice.datepaid', 'invoice.subtotal', 'invoice.credit', 'invoice.tax',
                'invoice.tax2', 'invoice.taxrate', 'invoice.taxrate2', 'invoice.total',
                'invoice.status', 'client.currency as currency', 'currency.code as currencycode',
                'client.firstname', 'client.lastname', 'client.companyname', 'client.country',
                'client.taxexempt', 'client.tax_id',
            ])
            ->all();
    }

    public function invoiceForDryRun(int $invoiceId): ?object
    {
        return Capsule::table('tblinvoices as invoice')
            ->leftJoin('tblclients as client', 'invoice.userid', '=', 'client.id')
            ->leftJoin('tblcurrencies as currency', 'client.currency', '=', 'currency.id')
            ->where('invoice.id', $this->positiveId($invoiceId, 'invoice'))
            ->whereIn('invoice.status', ['Paid', 'Unpaid'])
            ->first([
                'invoice.id', 'invoice.userid', 'invoice.invoicenum', 'invoice.date',
                'invoice.datepaid', 'invoice.subtotal', 'invoice.credit', 'invoice.tax',
                'invoice.tax2', 'invoice.taxrate', 'invoice.taxrate2', 'invoice.total',
                'invoice.status', 'client.currency as currency', 'currency.code as currencycode',
                'client.firstname', 'client.lastname', 'client.companyname', 'client.country',
                'client.taxexempt', 'client.tax_id',
            ]);
    }

    /** @return array{items:list<object>,total:int,page:int,pages:int} */
    public function bookingPaymentsBetween(
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        int $page = 1,
        int $perPage = 10,
    ): array {
        $perPage = max(1, min(50, $perPage));
        $query = Capsule::table('tblaccounts as payment')
            ->join(Migrator::MAPPING_TABLE . ' as mapping', 'payment.invoiceid', '=', 'mapping.invoice_id')
            ->join('tblinvoices as invoice', 'payment.invoiceid', '=', 'invoice.id')
            ->leftJoin('tblclients as client', 'invoice.userid', '=', 'client.id')
            ->leftJoin('tblcurrencies as invoice_currency', 'client.currency', '=', 'invoice_currency.id')
            ->leftJoin('tblcurrencies as transaction_currency', 'payment.currency', '=', 'transaction_currency.id')
            ->whereNotNull('mapping.sevdesk_id')
            ->where('mapping.sevdesk_id', '<>', '')
            ->whereIn('invoice.status', ['Paid', 'Unpaid'])
            ->whereBetween('payment.date', [
                $from->format('Y-m-d 00:00:00'),
                $until->format('Y-m-d 23:59:59'),
            ])
            ->where('payment.amountin', '>', 0)
            ->where('payment.amountout', '<=', 0)
            ->where('payment.refundid', 0)
            ->whereNotNull('payment.transid')
            ->where('payment.transid', '<>', '');

        $total = (int) (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $items = $query->orderBy('payment.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'payment.id as whmcs_account_id',
                'payment.invoiceid as invoice_id',
                'payment.date as transaction_date',
                'payment.amountin',
                'payment.amountout',
                'payment.transid as transaction_id',
                'payment.refundid',
                'payment.currency as transaction_currency_id',
                'payment.gateway',
                'mapping.sevdesk_id',
                'invoice.invoicenum',
                'invoice.userid',
                'invoice.status as invoice_status',
                'invoice_currency.code as invoice_currency',
                'transaction_currency.code as transaction_currency',
            ])
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function transactionsForInvoice(int $invoiceId): array
    {
        $invoice = $this->invoice($invoiceId);

        return self::normaliseRows($invoice['transactions']['transaction'] ?? []);
    }

    public function invoiceStatus(int $invoiceId): string
    {
        return (string) ($this->invoice($invoiceId)['status'] ?? '');
    }

    /**
     * AddFunds invoices use a separately confirmed tax profile. Mixing AddFunds
     * and normal invoice lines would require two tax profiles in one voucher and
     * is therefore rejected before any remote call.
     */
    public function isAddFundsInvoice(int $invoiceId): bool
    {
        $invoice = $this->invoice($invoiceId);
        $types = [];
        foreach (self::normaliseRows($invoice['items']['item'] ?? []) as $item) {
            $types[] = strtolower(trim((string) ($item['type'] ?? '')));
        }
        $types = array_values(array_unique(array_filter($types, static fn (string $type): bool => $type !== '')));
        $hasAddFunds = in_array('addfunds', $types, true);
        if ($hasAddFunds && count($types) > 1) {
            throw new RuntimeException('AddFunds and normal invoice lines cannot share one sevdesk voucher.');
        }

        return $hasAddFunds;
    }

    /**
     * @param array<string,mixed> $parameters
     * @return array<string, mixed>
     */
    private function call(string $command, array $parameters): array
    {
        $response = ($this->localApi)($command, $parameters);
        $result = strtolower((string) ($response['result'] ?? ''));
        if ($result !== 'success') {
            // Local API error messages may include client data; keep the stored
            // message generic and put only the command in the activity log.
            if (function_exists('logActivity')) {
                logActivity('sevdesk: WHMCS local API command failed: ' . $command);
            }
            throw new RuntimeException('WHMCS could not provide the required accounting data.');
        }

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private static function normaliseRows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        if (isset($rows['id']) || isset($rows['transid']) || isset($rows['description'])) {
            return [$rows];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function positiveId(int $id, string $name): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid WHMCS ' . $name . ' ID.');
        }

        return $id;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1
            || (is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true));
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
