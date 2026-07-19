# Teststrategie

## Ziel

Die Tests müssen vor allem zwei Schäden verhindern: falsche Buchungen und doppelte Belege. Reine Happy-Path-Abdeckung reicht nicht. Prozessabbruch, Timeout und unvollständige Remote-Antworten gehören zum normalen Testumfang.

Die Tests verwenden ausschließlich synthetische Kunden, Invoices und API-Fixtures. Private Dumps, echte PDFs, Token und Kundendaten sind als Testdaten verboten.

## Aktueller automatisierter Nachweis

- Die schnelle Unit-/Contract-/Kompositionstestsuite ist lokal grün;
- PHP-Lint und PSR-12 laufen über den vollständigen Modul- und Testbaum;
- PHPStan analysiert den vollständigen PHP-Modulcode auf Level 6;
- Unit-/Contract-Tests decken Dokumentzielresolver, Rule-19-Gates, Invoice-Payload,
  Invoice-Reconciliation, PDF-Prüfung und den einmaligen In-Memory-Mailkontext ab;
- Die MariaDB-Integrationstests prüfen eine kleine synthetische Legacy-Struktur, echte Unique-Constraints, Deduplizierung, Candidate-/Remote-ID-Erhalt und parallele Claims;
- Dieselbe Suite deckt sichere und riskante Lease-/Throwable-Recovery, den globalen Auth-Stopp, WHMCS-Kundenwährungen, Teilzahlungs-Pagination, Mapping-Revalidation und einen 1.000-Item-Lauf mit Fehler in der Mitte ab;
- Ohne konfigurierten Server meldet die lokale MariaDB-Suite ihre Tests als `skipped`. In CI und bei einem Lauf über `tools/test-mariadb.sh` sind sie verpflichtend.

MariaDB und PHP 8.3 bleiben eigene Release-Gates. Ein übersprungener Datenbanktest oder ein Lauf unter einer anderen PHP-Version ersetzt diese Nachweise nicht.

Der Invoice-API-Canary ist ebenfalls ein eigenes externes Gate. Mocks, OpenAPI-Fixtures und ein gesetzter Konfigurationswert beweisen nicht, dass er stattgefunden hat. Im aktuellen Repository ist dieser Testmandanten-Nachweis ausstehend.

## Testebenen

### 1. Unit-Tests

Schnelle Tests ohne WHMCS-Datenbank oder Netzwerk für:

- Steuerklassifikation;
- Eligibility (`Paid`, `import_after`, Sonderfälle);
- Konto-/Rule-/Rate-Matching gegen eine Guidance-Fixture;
- Netto-/Brutto- und Rundungslogik;
- Voucher-/Position-Payload;
- vollständige `voucher_only`-/`invoice_for_oss`-/`invoice_only`-Matrix mit beiden Dokumenthoheiten;
- gefrorene Dokumentzielentscheidung einschließlich paid-only, finaler Rechnungsnummer und fehlendem Fallback;
- Invoice-/InvoicePos-Payload, Pflichtreferenzen, Menge 1 und bewusst fehlendes `accountDatev`;
- exakte Invoice-/Positionsrückprüfung und typabhängige Booking-Endpunkte;
- PDF-MIME, Signatur, EOF, Größenlimit, Dateiname und SHA-256;
- einmaliger, invoice- und templategebundener In-Memory-Anhang;
- Fehlerklassifikation und Retry-Entscheidung;
- Statusübergänge von Job und Item;
- lazy Kontakt-Referenzdaten: bestehende/verknüpfte Kontakte dürfen weder
  Address-Kategorie noch CommunicationWay-Key vorab laden;
