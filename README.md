# WHMCS-sevDesk-Modul für sevDesk-Update 2.0

Dieses Repository ersetzt ein nicht mehr gepflegtes WHMCS-sevDesk-Modul. Das Addon ist für WHMCS 8.13.4 und PHP 8.3 gebaut. Es übernimmt vorhandene Zuordnungen und exportiert WHMCS-Rechnungen als Belege nach sevDesk.

> Code und Release-Paket liegen vor, sind aber noch nicht für Produktivdaten freigegeben. Automatisierte Tests prüfen die Fachlogik sowie das HTTP- und Persistenzverhalten. Auch die statische Analyse des PHP-Codes läuft durch.
>
> Vor der Freigabe müssen in der Zielumgebung noch der echte MariaDB-Lauf, der WHMCS-8.13.4-Runtime-Test, die lesende API-Prüfung und der sevDesk-Canary aus [docs/operations.md](docs/operations.md) erfolgreich sein. Bis dahin bleibt `sync_enabled` ausgeschaltet.

## Wichtige Begriffe

„sevDesk-Update 2.0“ bezeichnet die Buchhaltungslogik mit `taxRule`, `accountDatev` und strenger Kontenprüfung. Es ist **keine API unter `/api/v2`**. Die API-Basis bleibt:

```text
https://my.sevdesk.de/api/v1
```

Die mitgelieferte [OpenAPI-Spezifikation](docs/sevdesk-openapi.yaml) ist die technische Referenz für Endpunkte und Payloads.

## Festgelegter Umfang

Das Modul muss in Version 2.0.0:

- unter WHMCS 8.13.4 und PHP 8.3 ohne ionCube laufen;
- den bestehenden Modulnamen `sevdesk`, die funktionalen Addon-Einstellungen und `mod_sevdesk` weiterverwenden;
- WHMCS-Rechnungen zunächst als sevDesk-`Voucher` samt WHMCS-PDF anlegen;
- Einzel- und Massenexporte als persistente, wiederaufnehmbare Jobs verarbeiten;
- pro Rechnung ein nachvollziehbares Ergebnis speichern und nach Einzelfehlern weiterarbeiten;
- doppelte Belege durch bestehende Zuordnungen, Reservierung und Abgleich verhindern;
- `taxRule`, `accountDatev` und Steuersatz vor dem Schreiben über `ReceiptGuidance` prüfen;
- Hooks kurz halten: Sie planen Arbeit ein, werfen aber keine API-Fehler in den WHMCS-Kern zurück;
- vollständige Zahlungen und echte Teilzahlungen über einen serverseitig paginierten Buchungsassistenten in zwei Schritten buchen;
- eine ausdrücklich bestätigte WHMCS-Rückzahlung als eigenen negativen Korrektur-Voucher mit geprüften Positionen und stabilen Reconciliation-Markern anlegen;
- API-Token und personenbezogene Daten in Logs schützen.

Nicht enthalten sind:

- eine `/api/v2`-Anbindung;
- eine Rücksynchronisation von sevDesk nach WHMCS und sevDesk-Webhooks;
- eine externe Queue, ein zusätzlicher Dienst oder ein eigenes Framework;
- automatische OSS-Voucher, weil die sevDesk-Regeln 18 bis 20 für Voucher nicht unterstützt werden;
- automatische Refund-, Chargeback-, Gutschrift- oder Storno-Verarbeitung ohne Einzelfallprüfung;
- die Lizenzprüfung des Vorgängermoduls;
- unscharfes Zahlungs-Matching nach Name oder ungefähr passendem Betrag.

`BookingService` mit der Jobaktion `book_payment` und `CorrectionService` mit `correction_voucher` gehören zu Release 2.0.0.

Bei Zahlungen erstellt das Modul zunächst eine rein lesende Vorschau. Eine Buchung ist nur zulässig, wenn WHMCS-Referenz, Betrag und Währung übereinstimmen, Mapping und Voucher-Saldo seit der Bestätigung unverändert sind und genau eine ungebuchte sevDesk-Banktransaktion zugeordnet werden kann.

Bei Korrekturen prüft das Modul die Auswahl zunächst nur lokal. Ein Administrator bestätigt einzelne Fälle und reiht sie als Jobs ein. Vor dem Schreiben validiert der Worker jeden Kandidaten erneut.

