# Implementierungsplan

## Ziel und aktueller Stand

Ziel ist ein PHP-8.3-fähiges Drop-in-Replacement, das den vorhandenen Mappingbestand schützt und auch große Nachläufe in wiederaufnehmbaren Jobs verarbeitet. Die OSS-/Invoice-Erweiterung ist für Modulrelease 2.1.0 vorgesehen; Booking und Korrektur-Voucher bleiben Bestandteil von 2.0.0 und aufwärts.

Die Voucher-Basisphasen sowie die Codepfade für wählbare Voucher-/Invoice-Ziele, typisierte Mappings, Invoice-Recovery, Invoice-Booking und sevDesk-Dokumenthoheit sind im Repository umgesetzt. `2.1.0-rc.2` ergänzte die abgesicherte Bestandsübernahme und einen eng begrenzten, sevDesk-nativen ZUGFeRD-Pfad. `2.1.0-rc.3` übernahm die daraus entstandenen Live-API- und WHMCS-Kompatibilitätskorrekturen. `2.1.0-rc.4` schloss den zunächst vermuteten CLI-Kontextfehler. Der anschließende Postfachabgleich zeigte die eigentliche Plattformgrenze: WHMCS 8.13 verarbeitet Binäranhänge aus `EmailPreSend` nicht. Der Kanal `whmcs_template` ist deshalb gesperrt. Der Plan bildet die Grundlage für die Abnahme. Umgesetzt heißt nicht, dass die Invoice- oder E-Rechnungsfunktion bereits für Produktivdaten freigegeben ist.

`2.1.0-rc.5` ergänzt den zeitlich begrenzten Kleinunternehmerpfad, die strukturelle Behandlung von WHMCS-Sammelzahlungen und den eng freigegebenen Rule-11-Rabatt. Ein Live-Lauf mit einer normalen Rule-11-Invoice scheiterte erst beim Öffnen: sevDesk akzeptierte den Draft, wies `sendBy` wegen des automatisch gewählten Konten-Scopes aber mit Code 7100 zurück. Deshalb gibt es nun vor dem Rabattpfad ein eigenes Gate für alle Rule-11-Invoices. Offen bleiben Invoice-`bookAmount`, dieser rabattfreie Rule-11-Canary, der darauf aufbauende Rabatt-Canary, die Voucher-Canaries der produktiv genutzten Steuerfälle und die fachliche Abnahme.

Der Invoice-Canary bleibt ein hartes Release-Gate. Bis dahin blockiert `invoice_canary_confirmed=off` alle Invoice-Modi. Rule-11-Invoices benötigen zusätzlich `small_business_invoice_canary_confirmed` und eine aktuelle `ReceiptGuidance` mit Rule 11, 0 % und `REVENUE`-Scope. ZUGFeRD hat mit `e_invoice_canary_confirmed` ein eigenes Gate. Das additive Upgrade behält `voucher_only` bei und setzt das neue Rule-11-Gate auf aus. Der 2.0-Betrieb stoppt beim Upgrade einmalig mit `sync_enabled=off` und `runtime_review_required=on`.

## Feste Produktentscheidungen

- WHMCS 8.13.4, PHP 8.3
- Modulname `sevdesk`
- API-Basis `https://my.sevdesk.de/api/v1`
- sevDesk-Update-2.0-Payloads mit `taxRule` und `accountDatev`
- Upgrade-Default `whmcs + voucher_only + OSS blocked`; Voucher mit angehängtem WHMCS-PDF bleibt der unveränderte Bestandspfad
- drei Exportmodi: `voucher_only`, `invoice_for_oss`, `invoice_only`
- Dokumenthoheit `whmcs` in allen Modi; `sevdesk` ausschließlich mit `invoice_only`
- Rule 19 nur für ausdrücklich bestätigte, vollständig digitale EU-B2C-Leistungen; Rules 18/20 bleiben blockiert
- Invoice-Ziele sind paid-only und benötigen eine effektive WHMCS-Rechnungsnummer; bei leerem
  Legacy-`invoicenum` gilt read-only die interne Invoice-ID
