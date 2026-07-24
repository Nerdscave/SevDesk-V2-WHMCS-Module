<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Domain\ContactData;
use WHMCS\Module\Addon\SevDesk\Domain\Decimal;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceItemNormalizationException;
use WHMCS\Module\Addon\SevDesk\Domain\InvoiceSnapshot;
use WHMCS\Module\Addon\SevDesk\Domain\LineItem;
use WHMCS\Module\Addon\SevDesk\Domain\WhmcsInvoiceItem;

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

    /** @var Closure(): string */
    private readonly Closure $whmcsVersion;

    /**
     * @param null|callable(string, array<string, mixed>): array<string, mixed> $localApi
     * @param null|callable(): string $whmcsVersion
     */
    public function __construct(
        private readonly Config $config,
        ?callable $localApi = null,
        ?callable $whmcsVersion = null,
    ) {
        $this->localApi = $localApi === null
            ? static function (string $command, array $parameters): array {
                if (!function_exists('localAPI')) {
                    throw new RuntimeException('The WHMCS local API is unavailable.');
                }

                $response = localAPI($command, $parameters);

                return is_array($response) ? $response : [];
            }
            : Closure::fromCallable($localApi);
        $this->whmcsVersion = Closure::fromCallable(
            $whmcsVersion ?? fn (): string => $this->detectWhmcsVersion(),
        );
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

    /**
     * WHMCS uses the immutable invoice row ID as its invoice reference when no
     * separate invoicenum was assigned. This normalisation is read-only; it
     * must never backfill or otherwise change tblinvoices.
     */
    public static function effectiveInvoiceNumber(int $invoiceId, ?string $invoiceNumber): string
    {
        if ($invoiceId < 1) {
            throw new \InvalidArgumentException('Invalid WHMCS invoice ID.');
        }

        $invoiceNumber = trim((string) $invoiceNumber);

        return $invoiceNumber !== '' ? $invoiceNumber : (string) $invoiceId;
    }

    /**
     * WHMCS 8.13 stores the direct cash amount in `total`. Applied credit must
     * be added for the revenue document, and both header views must agree
     * exactly before a snapshot is allowed to reach an exporter.
     */
    public static function documentGrossTotal(
        string $invoiceSubtotal,
        string $invoiceTax,
        string $invoiceTax2,
        string $invoiceTotal,
        string $invoiceCredit,
    ): string {
        $subtotalMinor = Decimal::toMinorUnits($invoiceSubtotal);
        $taxMinor = Decimal::toMinorUnits($invoiceTax);
        $tax2Minor = Decimal::toMinorUnits($invoiceTax2);
        $directCashMinor = Decimal::toMinorUnits($invoiceTotal);
        $creditMinor = Decimal::toMinorUnits($invoiceCredit);
        if (
            $subtotalMinor < 0
            || $taxMinor < 0
            || $tax2Minor < 0
            || $directCashMinor < 0
            || $creditMinor < 0
        ) {
            throw new \InvalidArgumentException('WHMCS invoice header amounts cannot be negative.');
        }

        $headerGrossMinor = self::addMinorUnits(
            self::addMinorUnits($subtotalMinor, $taxMinor),
            $tax2Minor,
        );
        $paymentGrossMinor = self::addMinorUnits($directCashMinor, $creditMinor);
        if ($headerGrossMinor !== $paymentGrossMinor) {
            throw new \InvalidArgumentException(
                'WHMCS invoice totals must satisfy subtotal + tax + tax2 = total + credit.',
            );
        }

        return Decimal::fromMinorUnits($paymentGrossMinor);
    }

    /**
     * Capture the complete local contract that controls the accounting
     * payload. Only its SHA-256 fingerprint may be persisted in a job; the
     * canonical data below deliberately remains process-local.
     *
     * @return array{
     *     snapshot:InvoiceSnapshot,
     *     fingerprint:string,
     *     status:string,
     *     itemTypes:list<string>,
     *     creditMinor:int,
     *     configuredContactId:?string
     * }
     */
    public function invoiceExportContract(int $invoiceId): array
    {
        $invoice = $this->invoice($invoiceId);
        $clientId = (int) ($invoice['userid'] ?? 0);
        $client = $this->client($clientId);
        $snapshot = $this->invoiceSnapshotFromRows($invoiceId, $invoice, $client);
        $itemTypes = [];
        foreach (self::normaliseRows($invoice['items']['item'] ?? []) as $item) {
            $itemTypes[] = strtolower(trim((string) ($item['type'] ?? '')));
        }
        $itemTypes = array_values(array_unique(array_filter(
            $itemTypes,
            static fn (string $type): bool => $type !== '',
        )));
        sort($itemTypes);

        return [
            'snapshot' => $snapshot,
            'fingerprint' => self::invoiceContractFingerprint(
                $invoiceId,
                $invoice,
                $client,
                $snapshot,
                $this->config,
            ),
            'status' => trim((string) ($invoice['status'] ?? '')),
            'itemTypes' => $itemTypes,
            'creditMinor' => Decimal::toMinorUnits((string) ($invoice['credit'] ?? '0')),
            'configuredContactId' => self::configuredContactIdFromClient($client, $this->config),
        ];
    }

    public function invoiceSnapshot(int $invoiceId): InvoiceSnapshot
    {
        return $this->invoiceExportContract($invoiceId)['snapshot'];
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $client
     */
    private function invoiceSnapshotFromRows(
        int $invoiceId,
        array $invoice,
        array $client,
    ): InvoiceSnapshot {
        $clientId = (int) ($invoice['userid'] ?? 0);
        $taxType = strtolower((string) ($GLOBALS['CONFIG']['TaxType'] ?? 'Exclusive'));
        if (!in_array($taxType, ['exclusive', 'inclusive'], true)) {
            throw new RuntimeException('The configured WHMCS TaxType is unsupported.');
        }
        $net = $taxType === 'exclusive';
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

        $sourceItems = [];
        $hasPromoOrNegativeItem = false;
        foreach (is_array($rawItems) ? $rawItems : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $amount = (string) ($item['amount'] ?? '0');
            $type = trim((string) ($item['type'] ?? ''));
            if (strcasecmp($type, 'PromoHosting') === 0 || Decimal::toMinorUnits($amount) < 0) {
                $hasPromoOrNegativeItem = true;
            }
            $sourceItems[] = $item;
        }

        $lineItems = [];
        $discounts = [];
        if ($hasPromoOrNegativeItem) {
            $structuredItems = [];
            foreach ($sourceItems as $item) {
                $structuredItems[] = WhmcsInvoiceItem::fromWhmcs(
                    $item,
                    self::decimal($taxRate),
                    $net ? 'Exclusive' : 'Inclusive',
                );
            }
            $normalization = (new InvoiceItemNormalizer())->normalize($structuredItems);
            if (!$normalization->allowed) {
                throw new InvoiceItemNormalizationException(
                    $normalization->code,
                    $normalization->message,
                );
            }
            $lineItems = $normalization->lines;
            $discounts = $normalization->discounts;
        } else {
            foreach ($sourceItems as $item) {
                $lineItems[] = new LineItem(
                    (string) ($item['description'] ?? 'WHMCS invoice item'),
                    (string) ($item['amount'] ?? '0'),
                    self::truthy($item['taxed'] ?? false) ? self::decimal($taxRate) : '0',
                    $net,
                );
            }
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($invoice['date'] ?? ''));
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('The WHMCS invoice date is invalid.');
        }

        return new InvoiceSnapshot(
            $invoiceId,
            $clientId,
            self::effectiveInvoiceNumber($invoiceId, (string) ($invoice['invoicenum'] ?? '')),
            $date,
            (string) ($invoice['currencycode'] ?? $client['currency_code'] ?? ''),
            self::documentGrossTotal(
                (string) ($invoice['subtotal'] ?? '0'),
                (string) ($invoice['tax'] ?? '0'),
                (string) ($invoice['tax2'] ?? '0'),
                (string) ($invoice['total'] ?? '0'),
                (string) ($invoice['credit'] ?? '0'),
            ),
            (string) ($invoice['credit'] ?? '0'),
            $lineItems,
            $discounts,
        );
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $client
     */
    private static function invoiceContractFingerprint(
        int $invoiceId,
        array $invoice,
        array $client,
        InvoiceSnapshot $snapshot,
        Config $config,
    ): string {
        $items = [];
        foreach (self::normaliseRows($invoice['items']['item'] ?? []) as $item) {
            $items[] = [
                'id' => self::canonicalIdentifier($item['id'] ?? null),
                'type' => strtolower(trim((string) ($item['type'] ?? ''))),
                'relid' => self::canonicalIdentifier($item['relid'] ?? null),
                'amount' => self::canonicalDecimal($item['amount'] ?? '0', 'Invoice item amount'),
                'taxed' => self::truthy($item['taxed'] ?? false),
                'descriptionHash' => self::payloadDescriptionHash(
                    $item['description'] ?? 'WHMCS invoice item',
                ),
            ];
        }

        $vatNumber = trim((string) (
            $client['tax_id']
            ?? $client['tax_id_number']
            ?? $client['taxid']
            ?? ''
        ));
        $customFields = self::normaliseRows(
            $client['customfields']['customfield'] ?? $client['customfields'] ?? [],
        );
        try {
            $eInvoiceFieldId = $config->int('e_invoice_client_field_id');
        } catch (Throwable) {
            // Standalone contract tests do not boot WHMCS' database facade.
            $eInvoiceFieldId = 0;
        }
        $customFieldEvidence = [];
        foreach ($customFields as $field) {
            $fieldId = (int) ($field['id'] ?? 0);
            if ($fieldId < 1 || $fieldId !== $eInvoiceFieldId) {
                continue;
            }
            $customFieldEvidence[] = [
                'id' => $fieldId,
                'valueHash' => hash('sha256', trim((string) ($field['value'] ?? ''))),
            ];
        }
        usort(
            $customFieldEvidence,
            static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
        );

        $contactHash = hash('sha256', json_encode([
            'version' => 'whmcs_contact_contract_v1',
            'clientId' => (int) ($client['id'] ?? $snapshot->clientId),
            'companyName' => trim((string) ($client['companyname'] ?? '')),
            'firstName' => trim((string) ($client['firstname'] ?? '')),
            'lastName' => trim((string) ($client['lastname'] ?? '')),
            'email' => trim((string) ($client['email'] ?? '')),
            'street' => trim((string) ($client['address1'] ?? '')),
            'addressLine2' => trim((string) ($client['address2'] ?? '')),
            'postcode' => trim((string) ($client['postcode'] ?? '')),
            'city' => trim((string) ($client['city'] ?? '')),
            'countryCode' => strtoupper(trim((string) (
                $client['countrycode']
                ?? $client['country']
                ?? ''
            ))),
            'vatNumber' => $vatNumber !== '' ? $vatNumber : null,
            'taxExempt' => self::truthy($client['taxexempt'] ?? false),
            'customFields' => $customFieldEvidence,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return hash('sha256', json_encode([
            'version' => 'whmcs_invoice_export_contract_v1',
            'invoiceId' => $invoiceId,
            'clientId' => $snapshot->clientId,
            'status' => trim((string) ($invoice['status'] ?? '')),
            'invoiceNumber' => $snapshot->invoiceNumber,
            'invoiceDate' => $snapshot->invoiceDate->format('Y-m-d'),
            'currency' => $snapshot->currency,
            'taxType' => strtolower(trim((string) ($GLOBALS['CONFIG']['TaxType'] ?? 'exclusive'))),
            'subtotal' => self::canonicalDecimal($invoice['subtotal'] ?? '0', 'Invoice subtotal'),
            'credit' => self::canonicalDecimal($invoice['credit'] ?? '0', 'Invoice credit'),
            'tax' => self::canonicalDecimal($invoice['tax'] ?? '0', 'Invoice tax'),
            'tax2' => self::canonicalDecimal($invoice['tax2'] ?? '0', 'Invoice secondary tax'),
            'total' => self::canonicalDecimal($invoice['total'] ?? '0', 'Invoice direct cash total'),
            'taxRate' => self::canonicalDecimal($invoice['taxrate'] ?? '0', 'Invoice tax rate'),
            'taxRate2' => self::canonicalDecimal($invoice['taxrate2'] ?? '0', 'Invoice secondary tax rate'),
            'items' => $items,
            'contactHash' => $contactHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function payloadDescriptionHash(mixed $description): string
    {
        $description = trim((string) preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/',
            ' ',
            (string) $description,
        ));
        if ($description === '') {
            $description = 'WHMCS invoice item';
        }

        return hash('sha256', mb_substr($description, 0, 1000));
    }

    private static function canonicalIdentifier(mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            return '';
        }

        $value = trim((string) $value);
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return $value;
        }

        return ltrim($value, '0') ?: '0';
    }

    private static function canonicalDecimal(mixed $value, string $field): string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a decimal number.');
        }
        $value = Decimal::assert((string) $value, $field);
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $normalised = $whole . ($fraction !== '' ? '.' . $fraction : '');
        if ($normalised === '0') {
            return '0';
        }

        return ($negative ? '-' : '') . $normalised;
    }

    private static function addMinorUnits(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \InvalidArgumentException('WHMCS invoice amount sum is outside the supported range.');
        }

        return $left + $right;
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

    /** @param array<string,mixed> $client */
    private static function configuredContactIdFromClient(array $client, Config $config): ?string
    {
        try {
            $customFieldId = $config->int('custom_field_id');
        } catch (Throwable) {
            $customFieldId = 0;
        }
        if ($customFieldId < 1) {
            return null;
        }

        $contactId = self::contactIdFromClient($client, $customFieldId);
        if ($contactId !== null && preg_match('/^\d+$/', $contactId) !== 1) {
            throw new RuntimeException('The configured sevdesk contact ID must be numeric.');
        }

        return $contactId;
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
            ->where('payment.transid', '<>', '')
            ->whereNotExists(static function ($subquery): void {
                $subquery->selectRaw('1')
                    ->from('tblinvoiceitems as mass_payment_item')
                    ->whereColumn('mass_payment_item.invoiceid', 'invoice.id')
                    ->whereRaw('LOWER(TRIM(mass_payment_item.type)) = ?', ['invoice']);
            });

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

    /**
     * Binary attachments returned by EmailPreSend were introduced in WHMCS 9.
     * WHMCS 8.13 still executes the hook but ignores its attachment result.
     */
    public function supportsEmailPreSendAttachments(): bool
    {
        return self::versionSupportsEmailPreSendAttachments(($this->whmcsVersion)());
    }

    public static function versionSupportsEmailPreSendAttachments(string $version): bool
    {
        if (
            preg_match(
                '/^(\d+\.\d+\.\d+)(?:-release(?:\.\d+)?)?$/i',
                trim($version),
                $matches,
            ) !== 1
        ) {
            return false;
        }

        return version_compare($matches[1], '9.0.0', '>=');
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

    private function detectWhmcsVersion(): string
    {
        $configured = trim((string) ($GLOBALS['CONFIG']['Version'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        try {
            $stored = trim((string) Capsule::table('tblconfiguration')
                ->where('setting', 'Version')
                ->value('value'));
            if ($stored !== '') {
                return $stored;
            }
        } catch (\Throwable) {
            // The capability remains fail-closed when the local version cannot be read.
        }

        return defined('WHMCS_VERSION') ? (string) WHMCS_VERSION : '';
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

    public function invoiceStatusForDelivery(int $invoiceId): string
    {
        return trim((string) Capsule::table('tblinvoices')
            ->where('id', $this->positiveId($invoiceId, 'invoice'))
            ->value('status'));
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