Korrektur-Voucher entstehen nie automatisch. Ein Administrator muss eine einzelne WHMCS-Rückzahlung auswählen. Das Modul erzeugt daraus einen negativen Revenue-Voucher und schützt ihn mit einem gehashten Refund-Marker vor Duplikaten. Chargebacks bleiben blockiert.

Nach einem Kontakt- oder Korrektur-POST mit unbekanntem Ergebnis darf die Recovery nur lesen. Eine leere Suche beweist nicht, dass der POST fehlgeschlagen ist. Deshalb darf das Modul den Kontakt oder Voucher nicht automatisch erneut anlegen.

## Warum der Rewrite nötig ist

Für den Rewrite waren vor allem diese Probleme ausschlaggebend:

1. Das verschlüsselte Modul wurde für PHP 8.1 gebaut und blockiert unter PHP 8.3 bereits die Addon-Seiten.
2. EU-B2C-Rechnungen konnten mit einer B2B-Steuerregel an sevDesk gesendet werden. sevDesk quittierte die unzulässige Kombination aus Steuerregel und Konto mit HTTP 422.
3. Massenexporte liefen in einem einzigen Browser-Request. Proxy- oder PHP-Timeouts, nicht abgefangene Fehler und unklare Zwischenzustände machten große Nachläufe unsicher.

Der [Legacy-Datenvertrag](docs/legacy-analysis.md) beschreibt das Fehlerbild und die für Migration und Recovery relevanten Altzustände.

## Technischer Aufbau

Das WHMCS-Addon nutzt die vorhandene Zuordnungstabelle weiter. Bulk-Jobs liegen in der WHMCS-Datenbank und werden in kleinen Batches vom WHMCS-Cron verarbeitet.

Es gibt keinen separaten Queue-Server. Die Admin-Oberfläche startet und beobachtet Jobs; sie hält nicht den langen Export-Request offen.

## Dokumentation

| Dokument                                                   | Inhalt                                                   |
| ------------------------------------------------------------| ----------------------------------------------------------|
| [docs/architecture.md](docs/architecture.md)               | Zielarchitektur, Datenmodell, Zustände und Fehlergrenzen |
| [docs/legacy-analysis.md](docs/legacy-analysis.md)         | Legacy-Datenvertrag und relevante Altzustände            |
| [docs/sevdesk-api-and-tax.md](docs/sevdesk-api-and-tax.md) | API-Vertrag, Steuerklassifikation und blockierte Fälle   |
| [docs/implementation-plan.md](docs/implementation-plan.md) | Feste Umsetzungsschritte mit Abnahmekriterien            |
| [docs/testing.md](docs/testing.md)                         | Teststrategie und Release-Gates                          |
| [docs/operations.md](docs/operations.md)                   | Installation, Nachlauf, Monitoring und Störungsbehebung  |
| [docs/sevdesk-openapi.yaml](docs/sevdesk-openapi.yaml)     | Lokal abgelegte offizielle sevDesk-OpenAPI-Spezifikation |

## Daten- und Repository-Sicherheit

Lokale Arbeitsunterlagen, Datenbankexporte und Restore-SQL gehören nicht zum Repository. `.gitignore` schließt sie aus. Sie dürfen weder in Commits noch in Issues oder Logs landen.

Nicht ins Repository gehören:

- sevDesk-API-Token oder Lizenzschlüssel;
- WHMCS-Konfigurationsdateien;
- unredigierte SQL-/TSV-Exporte;
- Kundenadressen, Namen, E-Mail-Adressen oder Rechnungs-PDFs;
- vollständige API-Requests oder -Responses mit personenbezogenen Daten.

Produktive Bestandszahlen, Zeiträume, Statusverteilungen und konkrete Einstellungswerte werden nicht im Repository dokumentiert.

## Anforderungen und Quickstart

Für den Betrieb sind WHMCS 8.13.4, PHP 8.3 für Web und Cron sowie eine von WHMCS unterstützte MariaDB- oder MySQL-Version nötig. Außerdem braucht es einen regelmäßig laufenden WHMCS-Cron und einen sevDesk-Mandanten mit Update 2.0.

