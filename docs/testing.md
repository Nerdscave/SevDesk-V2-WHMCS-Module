# Teststrategie

## Ziel

Die Tests müssen vor allem zwei Schäden verhindern: falsche Buchungen und doppelte Belege. Reine Happy-Path-Abdeckung reicht nicht. Prozessabbruch, Timeout und unvollständige Remote-Antworten gehören zum normalen Testumfang.

Die Tests verwenden ausschließlich synthetische Kunden, Invoices und API-Fixtures. Private Dumps, echte PDFs, Token und Kundendaten sind als Testdaten verboten.

## Aktueller automatisierter Nachweis

- Die schnelle Unit-/Contract-/Kompositionstestsuite ist lokal grün;
- PHP-Lint und PSR-12 laufen über den vollständigen Modul- und Testbaum;
- PHPStan analysiert den vollständigen PHP-Modulcode auf Level 6;
- Die MariaDB-Integrationstests prüfen eine kleine synthetische Legacy-Struktur, echte Unique-Constraints, Deduplizierung, Candidate-/Remote-ID-Erhalt und parallele Claims;
- Dieselbe Suite deckt sichere und riskante Lease-/Throwable-Recovery, den globalen Auth-Stopp, WHMCS-Kundenwährungen, Teilzahlungs-Pagination, Mapping-Revalidation und einen 1.000-Item-Lauf mit Fehler in der Mitte ab;
- Ohne konfigurierten Server meldet die lokale MariaDB-Suite ihre Tests als `skipped`. In CI und bei einem Lauf über `tools/test-mariadb.sh` sind sie verpflichtend.

MariaDB und PHP 8.3 bleiben eigene Release-Gates. Ein übersprungener Datenbanktest oder ein Lauf unter einer anderen PHP-Version ersetzt diese Nachweise nicht.

## Testebenen

### 1. Unit-Tests

Schnelle Tests ohne WHMCS-Datenbank oder Netzwerk für:

- Steuerklassifikation;
- Eligibility (`Paid`, `import_after`, Sonderfälle);
- Konto-/Rule-/Rate-Matching gegen eine Guidance-Fixture;
- Netto-/Brutto- und Rundungslogik;
- Voucher-/Position-Payload;
- Fehlerklassifikation und Retry-Entscheidung;
- Statusübergänge von Job und Item;
- lazy Kontakt-Referenzdaten: bestehende/verknüpfte Kontakte dürfen weder
  Address-Kategorie noch CommunicationWay-Key vorab laden;
- Bereinigung sensibler Daten aus Fehlermeldungen.
- Health-Kompatibilität für die von WHMCS verwendete stabile Versionsform
  `8.13.4-release.N`, ohne Beta-/RC- oder WHMCS-9-Versionen freizugeben;
- bewusst unbestätigte optionale Steuerprofile erscheinen als Warnung und
  bleiben fachlich blockiert, während fehlerhafte bestätigte Profile den Health
  Check weiterhin als Fehler blockieren; ein global aktivierter
  Kleinunternehmermodus macht das zugehörige Profil verpflichtend.

Diese Logik darf nicht von globalem WHMCS-Zustand abhängen. Tabellengetriebene Tests bilden die fachliche Matrix lesbar ab.

### 2. Persistenz- und Migrationstests

Mit einer isolierten MySQL-/MariaDB-Datenbank:

- Neuinstallation ohne `mod_sevdesk`;
- Upgrade einer Legacy-Struktur;
- wiederholtes Upgrade;
- vollständige, `NULL`- und verwaiste Mappings;
- fehlende und bereits vorhandene Unique-Indizes;
- Job-/Item-Constraints und Pagination;
- eindeutiger `dedupe_key` bei überlappenden Jobs;
- MySQL Advisory Lock und atomarer Claim bei zwei Workern;
- Lease-Ablauf und Übernahme;
- Checkpoint-gesteuerte Entscheidung zwischen `retry_wait` und `ambiguous`;
- Merge eines Outcomes gegen die aktuelle Checkpoint-Zeile, damit ein veralteter
  Claim-Snapshot weder `whmcsClientId` noch Remote-ID oder Bestätigungskontext
  überschreibt;
- parallele Jobs für dieselbe Invoice;
- Erhalt aller Legacy-Zuordnungen bei Fehlern während der Migration.

Die Legacy-Fixture ist bewusst klein und vollständig synthetisch. Sie enthält vollständige, leere und verwaiste Mappings sowie künstliche Kollisionen.