- explizite Legacy-Kontakt-ID: Eine vorhandene Remote-ID bleibt auch ohne oder mit historisch anderer `customerNumber` maßgeblich und wird nicht aktualisiert. Ein 404 blockiert ohne Such- oder Create-Fallback;
- leeres Kontaktfeld: Zulässig sind kein Treffer, genau ein Treffer oder mehrere exakt geprüfte Kundennummerntreffer. Listentreffer ohne Kundennummer werden per ID nachgelesen. Beweist auch der Einzelabruf keine Gleichheit, blockiert das Modul den Fall als unverifizierbar;
- Kontakt-Neuanlage: Ein leerer Suchausgang blockiert ohne `customer_number_contact_creation_confirmed` vor Checkpoint und POST. Nach der Bestätigung darf dieselbe Eingabe einen Kontakt anlegen;
- fehlende, gelöschte oder nicht als `client` typisierte `custom_field_id` blockiert vor WHMCS-Kundenladen und vor jedem sevDesk-Kontaktpfad;
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
- Upgrade über den echten Addon-Callback mit synthetischen Altsettings und bewusst höherer, anbieterfremder Altversion: Mapping-Fingerprint, API-/Kontakt-/Kontowerte und unbekannte Lizenzfelder bleiben erhalten; der fehlende Rewrite-Nachweis persistiert vor Schemainspektion/DDL zuerst `runtime_review_required=on`, leert Signatur und Sync unabhängig und setzt die Signatur erst nach vollständiger Strukturprüfung;
- signaturloser 2.0-Rewrite-Bestand: Dokumentmodus und Mapping bleiben erhalten,
  automatische sowie manuelle Remote-Verarbeitung wird aber einmalig bis zur
  ausdrücklich bestätigten Setup-Prüfung quarantänisiert; ein
  Tabellen-/Settings-Fingerprint umgeht dieses Gate nicht;
- WHMCS-8.13.4-E2E nach dem für neue `hooks.php` erforderlichen unveränderten
  Speichern der Addon-Einstellungen: sämtliche inventarisierten unbekannten
  Alt-/Lizenz-Settings bleiben erhalten;
- derselbe Bootstrap über den ersten Adminaufruf, wenn ein bereits aktives Alt-Addon zufällig dieselbe Versionsnummer meldet und WHMCS deshalb keinen Upgrade-Callback ausführt; auch ein Fehler während der additiven Migration lässt Sync/Signatur aus und `runtime_review_required=on` bestehen;
- Review-Marker, neuer Quarantäne-Token und ungültige Signatur werden in einer Transaktion unter den festgelegten Sperren gespeichert. Kein Leser darf den neuen Token zusammen mit einer noch gültigen Signatur sehen. Schlägt diese Transaktion fehl, speichert eine zweite Transaktion Review-Marker und ungültige Signatur gemeinsam. Die anschließenden Signatur-, Sync- und Review-Updates sichern den Zustand nochmals unabhängig ab. Ohne nachgewiesenen neuen Token beginnt keine DDL;
- Migration und Runner verwenden denselben Advisory Lock; ein schon laufender Mehr-Item-Batch liest Review-Marker und Signatur ungecached vor jedem Claim, beendet höchstens sein aktuelles Item und beansprucht danach kein weiteres;
- der endgültige Claim sperrt Aktivierung, Review, Signatur und 401/403-Alarm zusammen mit Job und Item in der Reihenfolge Settings → Job → Item; Quarantäne, Deaktivierung oder Zugangsalarm können dadurch nicht zwischen Gate-Prüfung und Handlerstart vorbeilaufen;
- Setup verlangt die Bestandsbestätigung und eine erfolgreiche read-only Mandantenprüfung. Ein Fehler erhält die Quarantäne. Unter dem Runner-Lock werden abgelaufene sichere Leases und Leases mit bestätigtem Remote-Effekt ohne Abbruch zu `retry_wait`. Sichere abgebrochene Leases werden `cancelled`; unbekannte Write-Ausgänge und abgebrochene Leases mit bestätigtem Remote-Effekt werden `ambiguous`. Ein abgestürzter Altbestand blockiert dadurch nicht die gesamte Freigabe;
- Lease-Recovery und Cancel werden pro Item über Job- und Itemlock serialisiert und vergleichen die gescannte Lease erneut; ein paralleler Abbruch hinterlässt weder eine fremde neue Lease noch ein unclaimbares `retry_wait`;
- konkurrierende Setup-POSTs dürfen nach der Lock-Freigabe keine inzwischen gespeicherten Werte aus einem vor dem Lock gelesenen Snapshot zurückschreiben. Validierungsfehler rollen die Transaktion zurück; nur die innerhalb des Runner-Locks bewusst gesetzte Sync-Sperre bleibt fail-closed bestehen;
- Setup bindet die Betreiberbestätigung an einen opaken Quarantäne-Token. Ein neuer Token während oder nach dem Seitenaufruf gewinnt. Fehler beim unabhängigen Speichern von Token oder Signatur verhindern die Migration. Nach Commit oder Rollback wird der lokale Config-Cache verworfen;
- stabile, bereinigte Diagnosecodes für doppelte Invoice-/Remote-Zuordnungen und kollidierende Legacy-Indizes; unbekannte Datenbankmeldungen dürfen weder Admin-HTML noch Aktivierungsantwort erreichen;
- vollständige, `NULL`- und verwaiste Mappings;
- leere oder nur aus Leerzeichen bestehende Remote-IDs werden in Lookup, Health, Booking und UI wie `NULL` als unvollständige Recovery-Fälle behandelt;
- additive Nullable-Spalten `document_type`, `document_number`, `document_ready_at`, `delivered_at`, `pdf_sha256`;
- keine automatische Typannahme für vollständige Legacy-Mappings und atomare Typ-/ID-Speicherung für neue Mappings;
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
- alte `export_voucher`- und neue `export_document`-Items im gemeinsamen Dedupe-Namensraum;
- Erhalt aller Legacy-Zuordnungen bei Fehlern während der Migration.
- Hooks und Cron-/CLI-Runner bleiben bei fehlender/falscher Laufzeitsignatur oder aktivem Review-Marker trotz kollidierendem `module_active` fail-closed. Kunden-PDF und Mail-Schutz bleiben nur bei gültiger Signatur in der Review-only-Quarantäne als eng begrenzte read-only beziehungsweise Schutzpfade verfügbar; bei fehlender oder falscher Signatur bleiben auch sie fail-closed.
- Die Sicherheits-Updates nach 401/403 bleiben voneinander unabhängig: Alarm, Review-Fallback, Sync-Stopp und Jobpause. Scheitert die Alarmzeile, speichert der erste atomare Fallback Review-Marker und neuen Token; die gültige Signatur bleibt für Mail- und PDF-Schutz erhalten. Ein erfolgreicher atomarer Fallback wird danach nicht erneut gesetzt, damit er keine spätere Setup-Freigabe überschreibt. Scheitert auch der Token-Write, muss der zweite atomare Fallback Review-Marker und ungültige Signatur gemeinsam speichern. Nur wenn beide Fallbacks scheitern, folgt ein letzter Schreibversuch für den Review-Marker. Sync-Stopp und Jobpause werden in jedem Fall versucht. Tests decken Erfolg und Rollback der Token-Speicherung sowie beide Alarm-Fallbacks ab.