Vorgehen beim Drop-in-Wechsel:

1. Datenbank, bisherigen Modulordner und Addon-Settings sichern.
2. Das neue Verzeichnis `modules/addons/sevdesk` atomar einspielen und das Addon aktivieren oder upgraden.
3. Bestehende Einstellungen auf der internen Addon-Seite **Einrichtung** prüfen. Das allgemeine WHMCS-Konfigurationsformular enthält keine operativen Felder; `sync_enabled` bleibt nach dem Upgrade ausgeschaltet.
4. Health Check und vollständigen Dry-Run ausführen. `NULL`-Mappings, Orphans, EU-B2C, Guthaben und Refunds getrennt klären.
5. Eine einfache deutsche Rechnung als bestätigten Canary exportieren und Beleg, PDF, Konto, `taxRule`, Betrag und Mapping in beiden Systemen prüfen.
6. Erst danach kleine Zeiträume, anschließend quartalsweise Jobs und zuletzt die automatischen Hooks aktivieren.

Der vollständige Ablauf für Installation, Recovery und Rollback steht in [docs/operations.md](docs/operations.md). Das Release-Archiv baut `tools/build-release.sh 2.0.0` aus einer Positivliste. Lokale Arbeitsdaten, Tests und `vendor/` landen dadurch nicht im Paket.

## Bekannte Freigabegrenzen

- Kein automatischer OSS-Voucher; EU-B2C ist standardmäßig blockiert.
- EU-B2B Rule 3 ist standardmäßig blockiert und kann nur für bestätigte innergemeinschaftliche Warenlieferungen an Organisationen mit USt-ID und `taxexempt` freigegeben werden. Hosting und andere Dienstleistungen bleiben manuelle Prüffälle.
- Bei Rechnungen mit Kundenguthaben muss der volle Rechnungsbrutto-Voucher einzeln bestätigt werden. Das Guthaben wird nicht anteilig vom Umsatz abgezogen. Chargebacks und negative Transaktionen ohne eindeutige Klassifikation bleiben blockiert.
- Keine sevDesk→WHMCS-Synchronisation, Webhooks, Remote-Löschung oder automatische Festschreibung.
- WHMCS 9 und ein späterer sevDesk-Invoice-/E-Rechnungsmodus sind noch nicht freigegeben.
- Für die Freigabe mit Produktivdaten fehlen noch der echte MariaDB-Lauf, der WHMCS-8.13.4-Runtime-Test, die lesende API-Prüfung und der sevDesk-Canary.

## Implementierungsstand

Der aktuelle Stand umfasst:

1. PHP-8.3-fähiges Addon-Grundgerüst und additive Datenbankmigration.
2. Lesender API- und `ReceiptGuidance`-Check.
3. Reine Steuer- und Payload-Logik mit Regressionstests.
4. Sicherer Einzelexport in einen sevDesk-Testmandanten.
5. Persistente Bulk-Jobs und WHMCS-Cron-Worker.
6. Hooks und Admin-Oberfläche.
7. Zweistufiger Buchungsassistent und manuelle negative Korrektur-Voucher auf derselben Job-Infrastruktur.
8. Recovery-Werkzeuge und Buchhaltungsnachlauf in überschaubaren Abschnitten.

Syntaxprüfung, PSR-12, PHPStan, PHPUnit und der Positivlisten-Build laufen lokal durch. Die offenen Freigabetests stehen unter „Bekannte Freigabegrenzen“ und sind in [docs/operations.md](docs/operations.md) beschrieben.

## Steuerliche Freigabe

Die sevDesk-Prüfung bestätigt nur die technische Gültigkeit, nicht die steuerliche Behandlung. Vor dem Produktivlauf muss ein Steuerberater die Matrix für EU-B2B, Drittland, Reverse Charge und EU-B2C außerhalb der freigegebenen Nicht-OSS-Regel bestätigen.

Nach einem 401 oder 403 nimmt der Worker keine weiteren Items an, bis die Zugangsdaten unter **Einrichtung** erfolgreich geprüft wurden. Rechnungen ohne eindeutige Steuerklassifikation bleiben blockiert und müssen manuell geprüft werden.
