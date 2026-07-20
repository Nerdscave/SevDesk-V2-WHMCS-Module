# sevdesk 2.1.0-rc.4

`2.1.0-rc.4` ist eine Vorabversion für Testinstallationen. Sie ersetzt rc.3, nachdem der erste echte Postfachabgleich einen Fehler im WHMCS-Mailpfad gezeigt hat.

## Was wurde korrigiert?

Im eigenständigen CLI-Worker war `EmailPreSend` nicht registriert. WHMCS nahm den Versand trotzdem an und hängte die eigene Rechnungs-PDF an. Der Job markierte die Nachricht als an WHMCS übergeben, obwohl beim Empfänger nicht die sevDesk-PDF ankam.

rc.4 schließt diese Lücke:

- der CLI-Worker lädt die Modul-Hooks vor dem Runner;
- ohne geladenen Mail-Hook wird kein `SendEmail` ausgelöst;
- der vorbereitete Anhangskontext wird erst nach passender Invoice-, Vorlagen- und Tokenprüfung verbraucht;
- nach `SendEmail` prüft der Worker, ob der Hook den Kontext tatsächlich übernommen hat;
- fehlt dieser Nachweis, bleibt das Item am Write-Checkpoint `ambiguous`. Es wird nicht automatisch erneut versendet.

Der Fehler wurde mit einem bestehenden synthetischen ZUGFeRD-Beleg nachgestellt. Der korrigierte Wiederholungslauf hat die geprüfte sevDesk-PDF an den Hook übergeben und den Einmalkontext verbraucht. Für den Anhang aus diesem Versand fehlt noch der SHA-256-/XML-Abgleich. Bis dahin bleiben die Invoice- und E-Rechnungs-Canaries ausgeschaltet.

## Enthaltener Funktionsstand

Der übrige Umfang aus rc.3 bleibt unverändert:

- `voucher_only`, `invoice_for_oss` und `invoice_only` mit eingefrorenem Dokumentziel;
- Rule 19 für ausdrücklich bestätigte digitale EU-B2C-Leistungen;
- native ZUGFeRD-Invoices für den eng begrenzten deutschen B2B-Fall;
- additive Migration und Erhalt vorhandener Voucher-/Invoice-Zuordnungen;
- mailfreie Altbestands-Nachläufe mit Dublettenprüfung;
- authentifizierter Kunden-PDF-Download und Theme-Adapter;
- exakte Zahlungszuordnung über den Booking-Assistenten sowie der bestehende Voucher-Korrekturpfad.

## Weiterhin offene Freigaben

Vor einer finalen `2.1.0` fehlen noch:

- der Hash- und XML-Abgleich des neu eingegangenen WHMCS-Mailanhangs;
- Invoice-`bookAmount` mit einer eindeutig passenden, ungebuchten Testtransaktion;
- Voucher-Canaries für die in der Zielinstallation tatsächlich genutzten Steuerfälle;
- die steuerliche und betriebliche Freigabe für den jeweiligen Mandanten.

EU-B2B-Hosting ist in diesem RC nicht implementiert. Ein möglicher Rule-21-Pfad braucht eine eigene Architektur- und Steuerentscheidung, Implementierung, Tests und einen Canary. Die vorhandenen Rule-19- und Warenprofile schalten ihn nicht frei; der ZUGFeRD-Pfad betrifft deutsches B2B.

## Installation

Vor dem Dateitausch Datenbank, Modulverzeichnis und Einstellungen sichern. Das bisherige Addon nicht über WHMCS deaktivieren. Anschließend den Ordner `modules/addons/sevdesk` atomar ersetzen und die Bestandsprüfung im Setup durchlaufen. Bestehende Mappings werden nicht neu exportiert. Die genaue Reihenfolge steht in `UPGRADE.md` und `docs/operations.md`.

Das Release-Archiv heißt `sevdesk-2.1.0-rc.4.tar.gz`. Es enthält das Addon, die Upgrade- und Betriebsanleitung sowie die Lizenz. Tests, `vendor/`, Zugangsdaten, Testdokumente und lokale Arbeitsdateien gehören nicht in das Archiv.
