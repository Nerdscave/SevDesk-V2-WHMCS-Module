# Implementierungsplan

## Ziel und aktueller Stand

Ziel ist ein PHP-8.3-fähiges Drop-in-Replacement, das den vorhandenen Mappingbestand schützt und auch große Nachläufe in wiederaufnehmbaren Jobs verarbeitet.

Alle folgenden Phasen sind im Repository umgesetzt: additive Migration, Steuer- und API-Kern, persistente Jobs, Cron-/CLI-Runner, Adminoberfläche, Reconciliation, Buchungsassistent und manuelle Korrektur-Voucher. Der Plan bildet die Grundlage für die Abnahme. Umgesetzt heißt noch nicht, dass das Modul bereits für Produktivdaten freigegeben ist.

Offen sind noch die Prüfungen, die eine echte Zielumgebung brauchen: der verpflichtende MariaDB-Lauf, Installation und Renderprüfung unter WHMCS 8.13.4/PHP 8.3, ein rein lesender Vertragstest im echten sevDesk-Mandanten sowie Canary-Belege mit Freigabe durch die Buchhaltung. Bis dahin bleibt `sync_enabled=off`.

## Feste Produktentscheidungen

- WHMCS 8.13.4, PHP 8.3
- Modulname `sevdesk`
- API-Basis `https://my.sevdesk.de/api/v1`
- sevDesk-Update-2.0-Payloads mit `taxRule` und `accountDatev`
- Voucher-first mit angehängtem WHMCS-PDF
- `mod_sevdesk` in-place weiterverwenden
- persistente Jobs in MySQL, verarbeitet über WHMCS-Cron
- keine externe Queue und kein zusätzlicher Dienst
- keine externe Lizenzprüfung
- OSS-Voucher blockieren
- zweistufiger Buchungsassistent in Release 2.0.0: `BookingService`, Jobtyp `payment_booking`, Aktion `book_payment`
- manuell bestätigter negativer Korrektur-Voucher in Release 2.0.0: `CorrectionService`, Jobtyp `refund_correction`, Aktion `correction_voucher`
- keine automatische Refund-, Chargeback-, Gutschrift- oder Storno-Verarbeitung; Chargebacks bleiben blockiert
- keine sevDesk→WHMCS-Rücksynchronisation und keine sevDesk-Webhooks

## Phase 0: Dokumentation und Sicherheitsgrundlagen

### Aufgaben

- Root-README durch Projektdokumentation ersetzen.
- Arbeitsregeln für zukünftige Agenten festhalten.
- private Arbeitsunterlagen und Exporte über `.gitignore` ausschließen.
- Legacy-Datenvertrag, API-Begriffe, Steuergrenzen, Architektur, Tests und Betrieb dokumentieren.
- offizielle OpenAPI-Datei unverändert als Referenz bewahren.

### Exit-Kriterium

- Alle Dokumente verweisen konsistent auf `/api/v1` und sevDesk-Update 2.0.
- Keine Zugangsdaten oder PII sind im Git-Diff.
- Private Arbeitsdaten werden nicht als Projektdateien erfasst.
- Fachlich offene Punkte blockieren die Umsetzung, bis eine dokumentierte Entscheidung vorliegt.

## Phase 1: PHP-8.3-Addon-Grundgerüst

### Aufgaben

- Verzeichnisstruktur unter `modules/addons/sevdesk/` als eigenständige PHP-8.3-Implementierung neu aufbauen.
- WHMCS-Einstiegspunkte `config`, `activate`, `upgrade`, `deactivate` und Admin-Ausgabe implementieren.
- Autoloading innerhalb der WHMCS-Laufzeit sauber aufsetzen.
- Minimalen Dashboard- und Health-Endpunkt ohne Remote-Write bereitstellen.
- Lizenzfelder aus dem Laufzeitpfad entfernen.
- Die allgemeine WHMCS-Addon-Konfiguration rein lokal halten und dort keine operativen Felder veröffentlichen. Weder das Scannen noch das Speichern darf API- oder Lizenzanfragen auslösen oder die geschützte Setupvalidierung umgehen.
- Alle Fehler am WHMCS-Einstiegspunkt in bereinigte Adminmeldungen übersetzen.

### Tests

- PHP-Lint unter 8.3.
- Addon- und Einstellungsseite unter WHMCS 8.13.4 mit gültigem, fehlendem und falschem Token öffnen.
- Aktivieren, deaktivieren und erneut aktivieren, ohne bestehende Tabellen zu verändern.

