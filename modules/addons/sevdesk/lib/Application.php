<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk;

use GuzzleHttp\Client;
use RuntimeException;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;
use WHMCS\Module\Addon\SevDesk\Jobs\ExportJobHandler;
use WHMCS\Module\Addon\SevDesk\Jobs\BookingJobHandler;
use WHMCS\Module\Addon\SevDesk\Jobs\CorrectionJobHandler;
use WHMCS\Module\Addon\SevDesk\Jobs\JobOutcome;
use WHMCS\Module\Addon\SevDesk\Jobs\JobRunner;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
use WHMCS\Module\Addon\SevDesk\Repository\MappingRepository;
use WHMCS\Module\Addon\SevDesk\Service\ContactService;
use WHMCS\Module\Addon\SevDesk\Service\BookingService;
use WHMCS\Module\Addon\SevDesk\Service\CorrectionService;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;
use WHMCS\Module\Addon\SevDesk\Service\EInvoiceEligibilityService;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceExporter;
use WHMCS\Module\Addon\SevDesk\Service\InvoicePdf;
use WHMCS\Module\Addon\SevDesk\Service\InvoiceReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\LegacyMappingTypeService;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Service\PdfRenderer;
use WHMCS\Module\Addon\SevDesk\Service\ReconciliationService;
use WHMCS\Module\Addon\SevDesk\Service\ReferenceData;
use WHMCS\Module\Addon\SevDesk\Service\TaxPolicy;
use WHMCS\Module\Addon\SevDesk\Service\VoucherExporter;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

/** Lightweight composition root shared by the addon entrypoints and hooks. */
final class Application
{
    private static ?self $instance = null;

    public readonly Config $config;

    public readonly JobRepository $jobs;

    public readonly MappingRepository $mappings;

    public readonly WhmcsGateway $whmcs;

    public readonly PdfRenderer $pdf;

    private ?SevdeskClient $client = null;

    private ?ReferenceData $referenceData = null;

    private ?ContactService $contacts = null;

    private ?VoucherExporter $exporter = null;

    private ?InvoiceExporter $invoiceExporter = null;

    private ?ReconciliationService $reconciliation = null;

    private ?InvoiceReconciliationService $invoiceReconciliation = null;

    private ?InvoicePdf $invoicePdf = null;

    private ?EInvoiceEligibilityService $eInvoiceEligibility = null;

    private ?LegacyMappingTypeService $legacyMappingType = null;

    private ?BookingService $bookings = null;

    private ?CorrectionService $corrections = null;

    private ?ExportJobHandler $exportJobHandler = null;

    private ?BookingJobHandler $bookingJobHandler = null;

    private ?CorrectionJobHandler $correctionJobHandler = null;

    public function __construct()
    {
        $this->config = new Config();
        $this->jobs = new JobRepository();
        $this->mappings = new MappingRepository();
        $this->whmcs = new WhmcsGateway($this->config);
        $this->pdf = new PdfRenderer();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function client(): SevdeskClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $token = trim((string) $this->config->get('sevdesk_api_key', ''));
        if ($token === '') {
            throw new RuntimeException('No sevdesk API token is configured.');
        }
        if (!class_exists(Client::class)) {
            throw new RuntimeException('The Guzzle HTTP client shipped with WHMCS is unavailable.');
        }

        return $this->client = new SevdeskClient(
            new Client(),
            $token,
            'https://my.sevdesk.de/api/v1',
            'Nerdscave WHMCS-sevdesk/2.1.0-rc.3',
        );
    }

    public function referenceData(): ReferenceData
    {
        return $this->referenceData ??= new ReferenceData($this->client());
    }

    public function contacts(): ContactService
    {
        if ($this->contacts !== null) {
            return $this->contacts;
        }
        $referenceData = $this->referenceData();

        return $this->contacts = new ContactService(
            $this->client(),
            fn (int $clientId, string $contactId): bool => $this->storeContactId($clientId, $contactId),
            fn (string $countryCode): ?string => $referenceData->countryId($countryCode),
            '3',
            fn (): ?string => $referenceData->contactAddressCategoryId(),
            fn (): ?string => $referenceData->emailKeyId(),
            fn (): bool => $this->config->bool('customer_number_contact_creation_confirmed'),
        );
    }

    public function exporter(): VoucherExporter
    {
        return $this->exporter ??= new VoucherExporter(
            $this->client(),
            fn (int $invoiceId): ?string => $this->mappingId($invoiceId),
            fn (int $invoiceId, string $remoteId): bool => $this->storeMapping($invoiceId, $remoteId),
        );
    }

