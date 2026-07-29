# Sicherer Ersatz eines bestehenden WHMCS-sevDesk-Moduls

Diese Anleitung gehört zu `2.1.0-rc.9`. Der RC ist eine Vorabversion für kontrollierte Test- und Nachlaufinstallationen. Die technischen Invoice- und ZUGFeRD-Läufe unter WHMCS 8.13.4 sind weitgehend abgeschlossen. Zwei Postfachtests haben bestätigt, dass WHMCS 8.13 Binäranhänge aus `EmailPreSend` nicht übernimmt. Der Versandweg `whmcs_template` ist deshalb gesperrt; bei sevDesk-Dokumenthoheit steht der direkte sevDesk-Versand zur Verfügung. Für die vollständige Freigabe fehlen außerdem Invoice-`bookAmount`, der rabattfreie Rule-11-Invoice-Canary, die fünf getrennten Rabatt-Canaries, die produktiven Voucher-Steuerfälle und die fachliche Abnahme.

RC.9 ergänzt `mod_sevdesk.invoice_lifecycle_mode` und die Tabelle
`mod_sevdesk_related_documents`. Dort stehen ausschließlich technische
Verweise auf Mahnungen, Stornorechnungen und Late-Fee-Voucher. Die Migration
legt beides additiv an, erhält bestehende Mappings und startet weder Export noch
Mailversand. `after_payment_proforma`, `dunning_mode=off` und
`late_fee_mode=blocked` bleiben die sicheren Upgrade-Werte.

Diese Version verwendet weiterhin den Addon-Namen und Ordner `sevdesk` sowie
die Mappingtabelle `mod_sevdesk`. Der Austausch erhält vorhandene Zuordnungen
und Einstellungen additiv. Er ist keine Zusage, dass jede Version oder jede
Zusatzfunktion eines früheren Anbieters funktionsgleich nachgebaut ist.

Die vorgesehene Zielplattform ist ausschließlich WHMCS 8.13.4 mit PHP 8.3
und ein sevDesk-Mandant mit **Update 2.0**. Ein noch auf sevDesk V1 arbeitender
Altbetrieb ist kein funktionsfähiger Drop-in; `sync_enabled` bleibt bis zur
separaten Mandantenmigration aus. Das vollständige Betriebs- und
Recovery-Runbook liegt als `OPERATIONS.md` neben dieser Datei.

Native ZUGFeRD-Invoices brauchen zusätzlich PHP XMLReader (`ext-xml`) in Web und Cron. Ohne diese Erweiterung bleibt das E-Rechnungsprofil gesperrt.

Auch beim Upgrade des eigenen 2.0.0-Rewrites werden `sync_enabled` und die
Laufzeitsignatur einmalig ausgeschaltet und `runtime_review_required=on`
gesetzt: Alte Tabellen-/Settingreste sind kein sicherer Herkunftsnachweis. Der
`voucher_only`-Modus und alle Mappings bleiben unverändert, aber Hooks, Runner
und Remote-fähige Adminaktionen bleiben bis zur bestätigten Setup-Prüfung gesperrt.

## Vor dem Dateitausch

1. WHMCS in den Wartungsmodus setzen, externe Cron-/Worker-Prozesse stoppen und
   laufende Web- sowie CLI-Requests des Altmoduls vollständig auslaufen lassen.
2. Das bisherige Addon **nicht deaktivieren**. Sein unbekannter
   Deaktivierungs-Callback könnte Daten löschen oder verändern.
3. WHMCS-Datenbank und den bisherigen Modulordner vollständig sichern.
4. Anzahl und Fingerprint der `mod_sevdesk`-Zeilen sowie die verwendeten
   `tbladdonmodules`-Settings dokumentieren.
5. Prüfen, ob das alte Installationspaket weitere Hook-, Include- oder
   Cron-Dateien außerhalb `modules/addons/sevdesk` installiert hat. Ein alter
   Schreibpfad darf nach dem Wechsel nicht parallel aktiv bleiben.
6. Die tatsächlich verwendeten Funktionen mit dem Rewrite abgleichen.
   Laufende Kontaktaktualisierung, automatische Zahlungsbuchung ohne
   Bestätigung, Produktkonten und Fremdwährungsbelege sind nicht funktionsgleich
   enthalten.

## Austausch

1. Den Ordner `modules/addons/sevdesk` atomar durch diese Version ersetzen.
2. PHP-FPM beziehungsweise Apache samt OPcache kontrolliert neu laden. Bei
   `opcache.validate_timestamps=0` ist dies zwingend; kein alter Prozess darf
   nach dem Tausch noch Code des alten Moduls ausführen.