Die Legacy-Fixture ist bewusst klein und vollständig synthetisch. Sie enthält vollständige, leere und verwaiste Mappings sowie künstliche Kollisionen.

### 3. API-Contract-Tests

Der HTTP-Client läuft in diesen Tests gegen einen Fake-Server oder Mock-Handler. Die Fixtures orientieren sich an `docs/sevdesk-openapi.yaml`.

Abzudecken sind:

- gültige Systemversion;
- `ReceiptGuidance` mit erlaubter und verbotener Kombination;
- Contact-Read/Create;
- PDF-Upload mit HTTP 201 und Dateiname;
- Voucher-Create mit HTTP 201 und Remote-ID;
- Invoice-Create `RE`/100 mit HTTP 201, finaler WHMCS-Nummer, Marker, SevUser, Unity, Land, Rule und Positionen;
- `GET /Invoice/{id}` und `getPositions` mit exakter ID-/Nummer-/Kontakt-/Rule-/Status-/Summenprüfung;
- `sendBy`, `sendViaEmail`, `getPdf` und typabhängiges `/Invoice/{id}/bookAmount`;
- widersprüchliche, unvollständige oder übergroße Invoice-/PDF-Antworten;
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
- Setupvalidierung für Exportmodus, Hoheit, OSS-Profil, Canary, SevUser, Unity, Proforma, Theme-Manifest, Mailvorlage und die widerrufbare Bestätigung zur Kontakt-Neuanlage mit interner WHMCS-Client-ID;
- Moduswechsel bei aktiven oder ungeklärten Exportitems blockieren und bestehende Mappings unverändert lassen;
- Invoice und Client über die vorgesehenen WHMCS-Schnittstellen laden;
- WHMCS-PDF mit synthetischen Rechnungsdaten erzeugen;
- sevDesk-PDF über die authentifizierte Addon-Route als Eigentümer streamen und fremde Invoice-/Remote-IDs ablehnen;
- Adminrollen und CSRF prüfen;
- Single- und Bulk-Job starten;
- Admin-Rechnungsbutton öffnet den vorausgefüllten Einzelimport; der kompakte
  Kurzexport akzeptiert ausschließlich CSRF-geschützte POSTs und erzeugt nur ein
  dedupliziertes Jobitem;
