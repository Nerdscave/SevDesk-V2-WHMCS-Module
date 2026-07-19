# Arbeitsregeln für dieses Repository

Diese Regeln gelten für das gesamte Repository und für alle, die den Rewrite umsetzen oder prüfen.

## Auftrag

Baue ein wartbares Drop-in-Replacement für ein nicht mehr gepflegtes WHMCS-sevDesk-Modul. Der Buchhaltungsnachlauf soll keine Belege doppelt anlegen, nicht vom Browser abhängen und nach einem Fehler mit der nächsten Rechnung fortfahren.

## Vor jeder Änderung

Lies mindestens:

1. `README.md`
2. `docs/architecture.md`
3. `docs/sevdesk-api-and-tax.md`
4. den für die Aufgabe passenden Abschnitt in `docs/implementation-plan.md`
5. `docs/testing.md`

Bei Migration, Deployment oder Recovery ist zusätzlich `docs/operations.md` Pflicht. Für Legacy-Annahmen gilt `docs/legacy-analysis.md`.

## Feste Entscheidungen

- Zielplattform ist WHMCS 8.13.4 mit PHP 8.3.
- Das Addon bleibt unter dem Modulnamen `sevdesk` installierbar.
- Die API-Basis bleibt `https://my.sevdesk.de/api/v1`.
- „sevDesk-Update 2.0“ meint die Buchhaltungssemantik mit `taxRule` und `accountDatev`, nicht eine `/api/v2`.
- Release 2.0.0 arbeitet Voucher-first: WHMCS-Rechnung plus PDF werden als sevDesk-`Voucher` erfasst. Release 2.1.0 ergänzt die ausdrücklich freizugebenden Modi `invoice_for_oss` und `invoice_only`; Upgrade-Default bleibt `whmcs + voucher_only + OSS blocked`.
- `mod_sevdesk` bleibt als bestehende Invoice-zu-sevDesk-Zuordnung erhalten. Niemals ohne ausdrückliche Freigabe droppen, leeren oder neu aufbauen.
- Der Rewrite benötigt keine externe Lizenzprüfung und keine Remote-Abhängigkeiten, die nicht zum Export gehören.
- Bulk-Arbeit wird in der vorhandenen WHMCS-Datenbank persistiert und über den WHMCS-Cron in kurzen Worker-Läufen verarbeitet. Eine externe Queue ist nicht vorgesehen.
- OSS-Steuerregeln 18 bis 20 sind für Voucher technisch nicht unterstützt. Release 2.1.0 erlaubt ausschließlich Rule 19 für bestätigte elektronische/digitale EU-B2C-Leistungen über eine normale sevDesk-`Invoice`, hinter Betreiberprofil und bestandenem Testmandanten-Canary; Rules 18/20 und unklare oder gemischte Leistungen bleiben blockiert.
- Die Dokumenthoheit bleibt standardmäßig bei WHMCS. sevDesk-Hoheit ist ausschließlich mit `invoice_only`, WHMCS-Proforma, installiertem Theme-Adapter und ausdrücklicher Betreiberbestätigung zulässig; WHMCS bleibt Billing- und Zahlungsplattform.
- Release 2.1.0-rc.2 ergänzt ausschließlich sevDesk-natives ZUGFeRD für neue deutsche B2B-Invoices mit Rule 1. Voraussetzung sind `invoice_only`, sevDesk-Hoheit, Kunden-Opt-in über ein Admin-Tickbox-Feld, Aktivierungsdatum und ein separat bestandener E-Invoice-Canary. Rule 19, Rules 18/20, B2G/XRechnung und historische E-Rechnungs-Backfills bleiben ausgeschlossen.
- Bestehende Mappings werden bei einem Modus- oder Hoheitswechsel nicht konvertiert oder neu exportiert. Voucher bleiben Voucher; die für eine vorhandene Invoice eingefrorene Hoheit bleibt erhalten.
- Historische Backfills sind mailfrei und erzeugen keine E-Rechnungen. Vor einem Invoice-Create blockiert jeder mögliche Treffer nach Rechnungsnummer, Datum, Kontakt, Betrag oder Voucher-Marker die automatische Neuanlage.
- Invoice-Mappings sind im `CorrectionService` blockiert, bis ein eigener bestätigter `CreditNote`-Pfad geplant und umgesetzt ist.
- Ein signaturloser, strukturell abweichender oder neu aktivierter Bestand startet mit `runtime_review_required=on`. Hooks, Runner und Remote-fähige Adminaktionen bleiben bis zur erfolgreichen, ausdrücklich bestätigten Setup-Prüfung gesperrt.
- `BookingService` und die Jobaktion `book_payment` gehören zu Release 2.0.0. Zuerst erstellt das Modul eine rein lesende Vorschau. Nach der Auswahl validiert der Worker den Kandidaten erneut und ruft erst dann abhängig vom persistierten Dokumenttyp `/Voucher/{id}/bookAmount` oder `/Invoice/{id}/bookAmount` auf.
- Eine Zahlung ist nur buchbar, wenn WHMCS-Transaktionsreferenz, Betrag, Währung, offener Voucher und genau eine ungebuchte sevDesk-`CheckAccountTransaction` eindeutig übereinstimmen. Kein Fuzzy Matching.
- Zahlungskandidaten stammen seitenweise aus positiven `tblaccounts`-Transaktionen nach Transaktionsdatum. Sowohl vollständig bezahlte als auch noch offene, teilbezahlte Rechnungen sind zulässig; Refunds und Chargebacks bleiben ausgeschlossen.
- Vor `bookAmount` müssen das aktuelle vollständige `mod_sevdesk`-Mapping einschließlich Dokumenttyp und der aktuelle bereits gebuchte Dokumentbetrag exakt dem bestätigten Snapshot entsprechen.
- `CorrectionService` und `correction_voucher` gehören zu Release 2.0.0. Sie erzeugen nach ausdrücklicher Einzelfallbestätigung einen negativen Revenue-Voucher für genau eine ausgewählte WHMCS-Rückzahlung.
- Automatische Refund-/Chargeback-Ausführung oder Massenerzeugung, allgemeine Gutschriften, Stornos und `CreditNote`-Fallbacks bleiben ausgeschlossen. Die UI darf Rückzahlungen zur manuellen Auswahl anzeigen; Chargebacks werden blockiert.
- Eine Rücksynchronisation von sevDesk nach WHMCS und sevDesk-Webhooks gehören nicht zu Release 2.0.0.