- keine automatische Dokumenttyp-Fallbacklogik nach einem Remote-Write
- `mod_sevdesk` in-place weiterverwenden
- persistente Jobs in MySQL, verarbeitet über WHMCS-Cron
- keine externe Queue und kein zusätzlicher Dienst
- keine externe Lizenzprüfung
- OSS-Voucher blockieren; Rule-19-Invoice nur hinter Profil- und Canary-Gate
- zweistufiger Buchungsassistent in Release 2.0.0: `BookingService`, Jobtyp `payment_booking`, Aktion `book_payment`
- manuell bestätigter negativer Korrektur-Voucher in Release 2.0.0: `CorrectionService`, Jobtyp `refund_correction`, Aktion `correction_voucher`
- keine automatische Refund-, Chargeback-, Gutschrift- oder Storno-Verarbeitung; Chargebacks bleiben blockiert
- keine sevDesk→WHMCS-Rücksynchronisation und keine sevDesk-Webhooks
- keine dauerhafte Invoice-PDF-/XML-Spiegelung und kein Invoice-`CreditNote`-Pfad
- ZUGFeRD ausschließlich sevDesk-nativ für neue deutsche B2B-Rule-1-Invoices bei `invoice_only + sevdesk`; kein eigenes XML, kein B2G/XRechnung, keine OSS-E-Rechnung und kein historischer E-Rechnungs-Backfill

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
- `mod_sevdesk` additiv um `document_type`, `document_authority`, `document_number`, `document_ready_at`, `delivered_at`, `pdf_sha256`, `is_e_invoice` und `xml_sha256` ergänzen.
- `mod_sevdesk_jobs` und `mod_sevdesk_job_items` aus `docs/architecture.md` idempotent anlegen.
- funktionale Legacy-Settings lesen; Lizenzfelder ignorieren.
- Diagnose für vollständige, `NULL`- und verwaiste Mappings bauen.
- vollständige Legacy-Mappings ohne automatische Typ- oder Hoheitsannahme mit `NULL` erhalten; Typ und Hoheit erst nach read-only Vorschlag und Adminbestätigung ergänzen.
- Migration bei inkonsistenten Daten abbrechen und einen Report liefern, statt automatisch zu „reparieren“.

### Tests

- leere Neuinstallation;
- kleine synthetische Legacy-Struktur mit vollständigen, leeren und verwaisten Mappings;
- wiederholtes Upgrade;
- realer `sevdesk_upgrade()`-Aufruf für eine synthetische Originalmodul-Struktur: Mapping-Fingerprint, Kontaktfeld-ID, Token, Konten und unbekannte Alt-/Lizenzwerte bleiben erhalten, während Sync sicher deaktiviert wird;
- mehrere `NULL`-Mappings, verwaiste Invoice-IDs und synthetisch kollidierende IDs;
- Rollback des Codes bei erhaltenen additiven Tabellen.

### Exit-Kriterium

- Alle vorhandenen Mappingzeilen und Remote-IDs sind nach dem Upgrade unverändert.
- Neue Zuordnungen speichern Remote-ID und Dokumenttyp atomar; alte Zuordnungen werden nicht ungefragt neu exportiert.
- Die additive DDL bleibt idempotent. Ein wiederholter Upgrade-Aufruf auf bereits signierter, vollständiger Runtime ist ein No-op; eine ausdrückliche Reaktivierung quarantänisiert den Bestand dagegen absichtlich erneut und verlangt eine neue Setup-Freigabe.
- Kein `DROP`, `TRUNCATE` oder ungeprüftes Massen-`DELETE` ist Teil des normalen Pfads.

## Phase 3: Read-only API, Health und Konfigurationsprüfung

### Aufgaben

- schmalen sevDesk-Client mit Token-Redaktion, `User-Agent`, Connect- und Request-Timeout bauen.
- Fehler in stabile Kategorien übersetzen.
- Systemversion über `/Tools/bookkeepingSystemVersion` prüfen.
- `ReceiptGuidance` lesen und funktionale Konto-Settings gegen den Mandanten validieren.
- für Invoice-Modi `SevUser`, `Unity`, Modus-/Hoheitsmatrix und die lokale Canary-Bestätigung validieren.
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
- dokumentbewussten Resolver implementieren, der Zieltyp, Hoheit, Modus, OSS-Profil und Rule vor jedem Remote-Write einfriert.
- unterstützte, blockierte und manuelle Fälle explizit modellieren.
- Account-Datev, Rule und Rate gegen Guidance prüfen.
- Netto-/Brutto-Beträge und Rundungen deterministisch berechnen.
- Voucher- und VoucherPos-Payload ohne HTTP-Abhängigkeit bauen.
- keine Account-ID oder Tax Rule in Controller/Templates verstreuen.