- Cron/Worker ausführen;
- einen leeren CLI-Runner ausführen und bestätigen, dass er nur den Heartbeat
  aktualisiert, kein Item claimt und keinen sevdesk-Service konstruiert;
- relevante Invoice-, Paid- und Checkout-Hooks auslösen;
- mit sevDesk-Hoheit prüfen, dass bezahlte Invoice-only-Fälle zunächst Pending und anschließend Ready/Failure zeigen und keine sichtbaren WHMCS-Endrechnungslinks behalten;
- Twenty-One-Adapter und Custom-Adaptervertrag prüfen;
- den echten Hookablauf ausführen: Bei `invoice_only`, sevDesk-Hoheit, aktivem Modul und gültiger Laufzeitsignatur blockiert `InvoicePaidPreEmail` bereits die erste WHMCS-Zahlungs-Mail ohne Job oder Remote-Aufruf. Das gilt auch während Review-, Authentifizierungs-, Canary- und Sync-Pausen;
- bei aktivem Sync und Canary erzeugt `InvoicePaid` genau einen Delivery-Job. Trotz alarmbedingt ausgeschaltetem Sync entsteht ein dedupliziertes Pending-Item nur bei `InvoicePaid`, Review aus, gültiger Signatur, bestätigtem Canary, `invoice_only`/sevDesk und bereits gesetztem Authentifizierungsalarm. Normales Sync-off sowie falsche Signatur, Review oder Canary erzeugen kein Item;
- `EmailPreSend` gibt nur die exakt vorregistrierte Kombination aus Invoice, Vorlage und Token frei und konsumiert den Binäranhang einmal;
- spätere Invoice-Mail ohne request-lokalen Guard: Template-, Mapping- oder
  Kontext-Lesefehler werden protokolliert, dürfen aber weder eine aktuelle
  globale Hoheit als Ersatz für den eingefrorenen Snapshot verwenden noch alle
  WHMCS-Mails pauschal unterdrücken;
- die Enqueue-Matrix `InvoiceCreated`/`InvoicePaid` gegen alle drei Exportmodi und beide Werte von `import_only_paid` verhaltensbasiert ausführen; `ClientAreaPageViewInvoice` muss für eine eigene, fertige Invoice den kleinen Adaptervertrag ohne Remote-I/O liefern;
- mit `module_active=on`, gültiger Signatur, `runtime_review_required=off` und `sync_enabled=off` bestätigen, dass InvoiceCreated,
  InvoicePaid, InvoiceRefunded, InvoiceCancelled und AddTransaction keine Jobs
  anlegen, während ein leerer oder manuell befüllter Runner weiterhin läuft;
- sicherstellen, dass Hook-Fehler niemals den WHMCS-Ablauf abbrechen.
- sicherstellen, dass der Mail-Hook keine sevDesk-Abfrage ausführt und in CLI-/Cron-Ausführung funktioniert.

### 5. End-to-End im sevDesk-Testmandanten

Für diese Tests ist ein separater Mandant mit sevDesk-Update 2.0 erforderlich. Die Tests legen dort echte Testobjekte an. Anschließend werden die Objekte gelöscht oder eindeutig als Testdaten gekennzeichnet.

Geprüft werden:

- Voucher: Kontakt, WHMCS-PDF, Datum, Marker, Währung, Status, Positionen, `taxRule`, `accountDatev`, Mapping und zweiter Lauf ohne Duplikat;
- Invoice: normale `RE` im Draft-Status 100, unveränderte WHMCS-Nummer, Marker, Kontakt, `SevUser`, `Unity`, `deliveryAddressCountry`, Netto/Brutto, Rule und WHMCS-Steuersatz;
- Invoice-Positionen ohne frei konfiguriertes `accountDatev`;
- `sendBy`, `sendViaEmail`, finale `getPdf`-Antwort und `/Invoice/{id}/bookAmount`;
- erneute exakte Draft-Prüfung direkt vor `sendBy` und `sendViaEmail`; eine zwischenzeitliche Header- oder Positionsänderung verhindert jeden Write;
- volle 1.000er-Seiten bei Invoice-Suche, Positionen und Zahlungskandidaten blockieren als potenziell abgeschnitten;
- PDF-Stabilität nach Finalisierung sowie ID-Eindeutigkeit zwischen Voucher und Invoice;
- typisiertes Mapping, zweiter Lauf ohne Cross-Type-Duplikat und Recovery nach gezielt unterbrochenem Create/Open/Versand.

