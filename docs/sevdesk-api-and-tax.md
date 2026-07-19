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
- Token niemals in URL, Jobdaten oder Fehlertext.

Vor dem ersten Write und im Health Check liest das Modul `/Tools/bookkeepingSystemVersion` und erwartet sevDesk-Update 2.0. Eine abweichende oder nicht lesbare Version blockiert Writes.

### Benötigte Endpunkte

| Zweck | Endpunkt |
| --- | --- |
| Buchhaltungssystem prüfen | `GET /Tools/bookkeepingSystemVersion` |
| Konto-/Rule-Fähigkeit | `GET /ReceiptGuidance/forAllAccounts`, `/forAccountNumber`, `/forTaxRule`, `/forRevenue` |
| Kontakt lesen/anlegen | benötigte `Contact`-Endpunkte |
| Voucher-PDF temporär hochladen | `POST /Voucher/Factory/uploadTempFile` |
| Voucher samt Positionen anlegen | `POST /Voucher/Factory/saveVoucher` |
| Voucher lesen/suchen/buchen | benötigte `Voucher`-GET-Endpunkte, `PUT /Voucher/{id}/bookAmount` |
| normale Invoice anlegen | `POST /Invoice/Factory/saveInvoice` |
| Invoice und Positionen lesen | `GET /Invoice/{id}`, `GET /Invoice/{id}/getPositions` |
| Invoice ohne Mail öffnen | `PUT /Invoice/{id}/sendBy` |
| Invoice per sevDesk senden | `POST /Invoice/{id}/sendViaEmail` |
| finale Invoice-PDF abrufen | `GET /Invoice/{id}/getPdf` |
| Invoice-Zahlung buchen | `PUT /Invoice/{id}/bookAmount` plus typabhängige Read-/Log-Endpunkte |

Invoice-Erstellung, Öffnen, PDF und Versand sind getrennte Schritte. Erfolg eines Schritts beweist keinen späteren Schritt.

## Dokumentziel und kein Fallback

`DocumentTargetResolver` friert vor dem ersten Remote-Write genau ein Ziel ein:

| `export_mode` | Verhalten |
| --- | --- |
| `voucher_only` | alle freigegebenen Steuerfälle als Voucher |
| `invoice_for_oss` | Rule 19 als Invoice, sonst freigegebener Voucher-Pfad |
| `invoice_only` | alle im Invoice-Vertrag freigegebenen Rules als Invoice |

