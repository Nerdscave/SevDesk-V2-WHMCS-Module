# sevdesk 2.1.0-rc.2

`2.1.0-rc.2` ist eine Vorabversion für Testinstallationen. Der Code für Voucher, Hybridmodus, Invoice-only, sevDesk-Dokumenthoheit und native ZUGFeRD-Invoices ist enthalten. Für echte Buchhaltungsdaten ist dieser Stand noch nicht freigegeben, weil die Zielumgebungs- und Testmandanten-Canaries ausstehen.

## Was sich seit rc.1 geändert hat

rc.2 sichert vor allem den Wechsel in `invoice_only` ab und ergänzt einen begrenzten, sevDesk-nativen ZUGFeRD-Pfad.

- Das Setup zeigt vor Änderungen an Dokumentmodus, Hoheit, OSS- oder E-Rechnungsprofil eine rein lesende Übergangsinventur. Sie erfasst Mappings, offene und alte Jobs, bezahlte ungemappte Rechnungen und lokale Dublettenhinweise.
- Bestehende Zuordnungen bleiben unverändert. Voucher bleiben Voucher, vorhandene Invoices behalten ihre eingefrorene Dokumenthoheit. Es gibt keine Konvertierung und keinen automatischen Neu-Export.
- Alte fehlgeschlagene Voucher-Jobs laufen nach einem Moduswechsel nicht unbemerkt im neuen Modus weiter. Nur sichere Vor-Write-Zustände können nach einer eigenen Bestätigung als neuer mailfreier Job eingereiht werden.
- Der historische Nachlauf hat eine gemeinsame Vorschau und einen rein lesenden Dublettenschutz. Er versendet keine E-Mail und erzeugt keine E-Rechnung.
- Legacy-Mappings lassen sich gesammelt vorprüfen. Die Sammelbestätigung akzeptiert nur eindeutige Markertreffer; markerlose und kollidierende Belege bleiben Einzelfälle.
- Vollständige Mappings können nicht mehr einfach aufgehoben werden. Die lokale Zeile wird nur entfernt, wenn beide Voucher-/Invoice-by-ID-Abfragen die Abwesenheit eindeutig mit 400 oder 404 bestätigen.
- Native ZUGFeRD-Invoices werden von sevDesk erzeugt. Das Modul prüft Invoice, Empfängeradresse, PaymentMethod, XML und PDF, speichert aber keine Dokumentkopie in WHMCS.

## Austausch eines bestehenden Moduls

Addon-Name, Ordner und Mappingtabelle bleiben gleich. Vorhandene `mod_sevdesk`-Zeilen und unbekannte Werte in `tbladdonmodules` werden nicht gelöscht. Die Migration ergänzt das Schema nur um nullable Felder.

Der erste Start bleibt absichtlich gesperrt. Das Modul setzt `runtime_review_required=on` und schaltet die automatische Synchronisation aus. Danach müssen Setup, Übergangsinventur, Kontaktfeld, API-Zugang, Konten, Steuerprofile und offene Jobs geprüft werden. Erst die bestätigte, rein lesende Prüfung hebt die Quarantäne auf.

Das alte Addon vor dem Dateitausch nicht über WHMCS deaktivieren. Datenbank und Modulordner sichern, alte externe Hooks oder Crondateien stilllegen, den Ordner atomar ersetzen und danach die neue Adminseite öffnen. Die vollständige Reihenfolge steht in `modules/addons/sevdesk/UPGRADE.md`.

## Bestehende Kontakte

Enthält das konfigurierte WHMCS-Kundenfeld bereits eine sevDesk-ID, verwendet das Modul genau diese ID. Es ändert weder die Verknüpfung noch die vorhandenen Stammdaten.

Ist die ID in sevDesk nicht mehr vorhanden, wird der Export blockiert. Das Modul sucht dann keinen Ersatzkontakt und legt kein Duplikat an.

Nur bei leerem Feld wird nach `customerNumber=<WHMCS-Client-ID>` gesucht. Genau ein bestätigter Treffer darf verknüpft werden. Mehrere oder nicht eindeutig prüfbare Treffer bleiben gesperrt. Ein neuer Kontakt entsteht erst nach der eigenen Betreiberbestätigung im Setup.

Eine allgemeine Kontaktsynchronisation gehört weiterhin nicht zum Release.

Beim erstmaligen Verknüpfen schreibt das Modul nur in ein nachweislich leeres WHMCS-Kontaktfeld. Eine schon vorhandene andere ID oder doppelte Feldzeilen blockieren den Vorgang, statt eine alte Zuordnung zu überschreiben.

## Empfohlener Weg zu Invoice-only

Ein Upgrade behält zunächst diese sichere Grundstellung:

```text
document_authority = whmcs
export_mode        = voucher_only
oss_profile        = blocked
e_invoice_mode     = off
sync_enabled       = off
```

Nach bestandener Bestandsprüfung und Invoice-Canary kann die erste Testkonfiguration auf `invoice_only + whmcs` wechseln. E-Rechnungen bleiben dabei aus. WHMCS ist weiterhin die kundenseitige Dokumenthoheit, während neue geeignete und bezahlte Rechnungen als normale sevDesk-Invoices exportiert werden.