### Exit-Kriterium

- Keine Abhängigkeit vom bisherigen Modul oder von externen Lizenzdiensten.
- Addon- und Settings-Seiten laden auch bei sevDesk-Ausfall ohne WHMCS-500.
- Noch kein Codepfad kann einen Voucher schreiben.

## Phase 2: Additive Datenbankmigration und Kompatibilität

### Aufgaben

- Existenz und tatsächliches Schema von `mod_sevdesk` prüfen.
- Tabelle nur bei Neuinstallation legacy-kompatibel erstellen.
- fehlende Unique-Indizes sicher ergänzen, ohne Daten vorab zu löschen.
- `mod_sevdesk_jobs` und `mod_sevdesk_job_items` aus `docs/architecture.md` idempotent anlegen.
- funktionale Legacy-Settings lesen; Lizenzfelder ignorieren.
- Diagnose für vollständige, `NULL`- und verwaiste Mappings bauen.
- Migration bei inkonsistenten Daten abbrechen und einen Report liefern, statt automatisch zu „reparieren“.

### Tests

- leere Neuinstallation;
- kleine synthetische Legacy-Struktur mit vollständigen, leeren und verwaisten Mappings;
- wiederholtes Upgrade;
- mehrere `NULL`-Mappings, verwaiste Invoice-IDs und synthetisch kollidierende IDs;
- Rollback des Codes bei erhaltenen additiven Tabellen.

### Exit-Kriterium

- Alle vorhandenen Mappingzeilen und Remote-IDs sind nach dem Upgrade unverändert.
- Wiederholtes Aktivieren/Upgraden ist ein No-op.
- Kein `DROP`, `TRUNCATE` oder ungeprüftes Massen-`DELETE` ist Teil des normalen Pfads.

## Phase 3: Read-only API, Health und Konfigurationsprüfung

### Aufgaben

- schmalen sevDesk-Client mit Token-Redaktion, `User-Agent`, Connect- und Request-Timeout bauen.
- Fehler in stabile Kategorien übersetzen.
- Systemversion über `/Tools/bookkeepingSystemVersion` prüfen.
- `ReceiptGuidance` lesen und funktionale Konto-Settings gegen den Mandanten validieren.
- Health-Seite für PHP, WHMCS, DB-Schema, Token, Systemversion, Konten und jüngste Jobfehler bereitstellen.
- Nur die Felder aus API-Responses parsen, die das Modul tatsächlich benötigt.
- Bei 401/403 den betroffenen Job pausieren und den globalen Auth-Alarm setzen. Danach darf der Runner weder im aktuellen noch in späteren Läufen weitere Items claimen. Der Alarm wird erst nach einer erfolgreichen Setupprüfung gelöscht.

### Tests

- Contract-Tests gegen Fixtures aus der OpenAPI-Struktur.
- 200 mit gültiger Struktur sowie 401, 403, 422, 429, 5xx, Timeout, ungültiges JSON und fehlende Pflichtfelder.
- Token und Header in Logs redigiert.

### Exit-Kriterium

- Health kann eine falsche Systemversion oder ungültige Konten klar benennen.
- Kein API-Fehler verlässt den Addon-Controller als ungefangener `Throwable`.
- Es gibt weiterhin keinen Remote-Write.

## Phase 4: Steuerentscheidung und Payload-Builder

### Aufgaben

- normalisierte, unveränderliche Eingabedaten aus WHMCS-Invoice und Client ableiten.
- Tax-Resolver gemäß `docs/sevdesk-api-and-tax.md` implementieren.
- unterstützte, blockierte und manuelle Fälle explizit modellieren.
- Account-Datev, Rule und Rate gegen Guidance prüfen.
- Netto-/Brutto-Beträge und Rundungen deterministisch berechnen.
- Voucher- und VoucherPos-Payload ohne HTTP-Abhängigkeit bauen.
- keine Account-ID oder Tax Rule in Controller/Templates verstreuen.

### Tests

