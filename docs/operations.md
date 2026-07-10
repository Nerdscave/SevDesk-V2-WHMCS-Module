# Betrieb und Recovery

## Freigabestatus

Dieses Runbook beschreibt den Betrieb des implementierten Moduls. Modul-UI, Worker und Release-Build sind vorhanden. Für die Produktivfreigabe fehlen noch der MariaDB-/WHMCS-Test und der sevDesk-Canary. Bis dahin bleibt `sync_enabled` deaktiviert.

## Unterstützte Umgebung

- WHMCS 8.13.4
- PHP 8.3 für Web und Cron
- von dieser WHMCS-Version unterstützte MySQL-/MariaDB-Version
- regelmäßiger WHMCS-Cron
- sevDesk-Mandant mit sevDesk-Update 2.0
- API-Basis `https://my.sevdesk.de/api/v1`
- ausreichend berechtigter sevDesk-API-Benutzer

PHP-CLI und PHP-FPM müssen dieselbe relevante Konfiguration und dieselben Erweiterungen verwenden. Das Modul benötigt kein ionCube.

## Vor jeder Installation oder jedem Upgrade

1. Wartungsfenster und verantwortliche Person festlegen.
2. Vollständiges WHMCS-Datenbankbackup erstellen und dessen Lesbarkeit prüfen.
3. Aktuelles Verzeichnis `modules/addons/sevdesk` separat sichern.
4. Anzahl und Prüfsummen der bestehenden `mod_sevdesk`-Zeilen dokumentieren, ohne den Dump in Git abzulegen.
5. Aktive Jobs stoppen und laufende Worker beenden oder auslaufen lassen.
6. Sicherstellen, dass Web und Cron PHP 8.3 verwenden.
7. Rollback-Dateien griffbereit halten.
8. Prüfen, dass private Exporte und API-Token nicht im Deployment-Paket liegen.

`sevdesk_module_restore.sql` ist weder ein Upgrade- noch ein Rollback-Werkzeug. Die Datei leert Tabellen, spielt einen veralteten Datenstand ein und enthält Geheimnisse.

## Erstinstallation oder Drop-in-Wechsel

Der Dateideploy unterscheidet sich je nach Hosting. Die Reihenfolge ist immer:

1. Altes verschlüsseltes Modul außerhalb des WHMCS-Addon-Scanpfads sichern.
2. Neues Modul atomar unter `modules/addons/sevdesk` bereitstellen.
3. Im WHMCS-Adminbereich das Addon aktivieren oder das Upgrade auslösen.
4. Migrationsreport prüfen. Bei Mappingkonflikten abbrechen, nicht automatisch bereinigen.
5. Im Addon die interne Seite **Einrichtung** öffnen und funktionale Altwerte prüfen. Das normale WHMCS-Addon-Konfigurationsformular enthält keine operativen Felder.
6. Health Check vollständig ausführen.
7. Cron-/Worker-Lauf im Diagnosemodus starten.
8. Einen synthetischen oder fachlich freigegebenen Canary-Einzelexport ausführen.
9. Ergebnis in WHMCS, `mod_sevdesk` und sevDesk prüfen.
10. Erst danach Hooks und Bulk-Nachlauf freigeben.

Das Öffnen der Settings darf keinen Voucher schreiben und muss auch bei falschem Token möglich bleiben.

Operative Einstellungen dürfen nur über `addonmodules.php?module=sevdesk&a=setup` geändert werden. Dieser Pfad nimmt den Advisory Lock, prüft aktive Jobs, deaktiviert während der Änderung die Hooks und validiert Systemversion sowie `ReceiptGuidance`.

Vorhandene Werte in `tbladdonmodules` bleiben erhalten. Auf der allgemeinen WHMCS-Seite lassen sie sich nicht bearbeiten.

## Erforderliche Konfiguration

### API

- sevDesk-API-Token
- erwartete Systemversion Update 2.0
- keine abweichende API-Basis ohne Code- und Contract-Prüfung

### WHMCS

- `import_after`
- `import_only_paid`
- Client-Custom-Field mit vorhandenen sevDesk-Kontakt-IDs
- Netto-/Brutto-Einstellung und relevante Währungen

