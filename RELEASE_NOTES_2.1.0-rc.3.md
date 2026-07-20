# sevdesk 2.1.0-rc.3

`2.1.0-rc.3` ist eine Vorabversion für Testinstallationen. Sie enthält den vollständigen Stand aus rc.2 und zwei Korrekturen, die erst beim Test gegen WHMCS 8.13.4 und die echte sevDesk-API sichtbar wurden.

## Änderungen seit rc.2

- Rule 19 löst das Zielland vor dem ersten Write eindeutig über `StaticCountry` auf. Das Payload sendet den OSS-Ländercode so, wie sevDesk ihn tatsächlich erwartet, und ergänzt die benötigte Länderreferenz.
- Der Invoice-Readback bindet `addressCountry` ausdrücklich ein. Lässt sevDesk `deliveryAddressCountry` weg, kann die Rechnungsadresse den OSS-Zielstaat belegen.
- Ist `deliveryAddressCountry` vorhanden, bleibt es für OSS maßgeblich. Eine abweichende Rechnungsadresse ist zulässig und führt nicht mehr unnötig in einen unklaren Recovery-Zustand.
- Fehler bei der Länderauflösung enden vor `invoice_write_requested`. Ein fehlender, unbeschrifteter oder mehrdeutiger Treffer kann deshalb keinen halbfertigen Remote-Beleg erzeugen.
- Der authentifizierte PDF-Download prüft neben Rechnungseigentümer und Mapping jetzt auch das WHMCS-Benutzerrecht `invoices` für den aktiven Kunden. Delegierte Benutzer ohne dieses Recht erhalten keinen Dokumentzugriff.

## Stand der technischen Prüfung

Unter PHP 8.3 sind Syntaxprüfung, PSR-12, PHPStan, 568 Unit-/Contracttests und 92 MariaDB-Integrationstests erfolgreich gelaufen.

Mit synthetischen Daten wurden außerdem folgende Abläufe in der Zielumgebung geprüft:

- normale Invoice und Rule-19-Invoice mit unveränderter WHMCS-Nummer, Marker, Pflichtreferenzen, Positionen und PDF;
- Recovery einer bereits angelegten Rule-19-Invoice ohne zweiten Create;
- ZUGFeRD-Create, `getXml`, `getPdf`, `sendBy` und externe EN-16931-Prüfung;
- sevDesk-Versand mit `sendXml=false` und Übergabe des geprüften PDF-Inhalts an den WHMCS-Mailpfad;
- Kundendownload als Rechnungseigentümer, abgewiesener Fremdzugriff und abgewiesener delegierter Benutzer ohne Rechnungsrecht;
- der installierte Custom-Theme-Adapter ohne sichtbaren WHMCS-Core-PDF-Link.

Die Tests verwendeten ausschließlich eigens angelegte Testkunden und kleine Testbeträge. Der produktive Modulmodus wurde danach auf `voucher_only + whmcs` zurückgesetzt; Sync, OSS- und E-Invoice-Canary bleiben aus.

## Upgrade und bestehender Bestand

Der Addon-Name, der Ordner `sevdesk`, vorhandene Einstellungen und `mod_sevdesk` bleiben erhalten. Die Migration ist additiv. Bestehende Voucher werden nicht in Invoices umgewandelt, vorhandene Invoices werden nicht neu erzeugt und ein Moduswechsel startet keinen Altbestandsimport.

Eine gespeicherte sevDesk-Kontakt-ID bleibt maßgeblich. Das Modul aktualisiert oder ersetzt diesen Kontakt nicht. Fehlt die Remote-ID, wird der Export blockiert. Bei leerem Feld ist nur die exakt geprüfte WHMCS-Kundennummer zulässig; Kontakt-Neuanlagen brauchen weiterhin die ausdrückliche Bestätigung im Setup.

Beim ersten Wechsel aus einem nicht nachweisbaren Altbestand bleibt die Laufzeit in Quarantäne. Erst Bestandsprüfung, Health Check und Betreiberbestätigung geben Remote-Aktionen wieder frei. Die vollständige Reihenfolge steht in `modules/addons/sevdesk/UPGRADE.md` und `docs/operations.md`.

## Noch offene Freigaben

Diese Vorabversion ist noch keine Freigabe für echte Buchhaltungsdaten. Vor der finalen `2.1.0` fehlen:

- die Bestätigung, dass die technisch übergebenen Testmails im vorgesehenen Postfach mit genau den erwarteten Anhängen angekommen sind;
- der Invoice-`bookAmount`-Canary mit einer eindeutig passenden, ungebuchten Testtransaktion;
- Voucher-Canaries für die Steuerfälle, die der jeweilige Betreiber tatsächlich verwendet;
- die fachliche Prüfung von Steuerprofilen, OSS-Einsatz und E-Rechnungsprozess durch Betreiber beziehungsweise Steuerberatung.

Rule 18/20, B2G/XRechnung, Invoice-CreditNotes, automatische Invoice-Rückerstattungen, Fremdwährungen, dauerhafte PDF-/XML-Spiegelung und allgemeine Kontaktsynchronisation bleiben außerhalb dieses Releases.

## Release-Archiv

Das installierbare Archiv heißt `sevdesk-2.1.0-rc.3.tar.gz`. Es enthält das Addon, Upgrade- und Betriebsanleitung sowie die Lizenz. Tests, `vendor/`, Zugangsdaten, Testdokumente und lokale Arbeitsdateien sind nicht enthalten.
