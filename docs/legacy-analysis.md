# Legacy-Datenvertrag

## Zweck

Diese Datei beschreibt nur die technischen Altzustände, die für Migration, Recovery und Betrieb relevant sind. Produktive Bestandszahlen, Zeiträume, Statusverteilungen und konkrete Einstellungswerte werden nicht im Repository dokumentiert.

Für den aktuellen API-Vertrag gilt die versionierte OpenAPI-Datei.

## Bekannte Fehlerklassen

### Laufzeitkompatibilität

Die bisherige, nicht mehr gepflegte Erweiterung ist nicht mit der Zielplattform WHMCS 8.13.4 und PHP 8.3 kompatibel. Der Rewrite benötigt keine zusätzlichen Lizenz- oder Laufzeitdienste.

### EU B2C mit falscher Steuerregel

Ein EU-Privatkunde darf nicht allein wegen seines Landes wie ein innergemeinschaftlicher B2B-Fall behandelt werden. Eine solche Fehlklassifikation führt zu einer unzulässigen Kombination aus Steuerregel und Erlöskonto und kann von sevDesk mit HTTP 422 abgewiesen werden.

Land, Organisation, USt-ID und `taxexempt` müssen gemeinsam bewertet werden. Fehlen die nötigen Nachweise, bleibt der Fall B2C oder wird zur manuellen Prüfung blockiert.

### Konto und Steuerregel passen nicht zusammen

Eine statische Zuordnung von Steuerregeln reicht nicht aus, weil Erlöskonten mandantenspezifisch sind. Der Rewrite prüft daher Konto-ID, Steuerregel, Steuersatz und Dokumenttyp gemeinsam gegen `ReceiptGuidance`.

## Bestehender Datenvertrag

### Zuordnungstabelle

Die bestehende Datenbank enthält die Tabelle `mod_sevdesk`:

```sql
id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
invoice_id  INT NULL UNIQUE
sevdesk_id  VARCHAR(255) NULL UNIQUE
```

Es gibt keinen Foreign Key zu `tblinvoices`. Die Tabelle ordnet einer WHMCS-Invoice höchstens ein sevDesk-Objekt zu und dient zugleich als Duplikatschutz.

Die neue Migration ergänzt ausschließlich nullable Felder:

```text
document_type      VARCHAR(16) NULL
document_authority VARCHAR(16) NULL
document_number    VARCHAR(191) NULL
document_ready_at  DATETIME NULL
delivered_at       DATETIME NULL
pdf_sha256         VARCHAR(64) NULL
is_e_invoice        BOOLEAN NULL
xml_sha256          VARCHAR(64) NULL
```

Die bisherigen Unique-Constraints auf `invoice_id` und `sevdesk_id` bleiben bestehen. Eine produktive Annahme, dass Voucher- und Invoice-ID-Räume nicht kollidieren, ist deshalb erst nach dem externen Invoice-Canary zulässig.

Bei Migration und Recovery muss das Modul fünf Zustände unterscheiden:

- vollständige Zuordnungen mit lokaler und entfernter ID;
- vollständige Zuordnungen mit `document_type=NULL` oder `document_authority=NULL`, deren Remote-Typ oder Hoheit noch nicht bestätigt wurde;
- Reservierungen mit `sevdesk_id = NULL`;
- verwaiste Zuordnungen, deren WHMCS-Invoice nicht mehr existiert;
- Invoices ohne Mapping.

Eine Invoice ohne Mapping ist nicht automatisch ein fehlender Export. Status, Importdatum und fachliche Sonderfälle müssen anhand des aktuellen Datenbestands neu geprüft werden.

Bei älteren WHMCS-Rechnungen kann `tblinvoices.invoicenum` leer sein. WHMCS verwendet dann die unveränderliche
interne `tblinvoices.id` als Rechnungsreferenz. Der Rewrite bildet daraus zentral die effektive Rechnungsnummer
`trim(invoicenum) ?: id` für Vorschau, Export, Reconciliation und Kundenanzeige. Das ist kein Migrations- oder
Backfill-Schritt: Der gespeicherte Leerwert wird nicht verändert.

