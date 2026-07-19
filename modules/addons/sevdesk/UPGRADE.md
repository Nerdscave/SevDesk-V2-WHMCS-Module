# Sicherer Ersatz eines bestehenden WHMCS-sevDesk-Moduls

Diese Version verwendet weiterhin den Addon-Namen und Ordner `sevdesk` sowie
die Mappingtabelle `mod_sevdesk`. Der Austausch erhält vorhandene Zuordnungen
und Einstellungen additiv. Er ist keine Zusage, dass jede Version oder jede
Zusatzfunktion eines früheren Anbieters funktionsgleich nachgebaut ist.

Die freigegebene Zielplattform ist ausschließlich WHMCS 8.13.4 mit PHP 8.3
und ein sevDesk-Mandant mit **Update 2.0**. Ein noch auf sevDesk V1 arbeitender
Altbetrieb ist kein funktionsfähiger Drop-in; `sync_enabled` bleibt bis zur
separaten Mandantenmigration aus. Das vollständige Betriebs- und
Recovery-Runbook liegt als `OPERATIONS.md` neben dieser Datei.

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
   alter Schreibpfad darf nach dem Wechsel nicht parallel aktiv bleiben.
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
   `voucher_only + whmcs + OSS blocked` gelten. Unbekannte Altsettings bleiben
   gespeichert, werden aber nicht als kompatible Aliase geraten.
6. Migration, Health, Mappingbestand, offenen Jobbestand, Mandant/Token, Konten,
   Steuerprofile, Kontaktfeld-ID und vorhandene Kontakt-IDs prüfen. Pausieren
   und Abbrechen sind in Quarantäne möglich; abgelaufene Leases klassifiziert
   das Setup unter dem Runner-Lock rein lokal. Sichere und bereits verifizierte
   Fortsetzungen werden ohne Abbruch `retry_wait`, sichere abgebrochene Leases
   `cancelled`; unbekannte Write-Ausgänge oder Abbrüche nach bestätigtem
   Remote-Effekt bleiben `ambiguous`. Ein noch laufendes Item muss erst enden
   oder seine Lease muss ablaufen; Pause/Abbruch lösen es nicht unmittelbar auf.
7. Im Setup die Bestandsprüfung ausdrücklich bestätigen und speichern. Nur nach
   erfolgreicher read-only Mandantenprüfung wird `runtime_review_required`
   gelöscht. Erst danach einen kleinen Voucher-Canary ausführen und optional
   `sync_enabled` aktivieren. Invoice-Modi benötigen zusätzlich den
   dokumentierten echten sevDesk-Testmandanten-Canary.

## Bestehende Zuordnungen

- Bestehende Remote-IDs werden nicht geändert oder neu exportiert.
- `NULL`, leere oder nur aus Leerzeichen bestehende Remote-IDs sind
  unvollständige Recovery-Fälle.
- Vollständige Altzuordnungen bleiben zunächst ohne Dokumenttyp. Voucher und
  Invoice werden separat read-only geprüft; erst eine Adminbestätigung ergänzt
  den Typ.
- Unbekannte oder kollidierende Ergebnisse bleiben gesperrt.

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

## Kompatibilitäts- und Rollbackgrenze

Bei einer unbekannten Altversion wird der automatische Export sicher deaktiviert
und ihr Datenbestand bleibt erhalten. Die funktionale Übernahme ist aber erst bestätigt, wenn deren echte
Settingnamen, Mappingstruktur, Kontakt-Nummernlogik und Voucher-Beschriftung mit
dieser Installation abgeglichen wurden.

Das Zurückkopieren des alten Moduls ist kein allgemeiner Rollback. Sobald ein
Invoice-Mapping oder ein unklarer Invoice-Write existiert, darf das Altmodul
nicht mehr auf denselben Bestand zugreifen, weil es den Dokumenttyp als Voucher
missverstehen kann. Auch davor müssen gespeicherte Modulversion, Hook-Erkennung
und der echte Altcode in einer Testinstallation geprüft werden.
