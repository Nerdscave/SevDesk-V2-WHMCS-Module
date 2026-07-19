# sevdesk 2.1.0-rc.1

Dies ist eine Vorabversion für Testinstallationen. Sie enthält die neuen Invoice-Modi und den abgesicherten Austausch des bisherigen WHMCS-sevDesk-Moduls. Für Produktivdaten ist dieser Stand noch nicht freigegeben.

## Neu in diesem RC

- Drei Exportmodi: `voucher_only`, `invoice_for_oss` und `invoice_only`.
- Rule 19 für bestätigte digitale EU-B2C-Leistungen im Hybrid- und Invoice-Modus.
- Wahl zwischen WHMCS- und sevDesk-Dokumenthoheit. sevDesk-Hoheit ist nur mit `invoice_only`, WHMCS-Proforma, installiertem Theme-Adapter und aktivierter automatischer Einreihung möglich.
- Vollständiger Invoice-Ablauf mit Draft-Erstellung, Rückprüfung, Öffnung, PDF-Prüfung und optionalem Versand über sevDesk oder eine vorhandene WHMCS-Mailvorlage.
- Typisierte Voucher-/Invoice-Mappings sowie dokumentabhängige Zahlungsbuchung.
- Authentifizierter Download der sevDesk-Invoice im Kundenbereich. Eine dauerhafte PDF-Kopie in WHMCS wird nicht angelegt.
- Lesende Recovery nach unklarem Create-, Open-, Versand- oder Buchungsausgang. Ein möglicherweise ausgeführter Write wird nicht blind wiederholt.
- Gebündelter Adapter für das WHMCS-Theme Twenty-One und ein kleiner Vertrag für eigene Themes.

## Austausch einer bestehenden Installation

Der Addon-Name und der Ordner `sevdesk` bleiben gleich. Vorhandene `mod_sevdesk`-Zuordnungen und unbekannte Einstellungen in `tbladdonmodules` werden nicht gelöscht. Die Migration ergänzt das Schema nur additiv und führt dabei keine sevDesk-Schreibzugriffe aus.

Der Wechsel ist absichtlich nicht vollständig automatisch. Beim ersten Start setzt das neue Modul eine Review-Sperre und schaltet die automatische Synchronisation aus. Danach müssen Bestand, Kontaktfeld, API-Zugang, Konten, Steuerprofile und offene Jobs auf der Setupseite geprüft werden. Erst die bestätigte Prüfung hebt die Sperre auf.

Das alte Addon vor dem Dateitausch nicht über WHMCS deaktivieren. Datenbank, Modulordner und Einstellungen sichern, den Ordner atomar ersetzen und anschließend die Setupseite des neuen Moduls öffnen. Die vollständige Reihenfolge steht in `modules/addons/sevdesk/UPGRADE.md` und `modules/addons/sevdesk/OPERATIONS.md` im Release-Archiv.

### Bestehende sevDesk-Kontakte

- Enthält das konfigurierte WHMCS-Kundenfeld eine sevDesk-ID und existiert diese ID, wird genau dieser Kontakt wiederverwendet. Das Modul ändert weder die ID noch die vorhandenen Stammdaten.
- Existiert die gespeicherte ID in sevDesk nicht, wird der Export blockiert. Es gibt keinen automatischen Ersatzkontakt.
- Nur bei leerem Feld sucht das Modul nach der exakten WHMCS-Client-ID als `customerNumber`.
- Ein neuer Kontakt wird erst nach einer eigenen Betreiberbestätigung angelegt. Ohne diese Bestätigung bleibt ein leeres Suchergebnis blockiert.

Damit werden bekannte IDs erhalten und unbemerkte Kontaktduplikate vermieden. Eine laufende Aktualisierung bestehender Kontaktstammdaten ist nicht Bestandteil dieses Releases.

## Sichere Grundeinstellung

Ein Upgrade startet mit:

```text
document_authority = whmcs
export_mode        = voucher_only
oss_profile        = blocked
sync_enabled       = off
```

Bestehende Mappings werden nicht neu exportiert. Invoice-Ziele sind paid-only und benötigen eine finale WHMCS-Rechnungsnummer. Rules 18 und 20 sowie gemischte oder unklare Leistungsarten bleiben blockiert.

## Noch offene Freigaben

Vor einem Produktiveinsatz müssen außerhalb dieses Repositorys bestanden sein:

- MariaDB-Integrationstest;
- Lauf unter PHP 8.3 und WHMCS 8.13.4 einschließlich Rollen, Hooks und Kundenbereich;
- Voucher-Canaries für die verwendeten Steuerfälle;
- Invoice-API-Canary in einem sevDesk-Testmandanten, insbesondere für Rule 19, Marker, PDF, Versand, Buchung und die Eindeutigkeit von Voucher-/Invoice-IDs.

Das vollständige Canary-Protokoll bleibt außerhalb von Git. Im Repository darf nur ein pseudonymisiertes Ergebnis des Release-Gates dokumentiert werden. `invoice_canary_confirmed` darf erst nach einem bestandenen Canary aktiviert werden.

## Bekannte Grenzen

- Keine Rules 18 oder 20.
- Keine Invoice-CreditNotes; Korrekturen bleiben für Invoice-Mappings blockiert.
- Keine E-Rechnungen und keine dauerhafte PDF-Spiegelung.
- Dokumentexport derzeit nur in EUR.
- Keine automatische Aktualisierung vorhandener sevDesk-Kontakte.
- Keine automatische Produktklassifikation oder individuellen Buchungskonten je Produkt.
- Der Twenty-One-Adapter ersetzt die normalen sichtbaren Rechnungslinks. Ein direkt erratener WHMCS-Core-PDF-Endpunkt kann ohne Core-Änderung technisch weiter erreichbar sein.
- Andere WHMCS-, PHP- oder angepasste Altmodul-Varianten sind nicht pauschal freigegeben.

## Release-Datei

Das installierbare Archiv heißt `sevdesk-2.1.0-rc.1.tar.gz`. Es enthält ausschließlich das Addon, die Upgrade- und Betriebsanleitung sowie die Lizenz. Tests, Entwicklungsabhängigkeiten und lokale Arbeitsdaten sind nicht enthalten.
