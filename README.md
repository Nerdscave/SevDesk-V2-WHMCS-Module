# WHMCS-sevDesk-Modul für sevDesk-Update 2.0

Dieses Repository enthält ein Drop-in-Replacement für ein nicht mehr gepflegtes WHMCS-sevDesk-Modul. Das Addon ist für WHMCS 8.13.4 und PHP 8.3 gebaut. Es übernimmt vorhandene Zuordnungen und verarbeitet Exporte sowie Zahlungsbuchungen in persistenten Cron-Jobs.

Die wählbaren Invoice-Modi gehören zu 2.1.0. Der aktuelle Stand ist `2.1.0-rc.1` und nur für Testsysteme vorgesehen. Der Voucher-, Booking- und Korrekturumfang aus 2.0.0 bleibt erhalten.

> Der Invoice-API-Canary in einem echten sevDesk-Testmandanten steht noch aus. Bis er bestanden ist, bleibt `invoice_canary_confirmed` ausgeschaltet. Bei neuen Installationen und Freigaberollouts bleibt auch `sync_enabled` aus.
>
> Das Upgrade behält `voucher_only` bei, setzt aber einmalig `runtime_review_required=on`. Hooks, Worker und Remote-fähige Adminaktionen bleiben gesperrt, bis der übernommene Bestand im Setup geprüft und freigegeben wurde.

## Drop-in-Upgrade vom bisherigen Modul

Der Rewrite verwendet weiterhin den Addon-Namen und Ordner `sevdesk`. Erkennt WHMCS den Versionswechsel, ruft es den Upgrade-Callback auf. Die Migration ergänzt Tabellen und Defaults, lässt aber alle bestehenden `mod_sevdesk`-Zeilen und unbekannten `tbladdonmodules`-Werte unverändert. Sie sendet auch keine Anfrage an sevDesk.

Fehlt die Laufzeitsignatur des Rewrites, speichert das Modul vor der ersten Schemaänderung Review-Status, Quarantäne-Token und ungültige Signatur in einer Transaktion. Danach schaltet es die automatische Synchronisation aus. Diese Prüfung läuft auch beim ersten Wechsel von 2.0.0 auf 2.1, weil Tabellen und Settings allein die Herkunft des Bestands nicht belegen.

Migration und Runner verwenden dieselbe Datenbanksperre. Ein laufender Batch prüft sie vor jedem weiteren Item erneut.

Nach der Strukturprüfung setzt das Modul die gültige Signatur, lässt den Bestand aber im Review. Erst die Setup-Prüfung von Mandant und Token, Kontaktfeld, Konten, Steuerprofilen, Dokumentmodus und offenen Jobs hebt die Sperre nach einer ausdrücklichen Bestätigung auf. `sync_enabled` bleibt ein eigener Schalter und darf weiterhin aus bleiben.

Das alte Addon darf vor diesem Dateitausch nicht über WHMCS deaktiviert werden: Sein Deaktivierungs-Callback ist nicht Teil des geprüften Datenvertrags. Das ursprüngliche Installationspaket muss außerdem auf zusätzliche globale Hooks, Includes oder Crondateien geprüft werden. Davon darf nach dem Wechsel nichts parallel weiterlaufen. Die mit jedem Release ausgelieferte [UPGRADE-Anleitung](modules/addons/sevdesk/UPGRADE.md) enthält die vollständige Checkliste.

Die im ausgewählten WHMCS-Kunden-Custom-Field gespeicherte sevDesk-Kontakt-ID bleibt beim Ersatz maßgeblich:

- ist die ID remote vorhanden, wird genau dieser Kontakt wiederverwendet; das Modul sucht keinen Ersatz, legt kein Duplikat an und überschreibt weder ID noch Kontaktdaten;
- fehlt die Remote-ID, bleibt der Export als Klärfall stehen; es gibt keinen stillen Fallback auf Suche oder Neuanlage;
- nur bei leerem Feld sucht das Modul exakt nach der WHMCS-Kundennummer. Genau ein Treffer wird verknüpft und mehrere Treffer werden blockiert. Bei keinem Treffer wird ein neuer Kontakt nur angelegt, wenn der Betreiber zuvor im Setup ausdrücklich bestätigt hat, dass `customerNumber=<interne WHMCS-Client-ID>` für Neuanlagen verwendet werden darf.