3. Die neue Addon-Adminseite öffnen. Vor der additiven Migration wird der
   Bestand quarantänisiert; dabei erfolgen keine sevDesk-Requests, keine
   Kontaktänderungen und keine Exportjobs. Migration und Worker teilen sich den
   Runner-Lock. Die Rewrite-Laufzeitsignatur wird erst nach vollständiger
   lokaler Strukturprüfung gesetzt, `runtime_review_required=on` bleibt aber bis
   zur Betreiberfreigabe bestehen.
4. Die WHMCS-Addon-Einstellungen einmal unverändert speichern, damit WHMCS die
   neue `hooks.php` eines bereits aktiven Addons sicher registriert. Dies zuerst
   in einer Testkopie der konkreten WHMCS-Version durchführen und die zuvor
   erfassten unbekannten `tbladdonmodules`-Zeilen unmittelbar danach vergleichen;
   bei Abweichung nicht produktiv fortfahren.
5. Prüfen, dass `module_active=on`, `sync_enabled=off`,
   `runtime_review_required=on` sowie
   `voucher_only + whmcs + OSS blocked + E-Rechnung aus` gelten. Alle
   Rabatt-Gates müssen ebenfalls aus sein:
   `invoice_discount_canary_confirmed`,
   `invoice_discount_rule1_19_canary_confirmed`,
   `invoice_discount_rule1_19_eu_b2c_domestic_canary_confirmed`,
   `invoice_discount_rule17_0_canary_confirmed` und
   `invoice_discount_rule19_canary_confirmed`. Unbekannte
   Altsettings bleiben gespeichert, werden aber nicht als kompatible Aliase geraten.
6. Migration, Health, Mappingbestand, offenen Jobbestand, Mandant/Token, Konten,
   Steuerprofile, Kontaktfeld-ID und vorhandene Kontakt-IDs prüfen. Die
   Übergangsinventur zeigt typisierte Voucher/Invoices, untypisierte und leere
   Mappings, Orphans, relevante Jobzustände, bezahlte ungemappte Rechnungen und
   lokale Dublettenhinweise. Pausieren
   und Abbrechen sind in Quarantäne möglich; abgelaufene Leases klassifiziert
   das Setup unter dem Runner-Lock rein lokal. Sichere und bereits verifizierte
   Fortsetzungen werden ohne Abbruch `retry_wait`, sichere abgebrochene Leases
   `cancelled`; unbekannte Write-Ausgänge oder Abbrüche nach bestätigtem
   Remote-Effekt bleiben `ambiguous`. Ein noch laufendes Item muss erst enden
   oder seine Lease muss ablaufen; Pause/Abbruch lösen es nicht unmittelbar auf.
7. Im Setup die Bestandsprüfung ausdrücklich bestätigen und speichern. Die
   Bestätigung ist an den aktuellen Inventur-Fingerprint gebunden. Ändert sich
   der Bestand oder der globale WHMCS-Netto-/Bruttomodus, muss die Seite neu
   geladen werden. Nur nach
   erfolgreicher read-only Mandantenprüfung wird `runtime_review_required`
   gelöscht. Erst danach einen kleinen Voucher-Canary ausführen und optional
   `sync_enabled` aktivieren. Invoice-Modi benötigen zusätzlich den
   dokumentierten echten sevDesk-Testmandanten-Canary. Rule-11-Invoices benötigen
   darüber hinaus einen eigenen Canary und einen aktuellen passenden
   `REVENUE`-Scope aus `ReceiptGuidance`. Ein fester `PromoHosting`-Rabatt wird
   nur in `invoice_only`, in EUR und ohne E-Rechnung freigegeben. Rule 11/0 %,
   Rule 1/19 %, Rule 17/0 % und Rule 19 mit positivem, einheitlichem
   Zielsteuersatz haben getrennte Canaries. Ihre Settings speichern den exakten
   Schlüssel des beim Setup-Speichern aktiven WHMCS-Netto-/Bruttomodus; Rule 19
   verlangt außerdem den getesteten Zielsteuersatz. Der jeweilige Live-Lauf muss
   Capability-Key, Rabatt-Fingerprint, die negative `InvoicePos`,
   `discountSave=null`, `sumNet`, `sumTax`, `sumGross`, `sumDiscounts=0`, PDF
   und read-only Recovery bestätigen. Der globale sevDesk-Rabatt ist kein
   Fallback, weil er bei steuerpflichtigen Bruttorechnungen die
   WHMCS-Centverteilung verändern kann. `LateFee` bleibt unabhängig davon
   blockiert.

## Bestehende Zuordnungen