Das vollständige Canary-Protokoll mit Mandant, Zeitpunkt, Testobjekten und Ergebnis bleibt außerhalb von Git. Im Repository wird nur das pseudonymisierte Gate-Ergebnis festgehalten. Token und Kundendaten werden dort nicht abgelegt.

Scheitern Rule 19, Marker oder ID-Eindeutigkeit, sind Hybrid- und gegebenenfalls alle Invoice-Modi nicht freigegeben, bis eine neue Architekturentscheidung vorliegt.

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
| digitale EU-B2C-Leistung, Rule 19, paid/finale Nummer, Profil und Canary bestätigt | Invoice in `invoice_for_oss`/`invoice_only`, niemals Voucher |
| Rule 19 ohne Profil/Canary, in `voucher_only`, vor Zahlung oder ohne finale Nummer | blockiert, kein Remote-Write |
| OSS Rules 18/20 oder gemischte/unklare Leistungsart | blockiert, weder Voucher noch Invoice |
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
| unbehandelter interner Fehler nach verifiziertem Seiteneffekt | sichere Fortsetzung bei Attempts 1–3 über `retry_wait`; bei Attempt 4 `ambiguous`, Remote-ID und Checkpoint bleiben erhalten |
| nach `document_type_selected` | Resume verwendet denselben Zieltyp, Hoheit, Steuerprofile, finale Nummer und Versandkanal, auch nach Setupwechsel; Invoice wird nur bei weiterhin `Paid` fortgesetzt |
| Hybrid-Rule-19 noch unbezahlt, danach `InvoicePaid` vor Workerstart | der bestehende Dedupe-Besitzer liest den aktuellen Paid-Status und exportiert genau eine Invoice |
| `InvoicePaid` während eines laufenden `invoice_payment_pending`-Abschlusses | entweder wird der Besitzer mit `invoice_payment_event_followup` einmal requeued oder das Paid-Ereignis legt nach Key-Freigabe ein neues Item an; nie geht das Ereignis verloren und nie laufen zwei Zieltypen parallel |
| während Invoice-Create ohne lesbare Response | `ambiguous`, nur Invoice-Marker-/Feldsuche, kein zweiter Create |
| nach Invoice-Create vor Mapping | exakt passenden Invoice-Treffer lesen und typisiertes Mapping ergänzen |
| während `sendBy` ohne lesbare Response | `ambiguous`, Status/SendType nur lesend beweisen, kein zweites Open |
| während `sendViaEmail` ohne lesbare Response | `ambiguous`, Delivery nur lesend beweisen, kein automatischer Resend |
| später Invoice-Checkpoint, aber lokales Mapping fehlt | `ambiguous`, Checkpoint erhalten, kein GET-zu-Create-Fallback und kein `saveInvoice` |
| nach `whmcs_email_write_requested` vor Rückgabe | `ambiguous`; manueller Resend nur nach Doppelversandwarnung |
| nach `whmcs_email_handed_off` | ausschließlich lokale Metadaten ergänzen; kein zweites `SendEmail`, kein PDF-/sevDesk-Abruf; als Providerübergabe, nicht als Postfachzustellung behandeln |
| PDF nach Ready mit anderem SHA-256 | Download blockieren und Prüffall melden |
| während Korrektur-POST ohne lesbare Response, anschließend kein Markertreffer | `ambiguous`, strikt lesende Recovery, kein zweiter Voucher-POST |
| beschädigter Booking-/Korrektur-Snapshot oder Preflight-Fehler nach riskantem Write-Checkpoint | `ambiguous`, riskanten Checkpoint, Remote-ID und Dedupe behalten; kein scheinbar frischer Permanentfehler |
| `import_after` wird nach begonnenem Voucher-/Invoice-Write geändert | Recovery ignoriert den nachträglichen Stichtag und klärt ausschließlich den begonnenen Dokumenttyp |
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
- gültige Rule-19-Invoice-Ziele sowie als `permanent_failed` markierte Rule-18/20-/Credit-/Mischfälle;
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
- Ein Cancel während einer Lease wandelt einen danach zurückkehrenden sicheren Retry in `cancelled` um; ein Retry nach möglichem oder bestätigtem Remote-Effekt wird `ambiguous` und hält den Dedupe-Key.
- Retry-Obergrenze wird eingehalten.
- Ein 401/403-Alarm stoppt nach dem betroffenen Item alle weiteren Claims im selben und in späteren Runner-Läufen, bis das Setup ihn nach erfolgreicher Prüfung löscht.
- Erfolgreiche Exporte besitzen genau ein typisiertes Mapping und genau ein Remote-Dokument; alte und neue Jobs erzeugen kein Cross-Type-Duplikat.
- Historische und Bulk-Items lösen unabhängig von der Dokumenthoheit keine automatische Mail aus.

