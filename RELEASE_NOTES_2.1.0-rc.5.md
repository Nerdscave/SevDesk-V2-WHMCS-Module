# sevdesk 2.1.0-rc.5

`2.1.0-rc.5` ist eine Vorabversion für Testinstallationen. Sie sichert den Invoice-only-Nachlauf für den Kleinunternehmerzeitraum ab und behandelt WHMCS-Sammelzahlungen erstmals als eigenen Zahlungsfall.

## Neu in diesem RC

- Die Kleinunternehmerregelung kann mit einem Enddatum versehen werden. Für Rechnungen bis einschließlich dieses Datums gilt Rule 11 mit 0 %, unabhängig von Land und Kundenart. Ohne Enddatum bleibt das bisherige, unbegrenzte Verhalten erhalten.
- Rule-11-Invoices besitzen ein eigenes, standardmäßig ausgeschaltetes Gate. Ein Live-Lauf zeigte, dass sevDesk den Draft annehmen und erst beim Öffnen wegen des automatisch gewählten Konten-Scopes ablehnen kann. Deshalb verlangt das Modul zusätzlich zum Canary einen aktuellen `REVENUE`-Eintrag in `ReceiptGuidance`, der Rule 11 mit 0 % erlaubt.
- WHMCS-Sammelzahlungsrechnungen werden anhand ihrer `Invoice`-Verknüpfungen als Zahlungscontainer erkannt. Sie erzeugen keinen zusätzlichen Umsatzbeleg; exportiert werden nur die vollständig geprüften Originalrechnungen.
- Bei den Originalrechnungen gilt `subtotal + tax + tax2 = total + credit`. `total` ist der direkt gezahlte Betrag, der vollständige Bruttobetrag ist `total + credit`. Bei Vollguthaben steht `total` auf null. Die gemeinsame Banktransaktion wird nicht automatisch auf mehrere sevDesk-Dokumente verteilt.
- Direkt vor jedem Voucher- oder Invoice-Create liest der Worker den vollständigen WHMCS-Rechnungsvertrag noch einmal. Dazu gehören Datum, Nummer, Steuerdaten, Positionen, Kundeneinordnung und bei Sammelzahlungen der gesamte Zahlungsgraph. Änderungen während PDF-, Kontakt- oder E-Rechnungsprüfung stoppen den Export vor dem Beleg-POST.
- Die sevDesk-Kontakt-ID des Rechnungsempfängers wird getrennt eingefroren. Ändert sich das WHMCS-Kundenfeld von Kontakt A zu B, verwendet der Worker weder den neuen Empfänger noch startet er einen zweiten Create.
- Neue Mappings speichern die kundenseitige Dokumenthoheit dauerhaft. Ein späterer Setupwechsel ändert vorhandene Voucher und Invoices nicht. Hat ein älterer RC den Typ und die Hoheit bereits im Job eingefroren, darf die nachträgliche Mappingbestätigung diesem Nachweis nicht widersprechen.
- Normale Invoices erhalten die vollständige WHMCS-Rechnungsadresse direkt am Beleg. Das Modul verändert dafür keinen bestehenden sevDesk-Kontakt und prüft die Adresse vor Öffnung und Versand über einen PII-freien Hash.
- Eine alte Invoice kann sevDesk-Hoheit nur erhalten, solange die zugehörige WHMCS-Rechnung bezahlt ist. Die Remote-Status 200, 750 und 1000 gelten als finalisiert. Kundenansicht und PDF-Proxy prüfen den lokalen Status zusätzlich; eine unbezahlte Rechnung bleibt Proforma.
- Wiederholte, bereits identische Checkpoints gelten auch dann als gespeichert, wenn MariaDB dafür null geänderte Zeilen meldet. Lease, Kontext und Remote-ID müssen dabei weiterhin exakt übereinstimmen.
- Genau ein strukturell passender `PromoHosting`-Rabatt kann in `invoice_only` für Rule 11 mit 0 % als festes `discountSave` übertragen werden. Dieser Pfad setzt das allgemeine Rule-11-Invoice-Gate und zusätzlich seinen eigenen, standardmäßig ausgeschalteten Rabatt-Canary voraus.
- Alte Rechnungen ohne separate `invoicenum` verwenden rein lesend ihre unveränderliche interne WHMCS-ID als effektive Rechnungsnummer. Die Datenbank wird dabei nicht rückwirkend geändert.
- Neu erzeugte Voucher werden erst nach einem separaten Readback von Beleg und Positionen gemappt. Die Recovery verwendet denselben exakten Vergleich und stoppt bei einer vollen 1.000er-Suchgrenze, statt die Eindeutigkeit zu raten.
- Unerwartete Datenbank- oder Workerfehler erscheinen im Adminbereich nur noch als bereinigter Hinweis mit Referenznummer. SQL, Bindings und API-Token gelangen nicht in Job- oder Setupmeldungen.