`is_e_invoice=NULL` bedeutet bei einem Altbestand nur, dass diese Eigenschaft damals nicht erfasst wurde. Es ist keine Freigabe zur nachträglichen Umwandlung. Bestehende Voucher und normale Invoices werden weder zu einem anderen Dokumenttyp noch nachträglich zu ZUGFeRD. Neue normale Invoices speichern `is_e_invoice=0`, neue native ZUGFeRD-Invoices `is_e_invoice=1` und den bestätigten XML-Hash.

### Einstellungen

Die funktionalen Einstellungen liegen in `tbladdonmodules` unter `module = 'sevdesk'`. Der Rewrite übernimmt unterstützte Werte additiv. Unbekannte oder nicht mehr verwendete Werte bleiben bei einem normalen Upgrade unangetastet.

`small_business_until` ist additiv und bleibt beim Upgrade leer. Damit ändert sich das bisherige Verhalten von `smallBusinessOwner` nicht: Ist der Schalter aktiv und kein Enddatum gesetzt, gilt er weiterhin ohne zeitliche Grenze. Ein gesetzter Wert wird ausschließlich im Format `d-m-Y` akzeptiert und gegen das Rechnungsdatum geprüft.

`invoice_discount_canary_confirmed` ist ebenfalls additiv und bleibt beim Upgrade aus. Vorhandene negative Positionen werden dadurch nicht still als sevDesk-Rabatt interpretiert.

Der gleiche Namensraum ist die Drop-in-Grenze: Der echte `sevdesk_upgrade()`-Callback ergänzt neue Defaults, schaltet bei Altversionen die automatische Synchronisation aus und verändert weder Mappingzeilen noch bestehende API-, Konto-, Kontaktfeld- oder unbekannte Lizenzwerte. Es werden dabei keine Remote-Kontakte oder Belege gelesen oder geschrieben.

Das beweist keine vollständige Setting-Kompatibilität mit jeder veröffentlichten oder angepassten Becker-Version. Bestätigt sind nur der gemeinsame Modul- und Tabellennamensraum sowie die vom Rewrite tatsächlich gelesenen Schlüssel.

Hersteller-Version, Feldbezeichnungen, Token-Speicherung, zusätzliche Dateien und markerlose Altbelege müssen gegen das gesicherte Originalpaket und mit synthetischen Stichproben geprüft werden. Unbekannte Werte bleiben erhalten, werden aber nicht geraten oder still umgedeutet.

Eine im konfigurierten WHMCS-Kunden-Custom-Field vorhandene sevDesk-ID gilt als bestätigte historische Zuordnung. Ist die Remote-ID lesbar, wird sie unverändert wiederverwendet. Abweichende historische `customerNumber`-Werte führen nicht zu einer stillen Umverknüpfung. Eine fehlende Remote-ID wird zum Klärfall und löst keine automatische Neuanlage aus.

Bei leerem Feld ist ein Suchtreffer nur verwendbar, wenn `customerNumber` in der Liste oder im anschließenden ID-Einzelabruf exakt der WHMCS-Client-ID entspricht. Nicht beweisbare Treffer blockieren. Bleibt die Suche leer, erlaubt erst die standardmäßig deaktivierte Bestätigung `customer_number_contact_creation_confirmed` eine Neuanlage mit diesem Kundennummernschema.

Der Rewrite aktualisiert bereits verknüpfte Kontakte nicht automatisch. Das kann sich von der laufenden Kontaktsynchronisation des ersetzten Moduls unterscheiden.

Für ZUGFeRD prüft das Modul am selben Kontakt zusätzlich Käuferreferenz, genau eine passende Haupt-E-Mail, die vollständige deutsche Rechnungsadresse und das Behördenkennzeichen. Fehlt etwas, wird der Kontakt nicht automatisch ergänzt. Die ausdrücklich gewählte E-Rechnung bleibt blockiert, statt als normale Invoice weiterzulaufen.