### Buchhaltung

- Inlandskonto
- EU-B2C-Konto
- nur nach Freigabe: EU-B2B-Warenlieferungs-, Drittland- und Kleinunternehmerkonto
- schriftlich bestätigte Zuordnung von Geschäftsfall, Tax Rule, Steuersatz und Account-Datev

Nach Installation und Upgrade bleibt EU-B2B/Rule 3 standardmäßig gesperrt. Die Freigabe im Setup ist nur möglich, wenn der gesamte betroffene Geschäftsfall fachlich als innergemeinschaftliche Warenlieferung bestätigt wurde.

Für Hosting, Domains, Lizenzen und andere Dienstleistungen bleibt Rule 3 gesperrt. Entsprechende Rechnungen landen in der manuellen Prüfung.

Numerische Account-Datev-IDs müssen aus dem aktuellen Mandanten stammen und werden über `ReceiptGuidance` geprüft. IDs aus alten Dumps oder einem Testmandanten werden nicht übernommen.

## Health Check vor Writes

Alle Punkte müssen grün sein:

- PHP 8.3 und WHMCS 8.13.4 erkannt;
- Datenbankschema vorhanden und Migration vollständig;
- `mod_sevdesk` lesbar, Unique-Constraints intakt;
- keine ungeklärten Schema- oder Mappingkonflikte;
- API-Token akzeptiert;
- sevDesk-Systemversion ist Update 2.0;
- benötigte Account-Datev-IDs existieren;
- freigegebene Tax-Rule-/Rate-/Voucher-Kombinationen sind laut Guidance zulässig;
- Client-Custom-Field existiert;
- WHMCS-PDF lässt sich für eine Testinvoice erzeugen;
- Cron läuft regelmäßig und verarbeitet einen Diagnosejob;
- keine globale Sperre durch 401/403 oder Migration aktiv.

Ein fehlerhafter Health Check blockiert Remote-Writes, wenn der Fehler die Datenkonsistenz, Authentifizierung oder Steuerlogik betrifft.

## Normaler Bulk-Ablauf

1. Im Adminbereich Zeitraum und Filter wählen.
2. Vorschau erzeugen.
3. Die Gruppen prüfen: eligible, bereits gemappt, skipped und manuell blockiert.
4. Bei unerwartet großer oder fachlich gemischter Auswahl nicht starten, sondern Filter korrigieren.
5. Job anlegen. Danach darf der Browser geschlossen werden.
6. Fortschritt über Jobseite beobachten.
7. 401/403 und auffällige Serienfehler sofort untersuchen; einzelne 422 stoppen den restlichen Job nicht.
8. Nach Abschluss alle `permanent_failed`- und `ambiguous`-Items bearbeiten. Die UI kennzeichnet anhand des Fehlercodes, welche davon manuell geprüft werden müssen.
9. Endzahlen mit WHMCS und sevDesk abstimmen.

Ein Cancel verhindert neue Claims, bricht laufende API-Operationen aber nicht gewaltsam ab. Deren Ergebnis wird gespeichert oder per Recovery geklärt.

Der Buchungsassistent durchsucht positive WHMCS-Transaktionen im gewählten Transaktionszeitraum seitenweise. Die Vorschau muss bis zur letzten Seite geprüft werden. Neben Rechnungen mit Status `Paid` können auch teilbezahlte Rechnungen mit Status `Unpaid` erscheinen.

Vor dem Jobstart Mapping, Referenz, Betrag und offenen Voucherstand prüfen.

### Statusanzeige lesen

Jobstatus:

- `pending`: angelegt, noch kein Item aktiv
- `running`: mindestens ein Item wird verarbeitet
- `paused`: neue Claims sind angehalten; offene Items bleiben erhalten
- `completed`: alle Items terminal, ohne Fehler/Unklarheit
- `completed_with_errors`: alle Items terminal, mindestens ein `permanent_failed` oder `ambiguous`
- `cancelled`: Abbruch angefordert und alle offenen Items beendet

Itemstatus:

- `pending`: bereit
- `running`: mit `lease_token` und `leased_until` geclaimt
- `retry_wait`: sicher wiederholbarer Fehler; wartet bis `available_at`
- `succeeded`: Voucher und Mapping bestätigt
- `skipped`: kein Write nötig oder bereits anderweitig eingeplant/gemappt
- `permanent_failed`: ohne fachliche/technische Korrektur nicht wiederholen
- `ambiguous`: möglicher Remote-Write; zuerst abgleichen
- `cancelled`: vor Ausführung abgebrochen

„Manuell prüfen“ ist eine UI-Aktion oder ein Fehlercode auf `permanent_failed` oder `ambiguous`, kein eigener Status.

## Buchhaltungsnachlauf

Für den Nachlauf zählt nur der aktuelle Datenbestand. Deshalb beginnt er mit einer Live-Inventur.

### 1. Klassifizieren

- vollständige Mappings mit existierender WHMCS-Invoice;
- verwaiste Mappings;
- `NULL`-Mappings;
- ungemappte, exportfähige Paid-Invoices;
- mit dokumentiertem Grund übersprungene Unpaid-/Datumsfälle;
- OSS-, Credit-, Nullsummen-, Fremdwährungs- und sonstige manuelle Fälle.

Rechnungen mit angewendetem Kundenguthaben bleiben aus Bulk-Jobs ausgeschlossen. Beim Einzelexport zeigt das Modul Brutto, Guthaben und Zahlbetrag an. Anschließend lässt es nur die Bestätigung „voller Rechnungsbrutto-Voucher; Guthaben separat klären“ zu.

Ohne diese Bestätigung gibt es keinen Write. Eine proportionale Umsatzkürzung ist nicht vorgesehen.

Ungemappte Invoices dürfen nicht pauschal in einen Job übernommen werden. Maßgeblich ist die aktuelle Live-Inventur.

### 2. Altzustände bereinigen

Alle `NULL`-Mappings werden einzeln geprüft. Verwaiste Mappings werden nicht automatisch gelöscht.

### 3. Dry-Run

Der Dry-Run lädt die WHMCS-Daten, prüft Eligibility, klassifiziert die Steuerfälle und gleicht sie mit der Guidance ab. Er schreibt weder Kontakte noch PDFs noch Voucher. Der Report nennt für jede Invoice einen Grund.

### 4. Canary

Zunächst nur wenige repräsentative Invoices pro freigegebener Steuerklasse exportieren. Die Buchhaltung prüft Voucher, PDF, Konto, Rule, Rate und Summen direkt in sevDesk.

### 5. Abschnittsweise exportieren

Nach der Freigabe Jobs monats- oder quartalsweise starten. Der erste Lauf darf nicht den gesamten historischen Zeitraum umfassen. Nach jedem Abschnitt:

- Job-Endzustände prüfen;
- fehlgeschlagene/manuelle Items abarbeiten;
- Mappinganzahl und Remote-Belege vergleichen;
- Summenabstimmung dokumentieren;
- erst dann den nächsten Abschnitt starten.

## Recovery bei `sevdesk_id = NULL`

Eine leere Remote-ID bedeutet „Ausgang unbekannt“, nicht „nicht importiert“.

1. Weitere Verarbeitung dieser Invoice pausieren.
2. Jobhistorie, Zeitstempel und bereinigte Fehlermeldung prüfen.
3. In sevDesk anhand der stabilen WHMCS-Referenz suchen.
4. Kandidaten anhand von Rechnungsnummer, Datum, Betrag und Kontakt vergleichen.
5. Bei genau einem sicheren Treffer Mapping über die Recovery-Funktion ergänzen.
6. Nur wenn nachweislich kein Voucher erstellt wurde, die Reservierung über die Recovery-Funktion lösen und neu einplanen.
7. Bei mehreren oder unsicheren Kandidaten `ambiguous` beibehalten. Der Dedupe-Key bleibt gesetzt; keine Remote- oder Mappingzeile löschen.

Direkte SQL-Änderungen sind nur im Notfall zulässig. Vor und nach der Änderung muss jeweils ein Backup erstellt werden. Vier-Augen-Prüfung und dokumentierte IDs sind ebenfalls Pflicht.

