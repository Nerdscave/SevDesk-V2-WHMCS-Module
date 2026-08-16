# sevDesk-API und Steuerlogik

## Terminologie

**sevDesk-Update 2.0** meint in diesem Projekt die Buchhaltungslogik mit `taxRule`, `accountDatev`, `ReceiptGuidance` und strengerer Validierung. Die HTTP-API bleibt:

```text
https://my.sevdesk.de/api/v1
```

Eine `/api/v2` ist weder Voraussetzung noch Teil der Implementierung. Technische Referenz im Repository ist `docs/sevdesk-openapi.yaml`; sie wurde für die Invoice-Erweiterung nicht verändert.

## Verbindlicher API-Vertrag

### Authentifizierung und Transport

- API-Token ausschließlich im Header `Authorization`;
- `Accept: application/json`;
- passender `Content-Type` für JSON beziehungsweise Voucher-PDF-Upload;
- aussagekräftiger `User-Agent` aus Modulname und Version ohne Mandanten- oder Kundendaten;
- explizite Connect- und Request-Timeouts;
- automatische Redirects sind deaktiviert. Eine 3xx-Antwort auf einen Write gilt
  als unbekannter Ausgang und darf nicht als GET weiterverfolgt werden;
- JSON-, XML- und PDF-Antworten werden als Stream höchstens bis zum jeweiligen
  Limit plus ein Byte gelesen. Eine zu große `Content-Length` wird vor dem
  Einlesen abgelehnt;
- Token niemals in URL, Jobdaten oder Fehlertext.

Vor dem ersten Write und im Health Check liest das Modul `/Tools/bookkeepingSystemVersion` und erwartet sevDesk-Update 2.0. Eine abweichende oder nicht lesbare Version blockiert Writes.

### Benötigte Endpunkte

| Zweck | Endpunkt |
| --- | --- |
| Buchhaltungssystem prüfen | `GET /Tools/bookkeepingSystemVersion` |
| Konto-/Rule-Fähigkeit | `GET /ReceiptGuidance/forAllAccounts`, `/forAccountNumber`, `/forTaxRule`, `/forRevenue` |
| Kontakt lesen/anlegen | benötigte `Contact`-Endpunkte |
| Rule-19-Land auflösen | `GET /StaticCountry?code=<ISO>` |
| Voucher-PDF temporär hochladen | `POST /Voucher/Factory/uploadTempFile` |
| Voucher samt Positionen anlegen | `POST /Voucher/Factory/saveVoucher` |
| Voucher lesen/suchen/buchen | benötigte `Voucher`-GET-Endpunkte, `PUT /Voucher/{id}/bookAmount` |
| Voucher-Positionen lesen | `GET /VoucherPos?voucher[id]=<id>&voucher[objectName]=Voucher` |
| normale Invoice anlegen | `POST /Invoice/Factory/saveInvoice` |
| Invoice und Positionen lesen | `GET /Invoice/{id}`, `GET /Invoice/{id}/getPositions` |
| Invoice ohne Mail öffnen | `PUT /Invoice/{id}/sendBy` |
| Invoice per sevDesk senden | `POST /Invoice/{id}/sendViaEmail` |
| finale Invoice-PDF abrufen | `GET /Invoice/{id}/getPdf` |
| native E-Rechnungs-XML lesen | `GET /Invoice/{id}/getXml` |
| Invoice-Zahlung buchen | `PUT /Invoice/{id}/bookAmount` plus typabhängige Read-/Log-Endpunkte |
| Mahnung erzeugen | dokumentierter `Invoice`-Factory-Pfad `createInvoiceReminder`, danach normaler Invoice-Readback |
| Invoice stornieren | `PUT /Invoice/{id}/cancelInvoice`, danach Readback der erzeugten `SR` |

Invoice-Erstellung, Öffnen, PDF und Versand sind getrennte Schritte. Erfolg eines Schritts beweist keinen späteren Schritt.

Listen sind nur verwertbar, wenn jedes Element die für den jeweiligen Endpunkt
benötigte Struktur besitzt. Das Modul entfernt keine fehlerhaften Elemente, um
danach aus der kleineren Restmenge Eindeutigkeit abzuleiten. Eine gemischte oder
unvollständige Antwort blockiert daher Kandidatensuche, Mapping,
Korrektur-Create und `bookAmount`.

## Dokumentziel und kein Fallback

`DocumentTargetResolver` friert vor dem ersten Remote-Write genau ein Ziel ein:

| `export_mode` | Verhalten |
| --- | --- |
| `voucher_only` | alle freigegebenen Steuerfälle als Voucher |
| `invoice_for_oss` | Rule 19 als Invoice, sonst freigegebener Voucher-Pfad |
| `invoice_only` | alle im Invoice-Vertrag freigegebenen Rules als Invoice |

`document_authority=sevdesk` ist nur mit `invoice_only` zulässig. Im Upgrade-Default
`after_payment_proforma` bleiben Invoice-Ziele paid-only. Der gesondert
freizugebende Lebenszyklus `issue_on_creation` erlaubt ausschließlich
sevDesk-geführte Invoices bereits mit Status `Unpaid`. Beide Wege verlangen
eine effektive WHMCS-Rechnungsnummer. Dafür verwendet das Modul das getrimmte `invoicenum`; ist es
bei einer Legacy-Rechnung leer, gilt die unveränderliche interne Invoice-ID als WHMCS-Rechnungsnummer. Diese
Auflösung ist rein lesend und füllt `tblinvoices.invoicenum` nicht nachträglich.

Das bestätigte Rule-19-Profil ist gegenseitig ausschließend zur früheren Betreiberfreigabe `eu_b2c_mode=domestic_confirmed`. Das Setup blockiert diese widersprüchliche Kombination, damit derselbe EU-B2C-Fall nicht zugleich als deutsche Rule 1 und als OSS-Rule 19 freigegeben ist.

Ein fehlgeschlagener Voucher-Write wird niemals als Invoice wiederholt. Ein begonnenes Invoice-Item wird niemals zum Voucher zurückgeroutet. Der gespeicherte Zieltyp ist Teil von Mapping, Recovery und Booking-Snapshot.

## Voucher-Vertrag

Voucher übernehmen die WHMCS-Rechnung samt WHMCS-PDF. Ein vereinfachtes synthetisches Payload-Beispiel:

```json
{
  "voucher": {
    "objectName": "Voucher",
    "mapAll": true,
    "voucherDate": "01.01.2030",
    "supplier": {"id": "CONTACT_ID", "objectName": "Contact"},
    "description": "WHMCS INV-EXAMPLE [WHMCS-INVOICE:123]",
    "status": 100,
    "taxRule": {"id": "1", "objectName": "TaxRule"},
    "creditDebit": "D",
    "voucherType": "VOU",
    "currency": "EUR"
  },
  "filename": "FILENAME_FROM_UPLOAD",
  "voucherPosSave": [
    {
      "objectName": "VoucherPos",
      "mapAll": true,
      "sumGross": 12.34,
      "net": false,
      "taxRate": 19.0,
      "accountDatev": {"id": "CONFIGURED_ACCOUNT_ID", "objectName": "AccountDatev"},
      "comment": "Synthetische Testposition"
    }
  ],
  "voucherPosDelete": null
}
```

Für jeden Voucher gilt:

- echte JSON-Booleans und `null`, keine String-Ersatzwerte;
- `accountDatev`, Betrag, `taxRate`, Netto-/Bruttokennzeichen und `objectName` an jeder Position;
- konsistenter Netto- oder Bruttomodus;
- normaler Status 100, kein bezahlter Create-Sonderstatus;
- unveränderter Dateiname aus dem temporären Upload;
- HTTP 201 und valide Remote-ID;
- vor dem Mapping ein separates `GET /Voucher/{id}` sowie ein gefiltertes
  `GET /VoucherPos`: Kontakt, Rule, Status, Datum, Marker, Währung, Positionen,
  Steuersätze und das vor dem Write eingefrorene `accountDatev` müssen passen.
  Nur für die Voucher-Gesamtsumme bleibt die dokumentierte Toleranz von einem Cent.

Die Recovery verwendet ausschließlich den im Job gespeicherten Kontakt-, Rule- und
Kontovertrag. Fehlt er nach `voucher_write_requested`, bleibt das Item
`ambiguous`; aktuelle Setupwerte dürfen den Altjob nicht neu interpretieren. Die
Markersuche paginiert in 100er-Schritten bis höchstens 1.000 Kandidaten. Eine volle
letzte Seite beweist keine Eindeutigkeit und darf kein Mapping wiederherstellen.

## Invoice-Vertrag

Eine Invoice wird nicht aus der WHMCS-PDF importiert. Das Modul erstellt eine normale sevDesk-Invoice und sevDesk erzeugt deren offizielle PDF. Ein vereinfachtes synthetisches Payload-Beispiel:

```json
{
  "invoice": {
    "objectName": "Invoice",
    "mapAll": true,
    "invoiceNumber": "INV-2030-001",
    "invoiceDate": "01.01.2030",
    "deliveryDate": "01.01.2030",
    "invoiceType": "RE",
    "status": 100,
    "contact": {"id": "CONTACT_ID", "objectName": "Contact"},
    "contactPerson": {"id": "SEV_USER_ID", "objectName": "SevUser"},
    "currency": "EUR",
    "showNet": false,
    "taxRule": {"id": "19", "objectName": "TaxRule"},
    "customerInternalNote": "[WHMCS-INVOICE:123]",
    "deliveryAddressCountry": "fr",
    "address": "Synthetischer Kunde\nTeststraße 1\n12345 Teststadt\nFR",
    "addressName": "Synthetischer Kunde",
    "addressStreet": "Teststraße 1",
    "addressZip": "12345",
    "addressCity": "Teststadt",
    "addressCountry": {"id": "STATIC_COUNTRY_ID", "objectName": "StaticCountry"},
    "propertyIsEInvoice": false
  },
  "invoicePosSave": [
    {
      "objectName": "InvoicePos",
      "mapAll": true,
      "quantity": 1,
      "unity": {"id": "UNITY_ID", "objectName": "Unity"},
      "positionNumber": 1,
      "name": "Synthetische digitale Leistung",
      "text": "Synthetische digitale Leistung",
      "price": 12.34,
      "taxRate": 20.0
    }
  ],
  "invoicePosDelete": null,
  "discountSave": null,
  "discountDelete": null,
  "takeDefaultAddress": false
}
```

Verbindliche Regeln:

- `invoiceType=RE`, Create-Status 100;
- unveränderte effektive WHMCS-Nummer als `invoiceNumber`;
- Marker `[WHMCS-INVOICE:<invoice_id>]` in einem lesbaren internen Feld;
- positive EUR-Rechnung; angewendetes Guthaben ist nur bei einer exakt bewiesenen WHMCS-Sammelzahlung zulässig und verändert den Dokumentbrutto nicht;
- Positionen vom WHMCS-Typ `LateFee` bleiben unabhängig von Datum, Land und
  Dokumentmodus blockiert. Mahn- und Verzugsgebühren werden nicht still unter
  Rule 1, 11, 17 oder 19 als normaler Leistungsumsatz übertragen;
- negative Positionen bleiben blockiert. Die einzige Ausnahme ist genau ein strukturell zugeordneter `PromoHosting`-Eintrag, der hinter einer freigegebenen Rule-/Rate-Capability als negative `InvoicePos` übertragen wird; `discountSave` bleibt auch dann `null`;
- exakt derselbe Netto-/Bruttomodus und WHMCS-Steuersatz wie im gefrorenen Snapshot;
- eingefrorener, unmittelbar vor dem ersten Create nochmals lesend bestätigter `SevUser` und eine Standard-`Unity`;
- vollständige WHMCS-Rechnungsadresse direkt am Dokument und `takeDefaultAddress=false`; ein bestehender sevDesk-Kontakt wird dafür weder ergänzt noch geändert;
- v1 verwendet je WHMCS-Position Menge 1;
- kein benutzerdefiniertes `accountDatev` an Invoice-Positionen;
- nach Create werden Invoice und alle Positionen gelesen und ID, Nummer, Status,
  Kontakt, Rule, Währung, Positionen sowie `sumNet`, `sumTax` und `sumGross`
  exakt verglichen. Die drei Summen sind auch bei rabattfreien Invoices Pflicht;
- erst die bestätigte Remote-ID plus `document_type=invoice` ergibt ein erfolgreiches Mapping.