Ändere diese Entscheidungen nur nach ausdrücklicher Nutzerfreigabe und dokumentiere die Änderung als Architekturentscheidung.

## Lokale private Arbeitsdaten

Ignorierte lokale Arbeitsdaten gehören nicht zum Projektbestand. Öffne oder verarbeite sie nicht. Nimm sie weder ins Staging noch in Commits oder Patches auf und gib ihre Inhalte nicht in Tool-Ausgaben wieder. Dazu gehören Supportunterlagen, Exporte, Dumps, Backups, Zugangsdaten und andere lokale Analyseartefakte.

Für Legacy-Annahmen gilt allein `docs/legacy-analysis.md`.

`docs/sevdesk-openapi.yaml` ist eine versionierte Referenz. Ändere sie nicht nebenbei. Ein Update braucht einen eigenen Commit mit Quelle, Abrufdatum und Prüfung der relevanten Breaking Changes.

## Datenbankregeln

- Migrationen sind additiv, wiederholbar und transaktional, soweit WHMCS/MySQL dies zulassen.
- Übernimm bestehende funktionale Einstellungen aus `tbladdonmodules`. Unbekannte oder nicht mehr verwendete Werte dürfen ungenutzt bleiben, bei einem normalen Upgrade aber nicht ungefragt gelöscht werden.
- `mod_sevdesk.invoice_id` und `mod_sevdesk.sevdesk_id` bleiben eindeutig.
- Eine Zeile mit `sevdesk_id = NULL` ist kein erfolgreicher Import. Sie ist ein abgebrochener Legacy-Zustand und muss über Recovery behandelt werden. Der Rewrite erzeugt neue Mappingzeilen erst mit bestätigter Remote-ID.
- Ein Upgrade darf weder vorhandene Zuordnungen noch den konfigurierten Kontakt-Custom-Field-Bezug verlieren.
- Führe ignorierte Restore- oder Dump-Dateien nie auf einem laufenden System aus. Sie können veraltete Daten, Zugangswerte oder destruktive Anweisungen enthalten.
- Neue Jobtabellen enthalten nur die für Ablauf, Bestätigung und Diagnose nötigen Daten. `candidate_json` darf die unveränderliche Booking-Bestätigung oder die ausdrücklich bestätigten Korrekturpositionen enthalten. Keine vollständige Originalinvoice, Kundenadressen, PDFs oder API-Rohpayloads duplizieren.

## Steuer- und API-Regeln

