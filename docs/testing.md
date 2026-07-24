# Teststrategie

## Ziel

Die Tests müssen vor allem zwei Schäden verhindern: falsche Buchungen und doppelte Belege. Reine Happy-Path-Abdeckung reicht nicht. Prozessabbruch, Timeout und unvollständige Remote-Antworten gehören zum normalen Testumfang.

Die Tests verwenden ausschließlich synthetische Kunden, Invoices und API-Fixtures. Private Dumps, echte PDFs, Token und Kundendaten sind als Testdaten verboten.

## Aktueller automatisierter Nachweis

- Die schnelle Unit-/Contract-/Kompositionstestsuite ist lokal grün;
- PHP-Lint und PSR-12 laufen über den vollständigen Modul- und Testbaum;
- PHPStan analysiert den vollständigen PHP-Modulcode auf Level 6;
- Unit-/Contract-Tests decken Dokumentzielresolver, Rule-19-Gates, Invoice-Payload,
  Invoice-Reconciliation, PDF-Prüfung, die native ZUGFeRD-Auswahl, XML-Prüfung
  und den einmaligen In-Memory-Mailkontext ab;
- Die MariaDB-Integrationstests prüfen eine kleine synthetische Legacy-Struktur, echte Unique-Constraints, Deduplizierung, Candidate-/Remote-ID-Erhalt und parallele Claims;
- Dieselbe Suite deckt sichere und riskante Lease-/Throwable-Recovery, den globalen Auth-Stopp, WHMCS-Kundenwährungen, Teilzahlungs-Pagination, Mapping-Revalidation und einen 1.000-Item-Lauf mit Fehler in der Mitte ab;
- Ohne konfigurierten Server meldet die lokale MariaDB-Suite ihre Tests als `skipped`. In CI und bei einem Lauf über `tools/test-mariadb.sh` sind sie verpflichtend.

MariaDB und PHP 8.3 bleiben eigene Release-Gates. Ein übersprungener Datenbanktest oder ein Lauf unter einer anderen PHP-Version ersetzt diese Nachweise nicht.

Der Invoice-API-Canary und der davon getrennte ZUGFeRD-Canary sind eigene externe Gates. Mocks, OpenAPI-Fixtures und gesetzte Konfigurationswerte beweisen nicht, dass sie stattgefunden haben. Mit synthetischen Daten bestätigt sind inzwischen Rule 19, ZUGFeRD-Create und -Readback, `getXml`, `getPdf`, `sendBy`, der direkte sevDesk-Versand, die externe EN-16931-Prüfung und echte Kundensitzungen. Der eigene Kunde erhielt beim Download exakt die geprüften PDF-Bytes; ein fremder Kunde und ein delegierter Benutzer ohne `invoices`-Recht erhielten keinen Dokumentzugriff. Im aktiven Custom Theme blieb außerdem kein normaler WHMCS-PDF-Link sichtbar. Beide WHMCS-Mailtests lieferten dagegen die WHMCS-Core-PDF. Der zweite Lauf verbrauchte den In-Memory-Kontext korrekt und bewies damit den eigentlichen Kompatibilitätsfehler: WHMCS 8.13 ignoriert aus `EmailPreSend` zurückgegebene Binäranhänge. Setup, Health Check und Worker sperren `whmcs_template` deshalb auf der Zielplattform. Invoice-`bookAmount`, der rabattfreie Rule-11-Invoice-Canary, der anschließende Rabatt-Canary, die Voucher-Canaries der tatsächlich verwendeten Steuerfälle und die fachliche Abnahme sind weiterhin offen. Die Setup-Gates bleiben deshalb aus.

## Testebenen

### 1. Unit-Tests

Schnelle Tests ohne WHMCS-Datenbank oder Netzwerk für:

- Steuerklassifikation;
- Eligibility (`Paid`, `import_after`, Sonderfälle);
- Konto-/Rule-/Rate-Matching gegen eine Guidance-Fixture;
- Netto-/Brutto- und Rundungslogik;
- Voucher-/Position-Payload;
- vollständige `voucher_only`-/`invoice_for_oss`-/`invoice_only`-Matrix mit beiden Dokumenthoheiten;
- gefrorene Dokumentzielentscheidung einschließlich paid-only, effektiver Rechnungsnummer und fehlendem Fallback;
- zentrale, read-only Auflösung der effektiven Rechnungsnummer: getrimmtes `invoicenum` oder bei leerem
  Legacy-Wert die interne Invoice-ID; Snapshot, Dry-run und Worker dürfen den Fall nicht unterschiedlich
  bewerten und `tblinvoices` nicht verändern;