- deutscher Standardfall;
- synthetischer EU-B2C-Regressionsfall;
- unzulässige Konto-/Regel-Kombination aus `ReceiptGuidance`;
- Kleinunternehmer;
- B2B mit fehlenden/widersprüchlichen Nachweisen;
- OSS, Credit, Nullsumme, negative Position im normalen Invoice-Export, gemischte Steuerfälle und Fremdwährung als blockierte/manuelle Fälle;
- angewendetes Kundenguthaben im Bulk blockieren und im Einzelfall nur nach gespeicherter Bestätigung als vollen Rechnungsbrutto-Voucher zulassen; niemals proportional kürzen;
- Inclusive/Exclusive Tax und Cent-Rundung.

### Exit-Kriterium

- Gleiche Eingabe erzeugt stets dieselbe Entscheidung und denselben Payload.
- Kein unklarer Fall erzeugt einen Write-Payload.
- Die vom Steuerberater freigegebene Matrix ist als Testfall abgebildet. Ohne diese Freigabe endet die Phase vor Produktion.

## Phase 5: Sicherer Einzelexport im Testmandanten

### Aufgaben

- bestehendes Mapping und Legacy-`NULL`-Zustände zuerst prüfen.
- Invoice über den eindeutigen Item-`dedupe_key` atomar für genau eine aktive Aktion reservieren.
- Kontaktzuordnung aus dem konfigurierten WHMCS-Custom-Field prüfen und unter den festgelegten Schutzmaßnahmen anlegen.
- WHMCS-PDF erzeugen und hochladen.
- Voucher mit Status 100 anlegen.
- Remote-ID dauerhaft speichern und erst danach Erfolg melden.
- Für jeden dokumentierten Abbruchpunkt eine Recovery-Regel implementieren.
- Single-Import im Adminbereich als Ein-Item-Job ausführen, nicht als langer Sonderpfad.

### Tests

- erfolgreicher Export in einen separaten sevDesk-Testmandanten;
- bereits gemappte Invoice;
- paralleler Start derselben Invoice;
- Abbruch vor Dedupe/Claim, nach Claim, nach Contact-Write-Checkpoint, nach PDF-Upload, nach Voucher-Write-Checkpoint und vor Mapping-Update;
- Remote-Erfolg bei verlorener Response;
- PDF-Fehler und ungültiger Kontakt.

### Exit-Kriterium

- Jeder Erfolg besitzt genau ein vollständiges Mapping.
- Ein wiederholter Start erzeugt keinen zweiten Voucher.
- Ein unbekannter Remote-Ausgang setzt das Item auf `ambiguous`. Die UI verlangt einen manuellen Abgleich; ein automatischer Retry ist ausgeschlossen.
- Der Testmandant enthält für die freigegebenen Fixtures die erwarteten Voucher, Konten, Regeln, Raten und PDFs.

## Phase 6: Persistente Bulk-Jobs und Worker

### Aufgaben

- Bulk-Suche nach Rechnungsdatum mit serverseitiger Pagination und Eignungsvorschau bauen.
- Auswahl als unveränderliche Job-Items persistieren.
- Worker-Claim mit MySQL Advisory Lock, Item-Lease und atomarem Statuswechsel implementieren.
- Checkpoints vor Contact-, Voucher- und späteren nicht sicher wiederholbaren Writes persistieren.
- dieselbe Lease-/Checkpoint-Infrastruktur für `booking_write_requested` und `correction_voucher_write_requested` verwenden.
- globale aktive Deduplizierung über `dedupe_key` aus Aktion und passender Geschäftsreferenz anwenden: Invoice-ID, gehashte Zahlungsreferenz oder gehashte Refund-Referenz.
- Batchgröße und internes Zeitbudget begrenzen.
- Retry-Policy mit `retry_wait` und `available_at` für sichere Netzwerkfehler, 429 und 5xx implementieren.
- 4xx-Fachfehler als `permanent_failed` und unbekannte Write-Ausgänge als `ambiguous` beenden.
- Cron-Integration so bauen, dass überlappende Läufe sicher sind.
- abgelaufene Leases wiederaufnehmen und unbekannte Writes vorher abgleichen.

### Tests

- mindestens 1.000 synthetische Items mit gemischten Ergebnissen;
- Browser wird nach Jobstart geschlossen;
- Proxy-Request endet, während Cron weiterarbeitet;
- Worker-Prozess stirbt in jedem relevanten Schritt;
- zwei Cronläufe überlappen;
- ein Item liefert 422, das nächste wird trotzdem verarbeitet;
- Retry-Obergrenze und Backoff sind deterministisch testbar.

### Exit-Kriterium

