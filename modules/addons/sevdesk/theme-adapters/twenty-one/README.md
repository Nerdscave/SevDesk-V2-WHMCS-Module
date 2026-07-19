# Twenty-One-Referenzadapter

Dieser Adapter ersetzt ausschließlich die sichtbare Endrechnungs-Auslieferung im
WHMCS-Kundenbereich. WHMCS-Coredateien werden nicht verändert.

1. Kopiere `sevdesk-invoice-authority.tpl` in das Wurzelverzeichnis des aktiven
   Twenty-One-Themes.
2. Kopiere `manifest.json` dorthin und benenne die Kopie exakt in
   `sevdesk-invoice-authority.json` um.
3. Binde die Partial am Anfang von `viewinvoice.tpl` ein.
4. Prüfe die Zustände `proforma`, `pending`, `ready` und `failure` mit einem
   Kundenkonto. Im Zustand `ready` darf kein normaler WHMCS-PDF-Link sichtbar
   bleiben.
5. Bestätige die Installation erst danach auf der sevdesk-Einrichtungsseite.

Ein Custom-Theme-Adapter erhält in `sevdeskDocument` genau diese Felder:
`authority`, `state`, `invoiceNumber` und `downloadUrl`. Der direkt erratbare
WHMCS-PDF-Endpunkt kann ohne Coreänderung weiterhin existieren; zugesichert ist
die Ersetzung der normalen Oberfläche und E-Mail-Auslieferung.