- Kapsle die Steuerklassifikation als deterministischen Domänencode und teste sie separat. Verteile diese Logik nicht auf Controller oder Templates.
- Nutze `taxRule` am Beleg. Voucher-Positionen benötigen `accountDatev`; Invoice-v1 übernimmt bewusst kein benutzerdefiniertes `accountDatev`.
- Prüfe Konto, Steuerregel, Steuersatz und Dokumenttyp gegen `ReceiptGuidance`, bevor ein Voucher geschrieben wird.
- Account-Datev-IDs sind mandantenspezifisch. Trage deshalb keine IDs aus Analysen, Tests oder anderen sevDesk-Mandanten fest im Code ein.
- Ein EU-Land allein macht einen Kunden nicht zu EU B2B. Dafür sind die festgelegten B2B-Nachweise nötig. Andernfalls gilt der Fall als B2C oder muss manuell geprüft werden.
- Rule 3 bleibt standardmäßig gesperrt. Sie darf nur für eine Organisation mit USt-ID und WHMCS-`taxexempt` verwendet werden, wenn zusätzlich ein Profil für innergemeinschaftliche Warenlieferungen bestätigt ist. Hosting und andere Dienstleistungen sind nicht freigegeben.
- Bei unklaren Fällen darf das Modul nicht buchen. Es erzeugt stattdessen einen verständlichen Prüffall.
- Fehler mit HTTP 422 aufgrund der Daten- oder Steuerlogik werden nicht automatisch wiederholt. Netzwerkfehler sowie Antworten mit 429 oder 5xx dürfen begrenzt und mit Backoff wiederholt werden.
- Verwende explizite Verbindungs- und Request-Timeouts sowie einen aussagekräftigen `User-Agent`.
- API-Token nur im `Authorization`-Header senden und in jeder Logausgabe redigieren.
- Der normale Invoice-Export blockiert negative Positionen weiterhin. Negative Beträge dürfen nur im ausdrücklich bestätigten `CorrectionService`-Pfad entstehen. Dort werden positive Eingabepositionen erst nach der Tax-/Guidance-Prüfung in negative Voucher-Positionen übersetzt.
- Der Buchungsassistent verarbeitet nur positive Zahlungen. Refunds und Chargebacks dürfen nicht an `book_payment` gelangen.

## Zuverlässigkeit und Idempotenz

- WHMCS-Hooks dürfen keine Integrationsfehler in Rechnungserstellung, Checkout oder Zahlung zurückwerfen.
- Hooks planen Arbeit ein und kehren schnell zurück. Sie führen keinen langen Export im Benutzerrequest aus.
- Ein Bulk-Job muss nach Browserende, Proxy-Timeout und Prozessabbruch weiterlaufen können.
- Fehler werden pro Rechnung abgegrenzt. Ein Fehler beendet nicht den Batch.
- Prüfe vor jedem Remote-Write die vorhandene Zuordnung. Reserviere die aktive Aktion atomar über den eindeutigen Item-`dedupe_key`.
- Nach einem Abbruch zwischen Remote-Erstellung und lokalem Mapping zuerst remote abgleichen; nie blind erneut anlegen.
- Worker nutzen ein Advisory Lock, kurze Leases und Checkpoints vor jedem nicht sicher wiederholbaren Write. Läuft die Lease in einem sicher wiederholbaren Schritt ab, wechselt das Item zu `retry_wait`. Nach einem möglicherweise erfolgten Remote-Write wechselt es zu `ambiguous`.
- `book_payment` persistiert `booking_write_requested` vor `bookAmount` und `booking_completed` nach einem verifizierten Ergebnis. Eine alte oder veränderte Vorschau muss neu erzeugt werden.
- `correction_voucher` persistiert `correction_voucher_write_requested`, `correction_voucher_created` und `correction_mapping_persisted`. Nach unbekanntem Write-Ausgang wird zuerst anhand der Marker abgeglichen.
- Nach `contact_write_requested` oder einem Korrektur-Write-Checkpoint darf die Recovery nur noch lesen. Bleibt die Suche ohne Treffer, beweist das nicht, dass der POST fehlgeschlagen ist. Das Modul darf deshalb keinen zweiten Create auslösen.
- Normale Voucher tragen `[WHMCS-INVOICE:<id>]`. Korrektur-Voucher tragen zusätzlich einen gehashten Refund-Marker und die ID des Original-Vouchers.
- Bei der Reconciliation darf das Modul nur einen vollständig passenden Marker-Treffer zuordnen. Fehlt der Treffer oder gibt es mehrere oder widersprüchliche Treffer, darf es kein Mapping automatisch überschreiben.
- Itemstatus sind ausschließlich `pending`, `running`, `retry_wait`, `succeeded`, `skipped`, `permanent_failed`, `ambiguous` und `cancelled`. `manual_review` ist eine UI-Aktion oder ein Fehlercode, kein Status.
- Erfolg wird nur nach bestätigter Remote-Erstellung und gespeichertem `sevdesk_id` gemeldet.
- `import_only_paid` und `import_after` erzeugen explizite Skip-Ergebnisse, keinen scheinbaren Erfolg.

