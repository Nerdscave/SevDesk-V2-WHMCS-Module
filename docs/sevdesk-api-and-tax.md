# sevDesk-API und Steuerlogik

## Terminologie

**sevDesk-Update 2.0** meint in diesem Projekt die Buchhaltungslogik mit `taxRule`, `accountDatev`, `ReceiptGuidance` und strengerer Validierung.

Die HTTP-API heißt weiterhin:

```text
https://my.sevdesk.de/api/v1
```

Eine `/api/v2` ist weder Voraussetzung noch Teil der Implementierung. Die technische Primärquelle im Repository ist `docs/sevdesk-openapi.yaml`.

## Verbindlicher API-Vertrag

### Authentifizierung

- API-Token als unveränderter Wert im Header `Authorization`
- `Accept: application/json`
- `Content-Type: application/json` für JSON und passender Multipart-Typ beim PDF-Upload
- aussagekräftiger `User-Agent`, zum Beispiel Modulname und eigene Version ohne Domain oder Kundendaten
- Token niemals in URL, Jobdaten oder Fehlertext schreiben

Das Token hat laut Dokumentation kein kurzes automatisches Ablaufdatum. Ein Tokenwechsel wird trotzdem wie jeder andere Wechsel von Zugangsdaten behandelt.

### Systemversion

Vor dem ersten Write und im Health Check liest das Modul `/Tools/bookkeepingSystemVersion`. Es erwartet sevDesk-Update 2.0. Eine abweichende oder nicht lesbare Version blockiert Writes und zeigt den Grund an.

### Benötigte Endpunkte

| Zweck | Endpunkt |
| --- | --- |
| Buchhaltungssystem prüfen | `GET /Tools/bookkeepingSystemVersion` |
| alle zulässigen Kontenkombinationen | `GET /ReceiptGuidance/forAllAccounts` |
| bestimmtes Konto prüfen | `GET /ReceiptGuidance/forAccountNumber` |
| Konten für eine Tax Rule prüfen | `GET /ReceiptGuidance/forTaxRule` |
| Erlöskonten eingrenzen | `GET /ReceiptGuidance/forRevenue` |
| Kontakt lesen/anlegen | benötigte `Contact`-Endpunkte |
| PDF temporär hochladen | `POST /Voucher/Factory/uploadTempFile` |
| Voucher samt Positionen anlegen | `POST /Voucher/Factory/saveVoucher` |
| Voucher für Recovery lesen/suchen | benötigte `Voucher`-GET-Endpunkte |

Das Modul verwendet nur diese Aufrufe. Weitere Endpunkte kommen hinzu, sobald eine freigegebene Funktion sie braucht.

## Voucher-first

Die erste Version übernimmt die bereits in WHMCS erzeugte Rechnung als sevDesk-Voucher und hängt das WHMCS-PDF an. Der Buchhaltungsnachlauf verwendet damit das bestehende WHMCS-Dokument und legt keine zusätzliche sevDesk-Ausgangsrechnung an.

Vor dem ersten Produktivlauf muss die Steuerberatung dieses Vorgehen bestätigen, und es muss in einem sevDesk-Testmandanten geprüft werden. Lässt sich ein Steuerfall technisch nicht als Voucher abbilden, blockiert das Modul ihn. Ein automatischer Wechsel auf ein anderes Objektmodell findet nicht statt.

## Voucher-Payload

Die Struktur folgt der lokalen OpenAPI-Spezifikation. Ein vereinfachtes Beispiel mit rein synthetischen Werten:

```json
{
  "voucher": {
    "objectName": "Voucher",
    "mapAll": true,
    "voucherDate": "01.01.2030",
    "supplier": {
      "id": "CONTACT_ID",
      "objectName": "Contact"
    },
    "description": "WHMCS INV-EXAMPLE",
    "status": 100,
    "taxRule": {
      "id": "1",
      "objectName": "TaxRule"
    },
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
      "accountDatev": {
        "id": "CONFIGURED_ACCOUNT_ID",
        "objectName": "AccountDatev"
      },
      "comment": "Synthetische Testposition"
    }
  ],
  "voucherPosDelete": null
}
```

Für jeden Payload gilt:

- `mapAll` ist ein Boolean, nicht der String `"true"`.
- Nicht gesetzte Werte sind echtes JSON-`null`, nicht der String `"null"`.
- Jede Position hat `accountDatev`, Betrag, `taxRate`, `net` und ein korrektes `objectName`.
- Alle Positionen eines Vouchers verwenden konsistent Netto- oder Bruttobeträge.
- Der WHMCS-Modus `TaxType` bestimmt, ob `sumNet`/`net=true` oder `sumGross`/`net=false` gesendet wird.
- Der Voucher wird mit Status 50 oder 100 erstellt; für den normalen Nachlauf ist Status 100 vorgesehen. „Bezahlt“ wird nicht durch einen unzulässigen Create-Status simuliert.
- Der vom Upload zurückgegebene Dateiname wird unverändert an `saveVoucher` übergeben.
- Erfolg setzt HTTP 201, eine valide Voucher-ID und das erfolgreiche lokale Mapping voraus.

## Steuerregeln in sevDesk-Update 2.0

Für die erste Version sind vor allem diese Revenue-Regeln relevant:

| ID | Code/Fall | Zulässige Positionssteuersätze laut Spezifikation | Modulstatus |
| ---: | --- | --- | --- |
| 1 | `USTPFL_UMS_EINN`, steuerpflichtige Umsätze | 0, 7, 19 | unterstützt nach Guidance-Prüfung |
| 2 | `AUSFUHREN` | 0 | nur für fachlich bestätigte Ausfuhrfälle |
| 3 | `INNERGEM_LIEF` | 0, 7, 19 | nur für bestätigte innergemeinschaftliche Lieferung, nie pauschal für EU |
| 4 | steuerfreie Umsätze § 4 UStG | 0 | nur mit expliziter fachlicher Regel |
| 5 | Reverse Charge nach § 13b UStG | 0 | nur mit expliziter fachlicher Regel |
| 11 | Kleinunternehmer nach § 19 UStG | 0 | unterstützt, wenn der Mandant so konfiguriert und geprüft ist |
| 17 | nicht im Inland steuerbare Leistung | 0 | manueller/fachlich freizugebender Fall |
| 18–20 | One Stop Shop | landabhängig | für Voucher laut Spezifikation nicht unterstützt; blockiert |
| 21 | Reverse Charge nach § 18b UStG | 0 | für Voucher laut Spezifikation nicht unterstützt; blockiert |

Die Tabelle ist kein Steuerberatungsergebnis. Sie beschreibt API-Fähigkeiten und die Sicherheitsgrenze des Moduls.

## Fachliche Klassifikation

Der Tax-Resolver prüft die Fälle in fester Reihenfolge. Er liefert entweder eine eindeutige Klassifikation oder einen Prüffall.

### Eingaben

- globale Kleinunternehmer-Einstellung
- Land des Kunden
- Kundentyp: Firma oder Person
- USt-ID und deren fachlich festgelegter Validierungsstatus
- WHMCS-`taxexempt`
- Steuersatz und Besteuerungsart der Invoice
- Netto-/Bruttomodus
- Rechnungspositionstypen, insbesondere Guthaben/Refund
- Währung
- für die Steuerentscheidung erforderliche Leistungsart, soweit zuverlässig vorhanden

Fehlen erforderliche Daten, markiert der Resolver den Fall als blockiert.

### Geplante Entscheidungsmatrix