### Tests

- deutscher Standardfall;
- synthetischer EU-B2C-Regressionsfall;
- unzulässige Konto-/Regel-Kombination aus `ReceiptGuidance`;
- Kleinunternehmer aus, mit unbegrenzter Legacy-Semantik und mit zeitlicher
  Begrenzung anhand des Rechnungsdatums; ungültige Stichtage bleiben fail-closed;
- leeres Legacy-`invoicenum`: Snapshot, Dry-run und Worker verwenden konsistent die interne Invoice-ID,
  ohne `tblinvoices` zu verändern;
- B2B mit fehlenden/widersprüchlichen Nachweisen;
- Rule 19 in `invoice_for_oss`/`invoice_only` nur für bestätigte digitale EU-B2C-Leistungen; Rules 18/20, unbestätigtes OSS, gemischte Leistung, Nullsumme und Fremdwährung als blockierte/manuelle Fälle;
- reine WHMCS-Sammelzahlungsrechnungen strukturell als Zahlungscontainer erkennen, nur die exakt verknüpften Originalrechnungen freigeben, dabei `subtotal + tax + tax2 = total + credit`, Dokumentbrutto `total + credit` und direkten Zahlteil `total` centgenau prüfen und die gemeinsame Banktransaktion nicht automatisch aufteilen;
- sonstiges Kundenguthaben blockieren; im Voucher-Einzelfall bleibt nur die gespeicherte Bestätigung des vollen Rechnungsbruttos zulässig;
- genau einen über Typ, `relid` und `taxed` bewiesenen `PromoHosting`-Rabatt ausschließlich in `invoice_only` mit Rule 11/0 % und eigenem Canary als festes `discountSave` abbilden; Rabatt-Fingerprint und Remote-Marker gehören zur Recovery;
- Inclusive/Exclusive Tax und Cent-Rundung.

### Exit-Kriterium

- Gleiche Eingabe erzeugt stets dieselbe Entscheidung und denselben Payload.
- Kein unklarer Fall erzeugt einen Write-Payload.
- Kein Voucher-Fehler wechselt nachträglich zu Invoice; ein gespeicherter Zieltyp bleibt bei Resume unverändert.
- Die vom Steuerberater freigegebene Matrix ist als Testfall abgebildet. Ohne diese Freigabe endet die Phase vor Produktion.

## Phase 5: Sicherer Einzelexport im Testmandanten

### Aufgaben