- Fortschritt hängt nicht vom Browserrequest ab.
- Nach Neustart werden offene Items fortgesetzt.
- Ein fehlerhaftes Item beendet weder Batch noch Job.
- Kein Item bleibt ohne erklärbaren Zustand zurück.

## Phase 7: Admin-UX und sichere Hooks

### Aufgaben

- Jobliste mit Status, Start-/Endzeit und gruppierten Ergebniszahlen bauen.
- paginierte Itemliste mit Invoice-Link, Ergebniscode, Kurzmeldung und Retry-/Review-Aktion bereitstellen.
- gezielten Retry nur für `permanent_failed` und nach Abgleich für `ambiguous` erlauben.
- Die Bulk-Vorschau trennt eligible, skipped, fachlich blockiert und bereits gemappt. „Blockiert“ bleibt eine Vorschaukategorie oder ein Fehlercode und ist kein Itemstatus.
- CSRF- und Adminrollenprüfung für alle Mutationen.
- relevante WHMCS-Hooks registrieren; Hooks deduplizieren und ausschließlich Jobs einplanen.
- In der Admin-Rechnungsbearbeitung einen normalen Link zur vorausgefüllten
  Einzelimport-Vorprüfung und einen kompakten Kurzexport anbieten. Der Kurzexport
  ist POST-/CSRF-geschützt, arbeitet nur mit dem bereits gespeicherten
  Rechnungsstand, legt ausschließlich ein dedupliziertes Jobitem an und führt im
  Browserrequest keinen sevdesk-Aufruf aus.
- Das Quick-POST-Formular außerhalb des WHMCS-Rechnungsformulars über den
  Admin-Footer ausgeben; der Invoice-Control-Hook darf kein verschachteltes
  `<form>` erzeugen. Bekannte Reviewfälle, vollständige Mappings und Legacy-NULL
  werden fail-closed behandelt.
- Den separaten Admin-Nur-Ansehen-Modus in einem Kompatibilitätstest unter
  WHMCS 8.13.4 prüfen. Ohne dokumentierten Output-Hook wird keine allgemeine
  DOM-Injektion eingebaut.
- Mapping-Manager mit bestätigtem lokalen Unlink und ohne implizites Remote-Löschen bereitstellen.
- Modul-CSS und Vanilla-JavaScript über `AdminAreaHeadOutput` beziehungsweise
  `AdminAreaFooterOutput` in die authentifizierte WHMCS-Antwort einbetten. Direkte
  öffentliche Asset-URLs sind dafür keine Voraussetzung.
- Die Einrichtung zeigt sechs benannte Steuerprofile als getrennte Karten. Das
  Erlöskonto wird aus der Receipt-Guidance-Auswahl gewählt; TaxRule, Freigabestatus,
  Anwendungsgrenze und Blockierungsgrund bleiben dabei sichtbar.
- Eine gespeicherte Konto-ID, die in der aktuellen Guidance fehlt, muss als
  ausgewählte Warnoption erhalten bleiben. Ist die Guidance nicht erreichbar,
  bleibt ein numerischer Fallback verfügbar; Speichern darf keine bestehende ID
  unbemerkt verwerfen.
- Kontextinformationen sind mit Tastatur und Maus erreichbar und werden zusätzlich
  als sichtbarer Hilfetext angeboten. Steuerliche Freigaben bleiben explizite
  Checkboxen und werden nicht durch die Kontoauswahl impliziert.
- Die Modulrouten erscheinen als klassische Registerkarten mit sichtbarer aktiver
  Kante. Sie bleiben semantisch normale Seitenlinks mit `aria-current`; bei wenig
  Platz bricht die Leiste um und blendet keine Beschriftungen aus.
- Die Admin-UI verwendet die Bootstrap-3-Markupkonventionen des WHMCS-Admin-Themes
  (nav-tabs, Panels, Tabellen, Labels, Alerts) statt eines eigenen Designsystems.
  Ein kleines, unter `.sd-admin` gescoptes Rest-Stylesheet ergänzt nur, was das
  Theme nicht mitbringt; die `data-*`-Attribute bleiben der stabile Vertrag
  zwischen Templates und JavaScript.

### UX-Abnahme

