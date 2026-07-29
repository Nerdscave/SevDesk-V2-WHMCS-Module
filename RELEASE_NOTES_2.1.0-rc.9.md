# 2.1.0-rc.9

rc.9 erweitert den Invoice-Modus um einen sauberen Lebenszyklus für offene
Rechnungen, Mahnungen und Stornorechnungen. Außerdem können positive,
unbesteuerte WHMCS-Mahngebühren getrennt von der eigentlichen Leistung
verbucht werden.

Die neuen Wege sind bewusst noch nicht automatisch aktiv. Bestehende
Installationen behalten `after_payment_proforma`; Mahnverfahren und
Late-Fee-Verarbeitung bleiben aus, bis die jeweiligen Canaries im verbundenen
WHMCS- und sevDesk-System bestanden sind.

## Neu in dieser Version

- Zwei Invoice-Lebenszyklen:
  - `after_payment_proforma` erstellt die sevDesk-Endrechnung wie bisher nach
    vollständiger Zahlung;
  - `issue_on_creation` erstellt eine offene sevDesk-Invoice direkt nach dem
    WHMCS-Rechnungsereignis.
- Der Direktbetrieb verwendet WHMCS weiterhin für Forderung, Fälligkeit,
  Mahnstufe und Zahlung. Die kundenseitige Rechnung, Mahnung und
  Stornorechnung kommen aus sevDesk.
- WHMCS-Core-Mails werden im Direktbetrieb gezielt unterdrückt. Es gibt keinen
  stillen Rückfall auf eine WHMCS-PDF.
- Neue idempotente Jobaktionen für Mahnung, Invoice-Storno und
  Late-Fee-Voucher.
- Neue Zusatzdokument-Zuordnung für `reminder`, `cancellation` und
  `late_fee_voucher`, ohne Adressdaten, Mailadressen oder Dokumentdateien in
  WHMCS zu kopieren.
- Der Kundenbereich kann eine offene Direktbetriebs-Invoice sowie zugehörige
  Mahn- und Stornodokumente anzeigen. Eigentümer- und Hashprüfung bleiben
  Pflicht.

## Late Fees

Eine positive, unbesteuerte WHMCS-Position vom Typ `LateFee` wird nicht mehr
als Hosting- oder Leistungsposition behandelt. Nach ausdrücklicher Freigabe
entfernt das Modul sie aus der Primär-Invoice und friert ihren Vertrag separat
ein.

Für die Buchhaltung ist genau ein Weg zulässig:

1. Die sevDesk-Mahnung erfasst die Gebühr bereits nachweislich als Erlös.
2. Nach Zahlung entsteht ein eigener, mailfreier Rule-22-Revenue-Voucher.

Der zweite Weg verlangt ein aktuell durch `ReceiptGuidance` bestätigtes
`REVENUE`-Konto für Rule 22 und 0 %. Eine Gebühr kann nicht zugleich in der
Mahnung und im Voucher gebucht werden. Negative, besteuerte oder nachträglich
veränderte Gebühren bleiben blockiert.

Der Rule-22-Beleg ist ein interner Buchhaltungsbeleg. Er wird nicht an Kunden
versandt und erhält im Kundenbereich keinen künstlichen PDF-Link. Eine Zahlung
für Grundrechnung und Gebührenbeleg muss in sevDesk manuell aufgeteilt werden.

## Storno und Recovery

Eine unbezahlte, unveränderte Direktbetriebs-Invoice kann hinter einem eigenen
Canary über sevDesk storniert werden. Die erzeugte `SR` wird vollständig
zurückgelesen. Bei ZUGFeRD prüft das Modul zusätzlich XML und XML-Hash.

Die Stornorechnung wird nur versandt, wenn auch die ursprüngliche Invoice
nachweislich über sevDesk zugestellt wurde. Unversandte Entwürfe werden nicht
automatisch gelöscht. Teilzahlungen, Änderungen, Refunds, Collections,
Chargebacks und Credit-Note-Fälle bleiben manuelle Prüffälle.

Jeder neue Remote-Write besitzt einen eigenen Vorher-/Nachher-Checkpoint. Nach
einem Timeout oder unklaren Versand sucht der Worker ausschließlich lesend nach
dem bereits möglichen Ergebnis. Ein zweiter Create, Cancel oder Mailversand
wird nicht automatisch ausgelöst.

## Upgrade

Die Migration ist additiv:

- `mod_sevdesk` erhält `invoice_lifecycle_mode`;
- `mod_sevdesk_related_documents` wird neu angelegt;
- vorhandene Becker- und Rewrite-Mappings bleiben unverändert;
- ein Upgrade startet weder einen Export noch eine Mail;
- bestehende Mappings wechseln ihren Lebenszyklus nicht rückwirkend.

Der mitgelieferte Twenty-One-Adapter verwendet Vertrag v2. Ein Custom Theme
muss diesen Vertrag übernehmen, bevor der Direktbetrieb freigegeben werden
kann.

## Sichere Standardwerte

Nach Installation oder Upgrade gelten:

```text
invoice_lifecycle_mode=after_payment_proforma
dunning_mode=off
late_fee_mode=blocked
direct_invoice_canary_confirmed=off
dunning_canary_confirmed=off
cancellation_canary_confirmed=off
e_invoice_cancellation_canary_confirmed=off
late_fee_rule22_canary_confirmed=off
late_fee_reminder_accounting_canary_confirmed=off
```

Auch die bisherigen Review-, Invoice-, OSS-, Rule-11-, Rabatt- und
ZUGFeRD-Gates bleiben unverändert bestehen.

## Vor einer Freigabe

Für den mailfreien Late-Fee-Nachlauf ist Live-Gate 1 erforderlich: eine
synthetische bezahlte Rechnung mit Gebühr, geprüfte verkürzte Primär-Invoice,
geprüfte sevDesk-Mahnung, genau eine Buchhaltungsquelle und Recovery ohne
Doppelbeleg oder Kundenmail.

Der Direktbetrieb braucht Live-Gate 2: offene Invoice, genau eine sevDesk-Mail,
Kundendownload, Erinnerung, Mahnung, Teilzahlungs- und Payment-Race sowie
Storno mit und ohne vorherigen Originalversand. Rule 19 und ZUGFeRD werden
getrennt geprüft.

Eine finale 2.1.0 folgt erst nach dem vollständigen PHP-8.3-,
MariaDB-Integrations- und WHMCS-8.13.4-Hooklauf sowie beiden Live-Gates.
