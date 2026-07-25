# sevdesk 2.1.0-rc.7

`2.1.0-rc.7` ist eine Vorabversion für kontrollierte Test- und Nachlaufinstallationen. Sie trennt feste Rule-1-Rabatte nach dem tatsächlichen Steuerprofil, ohne bestehende Freigaben umzudeuten.

## Änderungen gegenüber rc.6

- Deutsche Rule-1-Rechnungen mit 19 Prozent und EU-B2C-Rechnungen unter der ausdrücklich bestätigten Inlandsbesteuerung haben jetzt eigene Rabatt-Gates.
- Das neue EU-B2C-Gate speichert einen exakten Schlüssel aus Steuerprofil, Länderklasse, Steuersatz und WHMCS-Netto-/Bruttomodus. Der deutsche Rule-1-Schlüssel kann diesen Fall nicht freigeben.
- Bereits gespeicherte deutsche, Rule-17- und Rule-19-Capabilities bleiben unverändert.
- Das Setup markiert einen Rabatt-Canary nur noch dann als aktiv, wenn der gespeicherte Schlüssel vollständig zum aktuellen Steuerkontext passt. Ein veralteter oder pauschaler Wert wird sichtbar gewarnt und nicht still neu bestätigt.
- Übergangsinventur, Health Check und Worker kennen das neue Gate. Die Inventur bindet außerdem den globalen WHMCS-Steuermodus ein; ändert er sich bei geöffnetem Setup, muss die Seite vor der Bestätigung neu geladen werden. Aktive oder ungeklärte Exportjobs verhindern weiterhin jede Änderung an einer Rabattfreigabe.
- Der eigentliche Belegvertrag bleibt gleich: genau eine negative `PromoHosting`-Position, `discountSave=null`, exakte Netto-, Steuer- und Bruttosummen sowie rein lesende Recovery nach einem möglichen Write.

## Bestehende Installationen

Das Upgrade ist additiv. Es verändert keine Mappings und ersetzt keinen vorhandenen Capability-Key. Das neue Setting ist zunächst leer. Ein Moduswechsel oder das Speichern der Einrichtung startet keinen Export.

Vorhandene Voucher und Invoices werden weder konvertiert noch erneut angelegt. Historische Exporte bleiben mailfrei.

## Weiterhin gesperrt

- mehrere `PromoHosting`-Rabatte in einer Rechnung;
- Rule-1-Rechnungen mit 0 Prozent außerhalb eines bestätigten Kleinunternehmerzeitraums;
- `LateFee`, andere negative Positionen und ZUGFeRD mit Rabatt;
- Rule-11-Invoices ohne passenden sevDesk-`REVENUE`-Scope;
- Rule 18 und 20, Invoice-CreditNotes und automatische Sammelzahlungsbuchungen.

## Installation

Vor dem Austausch Datenbank, Einstellungen und Modulordner sichern. Das aktive Addon nicht über WHMCS deaktivieren. Den Ordner `modules/addons/sevdesk` atomar ersetzen, PHP-FPM samt OPcache neu laden und anschließend Übergangsinventur und Health Check prüfen.

Die vollständige Reihenfolge steht in `modules/addons/sevdesk/UPGRADE.md` und `docs/operations.md`. Das Release-Archiv heißt `sevdesk-2.1.0-rc.7.tar.gz` und enthält weder Tests noch Zugangsdaten, Rechnungsdateien oder lokale Arbeitsunterlagen.

Diese Vorabversion ersetzt keine steuerliche Freigabe. Vor einem größeren Nachlauf muss jede tatsächlich verwendete Steuer- und Rabatt-Capability im verbundenen sevDesk-Mandanten geprüft sein.
