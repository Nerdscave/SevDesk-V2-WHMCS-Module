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

    /**
     * Verify that a WHMCS outbound transaction is a real refund of a positive
     * payment for the same invoice, never a chargeback-like transaction.
     */
    public function isVerifiedRefundTransaction(object $transaction): bool
    {
        $refundOf = (int) ($transaction->refundid ?? 0);
        if ((float) ($transaction->amountout ?? 0) <= 0 || $refundOf < 1) {
            return false;
        }

        $description = mb_strtolower(trim((string) ($transaction->description ?? '')));
        foreach (
            [
                'chargeback',
                'rücklastschrift',
                'ruecklastschrift',
                'dispute',
                'kartenrückbelastung',
                'kartenrueckbelastung',
            ] as $marker
        ) {
            if (str_contains($description, $marker)) {
                return false;
            }
        }

        return Capsule::table('tblaccounts')
            ->where('id', $refundOf)
            ->where('invoiceid', (int) ($transaction->invoiceid ?? 0))
            ->where('amountin', '>', 0)
            ->exists();
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
        $customFieldId = $this->configuredContactFieldId();
        $client = $this->client($clientId);
        $sevdeskId = self::contactIdFromClient($client, $customFieldId);

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
        $customFieldId = $this->configuredContactFieldId();
        $clientId = $this->positiveId($clientId, 'client');
        $sevdeskId = trim($sevdeskId);
        if (preg_match('/^[1-9]\d*$/', $sevdeskId) !== 1) {
            throw new RuntimeException('A valid sevdesk contact ID is required.');
        }

        Capsule::connection()->transaction(static function () use ($clientId, $customFieldId, $sevdeskId): void {
            // Locking the client serialises first-time links for this customer,
            // even when WHMCS has not created an empty custom-field row yet.
            $client = Capsule::table('tblclients')
                ->where('id', $clientId)
                ->lockForUpdate()
                ->first(['id']);
            if ($client === null) {
                throw new RuntimeException('The WHMCS client no longer exists.');
            }

            $rows = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $customFieldId)
                ->where('relid', $clientId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'value']);
            if ($rows->count() > 1) {
                throw new RuntimeException('The WHMCS client has duplicate sevdesk contact field rows.');
            }

            $row = $rows->first();
            if ($row !== null) {
                $current = trim((string) ($row->value ?? ''));
                if ($current !== '') {
                    if (hash_equals($current, $sevdeskId)) {
                        return;
                    }

                    throw new RuntimeException('The WHMCS client is already linked to another sevdesk contact.');
                }

                $updated = Capsule::table('tblcustomfieldsvalues')
                    ->where('id', (int) $row->id)
                    ->update(['value' => $sevdeskId]);
                if ($updated !== 1) {
                    throw new RuntimeException('The sevdesk contact link could not be stored safely.');
                }

                return;
            }

            Capsule::table('tblcustomfieldsvalues')->insert([
                'fieldid' => $customFieldId,
                'relid' => $clientId,
                'value' => $sevdeskId,
            ]);

            $stored = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $customFieldId)
                ->where('relid', $clientId)
                ->get(['value']);
            if ($stored->count() !== 1 || !hash_equals($sevdeskId, trim((string) $stored->first()->value))) {
                throw new RuntimeException('The sevdesk contact link could not be stored unambiguously.');
            }
        });
    }

    /** @param array<string,mixed> $client */
    private static function contactIdFromClient(array $client, int $customFieldId): ?string
    {
        $customFields = self::normaliseRows(
            $client['customfields']['customfield'] ?? $client['customfields'] ?? [],
        );
        foreach ($customFields as $field) {
            if ((int) ($field['id'] ?? 0) !== $customFieldId) {
                continue;
            }

            $value = trim((string) ($field['value'] ?? ''));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * Return existing client custom fields that can serve as the explicit
     * E-Invoice opt-in. The module never creates or changes such a field.
     *
     * @return list<array{id:int,label:string}>
     */
    public function eInvoiceOptInFields(): array
    {
        $fields = [];
        foreach (Capsule::table('tblcustomfields')->where('type', 'client')->orderBy('id')->get() as $field) {
            if (!self::isAdminTickboxField($field)) {
                continue;
            }
            $fields[] = [
                'id' => (int) $field->id,
                'label' => trim((string) ($field->fieldname ?? '')) ?: 'Feld #' . (int) $field->id,
            ];
        }

        return $fields;
    }

    public function isEInvoiceOptInField(int $fieldId): bool
    {
        if ($fieldId < 1) {
            return false;
        }
        $field = Capsule::table('tblcustomfields')
            ->where('id', $fieldId)
            ->where('type', 'client')
            ->first();

        return $field !== null && self::isAdminTickboxField($field);
    }

    /** Read the configured admin-only client tickbox without modifying it. */
    public function eInvoiceOptedIn(int $clientId): bool
    {
        $fieldId = $this->config->int('e_invoice_client_field_id');
        if (!$this->isEInvoiceOptInField($fieldId)) {
            throw new RuntimeException(
                'The configured E-Invoice opt-in field is missing or is not an admin-only client tickbox.',
            );
        }

        $client = $this->client($clientId);
        foreach (self::normaliseRows($client['customfields']['customfield'] ?? $client['customfields'] ?? []) as $field) {
            if ((int) ($field['id'] ?? 0) === $fieldId) {
                return self::truthy($field['value'] ?? false);
            }
        }

        return false;
    }

    private function configuredContactFieldId(): int
    {
        $customFieldId = $this->config->int('custom_field_id');
        if (
            $customFieldId < 1
            || !Capsule::table('tblcustomfields')
                ->where('id', $customFieldId)
                ->where('type', 'client')
                ->exists()
        ) {
            throw new RuntimeException(
                'The configured sevdesk contact ID field is missing or is not a WHMCS client custom field.',
            );
        }

        return $customFieldId;
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
            ->whereRaw("TRIM(mapping.sevdesk_id) <> ''")
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
                'mapping.document_type',
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
     * Hand a prepared Invoice email to WHMCS. The caller owns attachment
     * registration in EmailAttachmentContext immediately around this call.
     *
     * @param array<string, scalar> $customVariables
     */
    public function sendInvoiceEmail(int $invoiceId, string $templateName, array $customVariables = []): void
    {
        $templateName = trim($templateName);
        if ($templateName === '') {
            throw new \InvalidArgumentException('An Invoice email template is required.');
        }

        $parameters = [
            'messagename' => $templateName,
            'id' => $this->positiveId($invoiceId, 'invoice'),
        ];
        if ($customVariables !== []) {
            $parameters['customvars'] = base64_encode(serialize($customVariables));
        }

        $this->call('SendEmail', $parameters);
    }

    /** @return list<string> */
    public function activeCustomInvoiceTemplates(): array
    {
        $query = Capsule::table('tblemailtemplates')
            ->whereRaw('LOWER(type) = ?', ['invoice'])
            ->where('custom', 1)
            ->where('disabled', 0)
            ->where('name', '<>', '')
            ->orderBy('name');

        $names = [];
        foreach ($query->pluck('name')->all() as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    public function isActiveCustomInvoiceTemplate(string $templateName): bool
    {
        return in_array(trim($templateName), $this->activeCustomInvoiceTemplates(), true);
    }

    /**
     * WHMCS stores this option in tblconfiguration and also exposes it through
     * the bootstrapped CONFIG array. Checking both keeps CLI/Cron and web
     * execution consistent.
     */
    public function proformaInvoicingEnabled(): bool
    {
        $globalValue = $GLOBALS['CONFIG']['EnableProformaInvoicing'] ?? null;
        if ($globalValue !== null) {
            return self::truthy($globalValue);
        }

        $value = Capsule::table('tblconfiguration')
            ->where('setting', 'EnableProformaInvoicing')
            ->value('value');

        return self::truthy($value);
    }

    public function invoiceOwnerId(int $invoiceId): int
    {
        return (int) Capsule::table('tblinvoices')
            ->where('id', $this->positiveId($invoiceId, 'invoice'))
            ->value('userid');
    }

    /**
     * Verify the adapter manifest from the active WHMCS client theme, not the
     * bundled reference copy. The operator confirmation remains a separate
     * setup gate because a manifest cannot prove that the partial was placed at
     * the correct include point in viewinvoice.tpl.
     */
    public function themeAdapterManifestInstalled(): bool
    {
        if (!defined('ROOTDIR')) {
            return false;
        }

        $theme = trim((string) ($GLOBALS['CONFIG']['Template'] ?? ''));
        if ($theme === '') {
            $theme = trim((string) Capsule::table('tblconfiguration')
                ->where('setting', 'Template')
                ->value('value'));
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $theme) !== 1) {
            return false;
        }

        $themeDirectory = rtrim((string) ROOTDIR, '/\\') . '/templates/' . $theme;
        $manifestPath = $themeDirectory . '/sevdesk-invoice-authority.json';
        if (!is_file($manifestPath) || filesize($manifestPath) === false || filesize($manifestPath) > 32_768) {
            return false;
        }
        try {
            $contents = file_get_contents($manifestPath);
            $manifest = is_string($contents)
                ? json_decode($contents, true, 32, JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($manifest) || !self::validThemeAdapterManifest($manifest, $theme)) {
            return false;
        }

        $partial = (string) $manifest['partial'];

        return basename($partial) === $partial && is_file($themeDirectory . '/' . $partial);
    }

    /** @param array<string,mixed> $manifest */
    public static function validThemeAdapterManifest(array $manifest, string $activeTheme): bool
    {
        $contract = $manifest['contract'] ?? null;
        if (
            !is_array($contract)
            || count($contract) !== 4
            || count(array_filter($contract, 'is_string')) !== 4
        ) {
            return false;
        }
        $fields = array_values($contract);
        sort($fields);
        $required = ['authority', 'downloadUrl', 'invoiceNumber', 'state'];

        return ($manifest['module'] ?? null) === 'sevdesk'
            && ($manifest['contractVersion'] ?? null) === 1
            && in_array((string) ($manifest['theme'] ?? ''), [$activeTheme, '*'], true)
            && $fields === $required
            && is_string($manifest['partial'] ?? null)
            && preg_match('/^[A-Za-z0-9._-]{1,120}\.tpl$/', (string) $manifest['partial']) === 1;
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

    private static function isAdminTickboxField(object $field): bool
    {
        return strtolower(trim((string) ($field->fieldtype ?? ''))) === 'tickbox'
            && self::truthy($field->adminonly ?? false);
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