    public function reconciliation(): ReconciliationService
    {
        return $this->reconciliation ??= new ReconciliationService($this->client(), $this->mappings);
    }

    public function invoiceExporter(): InvoiceExporter
    {
        return $this->invoiceExporter ??= new InvoiceExporter(
            $this->client(),
            fn (int $invoiceId): ?string => $this->mappingId($invoiceId),
            fn (
                int $invoiceId,
                string $remoteId,
                string $type,
                string $number,
                bool $isEInvoice = false,
                ?string $xmlSha256 = null,
            ): bool => $this->storeTypedMapping(
                $invoiceId,
                $remoteId,
                $type,
                $number,
                $isEInvoice,
                $xmlSha256,
            ),
            (string) $this->config->get('invoice_sev_user_id', ''),
            (string) $this->config->get('invoice_unity_id', ''),
            fn (string $countryCode): ?string => $this->referenceData()->exactCountryId($countryCode),
        );
    }

    public function invoiceReconciliation(): InvoiceReconciliationService
    {
        return $this->invoiceReconciliation ??= new InvoiceReconciliationService(
            $this->client(),
            fn (int $invoiceId): ?string => $this->mappingId($invoiceId),
            fn (
                int $invoiceId,
                string $remoteId,
                string $type,
                string $number,
                bool $isEInvoice = false,
                ?string $xmlSha256 = null,
            ): bool => $this->storeTypedMapping(
                $invoiceId,
                $remoteId,
                $type,
                $number,
                $isEInvoice,
                $xmlSha256,
            ),
            (string) $this->config->get('invoice_sev_user_id', ''),
            (string) $this->config->get('invoice_unity_id', ''),
        );
    }

    public function invoicePdf(): InvoicePdf
    {
        return $this->invoicePdf ??= new InvoicePdf($this->client());
    }

    public function eInvoiceEligibility(): EInvoiceEligibilityService
    {
        return $this->eInvoiceEligibility ??= new EInvoiceEligibilityService(
            $this->config,
            $this->whmcs,
            $this->client(),
            $this->referenceData(),
        );
    }

    public function legacyMappingType(): LegacyMappingTypeService
    {
        return $this->legacyMappingType ??= new LegacyMappingTypeService(
            $this->client(),
            function (
                int $invoiceId,
                string $remoteId,
                string $documentType,
                string $documentNumber,
            ): void {
                $current = $this->mappings->findByInvoice($invoiceId);
                if (
                    $current === null
                    || trim((string) ($current->sevdesk_id ?? '')) !== $remoteId
                    || ($current->document_type ?? null) !== null
                ) {
                    throw new RuntimeException('The legacy mapping changed before type confirmation.');
                }

                $this->mappings->enrichDocumentMetadata(
                    $invoiceId,
                    $remoteId,
                    $documentType,
                    $documentNumber,
                );
            },
        );
    }

    public function documentTargetResolver(): DocumentTargetResolver
    {
        return new DocumentTargetResolver(
            (string) $this->config->get('export_mode', DocumentTargetResolver::MODE_VOUCHER_ONLY),
            (string) $this->config->get('document_authority', DocumentTargetResolver::AUTHORITY_WHMCS),
            (string) $this->config->get('oss_profile', DocumentTargetResolver::OSS_BLOCKED),
        );
    }

    public function bookings(): BookingService
    {
        return $this->bookings ??= new BookingService($this->client());
    }

    public function corrections(): CorrectionService
    {
        return $this->corrections ??= new CorrectionService(
            $this->client(),
            static function (string $reference): ?string {
                $item = Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('action', 'correction_voucher')
                    ->where('transaction_reference', $reference)
                    ->whereNotNull('sevdesk_id')
                    ->whereIn('status', ['succeeded', 'skipped'])
                    ->orderByDesc('id')
                    ->first();

                return $item === null ? null : (string) $item->sevdesk_id;
            },
            static function (string $reference, string $remoteId): bool {
                $remoteId = trim($remoteId);
                if ($remoteId === '') {
                    return false;
                }

                return Capsule::connection()->transaction(static function () use ($reference, $remoteId): bool {
                    $item = Capsule::table(Migrator::ITEMS_TABLE)
                        ->where('action', 'correction_voucher')
                        ->where('transaction_reference', $reference)
                        ->whereIn('status', ['running', 'pending', 'retry_wait'])
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first(['id', 'sevdesk_id']);
                    if ($item === null) {
                        return false;
                    }

                    $storedRemoteId = trim((string) ($item->sevdesk_id ?? ''));
                    if ($storedRemoteId !== '') {
                        return $storedRemoteId === $remoteId;
                    }

                    return Capsule::table(Migrator::ITEMS_TABLE)
                        ->where('id', (int) $item->id)
                        ->where(static function ($query): void {
                            $query->whereNull('sevdesk_id')->orWhere('sevdesk_id', '');
                        })
                        ->update([
                            'sevdesk_id' => $remoteId,
                            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                        ]) === 1;
                });
            },
        );
    }