### 3. API-Contract-Tests

Der HTTP-Client läuft in diesen Tests gegen einen Fake-Server oder Mock-Handler. Die Fixtures orientieren sich an `docs/sevdesk-openapi.yaml`.

Abzudecken sind:

- gültige Systemversion;
- `ReceiptGuidance` mit erlaubter und verbotener Kombination;
- Contact-Read/Create;
- PDF-Upload mit HTTP 201 und Dateiname;
- Voucher-Create mit HTTP 201 und Remote-ID;
- 400, 401, 403, 404, 409, 422, 429 und 5xx;
- Connect-Timeout, Read-Timeout, leere Antwort, ungültiges JSON und fehlende Pflichtfelder;
- `Retry-After` und begrenzter Backoff;
- Token-Redaktion in Exception, Log und Debug-Dump.

Contract-Tests prüfen, ob das Modul die Spezifikation wie vorgesehen interpretiert. Einen Test gegen einen echten sevDesk-Mandanten ersetzen sie nicht.

### 4. WHMCS-Integrationstests

In einer Testinstallation mit WHMCS 8.13.4 und PHP 8.3:

- Addon aktivieren, upgraden und deaktivieren;
- Settings-Seite mit sevDesk online und offline öffnen;
- prüfen, dass `sevdesk_config()` keine operativen Standardfelder veröffentlicht und Änderungen nur über die geschützte Setupseite möglich sind;
- Invoice und Client über die vorgesehenen WHMCS-Schnittstellen laden;
- WHMCS-PDF mit synthetischen Rechnungsdaten erzeugen;
- Adminrollen und CSRF prüfen;
- Single- und Bulk-Job starten;
- Admin-Rechnungsbutton öffnet den vorausgefüllten Einzelimport; der kompakte
  Kurzexport akzeptiert ausschließlich CSRF-geschützte POSTs und erzeugt nur ein
  dedupliziertes Jobitem;
- Cron/Worker ausführen;
- einen leeren CLI-Runner ausführen und bestätigen, dass er nur den Heartbeat
  aktualisiert, kein Item claimt und keinen sevdesk-Service konstruiert;
- relevante Invoice-, Paid- und Checkout-Hooks auslösen;
- mit `module_active=on` und `sync_enabled=off` bestätigen, dass InvoiceCreated,
  InvoicePaid, InvoiceRefunded, InvoiceCancelled und AddTransaction keine Jobs
  anlegen, während ein leerer oder manuell befüllter Runner weiterhin läuft;
- sicherstellen, dass Hook-Fehler niemals den WHMCS-Ablauf abbrechen.

### 5. End-to-End im sevDesk-Testmandanten

Für diese Tests ist ein separater Mandant mit sevDesk-Update 2.0 erforderlich. Die Tests legen dort echte Testobjekte an. Anschließend werden die Objekte gelöscht oder eindeutig als Testdaten gekennzeichnet.

Geprüft werden:

- Kontaktzuordnung;
- sichtbares PDF;
- Voucher-Datum, Beschreibung, Währung und Status;
- Positionen, Brutto-/Nettobeträge und Steuer;
- `taxRule` und `accountDatev` in der sevDesk-Oberfläche;
- Mapping zurück zur WHMCS-Invoice;
- zweiter Lauf ohne Duplikat;
- Recovery nach gezielt unterbrochenem Ablauf.

## Verbindliche Steuerfälle

| Testfall | Erwartung |
| --- | --- |
| DE, 19 %, brutto | Rule 1 und Guidance-kompatibles Inlandskonto |
| DE, 7 %, netto | Rule 1, korrekter Netto-/Steuerbetrag |
| EU B2C, keine Firma/USt-ID, nicht tax-exempt | niemals Rule 3; Rule 1 oder klar blockiert |
| EU B2B als Organisation mit USt-ID, `taxexempt` und bestätigter innergemeinschaftlicher Warenlieferung | Rule 3 ausschließlich mit Guidance-kompatiblem Konto |
| EU-Land mit `taxexempt`, aber fehlendem Nachweis | `permanent_failed` mit Review-Fehlercode |
| EU-B2B-Dienstleistung/Hosting oder nicht bestätigtes Warenprofil | blockiert, auch bei Firma und USt-ID |
| Guidance-Konto erlaubt nur `AUSFUHREN` | andere Rule wird lokal abgewiesen |
| Kleinunternehmer | Rule 11 und 0 % |
| OSS erforderlich | blockiert, kein Voucher-POST |
| Reverse Charge mit Voucher-inkompatibler Rule | blockiert |
| Drittland ohne eindeutige Leistungsart | blockiert |
| angewendetes Kundenguthaben | Bulk blockiert; Einzelfall nur als bestätigter voller Brutto-Voucher, keine proportionale Kürzung |
| Credit Note/Refund/Storno/AddFunds | manuelle Prüfung oder eigener bestätigter Sonderflow |
| Nullsumme oder negative Position | manuelle Prüfung |
| mehrere fachliche Steuerfälle | manuelle Prüfung |
| Fremdwährung ohne freigegebenen Flow | manuelle Prüfung |