Bereits gemappte Rechnungen werden nicht angefasst. Bezahlte, ungemappte Alt-Rechnungen ab `import_after` können nach der Vorschau als `historical_backfill` eingereiht werden. Vor jedem Invoice-Create sucht das Modul nach derselben Rechnungsnummer, nach passenden Invoice-Daten und nach möglichen Voucher-Markern. Jeder Treffer blockiert die Neuanlage, ohne ein Mapping zu raten.

Ein alter Exportjob mit ungeklärtem Remote-Write sperrt jeden neuen Export derselben Rechnung. Das gilt auch dann, wenn eine frühere Modulversion seinen Dedupe-Schlüssel schon freigegeben hatte.

sevDesk-Hoheit folgt erst später. Sie setzt `invoice_only`, WHMCS-Proforma, Theme-Adapter und einen geprüften Versandweg voraus und gilt nur für danach entschiedene Invoices.

## Native ZUGFeRD-Invoices

ZUGFeRD bleibt eine normale Invoice mit zusätzlicher strukturierter Komponente. Es gibt keinen dritten Dokumenttyp und keine eigene XML-Erzeugung.

Der Pfad wird nur gewählt, wenn alle Voraussetzungen erfüllt sind:

- `invoice_only` und sevDesk-Dokumenthoheit;
- normaler Invoice-Canary und eigener ZUGFeRD-Canary bestätigt;
- PHP XMLReader in Web und Cron;
- vorhandenes, nur für Administratoren sichtbares WHMCS-Kundenfeld vom Typ Tickbox;
- gesetztes Kunden-Opt-in;
- deutscher Organisationskunde, deutsches Rechnungsland und Rule 1;
- Rechnung nicht vor dem Aktivierungsdatum;
- gültige sevDesk-Referenzen für SevUser, Unity, PaymentMethod, Kontakt und Land;
- Käuferreferenz, passende Haupt-E-Mail und vollständige deutsche Rechnungsadresse am sevDesk-Kontakt;
- Kontakt ist nicht als Behörde geführt.

Greift das Kunden-Opt-in und fehlt eine Pflichtangabe, stoppt der Export. Es gibt keinen stillen Rückfall auf eine normale Invoice.

sevDesk erhält `propertyIsEInvoice=true`, die strukturierte Adresse, PaymentMethod und `takeDefaultAddress=false`. Das Modul liest die Daten zurück, prüft das CII-XML aus `getXml` und friert den SHA-256-Hash ein. Die EN-16931-Prüfung erfolgt im externen Canary. Kundenbereich und WHMCS-Mail liefern weiterhin die geprüfte ZUGFeRD-PDF. Beim sevDesk-Versand wird `sendXml=false` gesetzt.

Rule 19 bleibt eine normale Invoice. Rules 18/20, B2G, XRechnung und historische E-Rechnungs-Backfills sind ausgeschlossen.

## Was vor der finalen 2.1.0 noch fehlt

Folgende Gates sind noch offen:

- vollständiger Lauf unter PHP 8.3 einschließlich XMLReader;
- MariaDB-Integrationstests;
- WHMCS 8.13.4 mit Rollen, Hooks, Proforma, Theme-Adapter, Mail und Kundenbereich;
- Voucher-Canaries für die tatsächlich verwendeten Steuerfälle;
- Invoice-API-Canary mit Rule 19, Marker, Pflichtreferenzen, PDF, Versand, `bookAmount` und Prüfung auf Voucher-/Invoice-ID-Kollisionen;
- eigener ZUGFeRD-Canary mit Create, Readback, `getXml`, externer EN-16931-Prüfung, PDF, `sendBy`, `sendViaEmail(sendXml=false)`, geprüftem sevDesk-/ZUGFeRD-PDF-Anhang über WHMCS und Kundendownload;
- kleiner Live-Lauf mit normalen Invoices unter WHMCS-Hoheit, bevor der Altbestand eingereiht wird.

Der RC kann als GitHub-Pre-Release veröffentlicht werden, sobald die automatisierten Repository-Checks und der Archivscan grün sind. Das ist keine Freigabe für Produktivdaten. Die finale `2.1.0` folgt erst nach den genannten Gates.

## Bekannte Grenzen

- Keine Rules 18 oder 20.
- Keine Invoice-CreditNotes und keine automatische Invoice-Rückerstattung.
- Keine B2G- oder XRechnung.
- Keine Fremdwährungen im Dokumentexport.
- Keine automatische Aktualisierung vorhandener sevDesk-Kontakte.
- Keine individuellen Buchungskonten je Produkt.
- Keine dauerhafte PDF- oder XML-Spiegelung in WHMCS.
- Ein direkt erratener WHMCS-Core-PDF-Endpunkt lässt sich ohne Core-Änderung nicht vollständig abschalten. Der Adapter ersetzt die normalen sichtbaren Kundenlinks und die E-Mail-Auslieferung.
- Andere WHMCS-, PHP- oder angepasste Altmodulvarianten sind nicht pauschal freigegeben.

## Release-Archiv

Das installierbare Archiv heißt `sevdesk-2.1.0-rc.2.tar.gz`. Es enthält das Addon, Upgrade- und Betriebsanleitung sowie die Lizenz. Tests, `vendor/`, private Arbeitsdaten, PDFs, XML-Dateien und Zugangsdaten gehören nicht in das Archiv.