Fehlt dagegen bereits die konfigurierte Custom-Field-ID oder verweist sie nicht mehr auf ein WHMCS-Kundenfeld, stoppt der Vorgang vor dem Laden oder Anlegen eines sevDesk-Kontakts. Eine fehlerhaft übernommene Feldkonfiguration kann dadurch kein Duplikat erzeugen.

Der Rewrite synchronisiert Stammdaten eines bereits verknüpften sevDesk-Kontakts bewusst nicht automatisch. Betreiber, die die im Originalmodul angebotene laufende Kontaktaktualisierung genutzt haben, müssen diese Abgrenzung vor dem Wechsel berücksichtigen. Der genaue Upgradeablauf und die Prüfmatrix stehen in [docs/operations.md](docs/operations.md).

Ein leeres Kontaktfeld beweist nicht, dass kein Alt-Kontakt existiert. Ohne gespeicherte ID erkennt das Modul nur den exakten sevDesk-Wert `customerNumber=<WHMCS-Client-ID>`. Alt-Kontakte mit fehlender oder anderer Kundennummer müssen vor der Freigabe inventarisiert und verknüpft werden.

Die Bestätigung `customer_number_contact_creation_confirmed` ist nach einem Upgrade aus. Ohne sie bleibt ein leeres Suchergebnis als `contact_creation_not_confirmed` blockiert. Die vorhandene Token-Einstellung wird nicht gelöscht. Ihre Bezeichnung und eine mögliche anbieterspezifische Speicherung müssen jedoch im Setup geprüft werden; falls nötig, wird der Token dort neu eingegeben.

### Was „Drop-in“ hier bedeutet

„Drop-in“ bezeichnet die technische Austauschgrenze: gleicher Addon-Name und -Ordner, Erhalt der Zuordnungs- und Settingtabellen und eine additive Migration. Das neue Modul startet gesperrt und schreibt erst nach der Bestandsprüfung nach sevDesk.

Nicht jede Funktion des bisherigen Moduls wurde unverändert nachgebaut. Vor dem Wechsel gilt deshalb diese Funktionsmatrix:

| Bisher genutzte Funktion | Stand im Rewrite |
| --- | --- |
| automatischer Belegexport | vorhanden, aber erst nach Canary und bewusstem Aktivieren von `sync_enabled` |
| Massenimport | vorhanden als persistenter Cron-Job; historische Importe versenden nie automatisch |
| Kontaktanlage und Wiederverwendung gespeicherter IDs | vorhanden mit den oben beschriebenen fail-closed Regeln |
| laufende Aktualisierung vorhandener Kontakte | nicht enthalten; vorhandene Stammdaten bleiben unangetastet |
| automatische Zahlungszuordnung/-buchung | nicht funktionsgleich: nur exakte read-only Vorschau und ausdrückliche Einzelfallbestätigung |
| individuelle Buchungskonten je Produkt | nicht enthalten; Konten werden je bestätigtem Steuerprofil konfiguriert |
| Fremdwährungen | nicht freigegeben; Dokumentexport ist derzeit EUR-only |
| sevDesk-V1-Mandant | nicht kompatibel; Ziel ist ausschließlich sevDesk-Update 2.0 und Sync bleibt bis zur separaten Mandantenmigration aus |
| beliebige WHMCS-8.x-/PHP-Kombination | nicht zugesichert; Ziel ist ausschließlich WHMCS 8.13.4 mit PHP 8.3 |

