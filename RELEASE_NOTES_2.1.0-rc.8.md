# sevdesk 2.1.0-rc.8

`2.1.0-rc.8` ist eine Vorabversion für kontrollierte Test- und
Nachlaufinstallationen. Der Stand enthält vor allem Korrekturen aus dem
Vollreview von RC.7. Neue Steuerfälle werden dadurch nicht freigegeben.

## Änderungen seit RC.7

- Bei Invoices müssen `sumNet`, `sumTax` und `sumGross` jetzt immer centgenau
  mit WHMCS übereinstimmen. Das gilt für Erstellung, Öffnung und Recovery.
- Unvollständige oder strukturell fehlerhafte API-Listen gelten nicht mehr als
  eindeutiger Treffer. In diesem Fall stoppt das Modul vor einem Mapping,
  einer Buchung oder einem weiteren Schreibzugriff.
- Ein allgemeiner HTTP-400-Fehler beweist nicht, dass ein Beleg fehlt. Eine
  bestehende Zuordnung darf nur nach einem eindeutigen Not-found-Ergebnis
  entfernt werden.
- Antwortgrößen werden schon beim Einlesen begrenzt. Weiterleitungen sind für
  sevDesk-Aufrufe ausgeschaltet; ein unerwarteter 3xx-Status nach einem
  Schreibzugriff wird wie ein unklarer Ausgang behandelt.
- Abgebrochene Jobs behalten nach einem möglichen Remote-Effekt ihre
  Reservierung. Bereits bestätigte Checkpoints können dagegen sicher
  fortgesetzt werden.
- Rule 1 mit 0 Prozent wird außerhalb eines bestätigten
  Kleinunternehmerzeitraums unabhängig vom Rechnungsprofil blockiert. Das
  schließt auch Guthaben- und AddFunds-Rechnungen ein.
- USt-IDs, Receipt-Guidance, Währung und `import_after` werden strenger
  geprüft. Unklare Werte führen zu einem sichtbaren Prüffall statt zu einem
  stillen Export.
- Korrekturbelege verwenden einen exakten Centvergleich. Lange Texte mit
  Umlauten werden ohne beschädigte UTF-8-Zeichen gekürzt.
- Weitere WHMCS-Rechnungsstatus werden in Vorschau und Nachlauf sichtbar
  ausgewiesen, auch wenn sie nicht automatisch exportiert werden dürfen.
- Der Kunden-PDF-Proxy begrenzt Abrufe pro Kunde. Dafür legt die Migration
  additiv die Tabelle `mod_sevdesk_pdf_rate_limits` an.
- Der Worker-Lock ist an die jeweilige WHMCS-Datenbank gebunden. Mehrere
  Installationen auf demselben MySQL-Server blockieren sich dadurch nicht
  mehr gegenseitig.

## Verhalten bei bestehenden Installationen

Das Upgrade verändert keine vorhandenen Zuordnungen. Voucher bleiben Voucher,
Invoices bleiben Invoices, und es findet weder ein automatischer Neu-Export
noch ein Mailversand statt. Die neue Rate-Limit-Tabelle wird beim
Modul-Upgrade additiv angelegt.

Vor RC.8 bereits erzeugte Invoice-Mappings sollten vor dem
Buchhaltungsabschluss lesend auf die exakten Netto-, Steuer- und Bruttosummen
geprüft werden. Das ist eine Bestandskontrolle, kein Anlass für einen
automatischen Neu-Export.

## Geprüfter Stand

Der Release-Kandidat wurde unter PHP 8.3 vollständig geprüft:

- 855 Unit- und Verhaltentests mit 4.560 Assertions;
- 115 MariaDB-Integrationstests mit 1.671 Assertions;
- PHP-Syntaxprüfung, PHPStan Level 6 und PHPCS ohne Fehler.

Das Release-Archiv wird zusätzlich auf unerwartete Dateien, lokale Pfade und
Zugangsdaten kontrolliert.

## Noch offene Freigaben

RC.8 ist nicht mit der finalen `2.1.0` gleichzusetzen. Vor der allgemeinen
Produktivfreigabe fehlen weiterhin:

- der Invoice-`bookAmount`-Canary;
- ein neuer normaler Invoice-Canary nach den letzten
  Adress- und Summenänderungen;
- die Rückmeldung von sevDesk und der Canary für historische
  Rule-11-Invoices;
- die noch nicht bestätigten Rabatt- und Voucher-Steuerfälle;
- die fachliche beziehungsweise steuerliche Abnahme der tatsächlich
  verwendeten Fälle.

Der WHMCS-Versandweg `whmcs_template` bleibt auf WHMCS 8.13 gesperrt, weil der
Core den vom Hook bereitgestellten Binäranhang nicht zuverlässig übernimmt.
Bei sevDesk-Dokumenthoheit steht der direkte sevDesk-Versand zur Verfügung.

## Installation

Vor dem Austausch sollte ein Backup der WHMCS-Datenbank und des bisherigen
Modulordners erstellt werden. Das bestehende Addon wird nicht deaktiviert,
sondern atomar durch den Inhalt des neuen `sevdesk`-Ordners ersetzt. Danach
OPcache neu laden, das Modul-Upgrade ausführen und die Quarantäneprüfung im
Setup vollständig bestätigen.

Das Release-Archiv heißt `sevdesk-2.1.0-rc.8.tar.gz`. Es enthält den
deploybaren Modulordner sowie Lizenz, Upgrade-Anleitung und Betriebsdokument,
aber keine Tests, Zugangsdaten, Rechnungen oder lokalen Arbeitsunterlagen.