## Implementierungsstil

- Bevorzuge WHMCS-Bordmittel: Addon-Hooks, Cron, Capsule/Query Builder, Local API, Smarty und die bereits mit WHMCS verfügbare HTTP-Infrastruktur.
- Das Ergebnis bleibt ein einzelnes deploybares Addon. Microservices, Message Broker, WebSockets, Event Sourcing und eine generische Sync-Plattform gehören nicht dazu.
- Führe eine Abstraktion nur ein, wenn sie eine tatsächliche Grenze kapselt: sevDesk-HTTP, Steuerentscheidung, Persistenz/Job-Claim oder Exportablauf.
- PHP-8.3-Code bekommt klare Typen, kleine Methoden und fachliche Kommentare an den Stellen, an denen das „Warum“ nicht aus dem Code hervorgeht.
- Keine Kommentare, die lediglich die nächste Codezeile wiederholen.
- Entry-Point-Funktionen müssen die WHMCS-Konventionen erfüllen. Interne Klassen dürfen Namespaces und strikte Typen verwenden.
- Fange `Throwable` an Prozessgrenzen ab und übersetze Fehler in eigene, bereinigte Ergebnisdaten. Verschlucke Fehler nicht.
- Schreibe keine Roh-Requests oder Roh-Responses mit PII in Logs.
- Baue keine Konfigurationsoption für jeden internen Zahlenwert. Sichere Standardwerte gehören in Code; nur betriebsrelevante Werte kommen ins UI.
- Operative Einstellungen werden ausschließlich über die modulinterne Seite `a=setup` geändert. `sevdesk_config()` darf diese Werte nicht als ungeschütztes WHMCS-Standardformular veröffentlichen.
- Ein 401/403-Alarm gilt für den gesamten sevDesk-Mandanten. Danach claimt der Runner keine weiteren Items. Erst eine erfolgreiche Read-only-Prüfung der Zugangsdaten im Setup hebt die Sperre auf.

## Tests und Nachweise

Jede fachliche Änderung braucht passende Tests. Mindestens abzudecken sind:

- die beiden dokumentierten Tax-Rule-Regressionen;
- alle unterstützten und alle dokumentiert blockierten Steuerklassen;
- Migration mit bestehenden, verwaisten und `NULL`-Mappings;
- Idempotenz und konkurrierende Jobs für dieselbe Invoice;
- Abbruch nach Reservierung, PDF-Upload, Remote-Erstellung und vor Mapping-Update;
- 401, 422, 429, 5xx, Netzwerk-Timeout und ungültige Responses;
- ein großer synthetischer Bulk-Lauf, dessen Fortschritt nicht am Browserrequest hängt;
- Buchungsvorschau mit keinem, genau einem und mehreren Treffern sowie Änderung zwischen Vorschau und Bestätigung;
- `book_payment` mit Checkpoints, eindeutigem Ergebnis und unbekanntem Write-Ausgang;
- `correction_voucher` mit expliziter Bestätigung, Positions-/Summenprüfung, Chargeback-Blockade und markerbasierter Reconciliation;
- Prüfung, dass Hooks trotz sevDesk-Ausfall den WHMCS-Ablauf nicht stören;
- Prüfung, dass Logs keine Token oder unnötige Kundendaten enthalten.

Nutze keine echten Kundendumps als Test-Fixtures. Erzeuge kleine synthetische Datensätze mit denselben Strukturmerkmalen.

## Dokumentationspflicht

Aktualisiere bei jeder Änderung die passende Datei:

- Architektur oder Datenzustände: `docs/architecture.md`
- API-/Steuerverhalten: `docs/sevdesk-api-and-tax.md`
- Reihenfolge oder Scope: `docs/implementation-plan.md`
- Tests und Release-Gates: `docs/testing.md`
- Deployment oder Störungsbehebung: `docs/operations.md`
- neu bestätigte Legacy-Fakten: `docs/legacy-analysis.md`

Dokumentiere Entscheidungen und Grenzen, nicht jede triviale Implementierungszeile.

## Abschlusscheck für Agenten

Vor der Übergabe:

1. `git status --short` auf versehentlich erfasste private Dateien prüfen.
2. Sicherstellen, dass `docs/sevdesk-openapi.yaml` nur bei einem ausdrücklich beauftragten Spezifikationsupdate verändert wurde.
3. Syntax-, Unit-, Integrations- und relevante Failure-Tests ausführen.
4. Migration gegen eine bereinigte Legacy-Struktur testen.
5. Diff auf Token, lokale Pfade, PII, Debug-Ausgaben und destruktive SQL prüfen.
6. Restliche Risiken und manuelle Prüffälle konkret benennen.