Für unbekannte Kontakt- oder Korrektur-POSTs gilt außerdem: Die UI-Aktion **Abgleichen** liest nur Daten. Bleibt die Suche ohne Treffer, ist damit nicht bewiesen, dass sevDesk nichts angelegt hat. Das Item bleibt `ambiguous`. Ein neuer Create darf nur in einem separat geprüften, neuen Vorgang erfolgen.

## Störungsmatrix

### HTTP 401

Symptom: Authentifizierung fehlgeschlagen.

Maßnahmen:

1. Prüfen, ob der globale Auth-Alarm gesetzt ist. Nach dem betroffenen Item beendet der Runner den aktuellen Batch und claimt auch in späteren Cronläufen keine weiteren Items.
2. Token nicht in Ticket oder Chat kopieren.
3. API-Benutzer und Token in sevDesk prüfen.
4. Wenn das Token rotiert wurde, das neue Token nur über die interne Seite **Einrichtung** speichern. Der Alarm wird erst gelöscht, nachdem Zugang, Systemversion und `ReceiptGuidance` erfolgreich mit reinen Lesezugriffen geprüft wurden.
5. Health Check ausführen.
6. Erst danach pausierte Jobs fortsetzen und fehlgeschlagene Items gezielt retryen.

### HTTP 403

Das Token ist gültig, hat aber nicht die nötige Berechtigung. Der globale Alarm stoppt weitere Claims. Benutzerrolle oder Mandantenzugriff prüfen und anschließend die Zugangsdaten über **Einrichtung** validieren. Keine automatischen Retries starten.

### HTTP 422

Symptom: sevDesk lehnt Payload, Tax Rule, Steuersatz oder Konto ab.

Maßnahmen:

1. Item bleibt `permanent_failed` und wird nicht automatisch erneut versucht.
2. Exception-UUID, Ergebniscode und Invoice-ID notieren; keine Rohpayloads weitergeben.
3. Tax-Resolver-Ergebnis und aktuelle `ReceiptGuidance` vergleichen.
4. WHMCS-Kundentyp, `taxexempt`, Steuersatz und Sonderfall prüfen.
5. Konfiguration oder Code nur mit fachlicher Begründung ändern.
6. Regressionstest ergänzen, dann gezielt retryen.

### HTTP 429

Das Item wechselt auf `retry_wait`; der Worker respektiert `Retry-After` oder den berechneten Backoff. Bei anhaltender Rate-Begrenzung die Batchfrequenz senken und keine zusätzlichen Läufe parallel starten.

### HTTP 5xx oder Netzwerkfehler

Bei sicheren 5xx- oder Netzwerkfehlern führt der Worker nur begrenzte Retries über `retry_wait` aus. Tritt der Fehler während oder nach einem POST auf und bleibt der Ausgang unbekannt, wechselt das Item zu `ambiguous`. Vor einem weiteren Versuch ist ein Remote-Abgleich Pflicht. Nach Erreichen der Retry-Grenze bleibt es `permanent_failed`.

### Proxy-/Browser-Timeout

Die eigentliche Arbeit läuft serverseitig weiter. Jobseite neu laden und Status prüfen. Nicht denselben Bulk-Job erneut anlegen, solange der vorhandene Job existiert.

Wenn bereits das Anlegen eines Jobs timeoutet, zuerst in der Jobliste prüfen, ob er gespeichert wurde.

### Worker bleibt `running`

1. Cronstatus und PHP-Fehlerlog prüfen.
2. `checkpoint`, `lease_token` und `leased_until` kontrollieren.
3. Noch gültige Lease nicht manuell überschreiben.
4. Nach Ablauf Recovery-Claim ausführen.
5. Items mit möglichem Remote-Write vor Retry abgleichen.

### PDF kann nicht erzeugt/hochgeladen werden

- WHMCS-Rechnung und Template lokal rendern;
- Dateirechte, temporäres Verzeichnis und PHP-Erweiterungen prüfen;
- sicherstellen, dass keine vollständigen PDFs im Modul-Log landen;
- erst nach erfolgreichem PDF-Test retryen.