- bestehendes Mapping und Legacy-`NULL`-Zustände zuerst prüfen.
- Invoice über den eindeutigen Item-`dedupe_key` atomar für genau eine aktive Aktion reservieren.
- Kontaktzuordnung aus dem konfigurierten WHMCS-Custom-Field prüfen; eine Neuanlage nach leerer exakter Kundennummernsuche nur nach persistierter Betreiberbestätigung ausführen.
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
- PDF-Fehler und ungültiger Kontakt;
- vorhandene Kontakt-ID wird nach erfolgreichem GET unverändert wiederverwendet; eine fehlende konfigurierte ID blockiert ohne Such-/Create-Fallback; nur ein leeres Feld erlaubt die eindeutige Kundennummernsuche, und ein leerer Suchausgang erlaubt die Neuanlage nur mit `customer_number_contact_creation_confirmed`.

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
- neue Exporte als `export_document` einplanen, den bisherigen Dedupe-Schlüssel `export_voucher:<invoiceId>` aber absichtlich dokumenttypübergreifend beibehalten.
- `document_type_selected`, Invoice-Create, Open, Delivery und WHMCS-Mailübergabe als eigene riskante Checkpoints abbilden.
- dieselbe Lease-/Checkpoint-Infrastruktur für `booking_write_requested` und `correction_voucher_write_requested` verwenden.
- globale aktive Deduplizierung über `dedupe_key` aus Aktion und passender Geschäftsreferenz anwenden: Invoice-ID, gehashte Zahlungsreferenz oder gehashte Refund-Referenz.
- Batchgröße und internes Zeitbudget begrenzen.
- Retry-Policy mit `retry_wait` und `available_at` für sichere Netzwerkfehler, 429 und 5xx implementieren.
- 4xx-Fachfehler als `permanent_failed` und unbekannte Write-Ausgänge als `ambiguous` beenden.
- Cron-Integration so bauen, dass überlappende Läufe sicher sind.
- abgelaufene Leases wiederaufnehmen und unbekannte Writes vorher abgleichen.
- nach möglicherweise ausgeführtem Invoice-Create, Open oder Versand ausschließlich read-only reconciliieren; kein automatischer zweiter Write.

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
- Setup, Health, Dry-Run, Jobs, CSV und Mappingansicht um Zieltyp, Dokumenthoheit, Rule, Delivery-Zustand und Einschränkungen ergänzen.
- paginierte Itemliste mit Invoice-Link, Ergebniscode, Kurzmeldung und Retry-/Review-Aktion bereitstellen.
- gezielten Retry nur für `permanent_failed` und nach Abgleich für `ambiguous` erlauben.
- Die Bulk-Vorschau trennt eligible, skipped, fachlich blockiert und bereits gemappt. „Blockiert“ bleibt eine Vorschaukategorie oder ein Fehlercode und ist kein Itemstatus.
- CSRF- und Adminrollenprüfung für alle Mutationen.
- relevante WHMCS-Hooks registrieren; Hooks deduplizieren und ausschließlich Jobs einplanen. Alle ereignisgetriebenen Enqueue-Hooks respektieren zusätzlich `sync_enabled`. Während `runtime_review_required=on` bleiben Hooks, Runner und Remote-fähige Adminaktionen gesperrt; nach bestätigter Bestandsfreigabe ist der Runner bei aktiver, signierter Modullaufzeit auch mit `sync_enabled=off` für manuell angelegte Jobs verfügbar.
- In der Admin-Rechnungsbearbeitung einen normalen Link zur vorausgefüllten
  Einzelimport-Vorprüfung und einen kompakten Kurzexport anbieten. Der Kurzexport
  ist POST-/CSRF-geschützt, arbeitet nur mit dem bereits gespeicherten
  Rechnungsstand, legt ausschließlich ein dedupliziertes `export_document`-Item
  an und führt im Browserrequest keinen sevdesk-Aufruf aus. Der historische
  Cross-Type-Dedupe-Namensraum bleibt bestehen.
- Das Quick-POST-Formular außerhalb des WHMCS-Rechnungsformulars über den
  Admin-Footer ausgeben; der Invoice-Control-Hook darf kein verschachteltes
  `<form>` erzeugen. Bekannte Reviewfälle, vollständige Mappings und Legacy-NULL
  werden fail-closed behandelt.
- Den separaten Admin-Nur-Ansehen-Modus in einem Kompatibilitätstest unter
  WHMCS 8.13.4 prüfen. Ohne dokumentierten Output-Hook wird keine allgemeine
  DOM-Injektion eingebaut.
- Mapping-Manager mit bestätigtem lokalen Unlink und ohne implizites Remote-Löschen bereitstellen.
- Mapping-Manager für untypisierte Legacy-Mappings um read-only Typvorschlag und getrennte Adminbestätigung ergänzen.
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
- Die Einrichtung zeigt Exportmodus, Hoheit, Rule-19-Profil, Canary-Status,
  SevUser, Unity, Theme-Adapter und Versandkanal. Moduswechsel bleiben bei
  aktiven oder ungeklärten Exportjobs gesperrt.

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
- persistierten Dokumenttyp lesen und offenen Voucher beziehungsweise offene Invoice samt verbleibendem Betrag und Währung laden.
- ungebuchte `CheckAccountTransaction` ausschließlich über WHMCS-Transaktionsreferenz, exakten Betrag und Kontowährung matchen.
- keinen oder mehrere Treffer blockieren; kein Fuzzy Matching und keine Auswahl aus unsicheren Kandidaten.
- aus dem eindeutigen Kandidaten eine gehashte Bestätigungsreferenz bilden.
- nur ausdrücklich ausgewählte Vorschauen als `payment_booking`-Job mit Aktion `book_payment` einreihen.
- Dokumenttyp und Remote-ID in den bestätigten Snapshot aufnehmen.
- Direkt vor dem Write im Worker erneut prüfen: vollständiges typisiertes Mapping, Dokumentstatus, bereits gebuchter Betrag, Banktransaktion, Konto, Betrag, Währung und Eindeutigkeit.
- vor `PUT /{Voucher|Invoice}/{id}/bookAmount` `booking_write_requested` und nach typabhängig verifizierter Antwort `booking_completed` persistieren.
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
- identische Booking-Prüfungen für Voucher- und Invoice-Mappings;
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
- Invoice-Mappings mit `invoice_correction_not_supported` blockieren; kein stiller Voucher- oder `CreditNote`-Fallback.

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
- für Invoice-Modi ein dokumentierter Invoice-API-Canary; für OSS zusätzlich die Rule-19-Bestätigung für ausschließlich digitale Leistungen;
- bestätigte Behandlung für Rules 18/20, Drittland, Nullsummen und nicht unterstützte Credit-Fälle;
- fachliche Freigabe der manuellen negativen Korrektur-Voucher und des zweistufigen Zahlungsabgleichs;
- aktiver, überwachter WHMCS-Cron.