- Nutzer sieht während eines Laufs Gesamt, offen, laufend, erfolgreich, übersprungen, fehlgeschlagen und manuell zu prüfen.
- Fehlertext nennt konkrete Rechnung und Ursache, aber keine Token/PII.
- Seite kann neu geladen werden, ohne Job oder Auswahl zu verlieren.
- Ein mehrfach ausgelöster Kurzexport besitzt höchstens ein aktives Jobitem; die
  Rechnungsseite meldet Queueing, vorhandenen Job, Mapping oder Blockierung klar.
- Der normale Rechnungsbutton öffnet die Einzelimportseite mit vorausgefüllter
  Invoice-ID. Der Kurzexport übernimmt keine noch ungespeicherten Änderungen.
- Ein abgelaufener Proxyrequest erzeugt keinen unbekannten Exportzustand.
- Dashboard und Einrichtung bleiben bei schmalen Adminfenstern lesbar; Status wird
  nicht ausschließlich durch Farbe vermittelt.
- Alle Steuerprofile lassen sich ohne Kenntnis interner AccountDatev-IDs auswählen,
  sofern Receipt Guidance verfügbar ist.
- Der aktive Navigationstab ist auch nach einem Seitenwechsel in einer horizontal
  gescrollten Leiste sichtbar und vollständig per Tastatur erreichbar.

### Exit-Kriterium

- sevDesk-Ausfall in einem Hook beeinträchtigt Invoice-Erstellung, Checkout oder Zahlung in WHMCS nicht.
- Jobfortschritt und Endbericht stimmen mit der Itemtabelle überein.
- Alle mutierenden Adminaktionen sind autorisiert und bestätigt.

## Phase 8: Buchungsassistent und manuelle Korrektur-Voucher

Der Buchungsassistent und die manuellen Korrektur-Voucher gehören zu Release 2.0.0. Beide verwenden die vorhandenen Jobs, Leases, Dedupe-Keys und Ergebniszustände des Invoice-Exports. Im Browser gibt es dafür keinen synchronen Sonderpfad.

### 8A: `BookingService` und `book_payment`

#### Aufgaben

- Eine serverseitig paginierte, rein lesende Vorschau für positive `tblaccounts`-Zahlungen nach Transaktionsdatum bauen. Dabei sowohl vollständig als auch teilweise bezahlte Rechnungen berücksichtigen.
- nur Invoices mit vollständigem sevDesk-Mapping berücksichtigen.
- offenen Voucher, verbleibenden Betrag und Währung lesen.
- ungebuchte `CheckAccountTransaction` ausschließlich über WHMCS-Transaktionsreferenz, exakten Betrag und Kontowährung matchen.
- keinen oder mehrere Treffer blockieren; kein Fuzzy Matching und keine Auswahl aus unsicheren Kandidaten.
- aus dem eindeutigen Kandidaten eine gehashte Bestätigungsreferenz bilden.
- nur ausdrücklich ausgewählte Vorschauen als `payment_booking`-Job mit Aktion `book_payment` einreihen.
- Direkt vor dem Write im Worker erneut prüfen: vollständiges Invoice-Mapping, Voucher, bereits bezahlter Voucherbetrag, Banktransaktion, Konto, Betrag, Währung und Eindeutigkeit.
- vor `PUT /Voucher/{id}/bookAmount` `booking_write_requested` und nach verifizierter Antwort `booking_completed` persistieren.
- unbekannten Write-Ausgang als `ambiguous` beenden und nicht automatisch wiederholen.
- Refunds und Chargebacks bereits in der Vorschau blockieren.

#### Tests

- kein, genau ein und mehrere Banktransaktionstreffer;
- Referenz passt nur als Teil des Verwendungszwecks, Betrag und Währung müssen trotzdem exakt stimmen;
- Voucher geschlossen, bereits voll bezahlt, teilweise bezahlt oder Betrag größer als Restbetrag;
- anderes Konto oder andere Kontowährung;
- Kandidat ändert sich zwischen Vorschau und Worker-Revalidation;
- Mapping zeigt nach der Vorschau auf einen anderen Voucher;
- bereits gebuchter Voucherbetrag ändert sich bei ansonsten gleichem Teilzahlungsstatus;
- mehr als zehn positive Zahlungen sind über Folgeseiten erreichbar; eine offene teilbezahlte Rechnung erscheint nach Transaktionsdatum;
- Bestätigungsreferenz wurde verändert;
- erfolgreiche Voll- und Teilbuchung;
- Prozessabbruch nach `booking_write_requested` und nach Remote-Erfolg vor `booking_completed`;
- Refund- und Chargeback-Kandidaten erzeugen keinen `book_payment`-Job.