### Mapping vorhanden, Remote-Objekt fehlt

Keinen neuen Remote-Beleg automatisch anlegen. Zuerst Ursache und Löschung in sevDesk klären. Danach das Mapping als Prüffall markieren und entscheiden, ob eine historische Wiederherstellung fachlich zulässig ist.

### Verdacht auf Doppelbeleg

1. Jobs für die betroffene Invoice stoppen.
2. Keine Mappingzeile und keinen Remote-Beleg vorschnell löschen.
3. Beide Remote-IDs, Zeitpunkte und Inhalte vergleichen.
4. Die Buchhaltung entscheidet, welcher Beleg bestehen bleibt.
5. Das lokale Mapping auf den bestätigten Beleg setzen.
6. Die Ursache als Idempotenz-Regression testen.

## Logs und Diagnose

Zulässige Felder:

- Job-ID und Item-ID;
- WHMCS-Invoice-ID;
- Aktion/Endpoint ohne Query-PII;
- HTTP-Status;
- stabiler Ergebniscode;
- sevDesk-Exception-UUID;
- Versuchszahl und Zeitpunkt;
- bereinigte Kurzmeldung.

Nicht loggen:

- API-Token und Authorization-Header;
- vollständige Request-/Response-Bodies;
- Namen, Adressen, E-Mails und USt-IDs;
- PDF-Inhalte;
- vollständige Rechnungspositionen;
- WHMCS-Konfiguration oder Sessiondaten.

Der Supportexport enthält nur bereinigte Diagnosedaten. Screenshots der vollständigen Settings und SQL-Dumps sind kein zulässiger Standard-Supportweg.

## Monitoring

Mindestens überwachen:

- Zeit seit letztem erfolgreichen Cron-/Worker-Lauf;
- Anzahl `pending` und ältestes Item;
- abgelaufene `running`-Leases;
- Fehler nach Ergebniscode und HTTP-Klasse;
- Serien von 401/403/422;
- Items in `ambiguous` und `permanent_failed` mit Review-Fehlercode;
- `NULL`-Mappings;
- Jobs ohne Fortschritt;
- Mappingwachstum im Verhältnis zu erfolgreichen Items.

Für die Alarmierung reichen zunächst die vorhandene WHMCS-/Serverüberwachung und ein kleiner Health Check. Eine zusätzliche Monitoringplattform ist dafür nicht nötig.

## Tokenwechsel

1. Neue Job-Claims pausieren.
2. Token in sevDesk rotieren.
3. Neues Token direkt in WHMCS eintragen.
4. Altes Token aus Passwortmanagern oder Notizen entfernen, soweit vorgesehen.
5. Health und lesenden Guidance-Aufruf prüfen.
6. Worker fortsetzen.

Ein Tokenwechsel verändert keine Mappings und löst keinen automatischen Nachlauf aus.

## Rollback

1. Neue Claims und Hooks deaktivieren.
2. laufende Items auslaufen lassen oder ihren Zustand dokumentieren.
3. neue Moduldateien aus dem Scanpfad nehmen.
4. zuvor gesicherte, kompatible Moduldateien wiederherstellen oder Addon vorübergehend deaktiviert lassen.
5. `mod_sevdesk` und neue Jobtabellen nicht löschen.
6. Health der verbleibenden WHMCS-Installation prüfen.
7. Remote-Writes seit Deployment anhand der Jobs erfassen und mit sevDesk abstimmen.

Das bisherige ionCube-Modul läuft nicht unter PHP 8.3 und eignet sich auf derselben Runtime nicht als dauerhafte Rückfalllösung. Im Zweifel bleibt das Addon deaktiviert, bis der Fehler behoben ist.

## Deaktivierung und Deinstallation

Eine Deaktivierung stoppt Hooks und Worker, lässt die Daten aber bestehen. Bei der Deinstallation dürfen `mod_sevdesk` und die Jobreports nur nach einer separat bestätigten Export- und Löschentscheidung entfernt werden. Standardmäßig bleiben sie für Buchhaltungsnachweis und Idempotenz erhalten.