Sobald der Steuerberater die Produktivmatrix bestätigt hat, wird sie als eigene Testtabelle aufgenommen. Ohne diese Tests gibt es keine Produktionsfreigabe.

## Failure-Injection-Matrix

Die Tests simulieren Abbrüche an diesen Stellen:

| Abbruchzeitpunkt | Erwartete Recovery |
| --- | --- |
| vor Mappingcheck | Item erneut ausführbar |
| nach Dedupe/Claim, vor Remote-Write | Lease übernehmen und über `retry_wait` erneut ausführen |
| vorhandenes Legacy-`NULL`-Mapping | `ambiguous`, kein automatischer Write |
| nach Kontaktanlage, vor Custom-Field-Update | Kontakt nur lesend suchen; sicheren Treffer übernehmen, sonst `ambiguous`; kein zweiter Kontakt |
| nach PDF-Upload, vor Voucher-POST | erneuter Versuch zulässig; temporäre Datei ist kein Voucher |
| während Voucher-POST ohne lesbare Response | `ambiguous`, Dedupe bleibt gesetzt, Remote-Suche vor Retry |
| während Korrektur-POST ohne lesbare Response, anschließend kein Markertreffer | `ambiguous`, strikt lesende Recovery, kein zweiter Voucher-POST |
| nach Remote-ID, vor lokalem Mapping | Remote finden und Mapping ergänzen |
| nach Mapping, vor Item-Erfolg | Mapping führt beim Resume zu `skipped/already_mapped` oder Erfolg |
| nach Item-Erfolg, vor Jobabschluss | Jobstatus aus Items neu berechnen |

Zusätzlich simulieren die Tests einen Prozess-Kill, einen PHP-`Error`, einen DB-Disconnect und zwei gleichzeitig laufende Worker.

## Bulk-Tests

Ein synthetischer Lauf mit mindestens 1.000 Items enthält:

- bereits gemappte Invoices;
- eligible Paid-Invoices;
- Unpaid- und Datums-Skips;
- permanente 422-Fehler;
- vorübergehende 429/5xx/Timeouts;
- als `permanent_failed` markierte OSS-/Credit-Prüffälle;
- zwei konkurrierende Jobs mit überlappenden Invoice-IDs.

Zu prüfen:

- Der Adminrequest zum Anlegen des Jobs führt keine sevDesk-API-Calls aus.
- Browserende oder Proxyabbruch ändert den serverseitigen Job nicht.
- Worker verarbeitet nur sein geclaimtes Batch und beendet sich innerhalb des vorgesehenen Zeitbudgets.
- Nach Neustart stimmen offene und abgeschlossene Itemzahlen.
- Jedes Item besitzt genau einen erklärbaren Endzustand.
- Ein Fehler in der Mitte verhindert spätere Items nicht.
- `pending`, `running`, `retry_wait`, `succeeded`, `skipped`, `permanent_failed`, `ambiguous` und `cancelled` werden korrekt gezählt.
- Jobs wechseln korrekt zwischen `pending`, `running`, `paused`, `completed`, `completed_with_errors` und `cancelled`.
- Retry-Obergrenze wird eingehalten.
- Ein 401/403-Alarm stoppt nach dem betroffenen Item alle weiteren Claims im selben und in späteren Runner-Läufen, bis das Setup ihn nach erfolgreicher Prüfung löscht.
- Erfolgreiche Invoices besitzen genau ein Mapping und einen Remote-Voucher.

## UI- und Bedienprüfung

Diese Punkte werden manuell oder mit passenden Browsertests geprüft:

- leere Suche, ungültige Datumsspanne und sehr großer Zeitraum;
- Pagination der Vorschau und Ergebnisliste;
- Buchungsvorschau über mehr als zehn positive Transaktionen sowie eine offene teilbezahlte Invoice;
- Booking-Worker blockiert verändertes Invoice-Mapping und geänderten bereits bezahlten Voucherbetrag vor `bookAmount`;
- Fortschritt nach Reload und in einer zweiten Adminsession;
- klare Trennung von Erfolg, Skip, Fehler und manueller Prüfung;
- gezielter Retry und Bestätigung bei Unlink/Cancel;
- keine unescaped API-Meldung im HTML;
- keine PII in URL oder Querystring;
- Rollen ohne Modulzugriff können weder Jobs lesen noch starten.
- Invoice-Control-Markup enthält kein verschachteltes Formular. Das externe
  Footer-Form enthält nur CSRF-Token und Invoice-ID; der Quick-Button verweist
  explizit darauf.
- Kurzexport bei vollständigem Mapping, Legacy-NULL, Guthaben, Fremdwährung,
  Null-/Negativbetrag, negativer oder fehlender Position, ungeeignetem Status und
  Rechnung vor `import_after` prüfen. Nur der normale Einzelimport bleibt als
  erklärender Preflight verfügbar.
- Mehrfachklick beziehungsweise zwei Adminsessions erzeugen dank aktivem
  `export_voucher:<invoiceId>`-Dedupe-Key keinen zweiten aktiven Export.
- Im Browserrequest des Kurzexports werden weder sevdesk-Client, Receipt Guidance,
  Kontaktauflösung, PDF noch Worker aufgerufen. Nach dem Queueing läuft die
  Verarbeitung auch bei geschlossenem Browser weiter.
- Eine im Worker lokal blockierte Rechnung darf vor ihrem Fehler weder PDF noch
  Receipt Guidance laden, PDF erzeugen noch einen neuen sevdesk-Kontakt anlegen.
- Ein vorhandener Checkpoint `contact_write_requested` muss seine ausschließlich
  lesende Recovery vor Mapping-, Status-, Stichtags-, Währungs- und Tax-Terminals
  ausführen. Ohne eindeutigen Kontakt bleibt das Item `ambiguous` und behält seine
  Dedupe-Reservierung.
- Ein vorübergehender Fehler derselben Recovery darf `retry_wait` verwenden, muss
  aber `contact_write_requested` beziehungsweise `contact_linked` als Checkpoint
  behalten; der nächste Versuch bleibt GET-only.
- In einer Testinstallation unter WHMCS 8.13.4 prüfen, ob
  `AdminInvoicesControlsOutput` auch im Admin-Nur-Ansehen-Modus ausgeführt wird.
  Falls nicht, bleibt dieser Modus ohne Button; eine undokumentierte globale
  DOM-Injektion ist kein bestandenes Release-Gate.
- Modul-CSS und -JavaScript werden auch dann geladen, wenn der Webserver direkte
  Requests auf `/modules/addons/sevdesk/assets` verweigert; auf anderen Adminseiten
  werden die Assets nicht eingebunden.
- Dashboard und Einrichtung bei breitem Desktop, schmalem WHMCS-Inhaltsbereich und
  mobiler Breite prüfen; kein Steuerprofil darf horizontal aus dem Inhalt laufen.
- Jede Info-Hilfe ausschließlich per Tastatur öffnen, schließen und fokussieren;
  der wesentliche Hinweis muss unabhängig vom Popover als sichtbarer Hilfetext
  lesbar bleiben.
- Die sechs Steuerprofile zeigen sprechende Kontonamen und AccountDatev-ID. Eine
  gespeicherte, aktuell nicht gelistete ID bleibt als Warnoption ausgewählt.
- Receipt-Guidance-Ausfall simulieren: Der numerische Konto-Fallback bleibt nutzbar
  und keine vorhandene Einstellung wird durch leere Auswahl überschrieben.
- Status-Badges und Warnungen ohne Farbwahrnehmung prüfen; Text oder Symbol müssen
  den Zustand eindeutig benennen.
- Modulnavigation bei 1180, 768 und 375 Pixeln prüfen: Alle Tab-Beschriftungen
  bleiben erhalten; bei wenig Platz bricht die Bootstrap-Tab-Leiste in weitere
  Zeilen um, ohne Beschriftungen auszublenden oder zu beschneiden.
- Navigation nur per Tastatur durchlaufen. Die Seitenlinks verwenden
  `aria-current="page"`; ARIA-Tabrollen bleiben echten In-Page-Tabpanels vorbehalten.

## Sicherheitsprüfungen