Diese Abgrenzung bezieht sich auf die [aktuell öffentlich beschriebene Funktionsliste des bisherigen Moduls](https://becker-software.de/de/modules/sevdesk). Nutzt eine Installation eine nicht abgedeckte Funktion, bleibt die automatische Synchronisation aus, bis ein eigener Migrations- oder Erweiterungsweg entschieden ist.

Ein Dateirückwechsel zum Originalmodul ist nur vor neuen Invoice-Schreibvorgängen und nach vollständiger Bestandsprüfung vertretbar. Sobald ein Mapping den Typ `invoice` trägt oder ein Invoice-Write unklar geblieben ist, darf das Originalmodul nicht wieder auf diesen Bestand losgelassen werden: Es kennt den Dokumenttyp nicht und könnte die Remote-ID als Voucher behandeln. In diesem Zustand bleibt der Rewrite deaktiviert installiert, bis ein eigener Downgrade-Pfad vorliegt.

## Wichtige Begriffe

„sevDesk-Update 2.0“ bezeichnet die Buchhaltungslogik mit `taxRule`, `accountDatev` und strenger Kontenprüfung. Es ist **keine API unter `/api/v2`**. Die API-Basis bleibt:

```text
https://my.sevdesk.de/api/v1
```

Die versionierte [OpenAPI-Spezifikation](docs/sevdesk-openapi.yaml) bleibt die technische Referenz. Sie wird im Rahmen der Invoice-Erweiterung nicht verändert.

## Dokumentmodi

OSS erzwingt keinen vollständigen Wechsel von Voucher zu Invoice. Das Modul bietet drei globale Exportmodi:

| Modus | Ziel |
| --- | --- |
| `voucher_only` | alle freigegebenen Fälle bleiben im bisherigen Voucher-Pfad |
| `invoice_for_oss` | nur ausdrücklich bestätigte digitale EU-B2C-Leistungen mit Rule 19 werden Invoice; alle anderen unterstützten Fälle bleiben Voucher |
| `invoice_only` | alle vom freigegebenen Invoice-Vertrag unterstützten Steuerfälle werden Invoice |

Die Dokumenthoheit ist davon getrennt:

- `whmcs`: WHMCS-Rechnung und WHMCS-PDF bleiben kundenseitig maßgeblich. Eine sevDesk-Invoice wird ohne Kundenversand geöffnet.
- `sevdesk`: nur zusammen mit `invoice_only`. WHMCS bleibt Billing-, Proforma- und Zahlungsplattform; nach Zahlung ist allein die von sevDesk erzeugte Invoice-PDF als Endrechnung sichtbar.

Upgrade-Default und sichere Grundstellung bleiben `whmcs + voucher_only + OSS blocked`; bestehende Mappings werden nicht neu exportiert. Der neue Laufzeitnachweis pausiert automatische Exporte beim ersten 2.1-Upgrade einmalig bis zur Betreiberprüfung. Invoice-Ziele sind immer paid-only und benötigen eine finale WHMCS-Rechnungsnummer.

OSS-v1 unterstützt ausschließlich Rule 19 für vom Betreiber ausdrücklich als vollständig elektronisch/digital bestätigte EU-B2C-Rechnungen. Beschreibungen werden nicht heuristisch klassifiziert. Rules 18 und 20, gemischte oder unklare Leistungsarten sowie sonstige nicht freigegebene Steuerfälle bleiben blockiert.

## Technischer Umfang

Das Modul:

- verwendet den Modulnamen `sevdesk` und `mod_sevdesk` weiter;
- wählt vor jedem Remote-Write unveränderlich `voucher` oder `invoice`; ein fehlgeschlagener Voucher wird nie als Fallback zur Invoice;
- legt Voucher mit der WHMCS-PDF und Invoice-Dokumente über den normalen sevDesk-`Invoice`-Vertrag an;
- lässt sevDesk die offizielle Invoice-PDF erzeugen und lädt dafür keine WHMCS-PDF als Ausgangsrechnung hoch;
- verarbeitet Einzel-, Bulk-, Booking- und Korrekturvorgänge als persistente, wiederaufnehmbare Jobs;
- verhindert Cross-Type-Duplikate weiterhin mit dem bestehenden Dedupe-Schlüssel `export_voucher:<invoiceId>`;
- prüft vor jedem Erfolg Remote-Dokument, Positionen, Kontakt, Nummer, Rule und Summen;
- verarbeitet vollständige und eindeutige Teilzahlungen über den zweistufigen `BookingService` für den persistierten Dokumenttyp;
- erzeugt ausdrücklich bestätigte Rückzahlungen weiterhin nur als negative Korrektur-Voucher; Invoice-Mappings bleiben dafür blockiert, bis ein eigener `CreditNote`-Pfad freigegeben ist;
- hält WHMCS-Hooks kurz: Sie planen Arbeit ein und werfen keine sevDesk-Fehler in den WHMCS-Kern zurück;
- schützt Token, PDF-Inhalte und personenbezogene Daten vor Job- und Fehlerlogs.

Nicht enthalten sind eine `/api/v2`-Anbindung, sevDesk→WHMCS-Synchronisation, sevDesk-Webhooks, eine externe Queue, automatische Chargebacks, Rules 18/20, E-Rechnungen, Produktklassifikation, dauerhafte PDF-Spiegelung und ein Invoice-`CreditNote`-Pfad.

## Warum der Rewrite nötig ist

Das frühere Modul ist nicht mit PHP 8.3 kompatibel, konnte EU-B2C fälschlich als B2B klassifizieren und führte große Nachläufe in einem einzigen Browserrequest aus. Der Rewrite trennt Steuerentscheidung, persistente Arbeit und Remote-Recovery. [docs/legacy-analysis.md](docs/legacy-analysis.md) beschreibt die relevanten Altzustände ohne produktive Bestandsdaten.

## Invoice-Lifecycle und Versand

Eine normale sevDesk-Invoice wird als `RE` im Draft-Status 100 mit der unveränderten finalen WHMCS-Rechnungsnummer, dem Marker `[WHMCS-INVOICE:<id>]`, `SevUser`, `Unity`, Land, Währung und geprüften Positionen erstellt. Invoice-Positionen verwenden in der ersten Version Menge 1 und übernehmen kein frei konfiguriertes `accountDatev`.

Bei WHMCS-Dokumenthoheit bleibt die WHMCS-PDF maßgeblich. sevDesk-Dokumenthoheit setzt WHMCS-Proforma und die automatische Einreihung neuer Rechnungen voraus. Vor Zahlung sieht der Kunde die Proforma, danach zunächst einen neutralen Pending-Zustand und nach erfolgreicher Finalisierung den authentifizierten sevDesk-PDF-Download.

Während einer Review-, Authentifizierungs- oder Sync-Pause wird in diesem Modus keine WHMCS-Endrechnung als Ersatz versendet. Trifft ein Paid-Ereignis während eines Authentifizierungsalarms ein, speichert das Modul nur den deduplizierten lokalen Pending-Job. Der Runner verarbeitet ihn erst nach einer erfolgreichen Mandantenprüfung. Eine dauerhafte PDF-Kopie in WHMCS gibt es in dieser Version nicht.

Für eine kundenseitige Zustellung gibt es zwei explizite Wege:

- `sevdesk`: sevDesk öffnet und versendet die Invoice mit konfiguriertem Betreff und Text.
- `whmcs_template`: Das Modul öffnet die Invoice, lädt die geprüfte PDF und übergibt sie über eine aktive benutzerdefinierte WHMCS-Invoice-Mailvorlage. Der Binäranhang existiert nur in einem zufälligen, einmaligen In-Memory-Kontext.

Bulk- und historische Importe versenden nie automatisch. Nach einem möglicherweise ausgeführten Create-, Open- oder Versand-Write wird ausschließlich lesend reconciliiert; ein nicht beweisbarer Ausgang bleibt `ambiguous` und wird nicht automatisch wiederholt.

## Datenmodell und Zuverlässigkeit

`mod_sevdesk` bleibt die einzige verbindliche Invoice-zu-sevDesk-Zuordnung. Additiv kommen `document_type`, `document_number`, `document_ready_at`, `delivered_at` und `pdf_sha256` hinzu. Alte Mappings behalten zunächst `document_type = NULL`; der Typ darf erst nach read-only Prüfung und Adminbestätigung ergänzt werden. Legacy-Zeilen mit `sevdesk_id = NULL` bleiben Recovery-Fälle.

Neue Exporte verwenden die Jobaktion `export_document`, behalten aber absichtlich den historischen Dedupe-Namensraum. Riskante Writes haben eigene Checkpoints. Nach einem unklaren Create-, Open-, `bookAmount`- oder Versand-Ausgang darf kein zweiter Write blind erfolgen.

## Installation und Freigabe

1. Datenbank, bisherigen Modulordner und Addon-Settings sichern.
2. Das noch aktive Alt-Addon nicht deaktivieren, sondern das Verzeichnis `modules/addons/sevdesk` atomar ersetzen und anschließend den Upgrade-/Adminpfad des neuen Codes öffnen.
3. Auf der geschützten Seite **Einrichtung** die übernommene Kontaktfeld-ID und vorhandene Kontakt-IDs, Mandant/Token, Konten, Steuerprofile, Dokumentmodus sowie offene Jobs prüfen. Abgelaufene Worker-Leases werden dort rein lokal eingeordnet: sichere Fortsetzungen werden `retry_wait`, sichere abgebrochene Schritte `cancelled`, unbekannte Write-Ausgänge oder Abbrüche nach bestätigtem Remote-Effekt `ambiguous`.
4. Migration, Health Check, Dry-Run sowie Legacy- und `NULL`-Mappings prüfen und anschließend die angezeigte Bestandsfreigabe ausdrücklich bestätigen. Erst dann werden Runner und manuelle Jobaktionen wieder verfügbar; automatische Hooks folgen weiterhin separat `sync_enabled`.
5. Den bestehenden Voucher-Canary und anschließend den separaten Invoice-API-Canary aus [docs/operations.md](docs/operations.md) in einem Testmandanten durchführen.
6. Erst nach dokumentierter Freigabe `invoice_canary_confirmed` setzen, kleine Invoice-Batches testen und danach gegebenenfalls `sync_enabled` aktivieren.

Der Invoice-Canary muss unter anderem Rule 19, unveränderte Rechnungsnummer, Marker, Pflichtreferenzen, `sendBy`, `sendViaEmail`, `getPdf`, `/Invoice/{id}/bookAmount`, PDF-Stabilität und die ID-Eindeutigkeit zwischen Voucher und Invoice bestätigen. Kollidieren Remote-IDs oder sind Rule 19 beziehungsweise Marker nicht sicher abgleichbar, wird der gemischte Modus nicht freigegeben.

Der vollständige Ablauf für Installation, Recovery und Rollback steht in [docs/operations.md](docs/operations.md). Release-Archive werden weiterhin aus einer Positivliste gebaut und enthalten zusätzlich `LICENSE` sowie die eigenständige Moduldatei `UPGRADE.md`; lokale Arbeitsdaten, Tests und `vendor/` gehören nicht in das Paket.

## Daten- und Repository-Sicherheit

API-Token, WHMCS-Konfiguration, unredigierte Exporte/Dumps, Kundendaten, Rechnungs-PDFs und vollständige API-Rohpayloads gehören weder ins Repository noch in Supporttickets. Restore- oder Dump-Dateien sind keine Migrationsquelle und dürfen nicht auf einem laufenden System ausgeführt werden. Tests verwenden ausschließlich kleine synthetische Fixtures.

## Dokumentation

| Dokument | Inhalt |
| --- | --- |
| [docs/architecture.md](docs/architecture.md) | Architekturentscheidung, Datenmodell, Lifecycle und Fehlergrenzen |
| [docs/legacy-analysis.md](docs/legacy-analysis.md) | Legacy-Datenvertrag und untypisierte Altzuordnungen |
| [docs/sevdesk-api-and-tax.md](docs/sevdesk-api-and-tax.md) | Voucher-/Invoice-Vertrag, Steuerklassifikation und blockierte Fälle |
| [docs/implementation-plan.md](docs/implementation-plan.md) | Umsetzungsphasen und Rollout-Gates |
| [docs/testing.md](docs/testing.md) | Teststrategie und verbindliche Invoice-Canaries |
| [docs/operations.md](docs/operations.md) | Einrichtung, Nachlauf, Versand und Recovery |
| [docs/sevdesk-openapi.yaml](docs/sevdesk-openapi.yaml) | unveränderte lokale sevDesk-OpenAPI-Referenz |

## Freigabegrenzen

- Der externe Invoice-API-Canary wurde in diesem Repository nicht ausgeführt und bleibt ein hartes Gate.
- sevDesk-Dokumenthoheit verlangt `invoice_only`, WHMCS-Proforma, installierten Theme-Adapter und eine ausdrückliche Betreiberbestätigung.
- Der mitgelieferte Twenty-One-Adapter ersetzt die normalen sichtbaren Kundenlinks. Ein direkt erratener WHMCS-Core-PDF-Endpunkt kann ohne Core-Änderung technisch weiter erreichbar sein.
- Rule 3 bleibt ausschließlich für bestätigte innergemeinschaftliche Warenlieferungen an Organisationen mit USt-ID und `taxexempt` freigegeben; Hosting und andere Dienstleistungen bleiben blockiert.
- Rechnungen mit Guthaben, negative Positionen, Fremdwährungen und unklare Mischfälle folgen weiterhin ihren dokumentierten Blockierungs- oder Einzelfallregeln.
- WHMCS 9, Rules 18/20, E-Rechnungen, dauerhafte PDF-Spiegelung und Invoice-CreditNotes sind nicht freigegeben.

Die sevDesk-Prüfung bestätigt technische API-Gültigkeit, nicht die steuerliche Behandlung. Vor Produktivbetrieb muss die fachliche Matrix von Steuerberatung beziehungsweise Buchhaltung bestätigt werden. Nach 401/403 stoppt der Worker mandantenweit, bis eine erfolgreiche read-only Prüfung im Setup die Sperre aufhebt.
