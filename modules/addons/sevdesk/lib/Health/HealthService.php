<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Health;

use DateTimeImmutable;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;

final class HealthService
{
    public function __construct(private readonly Application $application)
    {
    }

    /** @return array{checks:list<array<string,mixed>>,stats:array<string,mixed>} */
    public function run(bool $remote = true): array
    {
        $checks = [];
        $this->add(
            $checks,
            'PHP 8.3',
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'Installiert: ' . PHP_VERSION,
            'error'
        );

        $whmcsVersion = $this->detectWhmcsVersion();
        $whmcsCompatible = self::supportsWhmcsVersion($whmcsVersion);
        $this->add(
            $checks,
            'WHMCS 8.13.4',
            $whmcsCompatible,
            'Installiert: ' . $whmcsVersion . ($whmcsCompatible ? '' : '; andere Versionen sind noch nicht freigegeben.'),
            'error'
        );

        $schemaReport = Migrator::schemaReport();
        $schemaOk = $schemaReport['tables']
            && $schemaReport['missing_columns'] === []
            && $schemaReport['mapping_invoice_unique']
            && $schemaReport['mapping_remote_unique']
            && $schemaReport['item_dedupe_unique'];
        $this->add(
            $checks,
            'Datenbankschema',
            $schemaOk,
            $schemaOk
                ? 'Pflichtspalten und Unique-Constraints der Mapping- und Jobtabellen sind vorhanden.'
                : 'Schema unvollständig oder ein erforderlicher Unique-Constraint fehlt.',
            'error',
            null,
            null,
            $schemaReport['missing_columns'] === [] ? null : implode(', ', $schemaReport['missing_columns'])
        );

        $exportMode = (string) $this->application->config->get('export_mode', 'voucher_only');
        $documentAuthority = (string) $this->application->config->get('document_authority', 'whmcs');
        $ossProfile = (string) $this->application->config->get('oss_profile', 'blocked');
        $euB2cMode = (string) $this->application->config->get('eu_b2c_mode', 'blocked');
        $eInvoiceMode = (string) $this->application->config->get('e_invoice_mode', 'off');
        $invoiceCapable = in_array($exportMode, ['invoice_for_oss', 'invoice_only'], true);
        $pdfRequired = in_array($exportMode, ['voucher_only', 'invoice_for_oss'], true);
        $pdfFunctions = defined('ROOTDIR') ? ROOTDIR . '/includes/invoicefunctions.php' : '';
        $pdfAvailable = $pdfFunctions !== '' && is_file($pdfFunctions);
        $this->add(
            $checks,
            'WHMCS-PDF-Funktion',
            !$pdfRequired || $pdfAvailable,
            $pdfAvailable
                ? 'Der absolute WHMCS-Pfad zur PDF-Erzeugung ist vorhanden; Voucher-PDFs werden je Jobposition validiert.'
                : ($pdfRequired
                    ? 'Die für Voucher erforderlichen WHMCS-PDF-Funktionen wurden unter ROOTDIR nicht gefunden.'
                    : 'Invoice only benötigt für den sevdesk-Export keine WHMCS-PDF-Erzeugung.'),
            'error'
        );

        $mappingCounts = ['complete' => 0, 'ambiguous' => 0, 'orphans' => 0];
        if ($schemaOk) {
            $mappingCounts = $this->application->mappings->counts();
            $mappingClean = $mappingCounts['ambiguous'] === 0 && $mappingCounts['orphans'] === 0;
            $this->add(
                $checks,
                'Legacy-Zuordnungen',
                $mappingClean,
                sprintf(
                    '%d vollständig, %d ohne sevdesk-ID, %d ohne lokale Rechnung. Keine Zeile wird automatisch gelöscht.',
                    $mappingCounts['complete'],
                    $mappingCounts['ambiguous'],
                    $mappingCounts['orphans'],
                ),
                'warning'
            );
            $untypedMappings = Capsule::table(Migrator::MAPPING_TABLE . ' as mapping')
                ->join('tblinvoices as invoice', 'mapping.invoice_id', '=', 'invoice.id')
                ->whereNotNull('mapping.sevdesk_id')
                ->whereRaw("TRIM(mapping.sevdesk_id) <> ''")
                ->whereNull('mapping.document_type')
                ->count();
            $this->add(
                $checks,
                'Legacy-Belegtypen',
                $untypedMappings === 0,
                $untypedMappings === 0
                    ? 'Alle vollständigen Zuordnungen besitzen einen bestätigten Dokumenttyp.'
                    : $untypedMappings . ' vollständige Altzuordnungen benötigen eine read-only Typprüfung und Adminbestätigung.',
                'warning',
                'addonmodules.php?module=sevdesk&a=assignmentManager&status=untyped',
                'Belegtypen prüfen',
            );
        }

        $customFieldId = $this->application->config->int('custom_field_id');
        $customFieldOk = $customFieldId > 0 && Capsule::table('tblcustomfields')
            ->where('id', $customFieldId)
            ->where('type', 'client')
            ->exists();
        $this->add(
            $checks,
            'Kontakt-ID-Kundenfeld',
            $customFieldOk,
            $customFieldOk ? 'Das konfigurierte WHMCS-Kundenfeld existiert.' : 'Bitte ein Kundenfeld für die sevdesk-Kontakt-ID wählen.',
            'error',
            'addonmodules.php?module=sevdesk&a=setup',
            'Einrichtung öffnen'
        );

        $contactCreationNotice = self::contactCreationPolicyNotice(
            $this->application->config->bool('customer_number_contact_creation_confirmed'),
        );
        $this->add(
            $checks,
            'Kontakt-Neuanlage / Kundennummer',
            $contactCreationNotice['status'] === 'healthy',
            $contactCreationNotice['message'],
            'warning',
            'addonmodules.php?module=sevdesk&a=setup',
            'Kontaktregel prüfen',
        );

        $modeValid = self::documentConfigurationValid(
            $exportMode,
            $documentAuthority,
            $ossProfile,
            $euB2cMode,
        );
        $this->add(
            $checks,
            'Exportmodus / Dokumenthoheit / OSS-Profil',
            $modeValid,
            $modeValid
                ? 'Aktiv: ' . $exportMode . ' mit ' . $documentAuthority . '-Dokumenthoheit und OSS-Profil ' . $ossProfile . '.'
                : 'Exportmodus, Dokumenthoheit, OSS-Profil und EU-B2C-Modus bilden keine zulässige Kombination.',
            'error',
            'addonmodules.php?module=sevdesk&a=setup',
            'Einrichtung öffnen',
        );

        $euB2cNotice = self::euB2cPolicyNotice($euB2cMode, $ossProfile);
        $this->add(
            $checks,
            'EU B2C / OSS Rule 19',
            $euB2cNotice['status'] === 'healthy',
            $euB2cNotice['message'],
            'warning',
        );

        if ($invoiceCapable) {
            $invoiceReferencesValid = preg_match(
                '/^[1-9]\d*$/',
                (string) $this->application->config->get('invoice_sev_user_id', ''),
            ) === 1 && preg_match(
                '/^[1-9]\d*$/',
                (string) $this->application->config->get('invoice_unity_id', ''),
            ) === 1;
            $this->add(
                $checks,
                'Invoice-Canary und Pflichtreferenzen',
                $this->application->config->bool('invoice_canary_confirmed') && $invoiceReferencesValid,
                $this->application->config->bool('invoice_canary_confirmed') && $invoiceReferencesValid
                    ? 'Canary, SevUser und Standard-Unity sind konfiguriert.'
                    : 'Invoice-Export bleibt gesperrt, bis Canary, SevUser und Unity bestätigt sind.',
                'error',
                'addonmodules.php?module=sevdesk&a=setup',
                'Invoice-Gate prüfen',
            );
        }

        $eInvoiceConfigurationReady = $eInvoiceMode === 'off';
        if ($eInvoiceMode === 'zugferd_domestic_b2b') {
            $eInvoiceFieldId = $this->application->config->int('e_invoice_client_field_id');
            $paymentMethodId = trim((string) $this->application->config->get(
                'e_invoice_payment_method_id',
                '',
            ));
            $activeFromValue = (string) $this->application->config->get('e_invoice_active_from', '');
            $activeFrom = DateTimeImmutable::createFromFormat('!d-m-Y', $activeFromValue);
            $eInvoiceConfigurationReady = $exportMode === 'invoice_only'
                && $documentAuthority === 'sevdesk'
                && class_exists(\XMLReader::class)
                && $this->application->config->bool('e_invoice_canary_confirmed')
                && $this->application->whmcs->isEInvoiceOptInField($eInvoiceFieldId)
                && preg_match('/^[1-9]\d*$/', $paymentMethodId) === 1
                && $activeFrom instanceof DateTimeImmutable
                && $activeFrom->format('d-m-Y') === $activeFromValue;
        }
        $this->add(
            $checks,
            'ZUGFeRD-Konfiguration',
            $eInvoiceConfigurationReady,
            $eInvoiceMode === 'off'
                ? 'Native E-Rechnungen sind ausgeschaltet.'
                : ($eInvoiceConfigurationReady
                    ? 'ZUGFeRD ist für bestätigte deutsche B2B-Kunden hinter eigenem Canary und Admin-Opt-in vorbereitet.'
                    : 'ZUGFeRD benötigt Invoice only, sevdesk-Hoheit, PHP XMLReader, Canary, Admin-Tickbox, Zahlungsmethode und Aktivierungsdatum.'),
            'error',
            'addonmodules.php?module=sevdesk&a=setup',
            'E-Rechnungsprofil prüfen',
        );

        if ($documentAuthority === 'sevdesk') {
            $deliveryChannel = (string) $this->application->config->get('invoice_delivery_channel', 'sevdesk');
            $templateOk = $deliveryChannel !== 'whmcs_template' || $this->application->whmcs->isActiveCustomInvoiceTemplate(
                (string) $this->application->config->get('whmcs_invoice_email_template', ''),
            );
            $authorityReady = $this->application->whmcs->proformaInvoicingEnabled()
                && $this->application->whmcs->themeAdapterManifestInstalled()
                && $this->application->config->bool('theme_adapter_confirmed')
                && in_array($deliveryChannel, ['sevdesk', 'whmcs_template'], true)
                && $templateOk;
            $this->add(
                $checks,
                'sevdesk-Dokumenthoheit',
                $authorityReady,
                $authorityReady
                    ? 'Proforma, Theme-Adapter und Versandkanal sind vorbereitet.'
                    : 'Proforma, Theme-Adapter oder der gewählte Versandkanal ist nicht vollständig vorbereitet.',
                'error',
                'addonmodules.php?module=sevdesk&a=setup',
                'Dokumenthoheit prüfen',
            );
        }

        $moduleActive = $this->application->config->bool('module_active');
        $this->add(
            $checks,
            'Modul-Laufzeit',
            $moduleActive,
            $moduleActive
                ? 'Runner und Aktionen in der WHMCS-Rechnungsansicht sind betriebsbereit; automatische Hooks folgen dem separaten Synchronisationsschalter.'
                : 'Die interne Modulaktivierung fehlt; Runner und Aktionen in der WHMCS-Rechnungsansicht bleiben deaktiviert.',
            'error'
        );

        $runtimeReviewRequired = $this->application->config->bool(Config::RUNTIME_REVIEW_SETTING);
        $this->add(
            $checks,
            'Bestands- und Laufzeitfreigabe',
            !$runtimeReviewRequired,
            $runtimeReviewRequired
                ? 'Ein übernommener oder strukturell auffälliger Bestand ist quarantänisiert; Runner und '
                    . 'Remote-fähige Jobaktionen bleiben bis zur bestätigten Einrichtung gesperrt.'
                : 'Der lokale Bestand wurde für diese Rewrite-Laufzeit ausdrücklich freigegeben.',
            'error',
            'addonmodules.php?module=sevdesk&a=setup',
            'Bestand prüfen',
        );

        $runnerSeen = (string) $this->application->config->get('runner_last_seen', '');
        $runnerFresh = false;
        if ($runnerSeen !== '') {
            try {
                $runnerFresh = (new DateTimeImmutable($runnerSeen)) > new DateTimeImmutable('-2 hours');
            } catch (Throwable) {
                $runnerFresh = false;
            }
        }
        $this->add(
            $checks,
            'WHMCS-Cron / Runner',
            $runnerFresh,
            $runnerSeen === '' ? 'Der Runner wurde noch nie beobachtet.' : 'Letzter Lauf: ' . $runnerSeen,
            'warning',
            null,
            null,
            $runnerSeen === '' ? null : $runnerSeen
        );

        $syncEnabled = $this->application->config->bool('sync_enabled');
        $this->add(
            $checks,
            'Automatische Synchronisation',
            $syncEnabled,
            $syncEnabled ? 'Hooks reihen Rechnungen automatisch ein.' : 'Automatische Hooks sind sicher deaktiviert.',
            'warning',
            'addonmodules.php?module=sevdesk&a=setup',
            'Einstellung prüfen'
        );

        $healthAlarm = trim((string) $this->application->config->get('health_alarm', ''));
        $this->add(
            $checks,
            'Persistenter Sicherheitsalarm',
            $healthAlarm === '',
            $healthAlarm === ''
                ? 'Kein globaler API- oder Authentifizierungsalarm ist aktiv.'
                : ($healthAlarm === 'api_authentication_failed'
                    ? 'Ein Worker hat HTTP 401/403 erkannt. Jobs bleiben pausiert, bis die Verbindung geprüft wurde.'
                    : 'Ein Worker hat den Sicherheitsalarm „' . mb_substr($healthAlarm, 0, 80) . '“ gesetzt.'),
            'error',
            'addonmodules.php?module=sevdesk&a=setup',
            'Verbindung prüfen'
        );

        if (Capsule::schema()->hasTable(Migrator::ITEMS_TABLE)) {
            $recentFailures = Capsule::table(Migrator::ITEMS_TABLE)
                ->whereIn('status', ['permanent_failed', 'ambiguous'])
                ->where('updated_at', '>=', (new DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s'))
                ->count();
            $this->add(
                $checks,
                'Jüngste Itemfehler',
                $recentFailures === 0,
                $recentFailures === 0
                    ? 'In den letzten sieben Tagen wurden keine offenen Fehler oder unklaren Writes gespeichert.'
                    : $recentFailures . ' Fehler oder unklare Writes aus den letzten sieben Tagen benötigen Aufmerksamkeit.',
                'warning',
                'addonmodules.php?module=sevdesk&a=corrections',
                'Klärfälle öffnen'
            );
        }

        $bookkeepingVersion = 'nicht geprüft';
        if (trim((string) $this->application->config->get('sevdesk_api_key', '')) === '') {
            $this->add(
                $checks,
                'sevdesk API-Authentifizierung',
                false,
                'Es ist kein API-Token hinterlegt.',
                'error',
                'addonmodules.php?module=sevdesk&a=setup',
                'Token hinterlegen'
            );
        } elseif ($remote) {
            try {
                $bookkeepingVersion = $this->application->referenceData()->bookkeepingVersion();
                $this->add(
                    $checks,
                    'sevdesk API-Authentifizierung',
                    true,
                    'Read-only API-Aufruf erfolgreich.',
                    'error'
                );
                $this->add(
                    $checks,
                    'sevdesk-Systemversion 2.0',
                    $bookkeepingVersion === '2.0',
                    'Gemeldet: ' . ($bookkeepingVersion !== '' ? $bookkeepingVersion : 'unbekannt'),
                    'error'
                );

                if ($modeValid && $exportMode === 'invoice_only') {
                    $this->addInvoiceTaxChecks($checks);
                } elseif ($modeValid) {
                    $guidance = $this->application->referenceData()->receiptGuidance(true);
                    $this->add(
                        $checks,
                        'Receipt Guidance',
                        $guidance !== [],
                        count($guidance) . ' zulässige Erlöskonten-Kombinationen gelesen.',
                        'error'
                    );
                    $this->addTaxChecks($checks);
                }
                if ($invoiceCapable) {
                    $sevUserId = (string) $this->application->config->get('invoice_sev_user_id', '');
                    $unityId = (string) $this->application->config->get('invoice_unity_id', '');
                    $referencesExist = $this->application->referenceData()->hasSevUser($sevUserId)
                        && $this->application->referenceData()->hasUnity($unityId);
                    $this->add(
                        $checks,
                        'Invoice-Referenzen im Mandanten',
                        $referencesExist,
                        $referencesExist
                            ? 'SevUser und Unity wurden read-only im aktuellen Mandanten bestätigt.'
                            : 'Mindestens eine konfigurierte Invoice-Referenz wurde im Mandanten nicht gefunden.',
                        'error',
                    );
                }
                if ($eInvoiceMode === 'zugferd_domestic_b2b') {
                    $paymentMethodId = (string) $this->application->config->get(
                        'e_invoice_payment_method_id',
                        '',
                    );
                    $paymentMethodExists = $this->application->referenceData()->hasPaymentMethod(
                        $paymentMethodId,
                    );
                    $this->add(
                        $checks,
                        'E-Rechnungs-Zahlungsmethode im Mandanten',
                        $paymentMethodExists,
                        $paymentMethodExists
                            ? 'Die konfigurierte PaymentMethod wurde read-only im aktuellen Mandanten bestätigt.'
                            : 'Die konfigurierte PaymentMethod wurde im aktuellen Mandanten nicht gefunden.',
                        'error',
                    );
                }
            } catch (ApiException $error) {
                $details = array_filter([
                    $error->httpStatus === null ? null : 'HTTP ' . $error->httpStatus,
                    $error->sevdeskCode,
                    $error->exceptionUuid === null ? null : 'UUID ' . $error->exceptionUuid,
                ]);
                $this->add(
                    $checks,
                    'sevdesk API-Authentifizierung',
                    false,
                    'Read-only API-Prüfung fehlgeschlagen.',
                    'error',
                    null,
                    null,
                    implode(', ', $details)
                );
            } catch (Throwable $error) {
                $this->add(
                    $checks,
                    'sevdesk API-Authentifizierung',
                    false,
                    'Die API-Prüfung konnte nicht ausgeführt werden.',
                    'error',
                    null,
                    null,
                    get_class($error)
                );
            }
        }

        $healthy = count(array_filter($checks, static fn (array $check): bool => $check['ok'] === true));
        $hasError = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'error')) > 0;
        $hasWarning = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'warning')) > 0;

        return [
            'checks' => $checks,
            'stats' => [
                'health_status' => $hasError ? 'error' : ($hasWarning ? 'warning' : 'healthy'),
                'healthy' => $healthy,
                'module_version' => '2.1.0-rc.4',
                'whmcs_version' => $whmcsVersion,
                'php_version' => PHP_VERSION,
                'bookkeeping_version' => $bookkeepingVersion,
                'mapping_counts' => $mappingCounts,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $checks */
    private function addTaxChecks(array &$checks): void
    {
        /** @var array<string, array{string,bool,?string,bool,bool,string,bool,bool}> $profiles */
        $profiles = [
            'Deutschland / Rule 1' => ['DE', false, null, false, false, '19', false, false],
            'Drittland' => ['US', false, null, false, false, '0', false, true],
            'Kleinunternehmer' => [
                'DE',
                false,
                null,
                true,
                false,
                '0',
                false,
                !$this->application->config->bool('smallBusinessOwner'),
            ],
            'AddFunds' => ['DE', false, null, false, true, '0', false, true],
        ];
        if ($this->application->config->bool('eu_b2b_goods_confirmed')) {
            $profiles['EU B2B / Rule 3 (bestätigte Warenlieferung)'] = [
                'BE', true, 'BE0123456789', false, false, '0', true, false,
            ];
        }
        foreach ($profiles as $name => [$country, $exempt, $vat, $smallBusiness, $addFunds, $rate, $organisation, $optional]) {
            $line = new \WHMCS\Module\Addon\SevDesk\Domain\LineItem('Health check', '1.00', $rate, true);
            $decision = $this->application->taxPolicy()->decide(
                $country,
                $exempt,
                $vat,
                $smallBusiness,
                $addFunds,
                [$line],
                $organisation,
            );
            $this->add(
                $checks,
                'Steuerprofil: ' . $name,
                $decision->allowed && $decision->guidanceValidated,
                $decision->message,
                self::taxProfileFailureStatus($decision->code, $optional),
                'addonmodules.php?module=sevdesk&a=setup',
                'Steuerprofil prüfen',
                $decision->code
            );
        }

        $this->addConfirmedOssInvoiceCheck($checks);
        $this->addTaxPolicyNotices($checks);
    }

    /** @param list<array<string,mixed>> $checks */
    private function addInvoiceTaxChecks(array &$checks): void
    {
        /** @var array<string, array{string,bool,?string,bool,bool,string,bool,bool}> $profiles */
        $profiles = [
            'Deutschland / Rule 1 (Invoice)' => ['DE', false, null, false, false, '19', false, false],
            'Drittland (Invoice)' => ['US', false, null, false, false, '0', false, true],
            'Kleinunternehmer (Invoice)' => [
                'DE',
                false,
                null,
                true,
                false,
                '0',
                false,
                !$this->application->config->bool('smallBusinessOwner'),
            ],
            'AddFunds (Invoice)' => ['DE', false, null, false, true, '0', false, true],
        ];
        if ($this->application->config->bool('eu_b2b_goods_confirmed')) {
            $profiles['EU B2B / Rule 3 (bestätigte Warenlieferung, Invoice)'] = [
                'BE', true, 'BE0123456789', false, false, '0', true, false,
            ];
        }

        $policy = $this->application->invoiceTaxPolicy();
        foreach ($profiles as $name => [$country, $exempt, $vat, $smallBusiness, $addFunds, $rate, $organisation, $optional]) {
            $line = new \WHMCS\Module\Addon\SevDesk\Domain\LineItem('Health check', '1.00', $rate, true);
            $decision = $policy->decideInvoice(
                $country,
                $exempt,
                $vat,
                $smallBusiness,
                $addFunds,
                [$line],
                $organisation,
            );
            $this->add(
                $checks,
                'Steuerprofil: ' . $name,
                $decision->allowed,
                $decision->message,
                self::taxProfileFailureStatus($decision->code, $optional),
                'addonmodules.php?module=sevdesk&a=setup',
                'Steuerprofil prüfen',
                $decision->code,
            );
        }

        $this->addConfirmedOssInvoiceCheck($checks, $policy);
        $this->addTaxPolicyNotices($checks);
    }

    /** @param list<array<string,mixed>> $checks */
    private function addConfirmedOssInvoiceCheck(
        array &$checks,
        ?\WHMCS\Module\Addon\SevDesk\Service\TaxPolicy $policy = null,
    ): void {
        if ((string) $this->application->config->get('oss_profile', 'blocked') !== 'rule19_digital_services_confirmed') {
            return;
        }

        $policy ??= $this->application->invoiceTaxPolicy();
        $decision = $policy->decideInvoice(
            'BE',
            false,
            null,
            false,
            false,
            [new \WHMCS\Module\Addon\SevDesk\Domain\LineItem('Health check', '1.00', '21', true)],
        );
        $this->add(
            $checks,
            'Steuerprofil: EU B2C / OSS Rule 19 (Invoice)',
            $decision->allowed
                && $decision->taxRuleId === '19'
                && $decision->accountDatevId === null
                && !$decision->guidanceValidated,
            $decision->message,
            'error',
            'addonmodules.php?module=sevdesk&a=setup',
            'OSS-Profil prüfen',
            $decision->code,
        );
    }

    /** @param list<array<string,mixed>> $checks */
    private function addTaxPolicyNotices(array &$checks): void
    {
        $euB2bGoodsConfirmed = $this->application->config->bool('eu_b2b_goods_confirmed');
        $this->add(
            $checks,
            'EU B2B / Rule-3-Sperre',
            true,
            $euB2bGoodsConfirmed
                ? 'Rule 3 ist ausschließlich für ausdrücklich bestätigte innergemeinschaftliche Warenlieferungen freigegeben.'
                : 'Rule 3 bleibt gesperrt; Hosting und andere EU-B2B-Dienstleistungen werden nicht automatisch exportiert.',
            $euB2bGoodsConfirmed ? 'warning' : 'healthy',
        );
    }

    /** @return array{status:'healthy'|'warning',message:string} */
    public static function euB2cPolicyNotice(string $euB2cMode, string $ossProfile): array
    {
        if ($ossProfile === 'rule19_digital_services_confirmed') {
            return [
                'status' => 'warning',
                'message' => 'Rule 19 ist ausschließlich für bestätigte elektronische/digitale Leistungen über Invoice freigegeben.',
            ];
        }
        if ($euB2cMode === 'domestic_confirmed') {
            return [
                'status' => 'warning',
                'message' => 'Deutsche USt wurde für EU-B2C ausdrücklich bestätigt; die steuerliche Grundlage ist je Zeitraum zu prüfen.',
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'OSS bleibt fail-closed. Rules 18 und 20 sowie unklare oder gemischte Leistungen sind nicht freigegeben.',
        ];
    }

    /** @return array{status:'healthy'|'warning',message:string} */
    public static function contactCreationPolicyNotice(bool $confirmed): array
    {
        if ($confirmed) {
            return [
                'status' => 'healthy',
                'message' => 'Neue Kontakte dürfen nach erfolgloser exakter Suche mit der internen WHMCS-Client-ID als customerNumber angelegt werden.',
            ];
        }

        return [
            'status' => 'warning',
            'message' => 'Kontakt-Neuanlagen sind nicht bestätigt. Vorhandene IDs und exakte Kundennummerntreffer bleiben nutzbar; ohne Treffer wird kein Kontakt angelegt.',
        ];
    }

    public static function documentConfigurationValid(
        string $exportMode,
        string $documentAuthority,
        string $ossProfile,
        string $euB2cMode,
    ): bool {
        return DocumentTargetResolver::contextValuesAreValid(
            $exportMode,
            $documentAuthority,
            $ossProfile,
            $euB2cMode,
            $documentAuthority === DocumentTargetResolver::AUTHORITY_SEVDESK
                ? DocumentTargetResolver::DELIVERY_SEVDESK
                : null,
        );
    }

    /**
     * WHMCS appends a release marker to stable builds. PHP's generic version
     * comparison treats that marker as a prerelease, so compare the numeric
     * core for official "-release.N" versions only.
     */
    public static function supportsWhmcsVersion(string $version): bool
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
        $normalised = $matches[1];

        return version_compare($normalised, '8.13.4', '>=')
            && version_compare($normalised, '9.0.0', '<');
    }

    /**
     * Optional profiles are intentionally fail-closed until confirmed. That
     * safe state is actionable information, not a broken installation.
     */
    public static function taxProfileFailureStatus(string $decisionCode, bool $optional): string
    {
        if ($optional && $decisionCode === 'unconfirmed_tax_profile') {
            return 'warning';
        }

        return 'error';
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
        } catch (Throwable) {
            // The visible health result below remains actionable.
        }

        return defined('WHMCS_VERSION') ? (string) WHMCS_VERSION : 'unbekannt';
    }

    /**
     * @param list<array<string,mixed>> $checks
     */
    private function add(
        array &$checks,
        string $name,
        bool $ok,
        string $message,
        string $failureStatus,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
        ?string $details = null,
    ): void {
        $status = $ok ? 'healthy' : $failureStatus;
        $checks[] = [
            'name' => $name,
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'summary' => $message,
            'description' => $message,
            'details' => $details,
            'action_url' => $actionUrl,
            'action_label' => $actionLabel,
        ];
    }
}