- Invoice-/InvoicePos-Payload, Pflichtreferenzen, Menge 1 und bewusst fehlendes `accountDatev`;
- exakte `StaticCountry`-Auflösung für Invoice, Kontaktanlage und E-Rechnung einschließlich leerer, unbeschrifteter, mehrdeutiger und fehlerhafter Antworten sowie der GB-Dubletten; jeder Fehler muss vor dem jeweiligen Write enden;
- gelöschter SevUser oder gelöschte Unity zwischen Zielauswahl und Create: Blockade vor `invoice_write_requested`; 429 und Transportfehler der rein lesenden Prüfung bleiben sicher wiederholbar;
- Invoice-Recovery nach begonnenem Write verwendet die eingefrorenen SevUser-/Unity-IDs und liest die aktuellen Referenzlisten nicht erneut;
- kleingeschriebenes OSS-Land im Payload, passende `addressCountry`-Referenz und Readback über `embed=addressCountry`;
- exakte Invoice-/Positionsrückprüfung und typabhängige Booking-Endpunkte;
- PDF-MIME, Signatur, EOF, Größenlimit, Dateiname und SHA-256;
- ZUGFeRD-Auswahl nur für `invoice_only` mit sevDesk-Hoheit, deutschem Organisationskunden, Rule 1, gültigem Aktivierungsdatum, gesetztem Admin-Tickbox-Feld und bestätigtem Canary;
- kein ZUGFeRD bei Rule 19, Rules 18/20, B2G, historischem Backfill, fehlendem XMLReader oder Rechnung vor dem Aktivierungsdatum;
- kein stiller Fallback, sobald das Kunden-Opt-in greift und Kontakt-, Adress-, PaymentMethod-, Unity-, SevUser- oder Länderdaten fehlen;
- `propertyIsEInvoice`, strukturierte Adresse, `PaymentMethod`, `takeDefaultAddress=false`, Rückprüfung des Adresshashs sowie unveränderliche PDF-/XML-Hashes; ein fehlendes Flag ist nur zusammen mit gültigem CII-XML zulässig, ein ausdrücklich falsches Flag nie;
- `getXml` mit Größenlimit, UTF-8, CII-Wurzel, Wohlgeformtheit, DTD-/Entity-Blockade und fehlender XMLReader-Laufzeit;
- einmaliger, invoice- und templategebundener In-Memory-Anhang;
- Fehlerklassifikation und Retry-Entscheidung;
- Statusübergänge von Job und Item;
- lazy Kontakt-Referenzdaten: bestehende/verknüpfte Kontakte dürfen weder
  Address-Kategorie noch CommunicationWay-Key vorab laden;
- die Haupt-E-Mail-Abfrage filtert den Kontakt serverseitig und prüft Kontakt-ID, Typ und Hauptkennzeichen nochmals lokal; fremde Kontakte, Telefonwege und Nebenadressen zählen nicht;
- explizite Legacy-Kontakt-ID: Eine vorhandene Remote-ID bleibt auch ohne oder mit historisch anderer `customerNumber` maßgeblich und wird nicht aktualisiert. Ein 400/404 blockiert ohne Such- oder Create-Fallback;
- leeres Kontaktfeld: Zulässig sind kein Treffer, genau ein Treffer oder mehrere exakt geprüfte Kundennummerntreffer. Listentreffer ohne Kundennummer werden per ID nachgelesen. Beweist auch der Einzelabruf keine Gleichheit, blockiert das Modul den Fall als unverifizierbar;
- Kontakt-Neuanlage: Ein leerer Suchausgang blockiert ohne `customer_number_contact_creation_confirmed` vor Checkpoint und POST. Nach der Bestätigung darf dieselbe Eingabe einen Kontakt anlegen;
- fehlende, gelöschte oder nicht als `client` typisierte `custom_field_id` blockiert vor WHMCS-Kundenladen und vor jedem sevDesk-Kontaktpfad;
- die Kontakt-ID wird unter Datenbanksperre nur in ein leeres Feld geschrieben. Gleiche IDs sind idempotent; abweichende IDs, doppelte Feldzeilen und gelöschte Kunden blockieren ohne `UpdateClient`-Seiteneffekt;
- Bereinigung sensibler Daten aus Fehlermeldungen;
- markerbasierte Legacy-Sammeltypisierung und gesperrtes Aufheben eines noch vorhandenen Remote-Dokuments;
- Legacy-sevDesk-Hoheit nur bei atomar erneut geprüftem WHMCS-Status `Paid`; ein fertiges Mapping darf bei `Unpaid` weder im Presenter noch im PDF-Proxy als Endrechnung erscheinen, bleibt nach einem späteren `Refunded`-Status aber abrufbar;
- eine eingefrorene Legacy-Entscheidung darf weder in der Einzel- noch in der Sammelbestätigung auf einen anderen Typ oder eine andere Hoheit umgestellt werden; Remote-Status 200, 750 und 1000 gelten als final, Draft 100 nicht;
- der PDF-Proxy prüft den lokalen Rechnungsstatus vor und nach dem sevDesk-Abruf, sodass ein Wechsel während des Remote-Reads keine PDF ausliefert;
- alte Voucher-Vor-Write-Jobs mit abweichender Konfiguration: normaler Retry blockiert, bestätigter neuer `export_document`-Job mailfrei und ohne E-Rechnung;
- Health-Kompatibilität für die von WHMCS verwendete stabile Versionsform
  `8.13.4-release.N`, ohne Beta-/RC- oder WHMCS-9-Versionen freizugeben;
- bewusst unbestätigte optionale Steuerprofile erscheinen als Warnung und
  bleiben fachlich blockiert, während fehlerhafte bestätigte Profile den Health
  Check weiterhin als Fehler blockieren; ein global aktivierter
  Kleinunternehmermodus macht das zugehörige Profil verpflichtend;
- Kleinunternehmer aus, unbegrenzt sowie mit Stichtag: Ein Rechnungsdatum am
  31.12.2025 verwendet Rule 11, ein Datum ab 01.01.2026 nicht mehr. Ungültige
  gespeicherte Stichtage blockieren Worker und Dry-run nur bei aktivem Profil.