#### Exit-Kriterium

- Nur genau ein vollständig passender, unmittelbar vor dem Write erneut bestätigter Kandidat wird gebucht.
- Kein Admin-Reload und kein zweiter Job kann dieselbe aktive Zahlung parallel buchen.
- Ein unbekannter `bookAmount`-Ausgang bleibt `ambiguous` und enthält genug IDs für den manuellen Remote-Abgleich.

### 8B: `CorrectionService` und `correction_voucher`

#### Aufgaben

- Adminauswahl für genau eine WHMCS-Rückzahlung mit positiver `amountout` bereitstellen.
- vollständiges Mapping der Originalrechnung, sevDesk-Kontakt, Währung und Rückzahlungsbetrag prüfen.
- Bei genau einem Steuersatz eine nachvollziehbare Standardposition vorschlagen. Bei mehreren Steuersätzen muss der Admin die Positionen ausdrücklich aufteilen.
- positive Eingabepositionen, einheitlichen Netto-/Bruttomodus, Tax Rule, Account-Datev, erlaubte Steuersätze und Summengleichheit prüfen.
- Einzelfallbestätigung verlangen und danach einen `refund_correction`-Job mit Aktion `correction_voucher` anlegen.
- Dedupe-Referenz aus der WHMCS-Rückzahlung und einen gekürzten SHA-256-Refund-Marker erzeugen; rohe Referenz nicht im Remote-Marker veröffentlichen.
- vor jedem Create nach Refund-, Invoice- und Original-Voucher-Marker suchen.
- Nach jedem möglichen Korrektur-Write nur noch lesend reconciliieren. Bleibt die Markersuche ohne Treffer, bleibt das Item `ambiguous`; ein weiterer Create ist ausgeschlossen.
- Genau einen vollständig passenden Korrektur-Voucher wieder zuordnen. Bei mehreren oder widersprüchlichen Treffern bleibt das Item `ambiguous`.
- Original-Voucher, Kontakt, Währung, Tax Rule und Maximalbetrag unmittelbar vor dem Write erneut prüfen.
- `correction_voucher_write_requested`, `correction_voucher_created` und `correction_mapping_persisted` persistieren.
- negativen Revenue-Voucher mit Status 100 erzeugen; kein `CreditNote`-Fallback und kein Enshrine.
- Chargebacks und andere Korrekturarten blockieren.

#### Tests

- fehlende Einzelfallbestätigung;
- Rückzahlung gehört zu anderer Invoice, Betrag oder Währung hat sich geändert;
- fehlendes Originalmapping oder fehlender Kontakt;
- eine oder mehrere Steuerraten, jeweils mit und ohne korrekte Aufteilung;
- Positionssumme weicht mehr als einen Cent ab;
- Tax-/Guidance-Profil nicht freigegeben;
- Chargeback wird vor jedem Write blockiert;
- kein Marker, genau ein vollständig passender Marker und mehrere/widersprüchliche Marker;
- negativer Payload entspricht exakt dem bestätigten positiven Rückzahlungsbetrag;
- verlorene POST-Antwort sowie Abbruch an allen drei Korrektur-Checkpoints;
- rein lesende Recovery ohne Markertreffer führt zu keinem zweiten Voucher-POST;
- bestehender Korrektur-Voucher wird wieder zugeordnet statt dupliziert.

#### Exit-Kriterium

- Ohne ausdrückliche Bestätigung entsteht weder Job noch negativer Voucher.
- Ein erfolgreicher Korrekturjob ist über Dedupe-Referenz, Marker und Remote-ID nachvollziehbar, ohne das Originalmapping zu ersetzen.
- Ein unsicherer Marker- oder Write-Zustand bleibt `ambiguous`; der Dedupe-Key verhindert einen zweiten Create.
- Es gibt keine automatische Refund-/Chargeback-Schleife.

## Phase 9: Recovery und Buchhaltungsnachlauf

### Voraussetzungen

- vollständiges WHMCS-Datenbankbackup und Moduldateibackup;
- sevDesk-Testmandant und produktiver API-Health-Check;
- schriftlich freigegebene Steuermatrix;
- bestätigte Behandlung für OSS, Drittland, Nullsummen und nicht unterstützte Credit-Fälle;
- fachliche Freigabe der manuellen negativen Korrektur-Voucher und des zweistufigen Zahlungsabgleichs;
- aktiver, überwachter WHMCS-Cron.