## UI- und Bedienprüfung

Diese Punkte werden manuell oder mit passenden Browsertests geprüft:

- leere Suche, ungültige Datumsspanne und sehr großer Zeitraum;
- Pagination der Vorschau und Ergebnisliste;
- Buchungsvorschau über mehr als zehn positive Transaktionen sowie eine offene teilbezahlte Invoice;
- vollständig bezahlte Voucher und Invoices erscheinen nicht erneut als Buchungskandidaten, andere Prüffälle bleiben sichtbar;
- Booking-Worker blockiert veränderten Dokumenttyp, Remote-ID und bereits gebuchten Voucher-/Invoice-Betrag vor `bookAmount`;
- Transaktionsreferenzen matchen nur als vollständige Tokens (`TX-42` darf `TX-420` nicht treffen); eine Post-Write-Recovery bleibt ohne lesbaren Verknüpfungsnachweis `ambiguous`;
- Fortschritt nach Reload und in einer zweiten Adminsession;
- klare Trennung von Erfolg, Skip, Fehler und manueller Prüfung;
- gezielter Retry und Bestätigung bei Unlink/Cancel;
- keine unescaped API-Meldung im HTML;
- keine PII in URL oder Querystring;
- Rollen ohne Modulzugriff können weder Jobs lesen noch starten.
- Zuordnungs-, Job- und Detailtemplates mit synthetischen Capsule-`stdClass`-Zeilen
  rendern beziehungsweise ihren View-Vertrag prüfen; `View` muss sie vor der
  Smarty-Punktnotation rekursiv zu Arrays normalisieren.
- Invoice-Control-Markup enthält kein verschachteltes Formular. Das externe
  Footer-Form enthält nur CSRF-Token und Invoice-ID; der Quick-Button verweist
  explizit darauf.
- Kurzexport bei vollständigem Mapping, Legacy-NULL, Guthaben, Fremdwährung,
  Null-/Negativbetrag, negativer oder fehlender Position, ungeeignetem Status und
  Rechnung vor `import_after` prüfen. Nur der normale Einzelimport bleibt als
  erklärender Preflight verfügbar.
- Mehrfachklick beziehungsweise zwei Adminsessions erzeugen dank aktivem
  `export_voucher:<invoiceId>`-Dedupe-Key keinen zweiten aktiven Export.
- Modus-/Hoheitsmatrix, Canary, Rule-19-Bestätigung, SevUser, Unity, Proforma,
  Theme-Manifest und Versandvorlage auf der Setupseite prüfen. Aktive oder
  `ambiguous` Exportitems müssen einen Wechsel von Modus, Hoheit oder den
  klassifikationsrelevanten OSS-/EU-B2C-Profilen blockieren.
- Legacy-Mapping ohne Typ liest Voucher und Invoice bei Vorschlag sowie Bestätigung
  getrennt. Nur ein exakter Endpoint-Treffer darf vorgeschlagen werden; erst eine
  getrennte Bestätigung ergänzt `document_type` und löst keinen Export aus.
  Markerlose Originalmodul-Belege erscheinen als schwächerer Legacy-Nachweis,
  widersprüchliche Marker und Cross-Type-ID-Kollisionen bleiben blockiert. Ein
  Voucher-`400` darf insbesondere nicht als Abwesenheit behandelt werden, während
  der dokumentierte Invoice-`400` den Voucher-Treffer nicht verdeckt.
- Clientbereich für Proforma, Pending, Ready und Failure mit Eigentümer- und
  PDF-Hashprüfung testen. Bei sevDesk-Hoheit darf kein normaler sichtbarer
  WHMCS-Endrechnungslink übrig bleiben. Historische Voucher und frühere
  sevDesk-Invoices müssen ihre jeweils eingefrorene Hoheit auch nach einem
  globalen Setupwechsel behalten.