### Aufgaben

- Vor Beginn den aktuellen Live-Datenbestand inventarisieren.
- vorhandene Voucher-/Invoice-Mappings, untypisierte und `NULL`-Mappings, Orphans, alte Exportjobs, mögliche Remote-Dubletten und ungemappte Invoices klassifizieren.
- vollständige Legacy-Mappings mit `document_type=NULL` read-only prüfen und erst nach Adminbestätigung typisieren.
- `NULL`-Mappings einzeln remote abgleichen.
- Dry-Run des offenen Zeitraums erstellen und manuelle Fälle abtrennen.
- historische ungemappte Rechnungen vor Create anhand Nummer, Marker sowie Datum/Kontakt/Betrag rein lesend gegen Invoice und Voucher prüfen.
- einen bestätigten Altbestand ausschließlich als mailfreien `historical_backfill` einreihen; der Moduswechsel selbst startet keinen Export und historische Jobs erzeugen keine E-Rechnung.
- sichere alte Voucher-Vor-Write-Jobs nur über einen neuen `export_document`-Job im aktuellen Modus fortsetzen. Riskante Checkpoints bleiben auf ihrem ursprünglichen Dokumentpfad.
- kleine Canary-Batches pro Steuerklasse exportieren und in sevDesk prüfen.
- anschließend quartals- oder monatsweise Jobs starten.
- nach jedem Abschnitt WHMCS, Mapping, sevDesk und Buchhaltungszahlen abstimmen.

### Exit-Kriterium

- Jede exportpflichtige Invoice im freigegebenen Zeitraum ist gemappt, mit dokumentiertem Grund übersprungen oder als Prüffall erfasst.
- Keine doppelte Remote-Erstellung wurde festgestellt.
- Steuerberatung/Buchhaltung bestätigt die Stichprobe und die Summenabstimmung.
- Abschlussbericht bleibt ohne Roh-PII exportierbar.

## Phase 10: Gate 1 – Invoice-Ziel bei WHMCS-Dokumenthoheit

### Hartes Vorab-Gate

Vor produktiver Aktivierung bestätigt ein sevDesk-Testmandant:

- normale `RE` im Draft-Status 100 mit Rule 19, Landsteuersatz, kleingeschriebenem `deliveryAddressCountry`, exakt passender `StaticCountry`-Referenz und ohne `accountDatev`;
- unveränderte WHMCS-Rechnungsnummer;
- stabilen Marker `[WHMCS-INVOICE:<id>]`;
- Pflichtreferenzen für `SevUser`, `Unity`, Kontakt, Positionen und Adresse;
- getrenntes Verhalten von Create, `sendBy`, `sendViaEmail`, `getPdf` und `/Invoice/{id}/bookAmount`;
- stabile finale PDF und keine problematische Voucher-/Invoice-ID-Kollision.

Der technische Live-Lauf hat Rule 19, Marker, Nummer, Pflichtreferenzen, PDF und beide Versandaufrufe bestätigt. Der direkte sevDesk-Versand kam an; der WHMCS-Vorlagenkanal ist wegen der bestätigten 8.13-Plattformgrenze gesperrt. Offen sind Invoice-`bookAmount`, die endgültige Bewertung der dokumenttypübergreifenden ID-Eindeutigkeit und die fachliche Abnahme. Scheitert einer dieser Nachweise, bleibt `invoice_for_oss` gesperrt und die Architektur muss neu entschieden werden.

