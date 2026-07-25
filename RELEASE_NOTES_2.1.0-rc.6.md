# sevdesk 2.1.0-rc.6

`2.1.0-rc.6` ist eine Vorabversion für kontrollierte Test- und Nachlaufinstallationen. Sie behebt mehrere Steuer- und Recovery-Grenzen, die bei der Prüfung echter historischer WHMCS-Rechnungen aufgefallen sind.

## Änderungen gegenüber rc.5

- Normale Rule-1-Invoices akzeptieren nur noch 7 oder 19 Prozent. Ein alter EU-B2C-Inlandsmodus kann dadurch keine Rule-1-Invoice mit 0 Prozent mehr freigeben.
- Drittlandsleistungen lassen sich im bestätigten Profil als Rule 17 mit 0 Prozent exportieren. Das Profil bleibt standardmäßig aus und ersetzt keine fachliche Einordnung der Leistung.
- EU-B2C mit Rule 19 verlangt einen positiven, einheitlichen Steuersatz aus den WHMCS-Positionen. Gemischte oder steuerfreie Positionen bleiben gesperrt.
- Genau ein eindeutig zugeordneter `PromoHosting`-Rabatt kann bei einer normalen Invoice als negative `InvoicePos` übertragen werden. Der globale sevDesk-Rabatt bleibt leer: Im Rule-1-Bruttotest verschob `discountSave` einen Cent von der Steuer ins Netto, während die negative Position die WHMCS-Verteilung exakt beibehielt. Rule 11/0 %, Rule 1/19 %, Rule 17/0 % und Rule 19 mit Zielsteuersatz haben getrennte Freigaben. Die drei neuen Freigaben speichern die tatsächlich geprüfte Kombination aus Steuerprofil, Steuersatz und WHMCS-Netto-/Bruttomodus; ein alter Wert wie `on` reicht dafür nicht.
- Der Worker friert den Rabattvertrag und seine Capability vor dem ersten Invoice-Write ein. Readback und Recovery prüfen Rabattmarker, positive und negative Positionen sowie Netto-, Steuer- und Bruttosumme centgenau; `sumDiscounts` muss numerisch 0 sein.
- `LateFee`-Positionen werden vor jedem Beleg-Write als eigener Prüffall gestoppt. Eine Mahn- oder Verzugsgebühr wird nicht stillschweigend wie der zugrunde liegende Hostingumsatz behandelt.
- Vorschau und Worker verwenden für Rabattfälle denselben Rechnungs-, Steuer- und E-Rechnungskontext. Eine Rechnung, die als ZUGFeRD ausgewählt wäre, erscheint mit Rabatt nicht mehr fälschlich als exportierbar.
- Bewusst gelöschte Testbelege hinterlassen keine dauerhafte Setup-Sperre mehr. Die Klärfallansicht schließt eine terminale Dokumenthistorie erst ab, nachdem Voucher und Invoice unter derselben gespeicherten ID erneut eindeutig fehlen. Unklare Kontakt-, Zahlungs- oder Versandfolgen bleiben geschützt.

## Bestehende Daten

Der Dateitausch startet keinen Export. Vorhandene Voucher und Invoices werden weder konvertiert noch erneut angelegt. Bestehende Kontakt-IDs und Mappingzeilen bleiben erhalten; eine vorhandene sevDesk-Kontakt-ID wird wiederverwendet und nicht automatisch geändert.

## Weiterhin gesperrt

- Rule-11-Invoices, solange der verbundene sevDesk-Mandant keinen passenden `REVENUE`-Scope ausweist;
- EU-B2B-Hosting ohne separat bestätigten Dienstleistungs- und Reverse-Charge-Pfad;
- `LateFee`, andere negative Positionen, mehrere Rabatte und ZUGFeRD mit Rabatt;
- Rule 18 und 20, Invoice-CreditNotes sowie automatische Sammelzahlungsbuchungen;
- historische EU-B2C-Exporte, solange die für den jeweiligen Zeitraum geltende Inlands- oder Bestimmungslandbesteuerung nicht bestätigt ist.

## Installation

Vor dem Austausch Datenbank, Einstellungen und den bisherigen Modulordner sichern. Das aktive Addon nicht über WHMCS deaktivieren. Den Ordner `modules/addons/sevdesk` atomar ersetzen, PHP-FPM samt OPcache neu laden und anschließend die Übergangsinventur sowie den Health Check im Setup prüfen.

Die vollständige Reihenfolge steht in `modules/addons/sevdesk/UPGRADE.md` und `docs/operations.md`. Das Archiv heißt `sevdesk-2.1.0-rc.6.tar.gz`. Es enthält weder Tests noch Zugangsdaten, Rechnungsdateien oder lokale Arbeitsunterlagen.

Diese Vorabversion ersetzt keine steuerliche Freigabe. Vor einem größeren Nachlauf müssen die tatsächlich verwendeten Steuerprofile und jeder aktivierte Rabatt-Canary im verbundenen sevDesk-Mandanten geprüft sein.