Der [Umsatzsteuer-Anwendungserlass, Abschnitt 1.3 Abs. 6](https://www.bundesfinanzministerium.de/Content/DE/Downloads/BMF_Schreiben/Steuerarten/Umsatzsteuer/Umsatzsteuer-Anwendungserlass/Umsatzsteuer-Anwendungserlass-aktuell.pdf?__blob=publicationFile)
behandelt nachgewiesene Mahngebühren, Mahnkosten und Verzugszinsen als
Schadensersatz und nicht als Entgelt für die ursprüngliche Leistung. Da das
Modul dafür noch keinen eigenen bestätigten sevDesk-Buchungsvertrag besitzt,
bleibt `LateFee` ein Prüffall statt Teil einer normalen Umsatzrechnung.

Jede normale Invoice übernimmt Empfängername, Straße einschließlich zweiter Adresszeile, Postleitzahl, Ort und die eindeutig aufgelöste `StaticCountry`-Referenz aus der WHMCS-Rechnungsadresse. Der Request verwendet immer `takeDefaultAddress=false`; damit hängt `sendBy` nicht davon ab, ob am bereits verknüpften sevDesk-Kontakt eine Standardadresse gepflegt ist. Vor dem Create werden nur Länder-ID und ein kanonischer SHA-256-Adresshash im Job eingefroren, niemals die Anschrift. Create, Recovery, `sendBy` und Versand verlangen anschließend denselben Remote-Adresshash. Fehlt der neue Snapshot nach einem möglicherweise ausgeführten Write, bleibt der Altjob `ambiguous`; er ergänzt weder den Kontakt noch verändert er einen bestehenden Draft.

Dieselbe strenge Länderauflösung gilt für neue Kontaktadressen und den deutschen E-Rechnungspfad. Die Antwort muss den angefragten ISO-Code tragen und eindeutig sein. Bei den bekannten GB-Dubletten wird nur der eindeutig bezeichnete Eintrag `United Kingdom` akzeptiert; fehlt er oder ist auch dieser mehrdeutig, findet kein Write statt.

Bei den OSS-Regeln 18 bis 20 erwartet sevDesk `deliveryAddressCountry` beim Create in der kleingeschriebenen Form aus `StaticCountry`, zum Beispiel `fr`. Von diesen Regeln ist in diesem Release nur Rule 19 erreichbar. Beim Factory-Endpunkt wird dieselbe zuvor lesend aufgelöste `StaticCountry`-Referenz als `addressCountry` der vollständigen Rechnungsadresse gesetzt. Intern normalisiert das Modul ISO-Codes weiterhin auf Großbuchstaben. Beim Readback über `GET /Invoice/{id}?embed=addressCountry` kann sevDesk das beim Create gesendete `deliveryAddressCountry` weglassen. Ist das Lieferland vorhanden, ist es für OSS maßgeblich und darf von der Rechnungsadresse abweichen. Nur wenn es fehlt, dient `addressCountry` als Fallback und muss zum eingefrorenen Zielland passen. Die vollständige Rechnungsadresse muss unabhängig davon lesbar dem eingefrorenen Hash entsprechen.

Die fehlende freie `accountDatev`-Zuordnung ist eine sichtbare Einschränkung von `invoice_only`, nicht etwas, das das Modul verdeckt ergänzt.

### Sammelzahlungen, Guthaben und feste Rabattpositionen

WHMCS-Sammelzahlungsrechnungen enthalten ausschließlich Positionen vom Typ `Invoice`. Deren `relid` zeigt auf die Originalrechnung, während die tatsächliche Gateway-Transaktion am Sammelbeleg hängt. Nur wenn alle Links, Mandanten, Status-, Steuer- und Betragsfelder sowie die Zahlungen zusammenpassen, gilt der Container als reiner Zahlungsbeleg. Er wird nicht als Umsatz nach sevDesk geschrieben.

Für die Originalrechnung gilt centgenau `subtotal + tax + tax2 = total + credit`. Der Dokumentbrutto ist `total + credit`; `total` ist der direkte Zahlteil. Bei Teilguthaben muss die Summe der eigenen positiven `tblaccounts`-Zahlungen deshalb exakt `total` entsprechen. Bei Vollguthaben steht `total = 0` und es gibt keine eigene positive Gateway-Transaktion an der Originalrechnung. Der gemeinsame Zahlungseingang wird nicht automatisch auf einzelne sevDesk-Invoices gebucht. Der Buchungsassistent akzeptiert weiterhin nur eine eindeutig zur einzelnen Rechnung passende Banktransaktion.

Rückerstattungen werden auch über die Gegenrichtung geprüft: Eine separate `tblaccounts`-Zeile mit `refundid` zur ursprünglichen Zahlung blockiert die Kette selbst dann, wenn ihre `invoiceid` nicht mehr auf den Parent oder die Originalrechnung zeigt.

Der Kopfvertrag ist unabhängig vom WHMCS-`TaxType`. Nur der Positionsvergleich unterscheidet sich: Bei `Exclusive` entspricht die Summe der WHMCS-Positionen `subtotal`, bei `Inclusive` dem Dokumentbrutto. Andere `TaxType`-Werte werden blockiert.

Ein alter, eindeutig unbezahlter (`Unpaid`) oder stornierter (`Cancelled`) Sammelzahlungsversuch ohne Zahlung, Guthaben, Mapping oder Rückerstattung blockiert einen später vollständig passenden Vorgang nicht. Andere Zustände werden nicht als harmlos geraten: Insbesondere `Refunded`, `Collections`, `Draft` und unbekannte Werte halten die Kette in der Prüfung, auch wenn keine Transaktionszeile mehr vorhanden ist. Sobald der alte Elternbeleg selbst einen möglichen Zahlungseffekt zeigt, bleibt die Kette ebenfalls gesperrt.

Ein Parent gilt nur als eindeutig, wenn sein gesamter Zielgraph frei von einem zweiten aktiven Parent ist. Teilt er auch nur ein Ziel mit einem konkurrierenden Sammelbeleg, bleiben beide Container und sämtliche Ziele beider betroffenen Graphen gesperrt. Ein Ziel darf den Konflikt nicht deshalb umgehen, weil es selbst nur in einem der Container vorkommt.

Ein negativer `PromoHosting`-Eintrag wird nur akzeptiert, wenn genau ein positiver `Hosting`-Eintrag mit derselben `relid` und demselben `taxed`-Wert existiert. Der Rabatt darf diesen Hosting-Betrag nicht überschreiten. Zulässig sind genau ein solcher Rabatt, `invoice_only`, EUR und eine normale Invoice ohne E-Rechnungsflag. Alle positiven Positionen und der Rabatt müssen denselben Netto-/Bruttomodus und denselben Steuersatz verwenden.

| Tax Rule und Rate | Capability-Basis | Zusätzliches Gate |
| --- | --- | --- |
| Rule 11, 0 % | `promo_discount_rule11_0` | `small_business_invoice_canary_confirmed`, aktuelle Rule-11-`REVENUE`-Guidance und `invoice_discount_canary_confirmed` |
| Rule 1, 19 %, deutscher Kunde | `promo_discount_rule1_19` | `invoice_discount_rule1_19_canary_confirmed` |
| Rule 1, 19 %, EU-B2C mit bestätigter Inlandsbesteuerung | `promo_discount_rule1_19` | `invoice_discount_rule1_19_eu_b2c_domestic_canary_confirmed` |
| Rule 17, 0 % | `promo_discount_rule17_0` | `invoice_discount_rule17_0_canary_confirmed` |
| Rule 19, positiver einheitlicher Zielsteuersatz | `promo_discount_rule19_destination` | `invoice_discount_rule19_canary_confirmed`; die Rate muss in der bestätigten Zielbesteuerung enthalten sein |

Profil, Länderklasse, Netto-/Bruttomodus, tatsächliche Rate und Vertragsversion gehören zum gespeicherten Capability-Key. Die Settings für Rules 1, 17 und 19 enthalten diesen exakten Key; ein pauschales `on` gilt nicht als Freigabe. Der deutsche Rule-1-Fall und die EU-B2C-Inlandsbesteuerung verwenden getrennte Keys und Gates. Rule 19 speichert den im Canary geprüften Zielsteuersatz separat in `invoice_discount_rule19_canary_rate`. Vor `invoice_write_requested` friert das Modul den Key und einen PII-freien SHA-256 des Rabattvertrags ein. Die Remote-Invoice trägt zusätzlich `[WHMCS-DISCOUNT:<sha256>]`.

Für Rabattfälle ist der Payload-Vertrag absichtlich enger als ein allgemeiner sevDesk-Rabatt: `invoicePosSave` enthält die positiven Positionen und genau eine negative Rabattposition mit Menge 1, identischem Steuersatz und fortlaufender Positionsnummer. `discountSave` bleibt `null`. Ein Rule-1-Live-Canary mit 4,94 Euro Bruttoleistung und 2,47 Euro Bruttorabatt zeigte den Grund. Der globale Rabatt ergab 2,08 Euro netto und 0,39 Euro Umsatzsteuer; die negative `InvoicePos` erhielt die WHMCS-Verteilung von 2,07 Euro netto und 0,40 Euro Umsatzsteuer. Der synthetische Canary las genau zwei Remote-Positionen und `sumDiscounts=0` zurück.

Readback und Recovery verlangen den Capability-Key, den Marker, die unveränderten positiven und negativen Positionen sowie exakte Werte für `sumNet`, `sumTax` und `sumGross`. `sumDiscounts` muss numerisch 0 sein. Deshalb benötigt jede der fünf Semantiken ihren externen Canary. Ein fehlender oder geänderter Key, Marker, Positions- oder Summenwert blockiert vor dem Write beziehungsweise führt nach einem möglichen Write zu `ambiguous`. `LateFee`, mehrere Rabatte, andere negative Positionen und E-Rechnungen mit Rabatt bleiben gesperrt.

### Rule 11 bei normalen Invoices

Der Voucher-Pfad prüft das konfigurierte `accountDatev` zusammen mit Rule 11 und 0 % gegen `ReceiptGuidance`. Bei normalen Invoices ist diese Zuordnung nicht möglich: `InvoicePos` unterstützt kein eigenes `accountDatev`, sodass sevDesk das Erlöskonto selbst bestimmt.

Ein Live-Lauf hat diese Grenze sichtbar gemacht. Create akzeptierte den Rule-11-Entwurf, `sendBy` lehnte ihn danach mit Code 7100 ab, weil `KLEINUNTERNEHMER_P19` für das automatisch gewählte Konto beziehungsweise dessen Scope nicht zulässig war. Ein erfolgreicher Draft beweist deshalb noch keine nutzbare Rule-11-Invoice.

Das Modul verlangt für Rule-11-Invoices zwei unabhängige Nachweise:

- `small_business_invoice_canary_confirmed` bestätigt den vollständigen, rabattfreien Lifecycle im aktuell verbundenen Mandanten;
- die jeweils aktuelle `ReceiptGuidance` enthält mindestens ein numerisches `REVENUE`-Konto, das Rule 11 mit 0 % zulässt.

Die Guidance-Prüfung wählt kein Konto für das Payload. Sie ist ein Capability-Gate und blockiert mit `invoice_rule11_tenant_scope_unsupported`, wenn der Mandant die nötige Kombination nicht anbietet. Ist nur der Canary offen, lautet der Fehler `small_business_invoice_canary_not_confirmed`. Rule 1, Rule 19 und Rule-11-Voucher verwenden unverändert ihre bisherigen Verträge.

### Native ZUGFeRD-Invoice

ZUGFeRD bleibt eine Eigenschaft der normalen `Invoice`. Es gibt keinen dritten Dokumenttyp und das Modul erzeugt kein eigenes XML. Der Pfad stützt sich auf sevDesks `propertyIsEInvoice`, `getXml` und die von sevDesk gerenderte PDF. Grundlage ist die [sevDesk-Ankündigung zur E-Rechnungs-API](https://tech.sevdesk.com/api_news/posts/2024_11_15-einvoice_changes/).

Angewendetes WHMCS-Guthaben ist für ZUGFeRD noch nicht freigegeben. Auch ein exakt bewiesener Sammelzahlungsfall endet ohne Write und ohne normalen PDF-Fallback. Eine Freigabe braucht einen eigenen Canary mit Create, XML-Inhalt, PDF und Recovery.

Ausgewählt wird er nur, wenn alle folgenden Bedingungen gleichzeitig erfüllt sind:

- `invoice_only` und sevDesk-Dokumenthoheit;
- normaler Invoice-Canary und eigener ZUGFeRD-Canary bestätigt;
- Admin-Tickbox beim Kunden gesetzt und Rechnungsdatum nicht vor `e_invoice_active_from`;
- deutsche Organisation mit deutschem Rechnungsland und Rule 1;
- gültige `SevUser`-, `Unity`-, `PaymentMethod`-, Kontakt- und Länderreferenzen;
- Käuferreferenz, genau eine Haupt-E-Mail, vollständige deutsche Kontaktadresse und `governmentAgency=false`.

Der Payload ergänzt `propertyIsEInvoice=true`, `paymentMethod`, `addressName`, `addressStreet`, `addressZip`, `addressCity`, `addressCountry` und `takeDefaultAddress=false`. Empfängername und strukturierte Adresse werden normalisiert zusammen gehasht. Die Persistenz enthält nur IDs und diesen Hash, keine Anschrift.

Nach Create liest das Modul PaymentMethod, Kontakt und strukturierte Adresse zurück. `propertyIsEInvoice` fehlt in echten Invoice-GET-Antworten teilweise, obwohl `getXml` das native CII-Dokument liefert. Ist das Feld vorhanden, muss es eindeutig wahr sein. Fehlt es, darf nur der anschließend erfolgreich geprüfte XML-Abruf den E-Rechnungspfad bestätigen. Ein ausdrücklich falscher oder unverständlicher Wert bleibt ein Vertragsfehler.

`getXml` wird auf Größenlimit, UTF-8, verbotene DTD-/Entity-Deklarationen, Wohlgeformtheit und den CII-Wurzelknoten `CrossIndustryInvoice` geprüft. Der erste bestätigte SHA-256 bleibt Bestandteil des unveränderlichen Recovery-Snapshots. Ein später abweichender Hash wird nur als beobachtete Abweichung protokolliert und führt zu `ambiguous`; er ersetzt niemals den Sollwert. Die vollständige EN-16931-Prüfung bleibt Teil des externen Canarys und wird nicht durch die Strukturprüfung im Modul vorgetäuscht.

Die versionierte Spezifikation nennt außerdem Pflichtdaten des eigenen sevDesk-Mandanten. Da dieser Stand keinen belastbaren vollständigen `SevClient`-Read-Vertrag enthält, bestätigt der Betreiber diese Stammdaten im separaten Canary. Eine sevDesk-Ablehnung mit 422 bleibt dauerhaft fehlgeschlagen. Nach einem ausdrücklichen Kunden-Opt-in gibt es keinen stillen Rückfall auf eine normale PDF-Invoice.

Rule 19 sowie Rules 18/20, Behördenfälle und historische Backfills werden nie als E-Rechnung erzeugt. Beim sevDesk-Mailkanal setzt das Modul `sendXml=false`; ausgeliefert wird die von sevDesk erzeugte ZUGFeRD-PDF. PDF und XML werden nicht dauerhaft in WHMCS gespeichert. Der strukturierte Bestandteil ist fachlich maßgeblich; die rechtliche Einordnung bleibt Sache des Betreibers und seiner Buchhaltung. Dazu siehe auch die [BMF-FAQ zur E-Rechnung](https://www.bundesfinanzministerium.de/Content/DE/FAQ/e-rechnung.html).

### Öffnen, Versand und PDF

- `sendBy` öffnet eine Invoice ohne kundenseitige sevDesk-Mail und wird für WHMCS-Hoheit verwendet. Der frühere WHMCS-Vorlagenkanal bleibt unter WHMCS 8.13.4 gesperrt, weil diese Version den Binäranhang aus `EmailPreSend` nicht übernimmt.
- `sendViaEmail` öffnet und versendet über sevDesk. Empfänger, Betreff und Text werden lokal validiert.
- Unmittelbar vor beiden Writes werden Draft-Header und Positionen erneut vollständig gelesen und exakt mit dem gefrorenen Snapshot verglichen. Abweichungen verhindern Open und Versand.
- `getPdf` wird erst nach nachweisbarer Finalisierung verwendet. Laut Spezifikation kommt die PDF als JSON/Base64; der reale Endpunkt kann stattdessen direkt `application/pdf` liefern. Das Modul akzeptiert beide Formen mit genau einem GET, verlangt HTTP 200 und prüft danach PDF-MIME, `%PDF`-Signatur, EOF-Marker und höchstens 10 MiB.
- Nur für diesen PDF-GET sind Guzzles automatische Inhaltsdekodierung und die entsprechende cURL-Dekodierung abgeschaltet. Das umgeht fehlerhafte `Content-Encoding`-Antworten einzelner sevDesk-Installationen, ohne den übrigen API-Client aufzuweichen.
- Die PDF wird nicht dauerhaft in WHMCS gespeichert; SHA-256, Ready- und Delivery-Zeitpunkt dürfen im Mapping stehen.
- Der authentifizierte Kundenproxy erlaubt pro WHMCS-Kunde höchstens acht
  Remote-Abrufe in 60 Sekunden. Danach liefert er HTTP 429 mit `Retry-After`,
  ohne sevDesk aufzurufen. Das schützt das gemeinsame Mandantenlimit, ersetzt
  aber keine der Eigentümer-, Status-, Signatur- oder Hashprüfungen.
- Bei ZUGFeRD wird vor Öffnung, Versand und PDF-Fortsetzung zusätzlich der unveränderliche XML-Hash geprüft.
- Bei der Bestätigung einer alten sevDesk-geführten Invoice gelten die Remote-Status 200, 750 und 1000 als final. Draft 100 bleibt gesperrt. Eine im Altjob bereits eingefrorene Typ-/Hoheitsentscheidung darf durch die nachträgliche Mappingbestätigung nicht geändert werden.

Nach `invoice_write_requested`, `invoice_open_write_requested` oder `invoice_delivery_write_requested` ist ein Transportfehler potenziell nicht wiederholbar. Recovery darf dann nur GETs ausführen. Ein fehlender Nachweis oder ein fehlendes Mapping nach einem späteren Write bleibt `ambiguous`; es gibt keinen Rückfall auf `saveInvoice`. Volle 1.000er-Seiten bei Kandidaten oder Positionen gelten als abgeschnitten und nicht beweiskräftig.

### Mahnung, Stornorechnung und Late-Fee-Beleg

`createInvoiceReminder` wird nur nach einem eigenen Mandanten-Canary verwendet.
Das Modul konstruiert keine eigene `MA`, sondern prüft das von sevDesk erzeugte
Dokument. Pflicht sind die exakte Eltern-Invoice, derselbe Kontakt, die
WHMCS-Mahnstufe und -frist sowie centgenaue Werte für `reminderDebit`,
`reminderCharge` und `reminderTotal`. Auch die finale PDF gehört zum Vertrag.
Eine unklare oder abweichende Antwort wird nicht versandt.

Vor jeder Mahnung werden Grundrechnung, WHMCS-Status und Forderungsfingerprint
erneut gelesen. Die Rechnung muss `Unpaid` und unverändert sein. Der offene
Betrag muss zum eingefrorenen Wert passen; Zahlungen, Guthaben, Refunds,
Cancellation und Teilzahlungen blockieren. Eine Stufe darf nur einmal
existieren. Erst nach nachgewiesenem Originalversand darf `sendViaEmail` für die
Mahnung folgen.

`cancelInvoice` ist ausschließlich für eine offene, unveränderte
Direktbetriebs-Invoice vorgesehen. Die zurückgegebene `SR` muss Elternbeleg,
Kontakt und Betrag exakt spiegeln. Bei einer ZUGFeRD-Grundrechnung werden
zusätzlich E-Invoice-Flag, PDF und XML geprüft. Nur wenn die Original-Invoice
nachweislich per sevDesk-Mail zugestellt wurde, darf auch die `SR` versandt
werden. Ein möglicherweise ausgeführter Cancel- oder Mail-Write wird nie blind
wiederholt.

Positive, unbesteuerte WHMCS-`LateFee`-Positionen werden aus der normalen
Leistungs-Invoice entfernt. Der zugrunde liegende WHMCS-Vertrag, die verkürzte
Primär-Invoice und die Gebührenpositionen werden getrennt gehasht. Andere,
negative oder besteuerte Gebühren bleiben blockiert.

Die Gebühr besitzt genau eine Erlösquelle:

- `reminder`, wenn ein gesonderter Canary beweist, dass `reminderCharge` in
  diesem sevDesk-Mandanten bereits buchhalterisch wirksam ist;
- `rule22_voucher`, wenn nach Zahlung ein positiver Revenue-Voucher mit Rule 22,
  0 % und dem im Setup bestätigten `accountDatev` erzeugt wird.

Der Rule-22-Pfad liest unmittelbar vor Create die aktuelle
`ReceiptGuidance/forRevenue`. Genau das konfigurierte numerische Konto muss
`REVENUE`, Rule 22 und 0 % erlauben. Der Voucher trägt Betrag, Parent-ID und
stabilen WHMCS-Marker, wird nach Create samt Positionen exakt zurückgelesen und
bleibt mailfrei. Eine `MA` darf in dieser Konfiguration nur das Mahndokument
sein; sie ersetzt den Voucher nicht. Bei Buchhaltungsquelle `reminder` ist es
umgekehrt und ein zusätzlicher Voucher verboten.

Der Positions-Readback verwendet auch hier ausschließlich den gefilterten
`GET /VoucherPos`; einen Unterendpunkt `/Voucher/{id}/getPositions` gibt der
versionierte Voucher-Vertrag nicht her.

## Steuerregeln in sevDesk-Update 2.0

| ID | Code/Fall | API-/Modulstatus |
| ---: | --- | --- |
| 1 | `USTPFL_UMS_EINN` | Voucher nach Guidance; Invoice im freigegebenen Vertrag |
| 2 | `AUSFUHREN` | nur fachlich bestätigte Ausfuhrfälle |
| 3 | `INNERGEM_LIEF` | nur bestätigte innergemeinschaftliche Warenlieferung, nie pauschal für EU |
| 4 | steuerfreie Umsätze § 4 UStG | nur mit expliziter fachlicher Regel |
| 5 | Reverse Charge nach § 13b UStG | nur mit expliziter fachlicher Regel |
| 11 | Kleinunternehmer nach § 19 UStG | Voucher nach Guidance; Invoice nur mit eigenem Canary und aktuellem REVENUE-Scope für 0 % |
| 17 | nicht im Inland steuerbare Leistung | nur fachlich freigegeben |
| 18 | OSS-Sonderfall | in OSS-v1 blockiert |
| 19 | OSS elektronische Leistungen | nur Invoice, nur bestätigtes digitales EU-B2C-Profil |
| 20 | OSS-Sonderfall | in OSS-v1 blockiert |
| 21 | Reverse Charge nach § 18b UStG | in dieser Invoice-/Voucher-Freigabe blockiert |
| 22 | Mahngebühr | ausschließlich separater, mailfreier Revenue-Voucher hinter Late-Fee-Canary und aktueller Guidance |

Die Tabelle ist kein Steuerberatungsergebnis. Sie beschreibt API-Fähigkeiten und die Fail-Closed-Grenze des Moduls.

## Fachliche Klassifikation

Der Tax-Resolver verwendet unter anderem Kleinunternehmerstatus, Land, Organisation, USt-ID, `taxexempt`, WHMCS-Steuersatz, Netto-/Bruttomodus, Positionsarten, Währung und eine zuverlässig bestätigte Leistungsart. Fehlen Pflichtdaten oder gibt es widersprüchliche Fälle, entsteht kein Payload.

Der Kleinunternehmerstatus kann mit einem Enddatum versehen werden. Rule 11 gilt dann nur für Rechnungsdaten bis einschließlich dieses Tages. Sie hat in diesem Zeitraum auch Vorrang vor dem bestätigten AddFunds-Sonderprofil; dadurch kann AddFunds weder auf Rule 1 ausweichen noch die Rule-11-Invoice-Gates umgehen. Nach dem Stichtag wird das AddFunds-Profil wieder gewählt, muss aber weiterhin einen zulässigen Rule-/Rate-Vertrag erfüllen. Ohne Enddatum bleibt der aktivierte Schalter aus Gründen der Upgrade-Kompatibilität unbegrenzt wirksam. Bei aktivem Kleinunternehmerprofil wird ein ungültiger gespeicherter Stichtag nicht als „Regelbesteuerung“ interpretiert, sondern blockiert den Export. Im Modus `invoice_only` kommen der Rule-11-Invoice-Canary und die aktuelle Mandantenfähigkeit hinzu; `invoice_for_oss` lässt diese Fälle weiterhin als Voucher laufen.

Der manuelle 0-%-Altbestandsnotweg aus rc.11 ist davon getrennt. Er
klassifiziert keinen Steuerfall und wird nie von Hooks oder normalen
Einzel-Exports gewählt. Im bestätigten historischen Sammelexport darf er eine
bezahlte EUR-Rechnung aus dem fest begrenzten Kleinunternehmerzeitraum
vorläufig als Voucher mit Rule 17 abbilden. Das gewählte Konto muss bei
Vorschau und Worker aktuell als `REVENUE` für Rule 17 mit 0 % gemeldet werden.
Positive 0-%-Positionen und eine centgenaue Summe sind Pflicht. Genau ein
strukturell belegter `PromoHosting`-Rabatt ist nur für Drittlandskunden als
Rule-17-Invoice zulässig. Dafür müssen `invoice_only`, der normale
Invoice-Canary und der exakte Rule-17-Rabatt-Canary aktiv sein. Da `InvoicePos`
kein `accountDatev` annimmt, kann dieser Beleg nicht auf das im Formular
gewählte Übergangskonto gezwungen werden. Guthaben, Sammelzahlungen, andere
Rabatte, AddFunds, Late Fees und Fremdwährungen bleiben blockiert. Diese
Kontierung bildet nicht § 19 UStG ab und muss vor jeder Auswertung oder Abgabe
in sevDesk manuell berichtigt werden.

Nach dem Kleinunternehmer-Stichtag akzeptiert Rule 1 nur noch 7 % oder 19 %. Das gilt unabhängig davon, ob die Regel über das deutsche Standardprofil, EU-B2C mit bestätigter Inlandsbesteuerung oder das AddFunds-Sonderprofil gewählt wurde. Enthält die WHMCS-Rechnung weiterhin eine Position mit 0 %, wird sie vor jedem Voucher- oder Invoice-Write mit `domestic_zero_tax_rate_outside_small_business_period` blockiert. Das Modul rät in diesem Fall keinen anderen Steuergrund und ändert die WHMCS-Rechnung nicht.

| Fall | Entscheidung |
| --- | --- |
| deutscher steuerpflichtiger Kunde | Rule 1 mit 7 % oder 19 %; Voucher oder Invoice gemäß Modus |
| deutscher Rule-1-Fall mit 0 % außerhalb des Kleinunternehmerzeitraums | blockiert; Quelldaten oder eigener bestätigter Steuerfall erforderlich |
| AddFunds mit Rule 1 und 0 % außerhalb des Kleinunternehmerzeitraums | blockiert; fachlich passende bestätigte Regel erforderlich |
| EU-Privatkunde, fachlich kein OSS | Rule 1 nach bestätigtem Nicht-OSS-Profil; Ziel gemäß Modus |
| bestätigte digitale EU-B2C-Leistung | Rule 19; nur `invoice_for_oss`/`invoice_only`, OSS-Profil und Canary |
| Rule-18-/Rule-20-Fall | blockiert |
| EU-Geschäftskunde ohne vollständige Nachweise | blockiert; EU-Land oder `taxexempt` allein genügt nicht |
| bestätigte innergemeinschaftliche Warenlieferung | Rule 3 nur Organisation + USt-ID + `taxexempt` + Setupbestätigung |
| Hosting/andere EU-B2B-Dienstleistung | Rule 3 verboten; ohne eigenes Profil blockiert |
| Drittland-Ausfuhr | Rule 2 nur nach fachlicher Freigabe |
| Drittland-Dienstleistung | blockiert, bis eigene Regel bestätigt ist |
| mehrere Steuer- oder Leistungsfälle | blockiert; keine vermutete Aufteilung |
| exakt bewiesene WHMCS-Sammelzahlung | Container ohne Umsatzexport; Originalrechnungen mit vollständigem Dokumentbruttobetrag |
| gewöhnliches Guthaben ohne `Invoice`-Verknüpfung | nur als ausdrücklich bestätigter Voucher-Einzelexport über den vollständigen Dokumentbruttobetrag |
| unklare Sammelzahlung oder anderer Guthabenfall | blockiert, manuelle Prüfung |
| positive, unbesteuerte WHMCS-Position vom Typ `LateFee` | nur hinter rc.9-Gates aus der Leistungs-Invoice entfernen; genau eine Buchhaltungsquelle über `MA` oder Rule-22-Voucher |
| negative, besteuerte oder veränderte `LateFee` | blockiert; manuelle Prüfung |
| genau ein struktureller `PromoHosting`-Rabatt mit passender Rule-/Rate-Capability und Canary | negative `InvoicePos` in `invoice_only`, `discountSave=null`; keine E-Rechnung |
| Null-/Negativbetrag, andere negative Position, Refund oder Storno | normaler Export blockiert oder eigener bestätigter Voucher-Korrekturpfad |
| Fremdwährung | blockiert, bis separat freigegeben |

Die Rule-19-Bestätigung gilt für **alle Positionen** der betreffenden Rechnung. Das Modul wertet Beschreibungen nicht aus. Kann der Betreiber diese Homogenität nicht zusichern, bleibt der Fall blockiert.

## B2B-Nachweis und Rule 3

`taxexempt=true` allein ist kein EU-B2B-Nachweis. Rule 3 ist standardmäßig deaktiviert und darf nur für innergemeinschaftliche Warenlieferungen freigegeben werden. Erforderlich sind Organisation, eine plausible USt-ID mit zum Rechnungsland passendem EU-Präfix, `taxexempt` und die ausdrückliche Setupbestätigung. Platzhalter wie `-`, `0` oder `n/a` werden abgelehnt. Die lokale Syntaxprüfung ist kein Ersatz für die von WHMCS beziehungsweise VIES bestätigte Gültigkeit. Hosting, Domains, Lizenzen und andere Dienstleistungen sind von diesem Profil ausgeschlossen. Northern Ireland und andere Sondergebiete benötigen eigene Tests und Freigaben.

## `ReceiptGuidance` und Invoice-Fähigkeit

Account-Datev-IDs sind mandantenspezifisch. Im Voucher-Pfad prüft das Modul vor jedem Write:

1. Konto existiert im aktuellen Mandanten.
2. Konto ist für Revenue/Voucher zulässig.
3. Konto erlaubt die Tax Rule.
4. Konto erlaubt den Positionssteuersatz.
5. Kombination ist für den Dokumenttyp Voucher zulässig.

`allowedReceiptTypes` muss in der Guidance strukturell lesbar sein und
`REVENUE` ausdrücklich enthalten. Ein fehlendes, leeres oder fehlerhaftes Feld
wird nicht als Freigabe interpretiert. Dasselbe gilt für Rule 11 im
Invoice-Pfad, obwohl dort kein `accountDatev` ins Payload geschrieben wird.

Invoice-Positionen übernehmen kein frei konfiguriertes `accountDatev`. Der Invoice-Steuerpfad lädt deshalb auch keine Voucher-`ReceiptGuidance`; er prüft stattdessen die freigegebene Invoice-Rule, den tatsächlichen WHMCS-Steuersatz, Pflichtreferenzen und den Invoice-API-Vertrag. B2B-Nachweise, Rule-3-Warenprofil und Betreiberbestätigungen bleiben identisch streng. In `invoice_for_oss` gilt dieser guidance-freie Pfad nur für den bestätigten Rule-19-Fall, während alle Voucher-Ziele weiter gegen Guidance geprüft werden. Eine erfolgreiche Guidance- oder Contract-Prüfung beweist nur technische Kompatibilität, nicht die steuerliche Richtigkeit.

## Verbindliche Regressionen

### EU B2C ohne OSS

EU-Land außerhalb Deutschlands, keine Firma/USt-ID und `taxexempt=false` darf nie Rule 3 erhalten. Je nach ausdrücklich freigegebenem Profil entsteht Rule 1 oder ein klarer Prüffall.

### Rule 19

Eine bezahlte EU-B2C-Rechnung mit effektiver Nummer und tatsächlichem WHMCS-Landsteuersatz wird nur dann
Rule-19-Invoice, wenn Modus, OSS-Profil, Canary und digitale Homogenität bestätigt sind. Die exakte
`StaticCountry`-Auflösung gehört zum Preflight: Eine leere, unbeschriftete oder mehrdeutige Antwort blockiert
vor dem Write. In `voucher_only`, ohne Profil, vor Zahlung, ohne auswertbare effektive Nummer oder bei
Rules 18/20 findet ebenfalls kein Remote-Write statt.

### Konto mit unzulässiger Rule

Erlaubt ein Voucher-Konto laut Guidance nur `AUSFUHREN`, muss jede andere Rule lokal vor PDF-, Kontakt- oder Voucher-Write scheitern.

## Beträge und Positionen

- Dezimalwerte werden ohne binäre Rundungsartefakte normalisiert.
- Der Voucher-Pfad behält seine dokumentierte Gesamttoleranz. Der
  Korrekturpfad und der normale Invoice-Pfad vergleichen Payload und gelesene
  Remote-Werte dagegen exakt in normalisierten Minor Units; eine Abweichung von
  einem Cent blockiert den Write beziehungsweise die Bestätigung.
- Korrektur-Voucher werden weder nach Create noch nach Marker-Recovery allein
  anhand ihres Headers gemappt. Der konkrete Voucher und alle gefilterten
  `VoucherPos` müssen den bestätigten Positionsvertrag exakt erfüllen.
- Invoice- und Voucher-Payload müssen denselben gefrorenen Netto-/Bruttomodus abbilden.
- Negative Positionen bleiben im normalen Export verboten. Einzige Ausnahme ist der oben beschriebene feste `PromoHosting`-Rabatt; er wird als exakt geprüfte negative `InvoicePos` übertragen. `discountSave` bleibt leer. Eine positive, unbesteuerte `LateFee` wird nicht als Invoice-Position übertragen, sondern folgt ausschließlich dem getrennten Mahngebührenvertrag.
- Leere und Nullsummen-Rechnungen sind Prüffälle.
- Beschreibungen sind Dokumentinhalt, aber kein Klassifikationssignal und kein Joblogfeld.

## Kontaktzuordnung

Das konfigurierte WHMCS-Client-Custom-Field bleibt die Kontaktzuordnung. Enthält es eine Remote-ID, wird `GET /Contact/{id}` ausgeführt. Ein vorhandener Kontakt wird als explizite Legacy-/Betreiberzuordnung wiederverwendet, auch wenn dessen optionales `customerNumber` fehlt oder historisch anders vergeben wurde. Das Modul verändert weder diese ID noch vorhandene Kontaktstammdaten. Liefert die ID keinen Kontakt, wird der Vorgang als `configured_contact_missing` blockiert; Suche und Neuanlage sind dann ausdrücklich kein Fallback.

Neu angelegte Kontakte erhalten die WHMCS-Client-ID als `customerNumber` und `buyerReference` sowie `governmentAgency=false`. Diese Werte werden bei bestehenden Kontakten bewusst nicht nachgetragen. Ein für ZUGFeRD ausgewählter Bestandskontakt ohne die nötigen Stammdaten blockiert daher, bis er in sevDesk geprüft und manuell ergänzt wurde.

Für die Haupt-E-Mail fragt das Modul höchstens 1.000 `CommunicationWay`-Einträge des Kontakts ab und filtert `type=EMAIL`, `main=true` und die Kontakt-ID selbst. Der Grund ist eine beobachtete API-Abweichung: In einzelnen Mandanten liefert die kombinierte Serverabfrage mit Kontakt-, Typ- und Hauptadressfilter eine leere Liste, obwohl der passende Eintrag vorhanden ist. Der breitere Read ist kein lockerer Fallback; fremde Kontakte, Nebenadressen und andere Kommunikationstypen bleiben lokal ausgeschlossen.

Vor dieser Auflösung muss die konfigurierte `custom_field_id` auf ein existierendes WHMCS-Feld mit `type=client` zeigen. Fehlt das Setting, wurde das Feld gelöscht oder hat es einen anderen Typ, scheitert der Worker lokal, bevor er einen sevDesk-Kontakt liest, sucht oder anlegt.

Nur bei leerem Feld sucht das Modul über `GET /Contact?customerNumber=<WHMCS-Client-ID>`. Genau ein passender Treffer darf in das Custom-Field geschrieben werden; mehrere Treffer ergeben `contact_conflict`.

Bleibt die Suche leer, ist eine Neuanlage nur mit der gespeicherten Bestätigung `customer_number_contact_creation_confirmed` erlaubt. Ohne sie endet der Vorgang als `contact_creation_not_confirmed`, noch vor dem Pre-Write-Checkpoint und `POST /Contact`.

Nach einem möglicherweise ausgeführten Contact-Create sucht die Recovery ausschließlich nach derselben WHMCS-Kundennummer. Sie verknüpft nur genau einen passenden Treffer. Kein oder mehrere Treffer bleiben `ambiguous`; ein zweiter `POST /Contact` ist ausgeschlossen.

## Historischer Dublettenschutz

Ein mailfreier Backfill prüft vor jedem Create zunächst die exakte effektive Rechnungsnummer. Danach liest er Invoices für denselben Kontakt und Tag über dokumentierte Filter und vergleicht Datum, Kontakt, EUR-Währung und Bruttobetrag clientseitig exakt. Unbekannte Betragsfilter werden nicht an sevDesk gesendet. Eine volle Seite mit 1.000 Treffern bleibt vorsorglich blockiert.

Zusätzlich sucht das Modul nach markerlosen Voucher-Kandidaten über Nummer, Datum, Kontakt und Betragsfenster sowie nach dem stabilen Marker `[WHMCS-INVOICE:<id>]`. Jeder mögliche Treffer verhindert die Neuanlage, ohne automatisch ein Mapping zu setzen. Die [sevDesk-OSS-Ankündigung](https://tech.sevdesk.com/api_news/posts/2025_03_06-oss-available/) ändert daran nichts: Rules 18 bis 20 werden nur über den ausdrücklich freigegebenen Invoice-Vertrag bewertet.

## HTTP-Fehlerklassen

| Klasse | Verhalten |
| --- | --- |
| 400/409/422 | kein automatischer Retry; Payload, Rule oder Lifecycle korrigieren |
| 401/403 | der gemeinsame API-Client setzt vor der Fehlerweitergabe den mandantenweiten Auth-Stopp; das gilt auch für Health- und Setup-Reads |
| 400/404 bei GET nach Remote-ID | 404 oder 400 mit dem bereinigten Code `NOT_FOUND` darf in den eng begrenzten read-only Prüfungen Abwesenheit belegen; ein generisches 400 nie |
| 429 | begrenzter Backoff, sofern noch kein unklarer Write vorliegt |
| 5xx/Timeout | vor Write begrenzt retrybar; während/nach Write zuerst Reconciliation |
| ungültiges JSON/Pflichtfeld fehlt | sicherer Fehler bei Reads; nach Write potenziell `ambiguous` |

Logs speichern höchstens HTTP-Status, stabilen Code, Exception-UUID und bereinigte Kurzmeldung. Rohpayload, Token, E-Mail, Adressen und PDF bleiben außerhalb.

## Pflege der OpenAPI-Spezifikation

`docs/sevdesk-openapi.yaml` wird nicht nebenbei aktualisiert. Ein eigenes Spezifikationsupdate braucht Quelle, Abrufdatum, Breaking-Change-Prüfung und aktualisierte Contract-Tests. Der Invoice-Canary dokumentiert Abweichungen von der vorhandenen Referenz, ändert sie aber nicht automatisch.