### Aufgaben

- Setupfelder `export_mode`, `document_authority`, `oss_profile`, `invoice_canary_confirmed`, `small_business_invoice_canary_confirmed`, `invoice_sev_user_id` und `invoice_unity_id` bereitstellen.
- Rule-11-Invoices vor Create zusätzlich gegen den eigenen Canary und die aktuelle Rule-11-/0-%-Fähigkeit eines `REVENUE`-Kontos aus `ReceiptGuidance` prüfen. `InvoicePos` erhält weiterhin kein `accountDatev`.
- Rule 19 ausschließlich aus einer ausdrücklich bestätigten EU-B2C-Digitalentscheidung erzeugen; keine Textheuristik.
- `DocumentTargetResolver` vor jedem Remote-Write ausführen und Entscheidung unter `document_type_selected` einfrieren.
- `InvoiceExporter` für `RE`, Status 100, effektive WHMCS-Nummer, Land, Währung, Rule, positive Positionen, SevUser, Unity und Marker implementieren.
- Remote-Invoice und Positionen exakt verifizieren, bevor das typisierte Mapping als Erfolg gilt.
- `InvoiceReconciliationService` und Create-/Open-Checkpoints read-only recoverbar machen.
- neue Aktion `export_document` verwenden, den Dedupe-Key `export_voucher:<invoiceId>` dokumenttypübergreifend behalten.
- `BookingService` anhand des persistierten Typs auf Voucher oder Invoice routen.
- `CorrectionService` für Invoice-Mappings blockieren.
- Setup, Health, Dry-Run, Jobs, CSV und Mappingansicht dokumenttypbewusst machen.

### Tests

- vollständige Modus-/Hoheitsmatrix sowie paid-only und effektive Nummer;
- Rule 19 in Hybrid/Invoice-only und alle Blockaden für Rule 18/20, gemischt, unklar und Rule 3;
- Rule 11 als Voucher unverändert; als Invoice ohne eigenen Canary oder ohne aktuellen REVENUE-Scope vor Create blockiert;
- Migration mit neuen Nullable-Spalten und unveränderten Legacy-Mappings;
- Invoice-Payload, Rundung, Pflichtreferenzen, fehlendes `accountDatev` und exakte Remote-Rückprüfung;
- Cross-Type-Dedupe, alte `export_voucher`-Items und Recovery an jedem riskanten Checkpoint;
- Invoice-Booking einschließlich Snapshotänderung;
- 401, 422, 429, 5xx, Timeout und ungültige Responses ohne zweiten Create/Open-Write.

### Exit-Kriterium

- `voucher_only` bleibt nach Upgrade unverändert.
- Hybrid und `invoice_only` sind ohne allgemeinen Invoice-Canary fail-closed; Rule-11-Invoices besitzen ihr zusätzliches Gate.
- Jede neue Zuordnung enthält Typ und Remote-ID atomar.
- Ein unbekannter Invoice-Write wird nie automatisch wiederholt.
- WHMCS bleibt in Gate 1 die kundenseitige Dokumenthoheit.

## Phase 11: Gate 2 – sevDesk-Dokumenthoheit und Versand

### Aufgaben

- `document_authority=sevdesk` ausschließlich mit `invoice_only`, WHMCS-Proforma, installiertem Adapter-Manifest, Canary und ausdrücklicher Bestätigung erlauben.
- Twenty-One-Referenzadapter und den Custom-Theme-Vertrag `authority`, `state`, `invoiceNumber`, `downloadUrl` liefern.
- Proforma-, Pending-, Ready- und Failure-Zustand im Clientbereich abbilden; sichtbare WHMCS-Endrechnungslinks bei bezahlten Invoice-only-Fällen entfernen.
- authentifizierte PDF-Route anhand der WHMCS-Invoice-ID mit Eigentümer-, Mappingtyp-, Signatur-, Größen- und Hashprüfung implementieren.
- keine dauerhafte PDF-Kopie anlegen; Downloads streamen und Mailanhänge nur im Speicher halten.
- Versandkanal `sevdesk` über `sendViaEmail` implementieren. Der vorbereitete Kanal `whmcs_template` bleibt im Datenvertrag erhalten, ist unter WHMCS 8.13 aber gesperrt, weil Binäranhänge aus `EmailPreSend` erst ab WHMCS 9 unterstützt werden. Eine spätere Freigabe braucht einen eigenen Kompatibilitäts- und Postfachtest.
- andere Invoice-Mailvorlagen nach Zahlung lokal blockieren; der Hook darf keine sevDesk-Abfrage ausführen.
- Bulk-/historische Exporte ohne automatische Zustellung lassen.
- unklaren Versand als `ambiguous` beenden; manuellen Resend nur nach Doppelversandwarnung erlauben.