- der aktive Kleinunternehmerzeitraum gewinnt bei gültigem Ländercode vor deutscher, EU-B2C-, EU-B2B-, Drittland- und AddFunds-Klassifikation; Voucher und Invoice verwenden Rule 11 mit 0 %, wobei AddFunds den Invoice-Canary und die Guidance-Prüfung nicht umgehen darf;
- reine Sammelzahlungscontainer, Voll- und Teilguthaben an exakt verknüpften Originalrechnungen sowie fehlende, doppelte oder widersprüchliche Elternbelege;
- Änderung von Parent, Fingerprint, Guthaben oder Dokumentbrutto vor der Dokumententscheidung sowie während PDF-, Kontakt- oder E-Rechnungs-I/O: unmittelbar vor dem Beleg-POST dauerhaft blockiert; nach einem möglichen Write `ambiguous`, jeweils ohne zweiten Remote-Write;
- ein Hook-Job darf seine bestätigte Parent-ID weder verlieren noch zu einem später passenden Parent oder einer gewöhnlichen Rechnung wechseln. Trifft ein Paid-Hook auf einen wartenden Hybridjob, wird dieselbe Parent-ID übernommen; eine abweichende ID setzt einen nicht überschreibbaren Konflikt;
- zwei aktive Elternbelege blockieren beide Container, das gemeinsam referenzierte Ziel und jedes weitere Geschwisterziel im betroffenen Zahlungsgraphen;
- nur nachweislich `Unpaid` oder `Cancelled` darf ohne weitere Zahlungsspur als inaktiver Altversuch gelten; `Refunded`, `Collections`, `Draft` und unbekannte Zustände bleiben gesperrt;
- separate Refund-Zeilen mit fremder `invoiceid` und `refundid` zur ursprünglichen Zahlung blockieren Parent und Zielrechnung;
- Änderung von Rechnungsdatum oder -nummer, Steuerart, Steuersatz, Steuerkennzeichen, Positionstext oder steuerlich relevanten Kundendaten zwischen Snapshot und Kontakt- beziehungsweise Beleg-Write. Im Jobsnapshot darf davon nur der SHA-256-Wert stehen;
- die sevDesk-ID aus dem WHMCS-Kundenfeld wird separat eingefroren: leer zu genau der im selben Workflow verknüpften ID ist zulässig, A zu A bleibt stabil, A zu B blockiert vor dem Beleg-POST und wird nach einem möglichen Write `ambiguous`. Kennt die Recovery bereits A, liest und verknüpft sie ausschließlich A; auch bei leerem WHMCS-Feld darf ein eindeutiger Kundennummerntreffer B den gespeicherten Empfänger nicht ersetzen;
- Kontaktneuanlage wird bei Vertragsdrift vor `POST /Contact` gestoppt. Wurde der Kontakt bereits erstellt, unterbleiben veraltete optionale Adress- und E-Mail-Writes, und der Dokument-Write bleibt gesperrt;
- der Paid-Hook darf Container-Mail und Zieljobs erst nach der vollständigen lokalen Prüfung freigeben. Reine `Invoice`-Positionen mit fremdem Kunden, falschem Betrag, fehlendem Ziel oder unbezahltem Elternbeleg bleiben unter dem normalen Authority-Guard;
- ein großer, exakt bestätigter Sammelzahlungsgraph wird im selben Hook-Request nur einmal vollständig gelesen. Die anschließenden Zielereignisse verwenden den request-lokalen Nachweis; ein normaler Worker-Aufruf muss Änderungen trotzdem sofort erkennen;
- ein eindeutig inaktiver alter Sammelzahlungsversuch darf genau eine später vollständig passende Kette nicht blockieren. Ein Elternbeleg mit Zahlung, Mapping oder mehreren passenden Nachfolgern bleibt ein Klärfall;
- `PromoHosting` nur anhand Typ, `relid` und `taxed`: ein eindeutiger Rabatt wird normalisiert, Beschreibungen klassifizieren nichts, mehrere oder fremde negative Positionen bleiben blockiert;
- Rabatt-Fingerprint und Marker: Text-, Relations-, Steuer- oder Betragsdrift nach `invoice_write_requested` wird `ambiguous`; gleiche Rabattsumme mit falschem Marker darf kein Mapping wiederherstellen;

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
- additive Nullable-Spalten `document_type`, `document_authority`, `document_number`, `document_ready_at`, `delivered_at`, `pdf_sha256`, `is_e_invoice` und `xml_sha256`; alte Zeilen behalten die neuen Felder als `NULL`;
- keine automatische Typ- oder Hoheitsannahme für vollständige Legacy-Mappings und atomare Typ-/ID-/Hoheitsspeicherung für neue Mappings;
- typisierte Voucher und Invoices behalten beim Modus- oder Hoheitswechsel ihre bestehende Zuordnung und den eingefrorenen Dokumentkontext;
- rein lesende Übergangsinventur mit frischem Fingerprint, vollständigen, untypisierten, `NULL`- und verwaisten Mappings sowie aktiven, unklaren und alten fehlgeschlagenen Exportjobs;
- markerbasierte Sammelbestätigung von höchstens 25 sichtbaren Legacy-Mappings; die Hoheit wird je Invoice ausdrücklich gewählt, Voucher bleiben bei WHMCS. Markerlose, kollidierende oder zwischen Vorschau und Bestätigung geänderte Treffer bleiben einzeln gesperrt;
- vollständige Mappings lassen sich nur nach atomarer Revalidation und eindeutigen 400- oder 404-Antworten von beiden Voucher-/Invoice-by-ID-Endpunkten entfernen; ein vorhandenes Objekt oder ein unklarer Read erhält die Zeile;
- fehlende und bereits vorhandene Unique-Indizes;
- Job-/Item-Constraints und Pagination;
- eindeutiger `dedupe_key` bei überlappenden Jobs;
- MySQL Advisory Lock und atomarer Claim bei zwei Workern;
- sofortiger Claim bei abweichenden PHP-Zeitzonen von Web und CLI, solange beide dieselbe Datenbank-Session-Zeit verwenden; Lease, normaler Retry und `invoice_payment_event_followup` müssen aus Sicht von `CURRENT_TIMESTAMP` korrekt in der Zukunft liegen;
- Lease-Ablauf und Übernahme;
- Checkpoint-gesteuerte Entscheidung zwischen `retry_wait` und `ambiguous`;
- Merge eines Outcomes gegen die aktuelle Checkpoint-Zeile, damit ein veralteter
  Claim-Snapshot weder `whmcsClientId` noch Remote-ID oder Bestätigungskontext
  überschreibt;
