<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Health;

use DateTimeImmutable;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Api\ApiException;
use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

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
        $whmcsCompatible = version_compare($whmcsVersion, '8.13.4', '>=') && version_compare($whmcsVersion, '9.0.0', '<');
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

        $pdfFunctions = defined('ROOTDIR') ? ROOTDIR . '/includes/invoicefunctions.php' : '';
        $this->add(
            $checks,
            'WHMCS-PDF-Funktion',
            $pdfFunctions !== '' && is_file($pdfFunctions),
            $pdfFunctions !== '' && is_file($pdfFunctions)
                ? 'Der absolute WHMCS-Pfad zur PDF-Erzeugung ist vorhanden; der Inhalt wird je Jobposition validiert.'
                : 'Die WHMCS-PDF-Funktionen wurden unter ROOTDIR nicht gefunden.',
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

                $guidance = $this->application->referenceData()->receiptGuidance(true);
                $this->add(
                    $checks,
                    'Receipt Guidance',
                    $guidance !== [],
                    count($guidance) . ' zulässige Erlöskonten-Kombinationen gelesen.',
                    'error'
                );
                $this->addTaxChecks($checks);
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
                'module_version' => '2.0.0',
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
        /** @var array<string, array{string,bool,?string,bool,bool,string,bool}> $profiles */
        $profiles = [
            'Deutschland / Rule 1' => ['DE', false, null, false, false, '19', false],
            'Drittland' => ['US', false, null, false, false, '0', false],
            'Kleinunternehmer' => ['DE', false, null, true, false, '0', false],
            'AddFunds' => ['DE', false, null, false, true, '0', false],
        ];
        if ($this->application->config->bool('eu_b2b_goods_confirmed')) {
            $profiles['EU B2B / Rule 3 (bestätigte Warenlieferung)'] = [
                'BE', true, 'BE0123456789', false, false, '0', true,
            ];
        }
        foreach ($profiles as $name => [$country, $exempt, $vat, $smallBusiness, $addFunds, $rate, $organisation]) {
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
                $decision->allowed ? 'warning' : 'error',
                'addonmodules.php?module=sevdesk&a=setup',
                'Steuerprofil prüfen',
                $decision->code
            );
        }

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

        $euB2cBlocked = $this->application->config->get('eu_b2c_mode', 'blocked') === 'blocked';
        $this->add(
            $checks,
            'EU B2C / OSS-Sperre',
            $euB2cBlocked,
            $euB2cBlocked
                ? 'EU-B2C bleibt ohne API-Versuch gesperrt.'
                : 'Deutsche USt wurde für EU-B2C ausdrücklich bestätigt; steuerliche Grundlage je Zeitraum prüfen.',
            $euB2cBlocked ? 'healthy' : 'warning'
        );
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
