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

    private ?ReconciliationService $reconciliation = null;

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
            'Nerdscave WHMCS-sevdesk/2.0.0',
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
                return Capsule::table(Migrator::ITEMS_TABLE)
                    ->where('action', 'correction_voucher')
                    ->where('transaction_reference', $reference)
                    ->whereIn('status', ['running', 'pending', 'retry_wait'])
                    ->update(['sevdesk_id' => $remoteId, 'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]) > 0;
            },
        );
    }

    public function taxPolicy(bool $freshGuidance = false): TaxPolicy
    {
        return new TaxPolicy(
            $this->config->taxProfiles(),
            (string) $this->config->get('eu_b2c_mode', TaxPolicy::EU_B2C_BLOCKED),
            $this->referenceData()->receiptGuidance($freshGuidance),
        );
    }

    public function runner(): JobRunner
    {
        return new JobRunner($this->jobs, $this->config, [
            // Construct remote-aware handlers only after JobRunner has acquired
            // its lock and claimed an item of the corresponding action.
            'export_voucher' => fn (object $item, callable $checkpoint): JobOutcome =>
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
        $this->mappings->link($invoiceId, $remoteId);

        return true;
    }

    private function storeContactId(int $clientId, string $contactId): bool
    {
        $this->whmcs->storeContactId($clientId, $contactId);

        return true;
    }
}