`document_authority=sevdesk` ist nur mit `invoice_only` zulässig. Invoice-Ziele sind immer paid-only und verlangen eine finale WHMCS-Rechnungsnummer.

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
- HTTP 201, valide Remote-ID, exakte Rückprüfung und typisiertes Mapping als Erfolgsvoraussetzung.

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
    "deliveryAddressCountry": "FR",
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
  "takeDefaultAddress": true
}
```

Verbindliche Regeln:

- `invoiceType=RE`, Create-Status 100;
- unveränderte finale WHMCS-Nummer als `invoiceNumber`;
- Marker `[WHMCS-INVOICE:<invoice_id>]` in einem lesbaren internen Feld;
- positive EUR-Rechnung ohne angewendetes Guthaben und ohne negative Position;
- exakt derselbe Netto-/Bruttomodus und WHMCS-Steuersatz wie im gefrorenen Snapshot;
- konfigurierter, im Mandanten existierender `SevUser` und eine Standard-`Unity`;
- v1 verwendet je WHMCS-Position Menge 1;
- kein benutzerdefiniertes `accountDatev` an Invoice-Positionen;
- nach Create werden Invoice und alle Positionen gelesen und ID, Nummer, Status, Kontakt, Rule, Währung, Positionen und Summen exakt verglichen;
- erst die bestätigte Remote-ID plus `document_type=invoice` ergibt ein erfolgreiches Mapping.

Die fehlende freie `accountDatev`-Zuordnung ist eine sichtbare Einschränkung von `invoice_only`, nicht etwas, das das Modul verdeckt ergänzt.

### Öffnen, Versand und PDF

- `sendBy` öffnet eine Invoice ohne kundenseitige sevDesk-Mail und wird für WHMCS-Hoheit sowie den WHMCS-Mailkanal verwendet.
- `sendViaEmail` öffnet und versendet über sevDesk. Empfänger, Betreff und Text werden lokal validiert.
- Unmittelbar vor beiden Writes werden Draft-Header und Positionen erneut vollständig gelesen und exakt mit dem gefrorenen Snapshot verglichen. Abweichungen verhindern Open und Versand.
- `getPdf` wird erst nach nachweisbarer Finalisierung verwendet. Der Abruf akzeptiert nur PDF-MIME, Base64, `%PDF`-Signatur, EOF-Marker und höchstens 10 MiB.
- Die PDF wird nicht dauerhaft in WHMCS gespeichert; SHA-256, Ready- und Delivery-Zeitpunkt dürfen im Mapping stehen.

Nach `invoice_write_requested`, `invoice_open_write_requested` oder `invoice_delivery_write_requested` ist ein Transportfehler potenziell nicht wiederholbar. Recovery darf dann nur GETs ausführen. Ein fehlender Nachweis oder ein fehlendes Mapping nach einem späteren Write bleibt `ambiguous`; es gibt keinen Rückfall auf `saveInvoice`. Volle 1.000er-Seiten bei Kandidaten oder Positionen gelten als abgeschnitten und nicht beweiskräftig.

## Steuerregeln in sevDesk-Update 2.0

| ID | Code/Fall | API-/Modulstatus |
| ---: | --- | --- |
| 1 | `USTPFL_UMS_EINN` | Voucher nach Guidance; Invoice im freigegebenen Vertrag |
| 2 | `AUSFUHREN` | nur fachlich bestätigte Ausfuhrfälle |
| 3 | `INNERGEM_LIEF` | nur bestätigte innergemeinschaftliche Warenlieferung, nie pauschal für EU |
| 4 | steuerfreie Umsätze § 4 UStG | nur mit expliziter fachlicher Regel |
| 5 | Reverse Charge nach § 13b UStG | nur mit expliziter fachlicher Regel |
| 11 | Kleinunternehmer nach § 19 UStG | bei entsprechend bestätigtem Mandantenprofil |
| 17 | nicht im Inland steuerbare Leistung | nur fachlich freigegeben |
| 18 | OSS-Sonderfall | in OSS-v1 blockiert |
| 19 | OSS elektronische Leistungen | nur Invoice, nur bestätigtes digitales EU-B2C-Profil |
| 20 | OSS-Sonderfall | in OSS-v1 blockiert |
| 21 | Reverse Charge nach § 18b UStG | in dieser Invoice-/Voucher-Freigabe blockiert |

Die Tabelle ist kein Steuerberatungsergebnis. Sie beschreibt API-Fähigkeiten und die Fail-Closed-Grenze des Moduls.

## Fachliche Klassifikation

Der Tax-Resolver verwendet unter anderem Kleinunternehmerstatus, Land, Organisation, USt-ID, `taxexempt`, WHMCS-Steuersatz, Netto-/Bruttomodus, Positionsarten, Währung und eine zuverlässig bestätigte Leistungsart. Fehlen Pflichtdaten oder gibt es widersprüchliche Fälle, entsteht kein Payload.

| Fall | Entscheidung |
| --- | --- |
| deutscher steuerpflichtiger Kunde | Rule 1; Voucher oder Invoice gemäß Modus |
| EU-Privatkunde, fachlich kein OSS | Rule 1 nach bestätigtem Nicht-OSS-Profil; Ziel gemäß Modus |
| bestätigte digitale EU-B2C-Leistung | Rule 19; nur `invoice_for_oss`/`invoice_only`, OSS-Profil und Canary |
| Rule-18-/Rule-20-Fall | blockiert |
| EU-Geschäftskunde ohne vollständige Nachweise | blockiert; EU-Land oder `taxexempt` allein genügt nicht |
| bestätigte innergemeinschaftliche Warenlieferung | Rule 3 nur Organisation + USt-ID + `taxexempt` + Setupbestätigung |
| Hosting/andere EU-B2B-Dienstleistung | Rule 3 verboten; ohne eigenes Profil blockiert |
| Drittland-Ausfuhr | Rule 2 nur nach fachlicher Freigabe |
| Drittland-Dienstleistung | blockiert, bis eigene Regel bestätigt ist |
| mehrere Steuer- oder Leistungsfälle | blockiert; keine vermutete Aufteilung |
| Guthaben, Null-/Negativbetrag, negative Position, Refund, Storno | normaler Export blockiert oder eigener bestätigter Voucher-Korrekturpfad |
| Fremdwährung | blockiert, bis separat freigegeben |

Die Rule-19-Bestätigung gilt für **alle Positionen** der betreffenden Rechnung. Das Modul wertet Beschreibungen nicht aus. Kann der Betreiber diese Homogenität nicht zusichern, bleibt der Fall blockiert.

## B2B-Nachweis und Rule 3

`taxexempt=true` allein ist kein EU-B2B-Nachweis. Rule 3 ist standardmäßig deaktiviert und darf nur für innergemeinschaftliche Warenlieferungen freigegeben werden. Erforderlich sind Organisation, USt-ID, `taxexempt` und die ausdrückliche Setupbestätigung. Hosting, Domains, Lizenzen und andere Dienstleistungen sind von diesem Profil ausgeschlossen. Northern Ireland und andere Sondergebiete benötigen eigene Tests und Freigaben.

## `ReceiptGuidance` und Invoice-Fähigkeit

Account-Datev-IDs sind mandantenspezifisch. Im Voucher-Pfad prüft das Modul vor jedem Write:

1. Konto existiert im aktuellen Mandanten.
2. Konto ist für Revenue/Voucher zulässig.
3. Konto erlaubt die Tax Rule.
4. Konto erlaubt den Positionssteuersatz.
5. Kombination ist für den Dokumenttyp Voucher zulässig.

Invoice-Positionen übernehmen kein frei konfiguriertes `accountDatev`. Der Invoice-Steuerpfad lädt deshalb auch keine Voucher-`ReceiptGuidance`; er prüft stattdessen die freigegebene Invoice-Rule, den tatsächlichen WHMCS-Steuersatz, Pflichtreferenzen und den Invoice-API-Vertrag. B2B-Nachweise, Rule-3-Warenprofil und Betreiberbestätigungen bleiben identisch streng. In `invoice_for_oss` gilt dieser guidance-freie Pfad nur für den bestätigten Rule-19-Fall, während alle Voucher-Ziele weiter gegen Guidance geprüft werden. Eine erfolgreiche Guidance- oder Contract-Prüfung beweist nur technische Kompatibilität, nicht die steuerliche Richtigkeit.

## Verbindliche Regressionen

### EU B2C ohne OSS

EU-Land außerhalb Deutschlands, keine Firma/USt-ID und `taxexempt=false` darf nie Rule 3 erhalten. Je nach ausdrücklich freigegebenem Profil entsteht Rule 1 oder ein klarer Prüffall.

### Rule 19

Eine bezahlte EU-B2C-Rechnung mit finaler Nummer und tatsächlichem WHMCS-Landsteuersatz wird nur dann Rule-19-Invoice, wenn Modus, OSS-Profil, Canary und digitale Homogenität bestätigt sind. In `voucher_only`, ohne Profil, vor Zahlung, ohne finale Nummer oder bei Rules 18/20 findet kein Remote-Write statt.

### Konto mit unzulässiger Rule

Erlaubt ein Voucher-Konto laut Guidance nur `AUSFUHREN`, muss jede andere Rule lokal vor PDF-, Kontakt- oder Voucher-Write scheitern.

## Beträge und Positionen

- Dezimalwerte werden ohne binäre Rundungsartefakte normalisiert.
- Beim Voucher- und Korrekturpfad gelten die jeweils dokumentierten Cent-Toleranzen für aus WHMCS abgeleitete Summen. Der normale Invoice-Pfad vergleicht Payload und gelesene Remote-Werte dagegen exakt in normalisierten Minor Units; eine Abweichung von einem Cent ist dort bereits ein Vertragsfehler.
- Invoice- und Voucher-Payload müssen denselben gefrorenen Netto-/Bruttomodus abbilden.
- Negative Positionen bleiben im normalen Export verboten.
- Leere und Nullsummen-Rechnungen sind Prüffälle.
- Beschreibungen sind Dokumentinhalt, aber kein Klassifikationssignal und kein Joblogfeld.

## Kontaktzuordnung

Das konfigurierte WHMCS-Client-Custom-Field bleibt die Kontaktzuordnung. Enthält es eine Remote-ID, wird `GET /Contact/{id}` ausgeführt. Ein vorhandener Kontakt wird als explizite Legacy-/Betreiberzuordnung wiederverwendet, auch wenn dessen optionales `customerNumber` fehlt oder historisch anders vergeben wurde. Das Modul verändert weder diese ID noch vorhandene Kontaktstammdaten. Liefert die ID keinen Kontakt, wird der Vorgang als `configured_contact_missing` blockiert; Suche und Neuanlage sind dann ausdrücklich kein Fallback.

Vor dieser Auflösung muss die konfigurierte `custom_field_id` auf ein existierendes WHMCS-Feld mit `type=client` zeigen. Fehlt das Setting, wurde das Feld gelöscht oder hat es einen anderen Typ, scheitert der Worker lokal, bevor er einen sevDesk-Kontakt liest, sucht oder anlegt.

Nur bei leerem Feld sucht das Modul über `GET /Contact?customerNumber=<WHMCS-Client-ID>`. Genau ein passender Treffer darf in das Custom-Field geschrieben werden; mehrere Treffer ergeben `contact_conflict`.

Bleibt die Suche leer, ist eine Neuanlage nur mit der gespeicherten Bestätigung `customer_number_contact_creation_confirmed` erlaubt. Ohne sie endet der Vorgang als `contact_creation_not_confirmed`, noch vor dem Pre-Write-Checkpoint und `POST /Contact`.

Nach einem möglicherweise ausgeführten Contact-Create sucht die Recovery ausschließlich nach derselben WHMCS-Kundennummer. Sie verknüpft nur genau einen passenden Treffer. Kein oder mehrere Treffer bleiben `ambiguous`; ein zweiter `POST /Contact` ist ausgeschlossen.

## HTTP-Fehlerklassen

| Klasse | Verhalten |
| --- | --- |
| 400/409/422 | kein automatischer Retry; Payload, Rule oder Lifecycle korrigieren |
| 401/403 | mandantenweiter Auth-Stopp bis erfolgreicher read-only Setupprüfung |
| 404 | fachlich und dokumenttypabhängig einordnen |
| 429 | begrenzter Backoff, sofern noch kein unklarer Write vorliegt |
| 5xx/Timeout | vor Write begrenzt retrybar; während/nach Write zuerst Reconciliation |
| ungültiges JSON/Pflichtfeld fehlt | sicherer Fehler bei Reads; nach Write potenziell `ambiguous` |

Logs speichern höchstens HTTP-Status, stabilen Code, Exception-UUID und bereinigte Kurzmeldung. Rohpayload, Token, E-Mail, Adressen und PDF bleiben außerhalb.

## Pflege der OpenAPI-Spezifikation

`docs/sevdesk-openapi.yaml` wird nicht nebenbei aktualisiert. Ein eigenes Spezifikationsupdate braucht Quelle, Abrufdatum, Breaking-Change-Prüfung und aktualisierte Contract-Tests. Der Invoice-Canary dokumentiert Abweichungen von der vorhandenen Referenz, ändert sie aber nicht automatisch.