- Bestehende Remote-IDs werden nicht geändert oder neu exportiert.
- Voucher bleiben Voucher. Bereits erzeugte Invoices behalten ihre dauerhaft gespeicherte Dokumenthoheit. Alte Zeilen bleiben zunächst ohne Hoheitsannahme; bei der Legacy-Bestätigung ist WHMCS vorausgewählt, solange kein früherer RC bereits eine andere Entscheidung im Job eingefroren hat. Diese Entscheidung darf nicht überschrieben werden. sevDesk-Hoheit setzt außerdem eine bezahlte WHMCS-Rechnung und einen finalisierten Remote-Status 200, 750 oder 1000 voraus. Ein späterer Modus- oder Hoheitswechsel gilt nur für danach entschiedene, noch ungemappte Rechnungen.
- `NULL`, leere oder nur aus Leerzeichen bestehende Remote-IDs sind
  unvollständige Recovery-Fälle.
- Vollständige Altzuordnungen bleiben zunächst ohne Dokumenttyp. Voucher und
  Invoice werden separat read-only geprüft; erst eine Adminbestätigung ergänzt
  den Typ.
- Eine Sammelvorschau kann höchstens 25 sichtbare Legacy-Mappings prüfen. Gemeinsam übernehmen lassen sich nur eindeutige Treffer mit Rewrite-Marker. Markerlose Originalbelege und Kollisionen bleiben Einzelfälle.
- Unbekannte oder kollidierende Ergebnisse bleiben gesperrt.
- Eine vollständige Zuordnung lässt sich nur entfernen, wenn beide Voucher-/Invoice-by-ID-Endpunkte für die gespeicherte ID eindeutig 400 oder 404 melden und das Repository ID und Typ nochmals atomar bestätigt. Ein vorhandener oder nicht sicher prüfbarer Remote-Beleg hält das Mapping fest.

## Bestehende Kontakte

- Zeigt das konfigurierte WHMCS-Kundenfeld auf eine vorhandene sevDesk-ID, wird
  genau dieser Kontakt wiederverwendet. Es gibt keinen Such-/Create-Fallback und
  keine automatische Stammdatenänderung.
- Fehlt die Remote-ID, bleibt der Export ein Klärfall.
- Nur bei leerem Kundenfeld wird exakt nach
  `customerNumber=<interne WHMCS-Client-ID>` gesucht. Fehlt die Nummer in der
  Suchantwort, wird der Treffer per ID erneut gelesen; bleibt die Identität
  unbewiesen, wird kein Kontakt verknüpft oder angelegt.
- Die neue Setupbestätigung `customer_number_contact_creation_confirmed` startet
  auch beim Upgrade deaktiviert. Ohne sie werden vorhandene IDs und exakte
  Suchtreffer weiterhin genutzt, bei keinem Treffer wird aber kein Kontakt angelegt.
- Vor der Aktivierung muss bestätigt sein, dass dieser `customerNumber`-Namensraum
  im sevDesk-Mandanten tatsächlich exklusiv den internen WHMCS-Client-IDs gehört.
  Alt-Kontakte mit anderer Nummer müssen manuell verknüpft werden.

## Invoice-only und Altbestand

Der Dateitausch startet keinen Invoice-Nachlauf. Nach bestandenem Invoice-Canary wird zunächst mit `invoice_only + whmcs` und ausgeschalteten E-Rechnungen getestet. WHMCS bleibt in dieser Stufe die kundenseitige Dokumenthoheit.

Bezahlte, ungemappte Rechnungen ab `import_after` können anschließend über die gemeinsame Vorschau ausgewählt werden. Der daraus entstehende `historical_backfill` ist mailfrei und erzeugt keine E-Rechnung. Vor jedem Invoice-Create sucht der Worker read-only nach derselben Rechnungsnummer, nach passenden Invoice-Daten aus Datum, Kontakt und Betrag sowie nach Voucher-Nummern und Markern. Jeder mögliche Treffer blockiert nur die betroffene Rechnung. Das Modul legt daraus nicht automatisch ein Mapping an.

Alte fehlgeschlagene Voucher-Jobs werden nach einem Moduswechsel nicht normal wiederholt. Bei einem sicheren Checkpoint vor dem ersten Dokument-Write erscheint eine eigene Requeue-Aktion. Sie legt nach Bestätigung einen neuen mailfreien `export_document`-Job im aktuellen Modus an und lässt das alte Item als Nachweis stehen. Nach `voucher_write_requested` oder einem späteren riskanten Schritt bleibt ausschließlich die Recovery des alten Voucher-Pfads zulässig.

## sevDesk-Hoheit und ZUGFeRD später aktivieren