Unklare Guthabenketten, sonstige negative Positionen und ZUGFeRD mit angewendetem Guthaben bleiben vor jedem sevDesk-Write blockiert. Die bestehende Kompatibilitätsausnahme für gewöhnliches Guthaben bleibt erhalten: Sie gilt nur beim ausdrücklich bestätigten Voucher-Einzelexport über den vollen Bruttobetrag, nicht in Bulk- oder Invoice-Läufen.

## WHMCS-Mailanhänge

Zwei Postfachtests haben die Grenze von WHMCS 8.13.4 bestätigt: `EmailPreSend` wird ausgeführt, der zurückgegebene Binäranhang aber nicht in die Nachricht übernommen. Der Kanal `whmcs_template` ist deshalb in Setup, Health Check und Worker gesperrt. Bei sevDesk-Dokumenthoheit bleibt der direkte Versand über `sendViaEmail` verfügbar.

## Vor dem produktiven Nachlauf

Vor der finalen `2.1.0` bleiben folgende Freigaben offen:

- Invoice-`bookAmount` mit einer eindeutig passenden, ungebuchten Testtransaktion;
- der rabattfreie Rule-11-Invoice-Canary und danach der eigene Rabatt-Canary;
- Voucher-Canaries für die tatsächlich verwendeten Steuerfälle;
- die steuerliche und betriebliche Abnahme der Zielinstallation.

Für Rule 11 reicht ein gespeichertes Setup-Häkchen nicht. sevDesk empfiehlt, Vorgänge aus dem Kleinunternehmerzeitraum vor dem Wechsel zur Regelbesteuerung abzuschließen. Lässt der aktuelle Mandant den alten Steuerfall nicht mehr zu, muss zunächst mit sevDesk beziehungsweise der Buchhaltung geklärt werden, wie diese Belege im Mandanten sauber nacherfasst werden.

Der Dateitausch startet keinen Bulk-Export. Bestehende Mappings werden weder konvertiert noch neu exportiert. Nach dem Upgrade bleiben automatische Verarbeitung und neue Canary-Gates aus, bis die lokale Übergangsinventur geprüft und ausdrücklich bestätigt wurde.

## Installation

Vor dem Dateitausch Datenbank, Modulverzeichnis und Einstellungen sichern. Das bisherige Addon nicht über WHMCS deaktivieren. Anschließend den Ordner `modules/addons/sevdesk` atomar ersetzen und die Bestandsprüfung im Setup durchlaufen. Die genaue Reihenfolge steht in `modules/addons/sevdesk/UPGRADE.md` und `docs/operations.md`.

Das Release-Archiv heißt `sevdesk-2.1.0-rc.5.tar.gz`. Es enthält das Addon, die Upgrade- und Betriebsanleitung sowie die Lizenz. Tests, `vendor/`, Zugangsdaten, Testdokumente und lokale Arbeitsdateien gehören nicht in das Archiv.