| Fall | Grundentscheidung | Erste Version |
| --- | --- | --- |
| Kleinunternehmer-Mandant | Rule 11, 0 %, passendes Guidance-Konto | unterstützt nach Health Check |
| deutscher steuerpflichtiger Kunde | Rule 1, WHMCS-Steuersatz | unterstützt |
| EU-Privatkunde, fachlich kein OSS-Fall | Rule 1, WHMCS-Steuersatz | unterstützt; eigener EU-B2C-Regressionsfall |
| EU-Privatkunde, OSS erforderlich | Rules 18–20 wären nötig | blockiert, weil Voucher nicht unterstützt |
| EU-Geschäftskunde | nicht allein aus Land oder `taxexempt` ableiten | nur mit freigegebener B2B-/Leistungsart-Regel |
| bestätigte innergemeinschaftliche Warenlieferung | Rule 3 und Guidance-kompatibles Konto | nur für Organisation + USt-ID + `taxexempt` und nach expliziter Setup-Bestätigung unterstützt |
| EU-B2B-Dienstleistung/Reverse Charge | je nach Fall Rule 5, 17 oder 21 | blockiert, solange Matrix und Voucher-Fähigkeit nicht eindeutig sind |
| Drittland-Ausfuhr | Rule 2 | nur nach fachlicher Freigabe |
| Drittland-Dienstleistung | häufig anderer Fall als Ausfuhr | blockiert, bis Rule/Konto freigegeben sind |
| AddFunds, negative Position, Refund, Gutschrift, Storno | Credit-/Sonderfall | manuelle Prüfung |
| mehrere Steuerfälle in einer Invoice | Das Modul darf den Voucher nicht anhand von Vermutungen aufteilen | manuelle Prüfung |
| Fremdwährung | zusätzliche Wechselkursregeln | manuell, bis separat getestet und freigegeben |

Vor dem Produktivbetrieb muss schriftlich festgelegt sein, welche Positionen als Lieferung oder Dienstleistung gelten und welche Rule daraus folgt. Die Bezeichnung „EU B2B“ allein reicht dafür nicht.

## B2B-Nachweis

`taxexempt = true` allein reicht als technischer Nachweis für EU B2B nicht aus. Das Profil ist standardmäßig deaktiviert. Der Betreiber kann Rule 3 erst auf der geschützten Setupseite freigeben. Dazu muss er bestätigen, dass das Profil nur für innergemeinschaftliche Warenlieferungen gilt. Diese Freigabe darf nicht für Hosting, Domains, Lizenzen oder andere Dienstleistungen verwendet werden.

Der jeweilige WHMCS-Kunde muss außerdem als Organisation geführt werden und eine USt-ID besitzen. Die Regel muss widersprüchliche Daten erkennen:

- Privatkunde ohne Firma/USt-ID darf nie `INNERGEM_LIEF` erhalten.
- Sind Firma und USt-ID vorhanden, muss die Klassifikation sie berücksichtigen.
- Ein gesetztes `taxexempt` ohne die verlangten B2B-Daten wird zum Prüffall.
- Ein vorhandenes Rule-3-Konto ohne gespeicherte Warenlieferungsbestätigung bleibt blockiert.
- Dienstleistungsfälle bleiben unabhängig von Firma und USt-ID in manueller Prüfung, solange kein eigenes bestätigtes Voucher-Profil implementiert ist.
- Northern Ireland und andere umsatzsteuerliche Sondergebiete brauchen eigene Testfälle, bevor sie freigegeben werden.

Eine USt-ID-Prüfung über einen externen Dienst gehört nicht automatisch zum ersten Release. Wird sie fachlich erforderlich, müssen Verfügbarkeit, Nachweis und Datenschutz gesondert entschieden werden.

## `ReceiptGuidance` als technische Freigabe

Die konfigurierten Account-Datev-IDs sind mandantenspezifisch. Beim Speichern der Einstellungen und vor einem Job prüft das Modul mindestens:

1. Existiert das Konto im aktuellen sevDesk-Mandanten?
2. Ist es ein zulässiges Erlöskonto für Voucher?
3. Erlaubt es die gewählte Tax Rule?
4. Erlaubt es den Positionssteuersatz?
5. Ist die Kombination für den Dokumenttyp Voucher zugelassen?

Der Worker lädt die benötigte Guidance einmal pro Lauf in den Speicher. Für die erste Version ist keine dauerhafte Cache-Schicht nötig. Kann er die Guidance nicht laden, schreibt er keinen Voucher.

Eine erfolgreiche Guidance-Prüfung beweist nur API-Kompatibilität, nicht die steuerliche Richtigkeit der fachlichen Klassifikation.

## Verbindliche Regressionstests

Mindestens diese Tests sind Release-Blocker:

### EU B2C

Gegeben:

- EU-Land außerhalb Deutschlands
- keine Firma
- keine USt-ID
- `taxexempt = false`
- steuerpflichtige WHMCS-Invoice

Erwartet:

- Klassifikation EU B2C, nicht EU B2B
- kein `INNERGEM_LIEF`
- Rule 1 und der konfigurierte EU-B2C-Account nur dann, wenn Guidance Regel und Steuersatz erlaubt
- andernfalls klarer Prüffall vor dem Remote-Write

### Konto mit unzulässiger Steuerregel

Gegeben ist ein Konto, dessen Guidance nur `AUSFUHREN` zulässt. Eine abweichende Rule wie `STFREIE_UMS_P4` muss der Payload-Builder ablehnen. Das Item endet mit einem verständlichen lokalen Validierungsfehler, bevor eine Anfrage an sevDesk geht.

## Beträge und Positionen

- Dezimalwerte werden ohne binäre Rundungsartefakte auf die WHMCS-Währungspräzision normalisiert.
- Summe der Positionen, Steuer und Invoice-Gesamtsumme müssen innerhalb einer festgelegten Cent-Toleranz zusammenpassen.
- Rabatt, Credit oder negative Position dürfen das Vorzeichen des Vouchers nicht unbemerkt ändern.
- Bei `taxed = false` wird ein Steuersatz von 0 nur verwendet, wenn die gewählte Rule/Konto-Kombination diesen Fall fachlich und technisch erlaubt.
- Eine leere oder reine Nullsummen-Invoice ist ein manueller Prüffall.
- Positionsbeschreibungen werden als Beleginhalt übertragen, aber nicht in Diagnose- oder Joblogs dupliziert.

## Kontaktzuordnung

Das konfigurierte WHMCS-Client-Custom-Field enthält die sevDesk-Kontakt-ID. Das Modul liest bestehende Werte weiter.

- Vor Wiederverwendung wird die Remote-ID lesend geprüft.
- Ein fehlender Kontakt wird einmal erstellt und die ID erst nach bestätigtem Erfolg in WHMCS gespeichert.
- Ein ungültiger oder nicht mehr existierender Kontakt erzeugt einen sichtbaren Recovery-Fall; das Modul erzeugt nicht ungefragt einen zweiten Kontakt.
- Nach einem Kontakt-Create mit unbekanntem Ausgang liest die Recovery nur noch. Sie verknüpft genau einen Treffer anhand der Kundennummer. Bei keinem oder mehreren Treffern bleibt das Item `ambiguous`; ein weiterer `POST /Contact` ist ausgeschlossen.
- Eine Änderung des konfigurierten Custom Fields erfordert eine Warnung und eine explizite Migration.

## HTTP-Fehlerklassen

| Klasse | Bedeutung | Retry |
| --- | --- | --- |
| 400 | syntaktisch/fachlich ungültiger Request | nein |
| 401 | Token fehlt oder ist ungültig | nein; weitere Claims stoppen |
| 403 | fehlende Berechtigung | nein; weitere Claims stoppen |
| 404 | Objekt/Endpunkt fehlt | nur nach fachlicher Einordnung |
| 409 | Konflikt/Zustand unzulässig | normalerweise nein |
| 422 | Validierung von Tax Rule, Konto oder Daten | nein |
| 429 | Rate Limit | ja, begrenzt mit `Retry-After`/Backoff |
| 5xx | sevDesk- oder Gatewayfehler | ja, begrenzt |
| Connect-/Read-Timeout | Ausgang abhängig vom Zeitpunkt | begrenzt; nach POST zuerst Recovery prüfen |

Das Modul speichert HTTP-Status, internen Ergebniscode, die sevDesk-Exception-UUID, soweit vorhanden, und eine bereinigte Kurzmeldung. Rohpayload, Token und Kundendaten bleiben außerhalb des Logs.

## Pflege der OpenAPI-Spezifikation

`docs/sevdesk-openapi.yaml` wird nicht automatisch überschrieben. Bei einem geplanten Update:

1. offizielle Quelle und Abrufdatum dokumentieren;
2. API-Basis, Update-2.0-Hinweise, Voucher-, Contact- und ReceiptGuidance-Endpunkte vergleichen;
3. Tax-Rule- und „unsupported use cases“-Tabellen prüfen;
4. Contract-Tests aktualisieren;
5. erst danach das Spezifikationsupdate separat committen.