sevDesk-Hoheit gilt nur mit `invoice_only`, installiertem Theme-Adapter und dem direkten sevDesk-Versand. Im Upgrade-Lebenszyklus bleibt WHMCS-Proforma Pflicht. `whmcs_template` bleibt auf WHMCS 8.13.4 gesperrt. Die Hoheit wird erst aktiviert, nachdem Paid-Mail, Pending-/Ready-Zustand und Kundendownload geprüft wurden. Frühere Invoices wechseln dadurch nicht rückwirkend die Hoheit.

ZUGFeRD braucht einen eigenen Testmandanten-Canary und bleibt bis dahin aus. Das Setup verlangt ein vorhandenes, nur für Administratoren sichtbares WHMCS-Kunden-Tickbox-Feld, eine sevDesk-PaymentMethod, Aktivierungsdatum, PHP XMLReader und die gesonderte Canary-Bestätigung. Ausgewählt werden nur neue deutsche Organisationskunden mit Rule 1 und gesetztem Kunden-Opt-in.

Der verknüpfte sevDesk-Kontakt braucht Käuferreferenz, passende Haupt-E-Mail, vollständige deutsche Rechnungsadresse und darf nicht als Behörde geführt sein. Das Modul ergänzt diese Daten nicht. Fehlen sie nach gesetztem Opt-in, wird die Rechnung blockiert; es gibt keinen Rückfall auf eine normale Invoice. Rule 19, Rules 18/20, B2G/XRechnung und historische E-Rechnungs-Backfills sind ausgeschlossen.

sevDesk erzeugt PDF und XML. Das Modul prüft beide, speichert nur ihre SHA-256-Hashes und hält keine dauerhafte Kopie in WHMCS. Beim sevDesk-Mailkanal wird `sendXml=false` verwendet. Der strukturierte Inhalt bleibt Bestandteil der ZUGFeRD-PDF.

## Direktbetrieb und Late Fees erst nach Canaries

Der neue Lebenszyklus `issue_on_creation` ist kein Upgrade-Schritt. Er darf erst
nach einem getrennten Live-Test aktiviert werden und setzt Folgendes voraus:

- `invoice_only` und sevDesk-Hoheit;
- WHMCS-Proforma aus;
- „Sequential Paid Invoice Numbering“ aus;
- „Set Invoice Date on Payment“ aus;
- Adaptervertrag v2;
- keine offenen Rechnungen oder ungeklärten Lifecycle-Jobs;
- bestätigte Canaries für Direktinvoice, Mahnung und Storno.

Das Setup zeigt vor dem Wechsel eine signierte Übergangsinventur. Eine
Bestätigung startet keinen Nachlauf. Bestehende Mappings behalten ihren alten
Lebenszyklus.

Positive, unbesteuerte `LateFee`-Positionen können hinter einem eigenen Canary
aus der Leistungs-Invoice herausgelöst werden. Die Gebühr wird genau einmal
erfasst: entweder durch eine nachweislich buchungswirksame sevDesk-Mahnung oder
nach Zahlung durch einen mailfreien Rule-22-Revenue-Voucher. Für den
Voucher-Pfad müssen das aktuelle `ReceiptGuidance`-Ergebnis, Rule 22, 0 % und
die mandantenspezifische Account-Datev-ID übereinstimmen.

Bis diese Nachweise vorliegen, bleiben
`direct_invoice_canary_confirmed`, `dunning_canary_confirmed`,
`cancellation_canary_confirmed`, `e_invoice_cancellation_canary_confirmed`,
`late_fee_rule22_canary_confirmed` und
`late_fee_reminder_accounting_canary_confirmed` aus. Historische Exporte bleiben
unabhängig davon mailfrei.

## Kompatibilitäts- und Rollbackgrenze

Bei einer unbekannten Altversion wird der automatische Export sicher deaktiviert
und ihr Datenbestand bleibt erhalten. Die funktionale Übernahme ist aber erst bestätigt, wenn deren echte
Settingnamen, Mappingstruktur, Kontakt-Nummernlogik und Voucher-Beschriftung mit
dieser Installation abgeglichen wurden.

Das Zurückkopieren des alten Moduls ist kein allgemeiner Rollback. Sobald ein
Invoice-/E-Rechnungs-Mapping oder ein unklarer Invoice-Write existiert, darf das Altmodul
nicht mehr auf denselben Bestand zugreifen, weil es den Dokumenttyp als Voucher
missverstehen kann. Auch davor müssen gespeicherte Modulversion, Hook-Erkennung
und der echte Altcode in einer Testinstallation geprüft werden.

Allgemeine Kontaktsynchronisation, Fremdwährungen, produktabhängige Konten und andere nicht belegte Sonderfunktionen des bisherigen Moduls gehören nicht zu diesem RC. Wer sie im Altbetrieb nutzt, lässt `sync_enabled` aus, bis dafür ein eigener Übergang geprüft ist.
