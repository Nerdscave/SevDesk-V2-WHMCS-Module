<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk;

use DateTimeImmutable;
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
use WHMCS\Module\Addon\SevDesk\Service\WhmcsPaymentStructureService;
use WHMCS\Module\Addon\SevDesk\Support\DocumentDeliveryContext;

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

    private ?WhmcsPaymentStructureService $paymentStructure = null;

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
            'WHMCS-sevdesk/2.1.0-rc.5',
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
            fn (string $countryCode): ?string => $referenceData->exactCountryId($countryCode),
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
                string $documentAuthority = MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
            ): bool => $this->storeTypedMapping(
                $invoiceId,
                $remoteId,
                $type,
                $number,
                $isEInvoice,
                $xmlSha256,
                $documentAuthority,
            ),
            (string) $this->config->get('invoice_sev_user_id', ''),
            (string) $this->config->get('invoice_unity_id', ''),
            fn (string $countryCode): ?string => $this->referenceData()->exactCountryId($countryCode),
            $this->config->bool('invoice_discount_canary_confirmed')
                && $this->config->bool('small_business_invoice_canary_confirmed'),
            fn (string $sevUserId, string $unityId): bool =>
                $this->referenceData()->hasSevUser($sevUserId)
                && $this->referenceData()->hasUnity($unityId),
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
                string $documentAuthority = MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
            ): bool => $this->storeTypedMapping(
                $invoiceId,
                $remoteId,
                $type,
                $number,
                $isEInvoice,
                $xmlSha256,
                $documentAuthority,
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
                string $documentAuthority,
            ): void {
                $current = $this->mappings->findByInvoice($invoiceId);
                if ($current === null) {
                    throw new RuntimeException('The legacy mapping changed before metadata confirmation.');
                }
                $currentType = trim((string) ($current->document_type ?? ''));
                $currentAuthority = trim((string) ($current->document_authority ?? ''));
                if (
                    trim((string) ($current->sevdesk_id ?? '')) !== $remoteId
                    || ($currentType !== '' && $currentType !== $documentType)
                    || ($currentAuthority !== '' && $currentAuthority !== $documentAuthority)
                ) {
                    throw new RuntimeException('The legacy mapping changed before metadata confirmation.');
                }
                $frozenDocument = DocumentDeliveryContext::frozenConfirmedDocument(
                    $this->jobs->latestDocumentContextForInvoice($invoiceId, true),
                );
                if (
                    $frozenDocument !== null
                    && (
                        $frozenDocument['documentType'] !== $documentType
                        || $frozenDocument['documentAuthority'] !== $documentAuthority
                    )
                ) {
                    throw new RuntimeException(
                        'The selected legacy document metadata conflicts with its frozen export decision.',
                    );
                }

                if ($documentAuthority === MappingRepository::DOCUMENT_AUTHORITY_SEVDESK) {
                    if (
                        $documentType !== MappingRepository::DOCUMENT_TYPE_INVOICE
                        || !$this->legacySevdeskAuthorityReady()
                    ) {
                        throw new RuntimeException(
                            'The sevdesk authority prerequisites are not ready for this legacy mapping.',
                        );
                    }
                    $pdf = $this->invoicePdf()->fetch($remoteId);
                    $this->mappings->enrichDocumentMetadata(
                        $invoiceId,
                        $remoteId,
                        $documentType,
                        $documentNumber,
                        new DateTimeImmutable(),
                        pdfSha256: $pdf['sha256'],
                        documentAuthority: $documentAuthority,
                        requiredWhmcsInvoiceStatus: 'Paid',
                    );

                    return;
                }

                $this->mappings->enrichDocumentMetadata(
                    $invoiceId,
                    $remoteId,
                    $documentType,
                    $documentNumber,
                    documentAuthority: $documentAuthority,
                );
            },
        );
    }

    public function legacySevdeskAuthorityReady(): bool
    {
        if (
            !$this->whmcs->proformaInvoicingEnabled()
            || !$this->whmcs->themeAdapterManifestInstalled()
            || !$this->config->bool('theme_adapter_confirmed')
        ) {
            return false;
        }

        $channel = (string) $this->config->get('invoice_delivery_channel', 'sevdesk');
        if ($channel === 'whmcs_template') {
            return $this->whmcs->supportsEmailPreSendAttachments()
                && $this->whmcs->isActiveCustomInvoiceTemplate(
                    (string) $this->config->get('whmcs_invoice_email_template', ''),
                );
        }
        if ($channel !== 'sevdesk') {
            return false;
        }

        return self::validSevdeskDeliveryText(
            (string) $this->config->get('sevdesk_email_subject', ''),
            (string) $this->config->get('sevdesk_email_body', ''),
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

    public function paymentStructure(): WhmcsPaymentStructureService
    {
        return $this->paymentStructure ??= new WhmcsPaymentStructureService();
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

    public function invoiceTaxPolicy(bool $freshGuidance = false): TaxPolicy
    {
        $smallBusinessInvoiceCanary = $this->config->bool(
            'small_business_invoice_canary_confirmed',
        );
        $ruleElevenGuidance = $smallBusinessInvoiceCanary
            && $this->config->bool('smallBusinessOwner')
            && (string) $this->config->get('export_mode', DocumentTargetResolver::MODE_VOUCHER_ONLY)
                === DocumentTargetResolver::MODE_INVOICE_ONLY
                ? $this->referenceData()->receiptGuidance($freshGuidance)
                : null;

        return new TaxPolicy(
            $this->config->taxProfiles(),
            (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED),
            $ruleElevenGuidance,
            (string) $this->config->get('oss_profile', TaxPolicy::OSS_BLOCKED),
            $smallBusinessInvoiceCanary,
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
            $this->paymentStructure(),
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
            documentAuthority: MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
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
        string $documentAuthority = MappingRepository::DOCUMENT_AUTHORITY_WHMCS,
    ): bool {
        $this->mappings->linkDocument(
            $invoiceId,
            $remoteId,
            $type,
            $number,
            $isEInvoice,
            $xmlSha256,
            $documentAuthority,
        );

        return true;
    }

    private function storeContactId(int $clientId, string $contactId): bool
    {
        $this->whmcs->storeContactId($clientId, $contactId);

        return true;
    }

    private static function validSevdeskDeliveryText(string $subject, string $body): bool
    {
        if ($subject === '' || $body === '' || mb_strlen($subject) > 200 || mb_strlen($body) > 5000) {
            return false;
        }
        foreach ([$subject, $body] as $value) {
            preg_match_all('/\{[A-Za-z0-9_]+\}/', $value, $matches);
            foreach ($matches[0] as $placeholder) {
                if (!in_array($placeholder, ['{invoice_number}', '{company_name}'], true)) {
                    return false;
                }
            }
            $withoutAllowed = str_replace(['{invoice_number}', '{company_name}'], '', $value);
            if (str_contains($withoutAllowed, '{') || str_contains($withoutAllowed, '}')) {
                return false;
            }
        }

        return true;
    }
}
