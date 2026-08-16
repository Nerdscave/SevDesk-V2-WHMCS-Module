# sevdesk 2.1.0-rc.11

`2.1.0-rc.11` ist eine Vorabversion für kontrollierte Test- und
Nachlaufinstallationen. Sie baut auf rc.10 auf und erweitert ausschließlich den
manuell bestätigten, mailfreien 0-%-Altbestandspfad.

## Neu in rc.11

Historische Drittlandrechnungen mit genau einem eindeutig zugeordneten
`PromoHosting`-Rabatt können nun als Rule-17-Invoice exportiert werden. Der
Pfad steht nur in `invoice_only` zur Verfügung und verlangt sowohl den normalen
Invoice-Canary als auch den exakten Rule-17-Rabatt-Canary für den verwendeten
WHMCS-Steuermodus.

Der Worker prüft Bezahlstatus, Zeitraum, Land, Währung, Steuersatz,
Rechnungsbetrag und Rabattstruktur vor dem ersten Write erneut. Zieltyp,
Capability-Key und Rabattfingerprint werden im Job eingefroren. Der Export
verschickt keine Kundenmail und erzeugt keine E-Rechnung.

Normale positive Fälle bleiben auf dem Rule-17-Voucherpfad aus rc.10. Deutsche
und EU-Rabattfälle, Guthaben, Sammelzahlungen, AddFunds, Late Fees, weitere
Rabatte und Fremdwährungen bleiben gesperrt.

## Wichtige Grenze

sevDesk-Invoice-Positionen übernehmen kein frei wählbares `accountDatev`.
Dieser historische Invoice-Zweig ist daher genauso vorläufig wie der
Voucher-Notweg. Die erzeugten Belege müssen vor einer sevDesk-Auswertung oder
Abgabe fachlich geprüft und manuell richtig zugeordnet werden.

Bestehende Mappings und Belege werden nicht verändert. Das Upgrade startet
keinen Export und verschickt keine Mail.