- parallele Jobs für dieselbe Invoice;
- alte `export_voucher`- und neue `export_document`-Items im gemeinsamen Dedupe-Namensraum;
- alte sichere Voucher-Vor-Write-Items werden bei veraltetem Exportkontext mit `stale_export_context_requeue_required` markiert. Nur der bestätigte Requeue erzeugt einen neuen mailfreien Job im aktuellen Modus, während riskante Checkpoints ihren alten Pfad behalten. Ein riskanter terminaler Altjob sperrt zentral auch Kurzexport, Einzelimport und Backfill für dieselbe Rechnung;
- historische Backfills frieren `historicalBackfill=true`, `delivery_requested=false` und E-Rechnungsmodus `off` ein;
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
- Invoice-Create `RE`/100 mit HTTP 201, effektiver WHMCS-Nummer, Marker, SevUser, Unity, Land, Rule und Positionen;
- `GET /Invoice/{id}` und `getPositions` mit exakter ID-/Nummer-/Kontakt-/Rule-/Status-/Summenprüfung;
- native E-Invoice mit `propertyIsEInvoice=true`, strukturierter Adresse, `PaymentMethod`, `takeDefaultAddress=false` sowie exakter Rückprüfung von Kontakt, Unity, PaymentMethod und Adresshash; die Matrix enthält vorhandenes Wahr-Flag, ausgelassenes Flag mit gültigem oder ungültigem XML und ein ausdrücklich falsches Flag;
- `getXml` mit gültigem CII, leerer/übergroßer/ungültiger Antwort, DTD/Entity, Hashabweichung und fehlender XMLReader-Laufzeit;
- `sendBy`, `sendViaEmail`, `getPdf` und typabhängiges `/Invoice/{id}/bookAmount`; bei `getPdf` sowohl dokumentiertes JSON/Base64 als auch die reale Raw-PDF-Antwort, fehlerhaftes Content-Encoding, HTTP 206/401, MIME, Signatur, Trailer und Größenlimit;
- `sendViaEmail(sendXml=false)` für ZUGFeRD, ohne lose XML-Datei im WHMCS-Mail- oder Kundenpfad;
- widersprüchliche, unvollständige oder übergroße Invoice-/PDF-Antworten;
- 400, 401, 403, 404, 409, 422, 429 und 5xx;
- Connect-Timeout, Read-Timeout, leere Antwort, ungültiges JSON und fehlende Pflichtfelder;
- `Retry-After` und begrenzter Backoff;
- Token-Redaktion in Exception, Log und Debug-Dump;
- ein fehlgeschlagener Setup-Write darf weder gebundene Datenbankwerte noch SQL
  im Admin-Hinweis ausgeben. Insbesondere bleibt ein neu eingegebener API-Token
  auch dann verborgen, wenn der Datenbanktreiber ihn in seine Exception einbaut;
- unerwartete lokale Worker-Fehler dürfen weder SQL noch Bindings oder
  Kundendaten in Job-Ergebnis oder Kandidatenkontext übernehmen. Der Job zeigt
  nur einen festen Hinweis mit einer anonymen Referenz.

Contract-Tests prüfen, ob das Modul die Spezifikation wie vorgesehen interpretiert. Einen Test gegen einen echten sevDesk-Mandanten ersetzen sie nicht.

### 4. WHMCS-Integrationstests

In einer Testinstallation mit WHMCS 8.13.4 und PHP 8.3:

- Addon aktivieren, upgraden und deaktivieren;
- Settings-Seite mit sevDesk online und offline öffnen;
- prüfen, dass `sevdesk_config()` keine operativen Standardfelder veröffentlicht und Änderungen nur über die geschützte Setupseite möglich sind;
- Setupvalidierung für Exportmodus, Hoheit, OSS-Profil, Canary, SevUser, Unity, Proforma, Theme-Manifest, den unter WHMCS 8.13 gesperrten Mailvorlagenkanal und die widerrufbare Bestätigung zur Kontakt-Neuanlage mit interner WHMCS-Client-ID;
- ZUGFeRD-Setup mit Modus, vorhandenem Admin-Tickbox-Feld, PaymentMethod, Aktivierungsdatum, eigenem Canary und PHP XMLReader;
- Übergangsinventur und Fingerprint vor Änderungen an Modus, Hoheit, OSS-, E-Rechnungs-, Rabatt-Canary- oder Kleinunternehmerprofil; das Speichern allein darf keinen Job anlegen;
- Moduswechsel bei aktiven oder ungeklärten Exportitems blockieren und bestehende Mappings unverändert lassen;
- Invoice und Client über die vorgesehenen WHMCS-Schnittstellen laden;
- WHMCS-PDF mit synthetischen Rechnungsdaten erzeugen;
- sevDesk-PDF über die authentifizierte Addon-Route als Eigentümer mit Benutzerrecht `invoices` streamen und fremde Invoice-/Remote-IDs sowie delegierte Benutzer ohne dieses Recht vor Mapping- und Remote-I/O ablehnen;
- Adminrollen und CSRF prüfen;
- Single- und Bulk-Job starten;
- gemeinsame Altbestandsvorschau und mailfreien `historical_backfill` starten; jeder mögliche Invoice-/Voucher-Dublettenhinweis muss das Create blockieren und darf kein Mapping raten;
- ein altes sicheres Voucher-Vor-Write-Item nach Moduswechsel nur über die bestätigte Requeue-Aktion neu einreihen; normaler Retry und riskante Checkpoints bleiben gesperrt;
- Admin-Rechnungsbutton öffnet den vorausgefüllten Einzelimport; der kompakte
  Kurzexport akzeptiert ausschließlich CSRF-geschützte POSTs und erzeugt nur ein
  dedupliziertes Jobitem;