    public function taxPolicy(bool $freshGuidance = false): TaxPolicy
    {
        return new TaxPolicy(
            $this->config->taxProfiles(),
            (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED),
            $this->referenceData()->receiptGuidance($freshGuidance),
            (string) $this->config->get('oss_profile', TaxPolicy::OSS_BLOCKED),
        );
    }

    public function invoiceTaxPolicy(): TaxPolicy
    {
        return new TaxPolicy(
            $this->config->taxProfiles(),
            (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED),
            null,
            (string) $this->config->get('oss_profile', TaxPolicy::OSS_BLOCKED),
        );
    }

    public function runner(): JobRunner
    {
        return new JobRunner($this->jobs, $this->config, [
            // Construct remote-aware handlers only after JobRunner has acquired
            // its lock and claimed an item of the corresponding action.
            'export_voucher' => fn (object $item, callable $checkpoint): JobOutcome =>
                ($this->exportJobHandler())($item, $checkpoint),
            'export_document' => fn (object $item, callable $checkpoint): JobOutcome =>
                ($this->exportJobHandler())($item, $checkpoint),
            'reconcile_voucher' => fn (object $item, callable $checkpoint): JobOutcome =>
                ($this->exportJobHandler())($item, $checkpoint),
            'book_payment' => fn (object $item, callable $checkpoint): JobOutcome =>
                ($this->bookingJobHandler())($item, $checkpoint),
            'correction_voucher' => fn (object $item, callable $checkpoint): JobOutcome =>
                ($this->correctionJobHandler())($item, $checkpoint),
            'review_notice' => static fn (): JobOutcome => JobOutcome::permanentFailure(
                'Dieser Vorgang benötigt eine manuelle buchhalterische Prüfung.',
                errorCode: 'manual_review_required',
            ),
        ]);
    }

    private function exportJobHandler(): ExportJobHandler
    {
        return $this->exportJobHandler ??= new ExportJobHandler(
            $this->config,
            $this->whmcs,
            $this->mappings,
            $this->jobs,
            $this->contacts(),
            $this->pdf,
            $this->exporter(),
            $this->reconciliation(),
            fn (): TaxPolicy => $this->taxPolicy(),
            $this->invoiceExporter(),
            $this->invoiceReconciliation(),
            $this->invoicePdf(),
            fn (): DocumentTargetResolver => $this->documentTargetResolver(),
            $this->eInvoiceEligibility(),
        );
    }

    private function bookingJobHandler(): BookingJobHandler
    {
        return $this->bookingJobHandler ??= new BookingJobHandler(
            $this->bookings(),
            $this->jobs,
            $this->config,
            $this->mappings,
        );
    }

    private function correctionJobHandler(): CorrectionJobHandler
    {
        return $this->correctionJobHandler ??= new CorrectionJobHandler(
            $this->corrections(),
            $this->whmcs,
            $this->mappings,
            $this->jobs,
            $this->config,
            fn (): TaxPolicy => $this->taxPolicy(),
        );
    }

    private function mappingId(int $invoiceId): ?string
    {
        $mapping = $this->mappings->findCompleteByInvoice($invoiceId);

        return $mapping === null ? null : (string) $mapping->sevdesk_id;
    }

    private function storeMapping(int $invoiceId, string $remoteId): bool
    {
        $this->mappings->linkDocument(
            $invoiceId,
            $remoteId,
            MappingRepository::DOCUMENT_TYPE_VOUCHER,
            isEInvoice: false,
        );

        return true;
    }

    private function storeTypedMapping(
        int $invoiceId,
        string $remoteId,
        string $type,
        string $number,
        bool $isEInvoice = false,
        ?string $xmlSha256 = null,
    ): bool {
        $this->mappings->linkDocument(
            $invoiceId,
            $remoteId,
            $type,
            $number,
            $isEInvoice,
            $xmlSha256,
        );

        return true;
    }

    private function storeContactId(int $clientId, string $contactId): bool
    {
        $this->whmcs->storeContactId($clientId, $contactId);

        return true;
    }
}