Abgedeckt sind der Erhalt von Mappings und Kontakt-IDs, der dokumentbewusste Export, persistente Jobs und Recovery. Allgemeine Kontaktsynchronisation, Fremdwährungen, Produktkonten und andere nicht bestätigte Becker-Sonderfunktionen sind noch offen.

### WHMCS-Sammelzahlungen

WHMCS bildet eine Sammelzahlung mit einem zusätzlichen Rechnungsdatensatz ab. Dieser enthält ausschließlich Positionen vom Typ `Invoice`; ihre `relid` verweist auf die Originalrechnungen. Die Gateway-Transaktion hängt am Sammelbeleg, während WHMCS den Originalrechnungen Guthaben zuweist. So beschreibt es auch die [WHMCS-8.13-Anleitung zu Mass Payments](https://docs.whmcs.com/8-13/billing-and-invoicing/invoicing-tutorials/create-a-mass-payment-invoice/). Ein read-only Abgleich unter WHMCS 8.13.4 hat für die Originalrechnungen den centgenauen Vertrag `subtotal + tax + tax2 = total + credit` bestätigt. `total` ist der direkte Zahlteil, `total + credit` der Dokumentbrutto; bei Vollguthaben steht `total = 0`. Die [GetInvoice-API](https://developers.whmcs.com/api-reference/getinvoice/) liefert die beteiligten Felder getrennt.

Der Rewrite akzeptiert diese Struktur nur, wenn Container, Verknüpfungen, Mandant, Status, Beträge, Steuern, Zahlungen und Mappings centgenau zusammenpassen. Beschreibungen, Transaktionsreferenzen und `tblcredit` dienen nicht als Nachweis. Der Container wird nicht nach sevDesk exportiert; die Originalrechnungen bleiben eigenständige Umsatzbelege. Die gemeinsame Zahlung wird nicht automatisch auf mehrere sevDesk-Dokumente aufgeteilt.

Eine negative Position mit dem Typ `PromoHosting` ist keine allgemeine Legacy-Regel. Sie wird nur dann als fester Invoice-Rabatt behandelt, wenn genau eine positive `Hosting`-Position dieselbe `relid` und denselben `taxed`-Wert trägt. Mehrere Treffer, andere negative Typen oder abweichende Steuermerkmale bleiben Prüffälle.

### WHMCS-8.13-Mailanhänge

WHMCS 8.13 führt `EmailPreSend` aus, verarbeitet aus dem Hook zurückgegebene
Binäranhänge aber noch nicht. Ein einmaliger In-Memory-Kontext kann deshalb
korrekt verbraucht werden, während der Mailer weiterhin die WHMCS-Core-PDF
versendet. Die Anhangsfunktion wurde erst mit WHMCS 9 ergänzt.

Der gespeicherte Versandwert `whmcs_template` bleibt aus
Migrationsgründen erhalten, ist auf der festgelegten Zielplattform aber
gesperrt. Setup, Health Check und Worker ändern ihn nicht automatisch. Für
sevDesk-Dokumenthoheit muss der Betreiber bewusst auf `sevdesk` umstellen.

Konkrete API-Token, Custom-Field-IDs, Konto-IDs, Importgrenzen und Schalter stehen bewusst nicht hier. Sie gehören zur jeweiligen Umgebung. Account-Datev-IDs aus einer bestehenden Installation dürfen auch nicht in Code oder Tests landen.

## Recovery-Grenze

Kontaktanlage, Voucher-PDF-Upload, Voucher-/Invoice-Erstellung, Invoice-Öffnung/-Versand und lokales Mapping liegen nicht in einer gemeinsamen Transaktion. Bricht ein Prozess nach einem Remote-Write ab, kann dessen Ausgang unbekannt bleiben. Recovery liest deshalb zuerst den typabhängigen Remote-Bestand und legt weder Kontakt noch Dokument blind ein zweites Mal an. Eine neue Mappingzeile entsteht erst, wenn Remote-ID und Dokumenttyp bestätigt sind.

Der Rewrite behält für Upgrades Voucher-first als sichere Grundstellung, kann nach externem Canary aber dokumentbewusst `voucher` oder `invoice` wählen. Bestehende Mappings werden durch einen Moduswechsel nie neu exportiert.

Neue Mappings speichern die Dokumenthoheit zusammen mit Remote-ID und Dokumenttyp. Bei älteren RC-Zeilen ohne diesen Wert dient der eingefrorene Jobkontext nur als Übergangsnachweis; lässt sich die Hoheit daraus nicht eindeutig belegen, bleibt der Vorgang gesperrt. Ein späterer globaler Wechsel gilt ausschließlich für danach entschiedene Invoices. Vorhandene Mappings und alte Jobs werden nicht umgeschrieben.

## Implizite Zustände

Die Tabelle kann neben vollständigen Zuordnungen auch unterbrochene oder verwaiste Zustände enthalten:

| Zustand | Bedeutung |
| --- | --- |
| keine Zeile | noch nie importiert, durch Filter ausgelassen, Mapping entfernt oder früh gescheitert |
| Invoice-ID, Remote-ID `NULL` | reserviert, laufend oder unterbrochen |
| beide IDs vorhanden, `document_type=NULL` | importiert, aber vor typabhängigem Booking/Recovery erst read-only klassifizieren und durch Admin bestätigen |
| beide IDs und Dokumenttyp vorhanden | typisiertes abgeschlossenes Mapping |
| Remote-ID vorhanden, Invoice fehlt | verwaiste historische Zuordnung |

Leere Reservierungen können nach einem Prozessabbruch, Timeout oder Laufzeitfehler stehen bleiben. Jede solche Zeile muss einzeln mit dem aktuellen Remote-Bestand abgeglichen werden. Das Modul darf weder Erfolg annehmen noch automatisch einen zweiten Beleg anlegen. Eine vollständige untypisierte Altzeile wird dagegen nicht gelöscht oder geraten: Voucher und Invoice werden read-only geprüft, und erst eine ausdrückliche Adminbestätigung ergänzt den vorgeschlagenen Typ.

Die Setupseite fasst diese Zustände in einer Übergangsinventur zusammen. Sie zeigt zusätzlich aktive, unklare und alte fehlgeschlagene Exportjobs, bezahlte ungemappte Rechnungen ab dem Stichtag und lokale Hinweise auf mögliche Remote-Dubletten. Die Bestätigung einer Modus-, Hoheits-, OSS-, E-Rechnungs-, Rabatt-Canary- oder Kleinunternehmeränderung ist an den aktuellen Inventur-Fingerprint gebunden. Sie startet keinen Export.

Für vollständige Legacy-Mappings gibt es eine Sammelvorschau mit höchstens 25 sichtbaren Zeilen. Nur eindeutige Treffer mit passendem Rewrite-Marker können gemeinsam bestätigt werden. Markerlose Originalbelege, Typkollisionen und geänderte Treffer bleiben einzelne Klärfälle.

Eine vollständige Mappingzeile darf nicht mehr allein deshalb entfernt werden, um einen Export neu zu starten. Erst eindeutige 400- oder 404-Antworten von beiden Voucher-/Invoice-by-ID-Endpunkten belegen, dass unter der gespeicherten ID kein Remote-Dokument mehr vorhanden ist. Danach prüft das Repository ID und Typ nochmals unter Zeilensperre. Jeder andere Ausgang erhält die Zuordnung.

## Jobs und Hooks

Massenexporte werden als persistente Jobs verarbeitet. Der Fortschritt bleibt nach Browserende oder Prozessabbruch erhalten, und ein Fehler beendet nur das betroffene Item. Ausgelassene Rechnungen erhalten einen eindeutigen Grund.

Hooks planen Arbeit lediglich idempotent als `export_document` ein. Der historische Schlüssel `export_voucher:<invoiceId>` bleibt absichtlich für beide Zieltypen bestehen, damit alte und neue Jobs nie parallel unterschiedliche Dokumente erzeugen. Remote-I/O findet ausschließlich im Worker statt und darf den WHMCS-Benutzerrequest nicht stören.

Ein fehlgeschlagener alter Voucher-Job darf nach einem Moduswechsel nicht als normaler Retry in die neue Konfiguration hineinlaufen. Sichere Vor-Write-Zustände enden mit `stale_export_context_requeue_required`. Nach einer eigenen Mailfrei-Bestätigung kann daraus ein neuer `export_document`-Job im aktuellen Modus entstehen. Das alte Item bleibt unverändert erhalten. Riskante Checkpoints werden ausschließlich auf ihrem ursprünglichen Dokumentpfad reconciliiert.

Ein bestätigter Altbestands-Nachlauf wird als `historical_backfill` gespeichert. Er ist mailfrei und kann keine E-Rechnung erzeugen. Vor jedem Invoice-Create sucht der Worker rein lesend nach derselben Rechnungsnummer, nach passenden Invoice-Kombinationen aus Datum, Kontakt, Währung und Betrag sowie nach Voucher-Nummern und Markern. Jeder mögliche Treffer blockiert die Neuanlage, setzt aber nicht automatisch ein Mapping.

Native ZUGFeRD-Dokumente bleiben normale `Invoice`-Mappings. Die Auswahl friert Kontakt-, PaymentMethod-, Unity- und Länder-ID sowie einen PII-freien Adresshash ein. Namen, Adressen, PDF und XML gehören nicht in die Jobtabellen. Der XML-Hash wird nach `getXml` gespeichert und bei späteren Schritten nicht ersetzt.

## API-Basis

Die HTTP-API bleibt unter `/api/v1`. „sevDesk-Update 2.0“ bezeichnet hier die Buchhaltungslogik mit `taxRule` und `accountDatev`, nicht eine neue API-Version.

## Lokale Restore- und Dump-Dateien

Lokale Restore- oder Dump-Dateien sind keine Migration. Sie können veraltete Daten, Zugangswerte oder destruktive Anweisungen enthalten und dürfen weder veröffentlicht noch in einer laufenden Umgebung ausgeführt werden.

## Vorgaben für den Rewrite

- Bestehende Mappings bleiben erhalten und werden nicht neu aufgebaut.
- Bestehende Voucher, Invoices, E-Rechnungsstatus und eingefrorene Dokumenthoheit werden durch Setupänderungen nicht rückwirkend verändert.
- Neue Mappings speichern Remote-ID, `document_type` und `document_authority` atomar; Legacy-Typen und -Hoheiten werden nicht automatisch angenommen.
- Die Typprüfung liest Voucher und Invoice getrennt. Ein markerloser Beleg des Originalmoduls darf nur bei exakt passender ID, Objektart und Dokumentnummer als sichtbar schwächerer Vorschlag erscheinen; widersprüchliche Marker und Cross-Type-ID-Kollisionen bleiben blockiert.
- `NULL`- und verwaiste Mappings erscheinen im Recovery-Bericht und werden nicht automatisch bereinigt.
- Invoices ohne Mapping werden anhand der aktuellen Eignungsregeln neu klassifiziert.
- Bezahlte, ungemappte Alt-Rechnungen werden erst nach Vorschau und read-only Dublettensuche mailfrei eingereiht; historische Jobs erzeugen keine E-Rechnung.
- Ein sicherer alter Voucher-Vor-Write-Job kann nur über einen neuen bestätigten Job in den aktuellen Modus wechseln. Nach einem möglichen Write bleibt der historische Pfad unverändert.
- Steuerentscheidung und `ReceiptGuidance` laufen vor jedem Write.
- Jobs und Ergebnisse werden dauerhaft gespeichert.
- Der Worker fängt Fehler pro Item ab und speichert nur bereinigte Diagnosedaten.
- Hook-Fehler bleiben vollständig vom WHMCS-Kern getrennt.
- Ein unbekannter Remote-Ausgang wird lesend abgeglichen, bevor ein Mensch über das weitere Vorgehen entscheidet.

## Freigabe

Vor der Produktivfreigabe müssen Steuerfälle, Migration und Aufbewahrung für die jeweilige Zielumgebung geprüft werden. Bestandsdetails und Prüfergebnisse gehören nicht ins Repository.