### Tests

- falsche/deaktivierte WHMCS-Vorlage, exakte Hook-Eingrenzung und Binärgleichheit des Anhangs;
- CLI-/Cron-Ausführung und Crash vor/nach `SendEmail`;
- Client-Eigentümerprüfung, Proforma/Pending/Ready/Failure und fremde Remote-ID;
- PDF-MIME, Signatur, Größenlimit, Hashabweichung und fehlende Ready-Markierung;
- Twenty-One-Adapter ohne sichtbare WHMCS-Endrechnungslinks;
- keine Mail bei Backfill/Bulk und kein automatischer Resend nach unbekanntem Ausgang;
- token- und PII-freie Logs.

### Exit-Kriterium

- Vor Zahlung bleibt WHMCS-Proforma sichtbar; nach Zahlung wird nur die geprüfte sevDesk-PDF als normale Endrechnung ausgeliefert.
- Ohne Adapter, Proforma, Canary oder Bestätigung kann sevDesk-Hoheit nicht gespeichert werden.
- PDF- und Mailpfade akzeptieren nur die lokale WHMCS-Invoice-ID und den einmaligen internen Kontext.
- Eine bestehende `whmcs_template`-Konfiguration niemals still auf sevDesk umstellen; Setup, Health Check und Worker müssen stattdessen verständlich blockieren.

## Phase 12: RC.2 – Native ZUGFeRD-Invoices

### Aufgaben

- geschützte Einstellungen `e_invoice_mode`, Kunden-Opt-in-Feld, PaymentMethod, Aktivierungsdatum und eigenen Canary bereitstellen;
- ZUGFeRD nur für neue, bezahlte deutsche Organisationskunden mit Rule 1, `invoice_only`, sevDesk-Hoheit und gesetztem Admin-Tickbox-Feld auswählen;
- angewendetes WHMCS-Guthaben auch bei exakt bewiesener Sammelzahlung blockieren, bis diese Kombination einen eigenen Create-/XML-/PDF-Canary bestanden hat; kein normaler Invoice-Fallback;
- Auswahl, Contact-, PaymentMethod-, Unity- und Country-ID sowie einen PII-freien Hash aus Empfängername und strukturierter Adresse einfrieren;
- `propertyIsEInvoice=true`, strukturierte Adresse, `paymentMethod` und `takeDefaultAddress=false` an den bestehenden `InvoiceExporter` übergeben;
- ein vorhandenes E-Rechnungsflag strikt zurückprüfen; fehlt es in der Invoice-Antwort, den Pfad nur nach erfolgreichem `getXml` fortsetzen. Kontakt, Zahlungsmethode und Adresshash bleiben Pflichtprüfungen, der erste verifizierte XML-Hash wird unveränderlich gespeichert;
- vor Öffnung, PDF-Auslieferung und Versand erneut lesen. Ein abweichender XML-Hash bleibt `ambiguous` und ersetzt nie den Soll-Hash;
- beim sevDesk-Versand `sendXml=false` verwenden. Der Kundenbereich liefert nur das geprüfte ZUGFeRD-PDF, ohne PDF oder XML dauerhaft zu speichern; der nicht tragfähige WHMCS-Vorlagenkanal bleibt auf 8.13 gesperrt;
- fehlende Pflichtdaten und HTTP 422 nach ausdrücklicher Auswahl blockieren. Es gibt keinen Rückfall auf eine normale PDF-Invoice.

### Tests und Gates

- Auswahlmatrix mit Opt-in, Datum, Land, Organisation, Rule, Hoheit, Referenzen und fehlendem XMLReader;
- Payload, Empfängername-/Adresshash, XML-Struktur, Größenlimit und Hashdrift;
- Recovery nach Create, XML-/PDF-Abruf, Open und Versand;
- mailfreie historische Backfills mit `is_e_invoice=false`;
- separater Testmandanten-Canary für Create, Readback, `getXml`, ZUGFeRD-PDF, externe EN-16931-Prüfung, `sendBy`, `sendViaEmail(sendXml=false)` und Kundendownload.