- Cron/Worker ausführen;
- einen leeren CLI-Runner ausführen und bestätigen, dass er nur den Heartbeat
  aktualisiert, kein Item claimt und keinen sevdesk-Service konstruiert;
- relevante Invoice-, Paid- und Checkout-Hooks auslösen;
- mit sevDesk-Hoheit prüfen, dass bezahlte Invoice-only-Fälle zunächst Pending und anschließend Ready/Failure zeigen und keine sichtbaren WHMCS-Endrechnungslinks behalten;
- mit gesetztem ZUGFeRD-Kundenfeld prüfen, dass fehlende Pflichtdaten ohne normale Invoice als Fallback blockieren und der fertige Kundenpfad ausschließlich die geprüfte ZUGFeRD-PDF liefert;
- Twenty-One-Adapter und Custom-Adaptervertrag prüfen; der Nachweis in der Zielumgebung umfasst auch den aktiven Hostiko-Adapter und das Entfernen des sichtbaren Core-PDF-Links;
- den echten Hookablauf ausführen: Bei `invoice_only`, sevDesk-Hoheit, aktivem Modul und gültiger Laufzeitsignatur blockiert `InvoicePaidPreEmail` bereits die erste WHMCS-Zahlungs-Mail ohne Job oder Remote-Aufruf. Das gilt auch während Review-, Authentifizierungs-, Canary- und Sync-Pausen;
- bei aktivem Sync und Canary erzeugt `InvoicePaid` genau einen Delivery-Job. Trotz alarmbedingt ausgeschaltetem Sync entsteht ein dedupliziertes Pending-Item nur bei `InvoicePaid`, Review aus, gültiger Signatur, bestätigtem Canary, `invoice_only`/sevDesk und bereits gesetztem Authentifizierungsalarm. Normales Sync-off sowie falsche Signatur, Review oder Canary erzeugen kein Item;
- `EmailPreSend` gibt nur die exakt vorregistrierte Kombination aus Invoice, Vorlage und Token frei und konsumiert den Binäranhang einmal. Ein falscher Token darf den echten Kontext nicht als verbraucht markieren. Zusätzlich muss der Test festhalten, dass diese Hook-Rückgabe unter WHMCS 8.13 nicht als Zustellnachweis gilt und `whmcs_template` vor Remote-Writes gesperrt bleibt;
- eine typisierte Invoice ohne lesbaren, eingefrorenen Hoheitskontext bleibt sowohl im Paid-Ablauf als auch beim manuellen Mailversand gesperrt; nur ein positiv bestätigter WHMCS-Kontext darf die Sperre lösen;
- spätere Invoice-Mail ohne request-lokalen Guard: Template-, Mapping- oder
  Kontext-Lesefehler werden protokolliert, dürfen aber weder eine aktuelle
  globale Hoheit als Ersatz für den eingefrorenen Snapshot verwenden noch alle
  WHMCS-Mails pauschal unterdrücken;
- die Enqueue-Matrix `InvoiceCreated`/`InvoicePaid` gegen alle drei Exportmodi und beide Werte von `import_only_paid` verhaltensbasiert ausführen; `ClientAreaPageViewInvoice` muss für eine eigene, fertige Invoice den kleinen Adaptervertrag ohne Remote-I/O liefern;
- mit `module_active=on`, gültiger Signatur, `runtime_review_required=off` und `sync_enabled=off` bestätigen, dass InvoiceCreated,
  InvoicePaid, InvoiceRefunded, InvoiceCancelled und AddTransaction keine Jobs
  anlegen, während ein leerer oder manuell befüllter Runner weiterhin läuft;
- sicherstellen, dass Hook-Fehler niemals den WHMCS-Ablauf abbrechen.
- sicherstellen, dass der Mail-Hook keine sevDesk-Abfrage ausführt und in CLI-/Cron-Ausführung funktioniert. Der eigenständige Worker muss `hooks.php` vor dem Runner laden. Ohne Hook darf er keinen WHMCS-Versand starten; ein nicht verbrauchter Kontext muss in `whmcs_email_attachment_not_consumed` enden.

### 5. End-to-End im sevDesk-Testmandanten

Für diese Tests ist ein separater Mandant mit sevDesk-Update 2.0 erforderlich. Die Tests legen dort echte Testobjekte an. Anschließend werden die Objekte gelöscht oder eindeutig als Testdaten gekennzeichnet.

Geprüft werden:

- Voucher: Kontakt, WHMCS-PDF, Datum, Marker, Währung, Status, Positionen, `taxRule`, `accountDatev`, Mapping und zweiter Lauf ohne Duplikat;
- Voucher-Readback nach Create sowie Recovery prüfen Header und `VoucherPos` mit
  demselben Verifier. Falsche Rule-/Kontowerte, fehlender eingefrorener Vertrag,
  Pagination über mehr als 100 Treffer und die volle 1.000er-Sicherheitsgrenze
  dürfen kein Mapping erzeugen;