- Den echten Clientarea-Einstiegspunkt in einem isolierten WHMCS-Harness ausführen: fremder Eigentümer und falscher Typ enden vor jedem PDF-Abruf mit 404, unvollständiges Ready mit 409, Hash- oder API-Fehler mit bereinigtem 503, ein 401/403 setzt den globalen Alarm und nur der vollständig passende Besitzer erhält exakt die geprüften PDF-Bytes.
- Direkten WHMCS-Core-PDF-Endpunkt als bekannte technische Restgrenze dokumentieren;
  der Test garantiert Kundenoberfläche und E-Mail-Auslieferung, keine Core-Änderung.
- sevDesk- und WHMCS-Template-Versand separat prüfen. Bulk-/Backfill-Jobs dürfen
  keine Mail auslösen; ein unklarer Versand darf nicht automatisch wiederholt werden.
- Im Browserrequest des Kurzexports werden weder sevdesk-Client, Receipt Guidance,
  Kontaktauflösung, PDF noch Worker aufgerufen. Nach dem Queueing läuft die
  Verarbeitung auch bei geschlossenem Browser weiter.
- Eine im Worker lokal blockierte Rechnung darf vor ihrem Fehler weder PDF noch
  Receipt Guidance laden, PDF erzeugen noch einen neuen sevdesk-Kontakt anlegen.
- `invoice_only` muss ohne Voucher-Konto und ohne Receipt-Guidance-Abruf eine
  gültige Invoice-Steuerentscheidung liefern; der Hybridmodus darf Guidance nur
  für seine tatsächlichen Voucher-Ziele laden.
- Eine volle sichere Suchseite von 1.000 Invoice- oder Zahlungskandidaten muss
  wegen nicht beweisbarer Eindeutigkeit blockieren, bevor ein Write erfolgt.
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
- PDF-Proxy auf IDOR testen: fremder Kunde, fremde WHMCS-Invoice, direkt übergebene sevDesk-ID, untypisiertes Mapping und fehlendes Ready müssen scheitern.
- Mailanhang-Token auf Zufälligkeit, einmaligen Verbrauch, falsche Vorlage, falsche Invoice und Prozessgrenze testen.
- bestätigen, dass weder PDF-Bytes noch E-Mail-Adresse/Betreff/Text in Job- oder Fehlerlogs landen.
- Abhängigkeiten auf bekannte Schwachstellen prüfen, sobald ein Composer-Setup existiert.

## Manuelle Buchhaltungsabnahme

Vor dem Nachlauf prüft die Buchhaltung je freigegebener Steuerklasse mindestens einen Canary des tatsächlich gewählten Dokumenttyps:

- richtiger Kunde/Kontakt;
- richtige Belegnummer und Datum;
- vollständiges PDF;
- korrekte Kontierung;
- korrekte Tax Rule und Steuersätze;
- korrekte Netto-, Steuer- und Bruttosumme;
- kein bereits vorhandener Doppelbeleg.

Für Invoice kommen unveränderte WHMCS-Nummer, `RE`, Rule 19/andere freigegebene Rule, SevUser, Unity, Landsteuersatz, fehlendes frei konfiguriertes `accountDatev`, Öffnungs-/Versandstatus und Stabilität der finalen sevDesk-PDF hinzu. Ein Voucher-Canary ersetzt diesen Invoice-Canary nicht.

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
9. der externe Invoice-Canary Rule 19, Marker, Pflichtreferenzen, `sendBy`, `sendViaEmail`, `getPdf`, Invoice-`bookAmount`, PDF-Stabilität und Voucher-/Invoice-ID-Eindeutigkeit bestätigt hat.
10. für sevDesk-Dokumenthoheit Proforma, Twenty-One-/Custom-Adapter, beide Versandkanäle und die authentifizierte PDF-Route in WHMCS 8.13.4 bestanden sind.
11. für einen Drop-in-Wechsel die Funktionsmatrix gegen den realen Altbetrieb geprüft und ein Dateirückwechsel sowohl vor als auch nach einem synthetischen Invoice-Mapping geprobt beziehungsweise nach Invoice-Beginn nachweislich blockiert wurde.
12. das Positivlisten-Releasearchiv die eigenständige `UPGRADE.md` und die GPL-Lizenz enthält, aber weder Tests, `vendor/` noch lokale Arbeitsdaten.

Offene Punkte in Steuerlogik, Idempotenz oder Mappingmigration blockieren das Release.