### Aufgaben

- Vor Beginn den aktuellen Live-Datenbestand inventarisieren.
- vorhandene Mappings, `NULL`-Mappings, Orphans und ungemappte Invoices klassifizieren.
- `NULL`-Mappings einzeln remote abgleichen.
- Dry-Run des offenen Zeitraums erstellen und manuelle Fälle abtrennen.
- kleine Canary-Batches pro Steuerklasse exportieren und in sevDesk prüfen.
- anschließend quartals- oder monatsweise Jobs starten.
- nach jedem Abschnitt WHMCS, Mapping, sevDesk und Buchhaltungszahlen abstimmen.

### Exit-Kriterium

- Jede exportpflichtige Invoice im freigegebenen Zeitraum ist gemappt, mit dokumentiertem Grund übersprungen oder als Prüffall erfasst.
- Keine doppelte Remote-Erstellung wurde festgestellt.
- Steuerberatung/Buchhaltung bestätigt die Stichprobe und die Summenabstimmung.
- Abschlussbericht bleibt ohne Roh-PII exportierbar.

## Phase 10: Erweiterungen nach stabilem Kern

Folgende Erweiterungen kommen erst nach einem erfolgreichen Nachlauf infrage:

- automatische Refund-/Chargeback-Verarbeitung oder allgemeine Gutschriften über passende `CreditNote`-Flows;
- andere Zahlungsabgleiche als das eindeutige `book_payment`-Verfahren;
- mögliche Invoice-first-Fälle;
- OSS über einen sevDesk-Objekttyp, der die Rules 18–20 unterstützt;
- Fremdwährungen;
- zusätzliche Kontakt- oder USt-ID-Prüfung.

Für jede Erweiterung braucht es einen konkreten Geschäftsfall, einen API-Nachweis, eine eigene Recovery-Regel und Tests. Eine Erweiterung ohne diese Voraussetzungen kommt nicht in den Kern.

## Übergreifende Abnahmekriterien

| Bereich | Muss-Kriterium |
| --- | --- |
| Kompatibilität | Addon, Settings, Cron und Invoice-Seiten laufen unter WHMCS 8.13.4/PHP 8.3 ohne Abhängigkeit vom bisherigen Modul |
| Legacy | Eine synthetische Struktur aus vollständigen, leeren und verwaisten Mappings übersteht die Migration unverändert |
| Steuer | Ein EU-B2C-Fall erhält nie `INNERGEM_LIEF`; ungültige Konto-/Rule-Kombination wird vor Write blockiert |
| Bulk | Job übersteht Browserende, Proxy-Timeout und Worker-Neustart |
| Isolation | 422 oder PHP-Fehler bei Invoice N verhindert Invoice N+1 nicht |
| Idempotenz | vorhandenes Mapping oder Recovery-Treffer verhindert zweiten Voucher |
| Ergebnisstatus | `skipped` und `permanent_failed` werden nicht als Erfolg gemeldet; `ambiguous` bleibt ungeklärt |
| Hooks | sevDesk-Ausfall erzeugt keinen Fehler im WHMCS-Kernablauf |
| Booking | `book_payment` schreibt nur nach eindeutiger Vorschau, expliziter Auswahl und vollständiger Worker-Revalidation; Refunds/Chargebacks bleiben blockiert |
| Korrektur | `correction_voucher` erzeugt nur nach Einzelfallbestätigung einen exakt negativen Revenue-Voucher und ordnet eindeutige Marker wieder zu, statt doppelt zu schreiben |
| Reconciliation | unbekannte Booking-/Correction-Writes bleiben je nach Pfad checkpoint- oder markerbasiert `ambiguous`; kein blinder Retry |
| Security | Logs und Jobdaten enthalten weder Token noch unnötige PII |
| Betrieb | Recovery, Pause/Stop, Retry und Rollback sind dokumentiert und getestet |

## Rollback-Prinzip

Ein Rollback deaktiviert Hooks und Worker und stellt die vorherigen Moduldateien wieder her. `mod_sevdesk` und die additiven Jobtabellen bleiben bestehen, damit der bisherige Fortschritt erhalten bleibt. Ignorierte Restore- oder Dump-Dateien dürfen dafür nicht verwendet werden.