### Exit-Kriterium

Das Modul kann den Pfad technisch fail-closed ausführen. Produktiv freigegeben wird er erst nach Invoice-Canary, eigenem ZUGFeRD-Canary und der WHMCS-Liveprüfung von Proforma, Adapter, Paid-Mail und Kunden-PDF.

## Phase 13: Erweiterungen nach stabilem Invoice-Rollout

Erst nach diesen Gates kommen Invoice-`CreditNote`, Rules 18/20, B2G/XRechnung, Produktklassifikation, Fremdwährung, dauerhafte Dokumentspiegelung, automatische Refund-/Chargeback-Flows oder zusätzliche USt-ID-Dienste infrage. Jede Erweiterung braucht einen konkreten Geschäftsfall, einen API-Nachweis, eine eigene Recovery-Regel und Tests.

## Übergreifende Abnahmekriterien

| Bereich | Muss-Kriterium |
| --- | --- |
| Kompatibilität | Addon, Settings, Cron und Invoice-Seiten laufen unter WHMCS 8.13.4/PHP 8.3 ohne Abhängigkeit vom bisherigen Modul |
| Legacy | Eine synthetische Struktur aus vollständigen, leeren und verwaisten Mappings übersteht die additive Typmigration unverändert; kein Legacy-Typ wird geraten |
| Steuer | Ein EU-B2C-Fall erhält nie `INNERGEM_LIEF`; Rule 19 verlangt Digitalprofil und Invoice-Gate; Rules 18/20 bleiben blockiert |
| Bulk | Job übersteht Browserende, Proxy-Timeout und Worker-Neustart |
| Isolation | 422 oder PHP-Fehler bei Invoice N verhindert Invoice N+1 nicht |
| Idempotenz | vorhandenes Mapping oder Recovery-Treffer verhindert einen zweiten Voucher oder eine zweite Invoice; Cross-Type-Dedupe bleibt erhalten |
| Ergebnisstatus | `skipped` und `permanent_failed` werden nicht als Erfolg gemeldet; `ambiguous` bleibt ungeklärt |
| Hooks | sevDesk-Ausfall erzeugt keinen Fehler im WHMCS-Kernablauf |
| Booking | `book_payment` schreibt Voucher oder Invoice nur nach eindeutiger Vorschau, typisiertem Snapshot, Auswahl und Revalidation |
| Korrektur | `correction_voucher` bleibt Voucher-only; Invoice-Mappings werden ohne `CreditNote`-Fallback blockiert |
| Reconciliation | unbekannte Create-/Open-/Versand-/Booking-/Correction-Writes bleiben typbewusst `ambiguous`; kein blinder Retry |
| Dokumenthoheit | sevDesk-Hoheit ist ohne Proforma, Adapter, Canary und bestätigten Versandweg nicht aktivierbar |
| PDF/Mail | Eigentümer-, Signatur-, Größen- und Hashprüfung; keine dauerhafte PDF-Kopie und keine automatische Backfill-Mail |
| E-Rechnung | nur neue Rule-1-Invoices hinter Kunden-Opt-in und eigenem Canary; wohlgeformtes CII-XML und stabile PDF/XML-Hashes; kein stiller Fallback |
| Security | Logs und Jobdaten enthalten weder Token noch unnötige PII |
| Betrieb | Recovery, Pause/Stop, Retry und Rollback sind dokumentiert und getestet |

## Rollback-Prinzip

Ein Rollback deaktiviert zuerst Hooks und Worker; `mod_sevdesk` und die additiven Jobtabellen bleiben bestehen, damit der bisherige Fortschritt erhalten bleibt. Die vorherigen Moduldateien dürfen nur wieder aktiviert werden, solange nachweislich weder ein Invoice-Mapping noch ein möglicher unklarer Invoice-Write entstanden ist. Das Originalmodul kennt den additiven Dokumenttyp nicht und könnte eine Invoice-ID fälschlich als Voucher-ID verwenden. Nach Beginn des Invoice-Pfads bleibt der Rewrite deshalb bis zu einem separat geplanten Downgrade deaktiviert installiert. Ignorierte Restore- oder Dump-Dateien dürfen dafür nicht verwendet werden.