- Invoice: normale `RE` im Draft-Status 100, unveränderte WHMCS-Nummer, Marker, Kontakt, `SevUser`, `Unity`, kleingeschriebenes `deliveryAddressCountry`, passende `StaticCountry`-Referenz, Netto/Brutto, Rule und WHMCS-Steuersatz;
- normale Invoices übertragen die vollständige WHMCS-Rechnungsadresse mit `takeDefaultAddress=false`, auch wenn der vorhandene sevDesk-Kontakt keine `ContactAddress` besitzt; Readback, Recovery und Draft-Prüfung verlangen denselben Adresshash und dieselbe `StaticCountry`-ID;
- unvollständige WHMCS-Adressen, nicht eindeutig auflösbare Länder sowie ein nach möglichem Write fehlender oder abweichender PII-freier Adresssnapshot blockieren ohne Invoice-Write;
- Invoice-Positionen ohne frei konfiguriertes `accountDatev`;
- Rule-11-Invoice ohne eigenes Canary-Gate sowie mit fehlendem `REVENUE`-Scope in der aktuellen `ReceiptGuidance`: kein Create;
- `sendBy`, `sendViaEmail`, finale `getPdf`-Antwort und `/Invoice/{id}/bookAmount`;
- erneute exakte Draft-Prüfung direkt vor `sendBy` und `sendViaEmail`; eine zwischenzeitliche Header-, Adress- oder Positionsänderung verhindert jeden Write;
- volle 1.000er-Seiten bei Invoice-Suche, Positionen und Zahlungskandidaten blockieren als potenziell abgeschnitten;
- PDF-Stabilität nach Finalisierung sowie ID-Eindeutigkeit zwischen Voucher und Invoice;
- typisiertes Mapping, zweiter Lauf ohne Cross-Type-Duplikat und Recovery nach gezielt unterbrochenem Create/Open/Versand.

Der getrennte ZUGFeRD-Canary prüft zusätzlich:

- synthetischer deutscher Organisationskunde mit Admin-Opt-in, Käuferreferenz, Haupt-E-Mail und vollständiger sevDesk-Rechnungsadresse;
- Rule 1, `propertyIsEInvoice=true`, strukturierte Empfängeradresse, PaymentMethod und `takeDefaultAddress=false`;
- Readback von Kontakt, Unity, PaymentMethod und Adresse; ein vorhandenes Flag muss wahr sein, ein fehlendes Feld braucht den zwingenden XML-Nachweis;
- `getXml`, lokale CII-Strukturprüfung und externe EN-16931-Validierung;
- stabile PDF-/XML-Hashes über Create, Open, `sendBy` und Download;
- `sendViaEmail(sendXml=false)` und authentifizierter Kundendownload mit exakt der geprüften sevDesk-/ZUGFeRD-PDF;
- zwei eigene Kundendownloads mit erwartetem SHA-256, abgewiesener Cross-Client-Zugriff und ein delegierter Benutzer ohne Rechnungsrecht;
- Recovery nach Create, XML-/PDF-Abruf, Öffnung und Versand ohne zweiten Write.

Vor dem Rabatt-Canary läuft ein eigener, rabattfreier Rule-11-Invoice-Canary. Die aktuelle `ReceiptGuidance` muss mindestens ein `REVENUE`-Konto mit Rule 11 und 0 % anbieten. Danach werden Create, exakter Readback, `sendBy`, finale PDF und eine absichtlich unterbrochene Recovery geprüft. Ein bloß erstellter Draft reicht nicht: Der frühere Live-Lauf scheiterte erst beim Öffnen mit Code 7100. Der Canary bleibt deshalb aus, bis der vollständige Lifecycle im aktuell verbundenen Mandanten funktioniert.

Erst danach folgt der Rule-11-Rabatt-Canary mit genau einer positiven `Hosting`-Position und genau einem strukturell passenden `PromoHosting`-Rabatt. Er prüft `discountSave`, den vollständigen Rabattmarker, `sumDiscounts`, Positionen, Gesamtsumme, finale PDF und die Recovery nach einem absichtlich unterbrochenen Create. Ein gleicher Betrag mit verändertem Text, anderer Relation oder falschem Marker darf nicht als Treffer gelten.

Das vollständige Canary-Protokoll mit Mandant, Zeitpunkt, Testobjekten und Ergebnis bleibt außerhalb von Git. Im Repository wird nur das pseudonymisierte Gate-Ergebnis festgehalten. Token und Kundendaten werden dort nicht abgelegt.

