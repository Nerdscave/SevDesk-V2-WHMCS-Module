# sevdesk 2.1.0-rc.10

`2.1.0-rc.10` ist eine Vorabversion für kontrollierte Test- und
Nachlaufinstallationen. Sie übernimmt den Stand aus rc.9 und ergänzt einen
bewusst engen Notweg für historische 0-%-Rechnungen, die ein inzwischen
umgestellter sevDesk-Mandant nicht mehr mit Rule 11 fertigstellen kann.

## Neu in rc.10

Im historischen Sammelexport kann der Betreiber einen vorläufigen,
mailfreien Rule-17-Voucherpfad einschalten und dafür ein aktuell von sevDesk
bestätigtes 0-%-Erlöskonto wählen. Das Modul zeigt Zieltyp, Rule, Konto und die
Pflicht zur späteren manuellen Umbuchung bereits im Dry-Run an.

Der Sonderweg akzeptiert nur bezahlte EUR-Rechnungen aus dem fest eingestellten
Kleinunternehmerzeitraum. Alle Positionen müssen positiv sein und 0 % Steuer
tragen; Steuer- und Gesamtsumme werden centgenau geprüft. Guthaben,
Sammelzahlungen, Rabatte, AddFunds, Late Fees und Fremdwährungen bleiben
gesperrt. Der Worker prüft das Konto erneut gegen die aktuelle Receipt Guidance
und friert Rule und Konto vor dem ersten Write ein.

Der Pfad versendet keine Kundenmail, erzeugt keine E-Rechnung und verändert
keine vorhandenen Mappings. Seine Kontierung bildet die
Kleinunternehmerregelung nicht fachlich korrekt ab. Die erzeugten Voucher
müssen deshalb vor jeder Auswertung oder Abgabe in sevDesk manuell richtig
zugeordnet werden.

## Unverändert offen

Direktversand, Mahnung, Storno und der getrennte Late-Fee-Pfad bleiben hinter
ihren Canaries. Eine finale `2.1.0` setzt weiterhin die vollständigen
PHP-8.3-, MariaDB-, WHMCS- und sevDesk-Live-Gates sowie die fachliche Abnahme
der tatsächlich verwendeten Steuerfälle voraus.