- Testtoken in jeder bekannten Log- und Exceptionform suchen.
- Request-/Response-Fixtures auf versehentliche Speicherung in Jobtabellen prüfen.
- XSS-Fixture in Positionsbeschreibung und sevDesk-Fehlermeldung verwenden.
- CSRF für Start, Retry, Cancel und Unlink testen.
- SQL-Eingaben ausschließlich gebunden/über Query Builder.
- Dateiuploadpfad und temporäre PDF-Dateien auf Berechtigungen und Cleanup prüfen.
- Abhängigkeiten auf bekannte Schwachstellen prüfen, sobald ein Composer-Setup existiert.

## Manuelle Buchhaltungsabnahme

Vor dem Nachlauf prüft die Buchhaltung je freigegebener Steuerklasse mindestens einen Canary-Voucher:

- richtiger Kunde/Kontakt;
- richtige Belegnummer und Datum;
- vollständiges PDF;
- korrekte Kontierung;
- korrekte Tax Rule und Steuersätze;
- korrekte Netto-, Steuer- und Bruttosumme;
- kein bereits vorhandener Doppelbeleg.

Anschließend gleicht die Buchhaltung die Summen jedes Nachlaufabschnitts zwischen WHMCS, Jobreport und sevDesk ab.

## Standardbefehle

Schnelle Tests und MariaDB-Suite laufen getrennt:

```bash
find modules/addons/sevdesk -name '*.php' -print0 | xargs -0 -n1 php -l
composer test
composer test:integration
tools/test-mariadb.sh
```

`composer test:integration` verbindet sich nur mit der Datenbank, wenn `SEVDESK_TEST_DB_HOST` gesetzt ist. Fehlt die Variable auf einem Entwicklungsrechner, meldet die Suite die Datenbanktests als `skipped`. Das zählt nicht als bestandener Persistenztest.

`tools/test-mariadb.sh` startet MariaDB 10.11 aus `docker-compose.test.yml`, wartet auf den Health Check und setzt alle Testvariablen. Danach führt das Skript die Suite aus und entfernt den Testcontainer wieder.

Die Integrationstests laden die echten Klassen `Migrator`, `MappingRepository` und `JobRepository`. Für die Tests ersetzt eine kleine Capsule-Bridge nur die Datenbank-Fassade, die WHMCS in Produktion bereitstellt.

`illuminate/database` ist ausschließlich als Composer-Entwicklungsabhängigkeit eingebunden. `vendor/`, Tests und Docker-Dateien gehören nicht zum Produktionspaket.

In CI läuft die Suite mit PHP 8.3 gegen einen separaten MariaDB-10.11-Service ohne MySQL Strict Mode. Der Datenbank-Host ist dort verpflichtend gesetzt. Die Suite kann die Datenbanktests deshalb nicht unbemerkt überspringen und den Lauf trotzdem als erfolgreichen Nachweis werten. Der deaktivierte Strict Mode entspricht den [WHMCS-8.13-Systemanforderungen](https://docs.whmcs.com/8-13/installation-guide/system-requirements/#mysql-database).

Die Persistenztests decken derzeit folgende Fälle ab:

- eine frische und eine wiederholte additive Migration;
- den unveränderten Erhalt einer kleinen synthetischen Struktur mit vollständigen, `NULL`- und verwaisten Mappings;
- den Abbruch bei kollidierenden Legacy-Mappings;
- globale Deduplizierung über überlappende Jobs und das Festhalten einer Reservierung bei `ambiguous`;
- Wiederaufnahme einer abgelaufenen sicheren Lease sowie Wechsel zu `ambiguous` nach einem möglichen Remote-Write;
- konkurrierende Claims in zwei PHP-Prozessen ohne doppelt geclaimtes Item.

## Release-Gates

Ein Release darf erst freigegeben werden, wenn:

1. Lint, Unit-, Persistenz-, Contract- und WHMCS-Integrationstests grün sind.
2. alle Tax-Regressionen und blockierten Fälle grün sind.
3. Failure-Injection und 1.000-Item-Bulk-Test grün sind.
4. sevDesk-E2E im Testmandanten grün ist.
5. Migration aus einer bereinigten Legacy-Struktur ohne Datenverlust läuft.
6. Token-/PII-Scan des Diffs und der Logs ohne Befund ist.
7. Buchhaltung die Canary-Fälle bestätigt hat.
8. Backup-, Rollback- und Recovery-Ablauf einmal geprobt wurden.

Offene Punkte in Steuerlogik, Idempotenz oder Mappingmigration blockieren das Release.