Scheitern Rule 19, Marker oder ID-Eindeutigkeit, sind Hybrid- und gegebenenfalls alle Invoice-Modi nicht freigegeben, bis eine neue Architekturentscheidung vorliegt. Scheitert der ZUGFeRD-Canary, bleibt ausschließlich `e_invoice_canary_confirmed` aus; normale freigegebene Invoices dürfen dadurch nicht nachträglich umklassifiziert werden.

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
| Kleinunternehmer, Voucher | Rule 11 und 0 % nach dem bisherigen Guidance-Vertrag |
| Kleinunternehmer, Invoice | nur mit eigenem Canary und aktuellem REVENUE-Scope für Rule 11/0 %; sonst kein Create |
| AddFunds am oder vor dem Kleinunternehmer-Stichtag | Rule 11/0 % gewinnt; Invoice-Canary und aktuelle Guidance bleiben zwingend |
| AddFunds nach dem Kleinunternehmer-Stichtag | bestätigtes AddFunds-Sonderprofil bleibt unverändert wirksam |
| digitale EU-B2C-Leistung, Rule 19, paid/effektive Nummer, Profil und Canary bestätigt | Invoice in `invoice_for_oss`/`invoice_only`, niemals Voucher |
| Rule 19 ohne Profil/Canary, in `voucher_only`, vor Zahlung oder ohne auswertbare effektive Nummer | blockiert, kein Remote-Write |
| bezahlte Legacy-Rechnung mit leerem `invoicenum` | interne Invoice-ID als effektive Nummer in Dry-run, gefrorenem Snapshot, Payload und Mapping; kein DB-Backfill |
| OSS Rules 18/20 oder gemischte/unklare Leistungsart | blockiert, weder Voucher noch Invoice |
| deutscher Organisationskunde, Rule 1, `invoice_only`, sevDesk-Hoheit, Admin-Opt-in, Datum und beide Canaries bestätigt | native ZUGFeRD-Invoice mit geprüftem XML und PDF |
| ZUGFeRD-Opt-in gesetzt, aber Kontakt-/Adress-/Referenzdaten oder XMLReader fehlen | blockiert, keine normale Invoice als Fallback |
| Rule 19 oder historischer Backfill bei aktivem ZUGFeRD-Profil | normale Invoice beziehungsweise mailfreier Backfill mit `is_e_invoice=false` |
| Reverse Charge mit Voucher-inkompatibler Rule | blockiert |
| Drittland ohne eindeutige Leistungsart | blockiert |
| reine WHMCS-Sammelzahlungsrechnung | kein Umsatzexport; Hook reiht die Originalrechnungen ein |
| exakt verknüpfte Originalrechnung mit Voll- oder Teilguthaben | `subtotal + tax + tax2 = total + credit`; Dokumentbrutto `total + credit`, direkter Zahlteil und positive `tblaccounts` jeweils `total`; gemeinsame Banktransaktion bleibt manuell |
| gewöhnliches Guthaben ohne `Invoice`-Verknüpfung | nur im bestätigten Voucher-Einzelexport über den vollständigen Dokumentbruttobetrag |
| unvollständige Sammelzahlung oder anderer Guthabenfall | blockiert, kein Remote-Write |
| genau ein passender `PromoHosting`-Rabatt, Rule 11/0 %, Canary bestätigt | `invoice_only` mit festem `discountSave`, exaktem Marker- und Summenabgleich |
| PromoHosting-Drift nach möglichem Create | `ambiguous`, nur read-only Recovery |
| ZUGFeRD-Opt-in mit angewendetem Guthaben | blockiert, kein normaler PDF-Fallback |
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
| nach `document_type_selected` | Resume verwendet denselben Zieltyp, Hoheit, Steuerprofile, effektive Nummer und Versandkanal, auch nach Setupwechsel; Invoice wird nur bei weiterhin `Paid` fortgesetzt |
| alter sicherer Voucher-Vor-Write-Checkpoint nach Moduswechsel | `stale_export_context_requeue_required`; kein normaler Retry; erst bestätigter neuer mailfreier `export_document`-Job im aktuellen Modus |
| historischer Backfill findet Nummern-, Marker- oder Datum/Kontakt/Betrag-Kandidaten | `ambiguous`/Prüffall vor Create; kein Mapping und kein zweiter Remote-Beleg |
| Hybrid-Rule-19 noch unbezahlt, danach `InvoicePaid` vor Workerstart | der bestehende Dedupe-Besitzer liest den aktuellen Paid-Status und exportiert genau eine Invoice |
| `InvoicePaid` während eines laufenden `invoice_payment_pending`-Abschlusses | entweder wird der Besitzer mit `invoice_payment_event_followup` einmal requeued oder das Paid-Ereignis legt nach Key-Freigabe ein neues Item an; nie geht das Ereignis verloren und nie laufen zwei Zieltypen parallel |
| während Invoice-Create ohne lesbare Response | `ambiguous`, nur Invoice-Marker-/Feldsuche, kein zweiter Create |
| nach Invoice-Create vor Mapping | exakt passenden Invoice-Treffer lesen und typisiertes Mapping ergänzen |
| ZUGFeRD nach Invoice-Create vor `invoice_xml_verified` | bekannte Invoice lesen, `getXml` prüfen, ersten Hash einfrieren; kein zweiter Create |
| nach `invoice_xml_verified` mit anderem XML-Hash | `ambiguous`, Soll-Hash bleibt unverändert, kein Open oder Versand |
| ZUGFeRD-Opt-in mit fehlenden Pflichtdaten oder sevDesk-422 | blockiert, keine normale Invoice als Fallback |
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
- Historische Invoice-Items setzen `is_e_invoice=false`, auch wenn ZUGFeRD global für neue Invoices aktiv ist.
- Die read-only Invoice-/Voucher-Dublettensuche läuft vor jedem historischen Invoice-Create. Ein Treffer, eine volle 1.000er-Seite oder ein unklarer Read blockiert nur das betroffene Item und der Batch läuft mit der nächsten Rechnung weiter.

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
- Die Übergangsinventur zeigt typisierte, untypisierte, leere und verwaiste Mappings, relevante Jobzustände, bezahlte ungemappte Rechnungen und lokale Dublettenhinweise. Ein veralteter Fingerprint verhindert das Speichern einer geschützten Profiländerung.
- ZUGFeRD lässt sich nur mit `invoice_only`, sevDesk-Hoheit, XMLReader, separatem Canary, vorhandenem Admin-Tickbox-Feld, PaymentMethod und Aktivierungsdatum speichern.
- Legacy-Mapping ohne Typ liest Voucher und Invoice bei Vorschlag sowie Bestätigung
  getrennt. Nur ein exakter Endpoint-Treffer darf vorgeschlagen werden; erst eine
  getrennte Bestätigung ergänzt `document_type` und löst keinen Export aus.
  Markerlose Originalmodul-Belege erscheinen als schwächerer Legacy-Nachweis,
  widersprüchliche Marker und Cross-Type-ID-Kollisionen bleiben blockiert. Nur
  eine eindeutige 400- oder 404-Antwort darf je by-ID-Endpoint als Abwesenheit
  behandelt werden.
- Die Sammelvorschau übernimmt höchstens 25 sichtbare, markerbestätigte Legacy-Typen. Vor der Bestätigung geänderte Remote-Daten, markerlose Belege und Kollisionen bleiben unangetastet.
- „Aufheben“ entfernt eine vollständige Zuordnung nur nach eindeutigen 400- oder 404-Antworten von beiden by-ID-Endpunkten und atomarer Revalidation von Remote-ID und Dokumenttyp. 401/403 setzt den globalen Alarm; 429, 5xx, Timeout oder ein vorhandener Beleg lassen das Mapping stehen.
- `stale_export_context_requeue_required` zeigt keinen normalen Retry. Die gesonderte Aktion verlangt die Mailfrei-Bestätigung und legt nur bei einem sicheren Vor-Write-Zustand einen neuen Job an.
- Clientbereich für Proforma, Pending, Ready und Failure mit Eigentümer-,
  `invoices`-Berechtigungs- und PDF-Hashprüfung testen. Bei sevDesk-Hoheit darf kein normaler sichtbarer
  WHMCS-Endrechnungslink übrig bleiben. Historische Voucher und frühere
  sevDesk-Invoices müssen ihre jeweils eingefrorene Hoheit auch nach einem
  globalen Setupwechsel behalten.
- Den echten Clientarea-Einstiegspunkt in einem isolierten WHMCS-Harness ausführen: fremder Eigentümer, fehlendes Benutzerrecht `invoices` und falscher Typ enden vor Mapping- und PDF-Abruf mit 404, unvollständiges Ready mit 409, Hash- oder API-Fehler mit bereinigtem 503, ein 401/403 setzt den globalen Alarm und nur der vollständig berechtigte Besitzer erhält exakt die geprüften PDF-Bytes.
- Direkten WHMCS-Core-PDF-Endpunkt als bekannte technische Restgrenze dokumentieren;
  der Test garantiert die normale Kundenoberfläche, keine Core-Änderung.
- Den unterstützten sevDesk-Versand prüfen. `whmcs_template` muss unter WHMCS
  8.13.4 in Setup, Health Check und Worker gesperrt bleiben. Bulk-/Backfill-Jobs
  dürfen keine Mail auslösen; ein unklarer Versand darf nicht automatisch
  wiederholt werden.
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
- PDF-Proxy auf IDOR testen: fremder Kunde, delegierter Benutzer ohne `invoices`-Recht, fremde WHMCS-Invoice, direkt übergebene sevDesk-ID, untypisiertes Mapping und fehlendes Ready müssen vor Mapping- und Remote-I/O scheitern.
- Mailanhang-Token auf Zufälligkeit, einmaligen Verbrauch, falsche Vorlage, falsche Invoice und Prozessgrenze testen.
- bestätigen, dass weder PDF-Bytes noch E-Mail-Adresse/Betreff/Text in Job- oder Fehlerlogs landen.
- bestätigen, dass XML-Bytes und strukturierte Empfängeradressen weder in Jobs noch in Logs landen; persistiert werden nur IDs und Hashes.
- XML-Parser mit DTD, externen Entitäten, ungültigem UTF-8, übergroßen Antworten und falschem CII-Wurzelelement testen. Netzwerkzugriffe des Parsers müssen deaktiviert bleiben.
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

Für ZUGFeRD kommen Kunden-Opt-in, Rule 1, Käuferreferenz, Haupt-E-Mail, strukturierte Adresse, PaymentMethod, `propertyIsEInvoice`, extern geprüfte EN-16931-Konformität, XML-Hash und ZUGFeRD-PDF hinzu. Der normale Invoice-Canary ersetzt diesen getrennten E-Rechnungs-Canary ebenfalls nicht.

Anschließend gleicht die Buchhaltung die Summen jedes Nachlaufabschnitts zwischen WHMCS, Jobreport und sevDesk ab.

## Standardbefehle

Schnelle Tests und MariaDB-Suite laufen getrennt:

```bash
php -r 'exit(class_exists("XMLReader") ? 0 : 1);'
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
- ein identischer Checkpoint mit identischem Kontext innerhalb derselben MariaDB-Zeitsekunde bleibt erfolgreich, obwohl das Update null geänderte Zeilen meldet. Ein anderer Lease-Token oder abweichender Zielzustand darf diesen Vergleich nicht bestehen;
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
10. für sevDesk-Dokumenthoheit Proforma, Twenty-One-/Custom-Adapter, der direkte sevDesk-Versand und die authentifizierte PDF-Route in WHMCS 8.13.4 bestanden sind; `whmcs_template` bleibt dort nachweislich gesperrt.
11. der getrennte ZUGFeRD-Canary Create, Readback, `getXml`, externe EN-16931-Prüfung, stabile PDF-/XML-Hashes, `sendBy`, `sendViaEmail(sendXml=false)` und den Kundendownload bestätigt hat. PHP XMLReader muss in Web und Cron vorhanden sein.
12. Übergangsinventur, Legacy-Sammeltypisierung, sicheres Unlink, alter Voucher-Requeue und mailfreier Altbestands-Backfill in der Zielumgebung geprüft wurden.
13. für einen Drop-in-Wechsel die Funktionsmatrix gegen den realen Altbetrieb geprüft und ein Dateirückwechsel sowohl vor als auch nach einem synthetischen Invoice-Mapping geprobt beziehungsweise nach Invoice-Beginn nachweislich blockiert wurde.
14. bei Verwendung fester Rule-11-Rabatte der separate Canary `discountSave`, vollständigen Rabattmarker, `sumDiscounts`, Positionen, Gesamtsumme, PDF und read-only Recovery bestätigt hat.
15. das Positivlisten-Releasearchiv die eigenständige `UPGRADE.md` und die GPL-Lizenz enthält, aber weder Tests, `vendor/` noch lokale Arbeitsdaten. Die Release Notes werden separat am GitHub-Pre-Release veröffentlicht.

`2.1.0-rc.5` darf als klar gekennzeichnete GitHub-Vorabversion veröffentlicht werden, sobald die automatisierten Repository-Checks und der Archivscan grün sind. Das ist keine Produktivfreigabe. Die finale `2.1.0` und jeder Einsatz mit echten Buchhaltungsdaten bleiben bis zu allen oben genannten Zielumgebungs- und Canary-Nachweisen gesperrt.

Offene Punkte in Steuerlogik, Idempotenz oder Mappingmigration blockieren auch eine Vorabversion.
