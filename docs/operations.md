# Betrieb und Recovery

## Freigabestatus

Dieses Runbook gilt für den Arbeitsstand nach `2.1.0-rc.5`. Der RC ist für Testinstallationen gedacht, nicht für Produktivdaten. Die automatisierten Prüfungen unter PHP 8.3 mit XMLReader und MariaDB sowie die technischen Live-Läufe für normale Invoices, Rule 19 und ZUGFeRD wurden mit synthetischen Daten ausgeführt. Der direkte sevDesk-Versand, echte Kundensitzungen und die Rechteprüfung waren erfolgreich. Zwei WHMCS-Postfachläufe enthielten dagegen die WHMCS-Core-PDF. WHMCS 8.13 führt den Hook zwar aus, kann dessen Binäranhang aber nicht übernehmen. Der Kanal `whmcs_template` ist auf der Zielplattform daher gesperrt. Vor der Freigabe stehen noch Invoice-`bookAmount`, der rabattfreie Rule-11-Invoice-Canary, der darauf aufbauende Rabatt-Canary, die Voucher-Canaries der produktiv verwendeten Steuerfälle und die fachliche Abnahme aus.

Bis dahin bleiben `invoice_canary_confirmed`, `small_business_invoice_canary_confirmed`, `e_invoice_canary_confirmed`, `invoice_discount_canary_confirmed` und bei neuen Rollouts auch `sync_enabled` deaktiviert. Ein 2.0-Bestand behält beim Upgrade den Modus `voucher_only` und erhält `runtime_review_required=on`. Diese Quarantäne stoppt automatische und manuelle Verarbeitung. Sie endet erst nach einer erfolgreichen read-only Prüfung und der Bestätigung im Setup.

## Unterstützte Umgebung

- WHMCS 8.13.4
- PHP 8.3 für Web und Cron
- für native ZUGFeRD-Invoices zusätzlich PHP XMLReader (`ext-xml`) in Web und Cron
- von dieser WHMCS-Version unterstützte MySQL-/MariaDB-Version
- regelmäßiger WHMCS-Cron
- sevDesk-Mandant mit sevDesk-Update 2.0
- API-Basis `https://my.sevdesk.de/api/v1`
- ausreichend berechtigter sevDesk-API-Benutzer

PHP-CLI und PHP-FPM müssen dieselbe relevante Konfiguration und dieselben Erweiterungen verwenden. Ihre PHP-Standardzeitzone darf abweichen; die Queue verwendet dafür bewusst die Uhr der Datenbankverbindung. Web und CLI müssen jedoch dieselbe MariaDB-Session-Zeitzone sehen. Das lässt sich in beiden Laufzeiten mit `SELECT @@session.time_zone, CURRENT_TIMESTAMP` prüfen.

## Vor jeder Installation oder jedem Upgrade

1. Wartungsfenster und verantwortliche Person festlegen und WHMCS vor dem
   Dateitausch in den Wartungsmodus setzen.
2. Vollständiges WHMCS-Datenbankbackup erstellen und dessen Lesbarkeit prüfen.
3. Aktuelles Verzeichnis `modules/addons/sevdesk` separat sichern.
4. Anzahl und Prüfsummen der bestehenden `mod_sevdesk`-Zeilen dokumentieren, ohne den Dump in Git abzulegen.
5. Aktive Jobs stoppen und laufende Worker sowie Web-/CLI-Requests des
   Altmoduls vollständig beenden oder auslaufen lassen.
6. Prüfen, dass keine `ambiguous` Exportitems offen sind; sie blockieren einen Moduswechsel. Alte `permanent_failed`-Voucher-Jobs ebenfalls erfassen, weil sie nach einem Wechsel nicht normal wiederholt werden dürfen.
7. Sicherstellen, dass Web und Cron PHP 8.3 verwenden. Für ZUGFeRD muss XMLReader in beiden Laufzeiten verfügbar sein.
8. Rollback-Dateien griffbereit halten.
9. Prüfen, dass private Exporte, PDF-Kopien und API-Token nicht im Deployment-Paket liegen.
10. Gegen die Funktionsmatrix im README prüfen, ob der Altbetrieb Kontaktaktualisierungen, automatische Zahlungsbuchung, Produktkonten oder Fremdwährungen verwendet. Für solche Abweichungen bleibt `sync_enabled` aus, bis ein eigener Übergang entschieden ist.
11. Das ursprüngliche Herstellerpaket beziehungsweise Installationsmanifest auf globale Hooks, Includes, eigene Crondateien und weitere Schreibpfade außerhalb von `modules/addons/sevdesk` prüfen; sie müssen beim Dateitausch gezielt stillgelegt werden.
12. Prüfen, ob PHP-FPM/Apache beziehungsweise OPcache nach dem Tausch neu
    geladen werden müssen. Bei `opcache.validate_timestamps=0` ist der Reload
    zwingend, damit kein Code des alten Moduls weiterläuft.

Ignorierte Restore- und Dump-Dateien sind weder Upgrade- noch Rollback-Werkzeuge. Sie können veraltete Daten, Zugangswerte oder destruktive Anweisungen enthalten.

## Erstinstallation oder Drop-in-Wechsel

Der Dateideploy unterscheidet sich je nach Hosting. Die Reihenfolge ist immer:

1. Bisherige Modulversion außerhalb des WHMCS-Addon-Scanpfads sichern.
2. Das bisherige Addon **nicht über WHMCS deaktivieren**. Sein Deaktivierungs-Callback gehört nicht zum bestätigten Datenvertrag. Stattdessen das noch aktive Modulverzeichnis atomar durch das neue `modules/addons/sevdesk` ersetzen.
3. PHP-FPM/Apache samt OPcache kontrolliert neu laden und sicherstellen, dass
   kein vor dem Tausch gestarteter Prozess mehr läuft.
4. Im WHMCS-Adminbereich den vom Versionswechsel ausgelösten Upgrade-Callback abwarten beziehungsweise direkt die neue Addon-Seite öffnen. Das neue Addon nicht nochmals als vermeintliche Erstinstallation aktivieren. Danach die Addon-Einstellungen einmal speichern, damit WHMCS bei einem bereits aktiven Altmodul die neue `hooks.php` sicher registriert.
5. Schema-/Migrationsreport prüfen. Bei einem der unten beschriebenen Diagnosecodes abbrechen, nicht automatisch bereinigen.
6. Im Addon die interne Seite **Einrichtung** öffnen und Mandant/API-Token, Kontaktfeld und vorhandene Kontakt-IDs, Konten, Steuerprofile, Dokumentmodus sowie offene Jobs prüfen. Die dort angezeigte Übergangsinventur muss mit der zuvor erfassten Bestandsaufnahme plausibel sein. Das normale WHMCS-Addon-Konfigurationsformular enthält keine operativen Felder.
7. Abgelaufene Alt-Worker-Leases werden beim Speichern unter dem Runner-Lock ausschließlich lokal klassifiziert. Ohne Abbruch wechseln sichere Checkpoints und Checkpoints mit bestätigtem Remote-Effekt zu `retry_wait`. Bei einem abgebrochenen Job werden sichere Checkpoints `cancelled`; unbekannte Write-Ausgänge und bereits bestätigte Remote-Effekte werden `ambiguous`. Dabei läuft kein Handler. Ein laufendes Item muss regulär enden oder seine Lease muss ablaufen. Pause und Abbruch verhindern nur weitere Claims.
8. Die Bestandsfreigabe im Setup ausdrücklich bestätigen. Die Bestätigung ist an den beim Seitenaufruf angezeigten, opaken Quarantäne-Stand gebunden. Wurde die Quarantäne zwischenzeitlich erneuert, Seite neu laden und den Bestand erneut prüfen. Erst eine erfolgreiche read-only Mandantenprüfung löscht `runtime_review_required`; `sync_enabled` bleibt separat wählbar.
9. Health Check vollständig ausführen.
10. Cron-/Worker-Lauf im Diagnosemodus starten.
11. Zuerst einen synthetischen oder fachlich freigegebenen Voucher-Canary im Defaultmodus ausführen.
12. Ergebnis in WHMCS, `mod_sevdesk` und sevDesk prüfen.
13. Invoice-Modi erst nach dem gesonderten Invoice-API-Canary und dokumentierter Betreiberbestätigung aktivieren. Für den ersten produktionsnahen Test `invoice_only + whmcs` wählen und E-Rechnungen ausgeschaltet lassen.
14. Erst danach kleine Invoice-Batches prüfen und einen bestätigten Altbestand über die gemeinsame Vorschau mailfrei einreihen. Der Moduswechsel allein startet keinen Nachlauf.
15. sevDesk-Hoheit und ZUGFeRD erst nach den jeweils eigenen Live- und Canary-Prüfungen aktivieren. Anschließend Hooks freigeben und den WHMCS-Wartungsmodus beenden.

Das Öffnen der Settings darf weder Voucher noch Invoice schreiben und muss auch bei falschem Token möglich bleiben.

Der Upgrade-Callback fragt sevDesk nicht ab, erzeugt keinen Job und verändert keine Kontakt-ID. Die eindeutige Laufzeitsignatur gab es in Release 2.0.0 noch nicht. Tabellen und Settings können außerdem nach Tests oder einem Rollback zurückbleiben. Sie zeigen daher nicht zuverlässig, welcher Code zuletzt aktiv war.

Bei einem signaturlosen Bestand speichert das Modul vor der ersten Schemainspektion drei Werte gemeinsam in einer Transaktion: `runtime_review_required=on`, einen neuen Quarantäne-Token und eine ungültige Signatur. Anschließend schaltet es `sync_enabled` aus und setzt die Sicherheitswerte nochmals unabhängig. Das gilt auch beim ersten Wechsel von 2.0 auf 2.1.

Schlägt die erste Transaktion fehl, versucht eine zweite Transaktion, Review-Marker und ungültige Signatur gemeinsam zu speichern. Ohne nachgewiesenen neuen Token, aktiven Review-Marker und ungültige Signatur beginnt keine DDL. Setup und Worker können dadurch keinen unvollständigen Quarantänestand freigeben oder zur Verarbeitung nutzen.

Migration und Worker verwenden denselben Advisory Lock. Ein laufender Worker liest Review und Signatur vor jedem weiteren Claim neu und endet spätestens nach seinem aktuellen Item. Danach ergänzt die Migration das Schema und setzt die gültige Signatur, sobald Pflichtspalten und Unique-Indizes geprüft sind. Der Review bleibt bis zur Setup-Freigabe aktiv. Bei einem Struktur- oder Migrationsfehler bleiben Signatur und Synchronisation ausgeschaltet.

Mapping-Fingerprint, Zeilenzahl, `custom_field_id`, Konten und unbekannte Alt- oder Lizenzwerte müssen nach dem Upgrade den vorher erfassten Werten entsprechen. Auch die vorhandene Token-Zeile bleibt erhalten.

Im Setup ist trotzdem zu prüfen, ob das Originalmodul denselben Schlüssel und dieselbe Speicherung verwendet hat. Falls nötig, wird der Token dort neu eingetragen. Fehlt ein zuvor genutzter Wert, bleibt der Wechsel bis zur Klärung gesperrt. Eine unbekannte Hersteller-Version beweist keine funktionale Kompatibilität.

### Bereits aktives Alt-Addon ohne internes Aktivierungsflag

Wurde der Rewrite über ein in WHMCS bereits aktives Alt-Addon gelegt, kann der neue Aktivierungs- oder Upgrade-Callback ausbleiben, etwa wenn beide Anbieter zufällig dieselbe Versionsnummer melden. Beim ersten Öffnen der neuen Addon-Adminseite führt der Rewrite deshalb dieselbe rein lokale, additive Vorbereitung aus. Jeder signaturlose oder strukturell abweichende Bestand wird vor einer Migration fail-closed gestoppt; erst das vollständig geprüfte Schema darf die Signatur tragen. Das erzeugt keinen Job und sendet keinen sevdesk-Request.

Vor und nach diesem ersten Seitenaufruf sind Settings, Mapping-Fingerprint sowie Job-/Itemzahlen zu vergleichen. Anschließend werden die Addon-Einstellungen in WHMCS einmal unverändert gespeichert, damit WHMCS die neue `hooks.php` eines bereits aktiven Addons erkennt. Auch nach diesem WHMCS-Core-Speichervorgang müssen alle zuvor inventarisierten unbekannten Alt-/Lizenzzeilen erneut vorhanden sein; dieser Nachweis ist zuerst in einer Testkopie mit exakt WHMCS 8.13.4 zu führen. Bei einer Abweichung wird der Produktivwechsel abgebrochen. Kann die Adminseite die Migration nicht ausführen, darf `module_active` nicht isoliert per SQL gesetzt werden; die angezeigte Diagnose wird zuerst geklärt und die Aktivierung danach über WHMCS erneut ausgeführt.

`module_active=on` allein aktiviert keine Exporte. Während `runtime_review_required=on` sind Ereignis-Hooks, Cron/CLI, Schnellaktionen, neue Jobs sowie Resume/Retry/Reconciliation gesperrt; Pausieren und Abbrechen bleiben möglich. Nach bestätigter Freigabe verlangen Runner und manuelle Adminjobs weiterhin eine gültige Signatur, sind aber auch bei `sync_enabled=off` verfügbar. Ereignisgetriebene Invoice-, Refund-, Cancel- und Transaktions-Hooks benötigen zusätzlich `sync_enabled=on`.

### Migrationsdiagnosen

Der Einstiegspunkt zeigt keine rohe SQL- oder Treibermeldung an. Bekannte Altbestandskonflikte erhalten stabile Codes; unbekannte Fehler erscheinen als `migration_failed` mit einer korrelierbaren Protokollreferenz.

| Code | Bedeutung und Vorgehen |
| --- | --- |
| `legacy_duplicate_invoice_mapping` | mehrere `mod_sevdesk`-Zeilen verwenden dieselbe WHMCS-Invoice-ID; Bestand außerhalb des laufenden Systems sichern und fachlich klären |
| `legacy_duplicate_remote_mapping` | mehrere Zeilen verwenden dieselbe Remote-ID; Dokument und Rechnungszuordnung einzeln klären |
| `legacy_invoice_index_conflict` | der erwartete Indexname existiert mit anderer Definition; Schema prüfen, nicht automatisch umbenennen |
| `legacy_remote_index_conflict` | entsprechender Konflikt am Remote-ID-Index |
| `migration_failed` | sonstiger Schema-/Datenbankfehler; anhand der Referenz im WHMCS-/Serverprotokoll untersuchen |

Ein Fehler aktiviert weder Hooks noch Remote-Schreibvorgänge. Es werden keine doppelten Zeilen oder widersprüchlichen Indizes automatisch „repariert“.

### Rechnungsaktionen nach einem Upgrade prüfen

1. Eine bereits gespeicherte, einfache EUR-Rechnung ohne Mapping, Guthaben oder
   negative Position öffnen. Ungespeicherte Änderungen vorher sichern.
2. „Zu sevdesk exportieren“ anklicken und prüfen, dass die Einzelimportseite mit
   derselben Invoice-ID vorausgefüllt ist. Noch keinen Export bestätigen.
3. Zur Rechnung zurückkehren und den kompakten sevdesk-Logo-Button einmal anklicken. Die
   Rechnungsseite muss per HTTP-Redirect zurückkehren und den angelegten Job nennen.
4. Vor dem Cronlauf prüfen, dass noch kein Remote-Beleg geschrieben wurde. Danach
   genau einen Worker-/Cronlauf ausführen und Job, Mapping, PDF und Voucher prüfen.
5. Den Kurzexport derselben noch ungemappten Rechnung vor beziehungsweise während
   eines aktiven Jobs erneut auslösen. Die UI muss den bestehenden Export melden;
   ein zweites aktives Item darf nicht entstehen.
6. Nach erfolgreichem Mapping die Rechnungsseite neu laden. Statt Exportaktionen
   muss der Link zum zugeordneten sevdesk-Beleg erscheinen.
7. Separat den Admin-Nur-Ansehen-Modus prüfen. WHMCS dokumentiert dafür keinen
   eigenen Invoice-Output-Hook; fehlt der Button dort, ist das kein Grund für eine
   globale DOM-Injektion.

Operative Einstellungen dürfen nur über `addonmodules.php?module=sevdesk&a=setup` geändert werden. Dieser Pfad nimmt den Advisory Lock, prüft aktive Jobs, deaktiviert während der Änderung die Hooks und validiert Systemversion sowie `ReceiptGuidance`.

Vorhandene Werte in `tbladdonmodules` bleiben erhalten. Auf der allgemeinen WHMCS-Seite lassen sie sich nicht bearbeiten.

Nach dem Upgrade muss die sichere Grundstellung lauten:

```text
export_mode=voucher_only
document_authority=whmcs
oss_profile=blocked
invoice_canary_confirmed=off
small_business_invoice_canary_confirmed=off
e_invoice_mode=off
e_invoice_canary_confirmed=off
customer_number_contact_creation_confirmed=off
runtime_review_required=off (erst nach bestätigter Bestandsprüfung)
```

Die Migration ergänzt Mappingfelder nur additiv. Vollständige Altzuordnungen behalten `document_type=NULL` und `document_authority=NULL`; das Modul nimmt weder Belegtyp noch Hoheit automatisch an.

Die read-only Prüfung fragt die gespeicherte ID am Voucher- und am Invoice-Endpoint ab. Nur genau ein Treffer mit passender Objektart, ID und exakter WHMCS-Dokumentnummer darf einen Typ vorschlagen. Bei neuen Modulbelegen liefert der Rewrite-Marker einen zusätzlichen Nachweis. Bei Belegen des Originalmoduls kann er fehlen; die Oberfläche weist dann auf den schwächeren Legacy-Nachweis hin.

Ein widersprüchlicher Marker oder eine ID, die an beiden Endpoints existiert, blockiert die Zuordnung. Nur eine eindeutige 400- oder 404-Antwort gilt beim jeweiligen Voucher-/Invoice-by-ID-Endpoint als Abwesenheit. Erst eine Adminbestätigung nach einer erneuten Prüfung beider Endpoints ergänzt Typ und Hoheit. WHMCS ist die sichere Vorauswahl, sofern kein früherer RC bereits eine andere Entscheidung im Job eingefroren hat; einem solchen Nachweis darf die Bestätigung nicht widersprechen. sevDesk kann nur für eine lokal bezahlte WHMCS-Rechnung, eine finalisierte Remote-Invoice mit Status 200, 750 oder 1000 und bei vollständigen Proforma-, Theme- und Versandvoraussetzungen gewählt werden. Der Paid-Status wird beim Speichern unter Datenbanksperre erneut geprüft; anschließend speichert das Modul den Hash der finalen PDF. Es entstehen weder ein neues Remote-Dokument noch eine andere Remote-ID.

### Übergangsinventur und Moduswechsel

Vor einer Änderung an Exportmodus, Dokumenthoheit, OSS-, E-Rechnungs-, Rule-11-Invoice-, Rabatt-Canary- oder Kleinunternehmerprofil zeigt das Setup eine rein lokale Bestandsaufnahme. Sie zählt:

- typisierte Voucher und Invoices;
- vollständige Mappings ohne Dokumenttyp;
- `NULL`-/Leer-Mappings und verwaiste Zuordnungen;
- aktive, unklare und alte fehlgeschlagene Exportjobs;
- bezahlte, ungemappte Rechnungen ab `import_after`;
- lokale Hinweise auf mögliche Remote-Dubletten.

Die Bestätigung ist an den aktuellen Fingerprint dieser Inventur gebunden. Ändert sich der Bestand zwischen Seitenaufruf und Speichern, muss die Seite neu geladen und erneut geprüft werden. Das Speichern einer neuen Einstellung erzeugt keinen Exportjob.

Vorhandene Zuordnungen sind unveränderlich. Ein Voucher bleibt Voucher. Eine Invoice behält die im Mapping gespeicherte Dokumenthoheit; bei älteren RC-Zeilen dient der eingefrorene Jobkontext nur so lange als Übergangsnachweis, bis die Hoheit bestätigt wurde. Weder ein Modus- noch ein Hoheitswechsel konvertiert, versendet oder exportiert bestehende Belege erneut.

Alte fehlgeschlagene `export_voucher`-Items behalten ihren ursprünglichen Pfad. Weicht ihre eingefrorene Konfiguration vom aktuellen Setup ab, erscheint `stale_export_context_requeue_required`. Ein normaler Retry ist dann gesperrt. Nur ein eindeutig sicherer Zustand vor dem ersten Dokument-Write kann nach sichtbarer Bestätigung als neuer `export_document`-Job eingereiht werden. Dieser Job ist mailfrei, verwendet den aktuellen Modus, erzeugt keine E-Rechnung und verweist auf das alte Item. Das alte Item bleibt als Nachweis erhalten. Ab `voucher_write_requested` oder einem späteren riskanten Checkpoint gibt es diesen Übergang nicht; dort gilt ausschließlich die Recovery des ursprünglichen Dokumenttyps. Solange so ein riskanter Altzustand ungeklärt ist, sperrt er außerdem jeden neuen Exportjob für dieselbe WHMCS-Rechnung, auch Kurzexport und Einzelimport.

Die Zuordnungsansicht kann höchstens 25 sichtbare Legacy-Mappings gesammelt vorprüfen. Nur eindeutige Treffer mit passendem Rewrite-Marker sind für die Sammelbestätigung zugelassen. Markerlose Belege des Originalmoduls, Cross-Type-Kollisionen und widersprüchliche Treffer bleiben Einzelfälle. Vor jeder Übernahme liest das Modul beide Endpoints erneut.

Eine vollständige Zuordnung lässt sich nicht mehr allein durch einen Admin-Klick für einen Neu-Export freigeben. Das Modul prüft die gespeicherte ID zuerst am Voucher- und am Invoice-by-ID-Endpoint. Nur wenn beide Abfragen die Remote-Abwesenheit jeweils eindeutig mit HTTP 400 oder 404 bestätigen, darf die lokale Zeile entfernt werden. Authentifizierungsfehler, Timeouts, 429, 5xx und jeder vorhandene Remote-Beleg lassen das Mapping stehen. Unvollständige lokale Reservierungen ohne Remote-ID können weiterhin nach der gesonderten Prüfung entfernt werden.

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

### Bestehende sevDesk-Kontakte

| WHMCS-Custom-Field | Remote-Ergebnis | Verhalten |
| --- | --- | --- |
| Setting fehlt/Feld gelöscht oder kein Kundenfeld | nicht abfragen | lokal blockieren; Setup korrigieren, keinerlei Kontakt-Suche oder -Create |
| enthält ID | Kontakt existiert | genau diese ID wiederverwenden; keine Suche, Neuanlage oder Stammdatenänderung |
| enthält ID | 400/404 beziehungsweise kein passendes Objekt | `configured_contact_missing`; manuell klären, kein Fallback |
| leer | genau ein exakter `customerNumber`-Treffer | ID in WHMCS speichern und Kontakt wiederverwenden |
| leer | Treffer ohne verifizierbare `customerNumber`, auch nach Einzelabruf | `contact_search_unverifiable`; weder verknüpfen noch neu anlegen |
| leer | mehrere exakte Treffer | `contact_conflict`; keine automatische Auswahl |
| leer | kein Treffer, Neuanlage nicht bestätigt | `contact_creation_not_confirmed`; vor Pre-Write-Checkpoint und `POST /Contact` blockieren |
| leer | kein Treffer, Neuanlage bestätigt | Kontakt nach Pre-Write-Checkpoint mit interner WHMCS-Client-ID als `customerNumber` anlegen und ID speichern |

Vor dem ersten Canary sind Stichproben aus allen tatsächlich vorkommenden Zuständen zu prüfen. Eine vorhandene Custom-Field-ID gilt als bestätigte historische Zuordnung. Eine abweichende `customerNumber` in sevDesk überschreibt sie nicht. Falsch kopierte IDs müssen deshalb vor der Freigabe korrigiert werden.

Bereits verknüpfte sevDesk-Kontakte werden nicht automatisch mit späteren WHMCS-Stammdatenänderungen aktualisiert.

Eine neue Kontakt-ID wird direkt in WHMCS unter Datenbanksperre eingetragen. Ist inzwischen dieselbe ID vorhanden, ist der Schritt bereits erledigt. Eine andere ID, doppelte Custom-Field-Zeilen oder ein gelöschter Kunde stoppen den Vorgang; das Modul überschreibt die Zuordnung nicht.

Leere Custom-Fields müssen vor der Aktivierung inventarisiert werden. Die exakte Suche findet einen alten Kontakt nur, wenn sevDesk in diesem Mandanten bereits die interne WHMCS-Client-ID als `customerNumber` verwendet. Fehlt die Kundennummer oder folgt sie einem anderen Schema, ist kein sicherer automatischer Abgleich möglich. Solche Kontakte müssen vor der Freigabe manuell über ihre bestätigte ID verknüpft werden.

Erst danach darf `customer_number_contact_creation_confirmed` im Setup aktiviert werden. Ohne diesen Schalter bleiben vorhandene IDs und exakte Treffer nutzbar, aber ein leerer Suchausgang erzeugt keinen Kontakt. Liefert ein API-Treffer keine prüfbare Kundennummer, blockiert das Modul die Verknüpfung und Neuanlage. Auch ein 401/403 in einem optionalen Kontakt-Detailabruf löst den mandantenweiten Zugangsalarm aus.

### Buchhaltung

- Inlandskonto
- EU-B2C-Konto
- nur nach Freigabe: EU-B2B-Warenlieferungs-, Drittland- und Kleinunternehmerkonto
- schriftlich bestätigte Zuordnung von Geschäftsfall, Tax Rule, Steuersatz und Account-Datev

### Dokumentziel und Invoice-Referenzen

- `export_mode`: `voucher_only`, `invoice_for_oss` oder `invoice_only`;
- `document_authority`: `whmcs` oder ausschließlich bei `invoice_only` `sevdesk`;
- `oss_profile`: normalerweise `blocked`; Rule 19 nur als `rule19_digital_services_confirmed` nach Bestätigung, dass alle betroffenen Positionen elektronisch/digital sind;
- bei bestätigtem Rule-19-Profil muss die bisherige Option `eu_b2c_mode=domestic_confirmed` deaktiviert sein; deutsche EU-B2C-Besteuerung und OSS dürfen nicht gleichzeitig konfiguriert werden;
- `invoice_canary_confirmed` erst nach vollständig protokolliertem Testmandanten-Canary;
- im aktuellen Mandanten existierender `SevUser` und eine Standard-`Unity`;
- Hinweis für `invoice_only` akzeptieren: Invoice-Positionen übernehmen kein frei konfiguriertes `accountDatev`.

Invoice-Ziele werden nur für vollständig bezahlte WHMCS-Rechnungen mit auswertbarer effektiver Rechnungsnummer verarbeitet. Ist `invoicenum` in einem Altbestand leer, verwendet das Modul die unveränderliche interne Invoice-ID. Rules 18/20, gemischte oder unklare Leistungsarten bleiben blockiert.

### Native ZUGFeRD-Invoices

ZUGFeRD ist eine Eigenschaft einer normalen sevDesk-`Invoice`, kein dritter Dokumenttyp. Die geschützte Setupseite enthält dafür:

- `e_invoice_mode=off|zugferd_domestic_b2b`;
- die ID eines vorhandenen, nur für Administratoren sichtbaren WHMCS-Kundenfelds vom Typ Tickbox;
- eine im aktuellen sevDesk-Mandanten bestätigte `PaymentMethod`;
- das Aktivierungsdatum;
- `e_invoice_canary_confirmed` als eigenes Gate.

Der Pfad ist nur mit `invoice_only`, sevDesk-Dokumenthoheit, bestandenem Invoice- und ZUGFeRD-Canary sowie PHP XMLReader aktivierbar. Das Kundenfeld muss für den betroffenen Kunden gesetzt sein. Zusätzlich verlangt das Modul eine deutsche Organisation, deutsche Rechnungsdaten, Rule 1, eine Rechnung ab dem Aktivierungsdatum und vollständige Referenzen für `SevUser`, `Unity`, `PaymentMethod`, Kontakt und Land.

Der bestehende sevDesk-Kontakt muss eine Käuferreferenz, genau eine passende Haupt-E-Mail, eine vollständige deutsche Rechnungsadresse und `governmentAgency=false` besitzen. Das Modul trägt fehlende Daten nicht automatisch nach. Sobald das Kunden-Opt-in greift, führt ein fehlendes oder abweichendes Pflichtfeld zu einem Prüffall. Es gibt keinen stillen Rückfall auf eine normale PDF-Invoice.

Das Modul übergibt `propertyIsEInvoice=true`, die strukturierte Empfängeradresse, die `PaymentMethod` und `takeDefaultAddress=false`. Nach dem Create liest es Invoice, Kontakt, Zahlungsmethode und Adresse zurück. Liefert sevDesk das E-Invoice-Flag mit, muss es wahr sein. Fehlt das Feld, darf erst ein gültiges Ergebnis von `getXml` den Ablauf fortsetzen. Ein ausdrücklich falscher Wert blockiert auch dann, wenn XML abrufbar wäre. Das XML wird auf Größe, UTF-8, CII-Wurzelelement und Wohlgeformtheit ohne DTD oder externe Entitäten geprüft; anschließend friert das Modul den SHA-256-Hash ein. Die lokale Strukturprüfung ersetzt die externe EN-16931-Prüfung nicht.

Das Mapping bleibt `document_type=invoice` und erhält additiv `is_e_invoice` sowie `xml_sha256`. `pdf_sha256` gehört weiterhin zur ausgelieferten sevDesk-PDF. Namen und Adressen werden nicht im Job gespeichert; dort liegen nur die notwendigen IDs und ein PII-freier Adresshash. PDF und XML werden weder dauerhaft in WHMCS gespeichert noch geloggt.

Der Kundenbereich liefert die geprüfte ZUGFeRD-PDF. Beim sevDesk-Versand setzt das Modul `sendXml=false`, weil der strukturierte Bestandteil bereits in der PDF steckt. Der WHMCS-Vorlagenkanal bleibt auf WHMCS 8.13.4 gesperrt, da diese Version den Binäranhang aus `EmailPreSend` nicht übernimmt. Rule 19 bleibt immer eine normale Invoice. Rules 18/20, B2G, XRechnung und historische E-Rechnungs-Backfills sind nicht freigegeben.

### sevDesk-Dokumenthoheit und Versand

Zusätzlich erforderlich:

- WHMCS-Proforma aktiviert;
- `export_mode=invoice_only`;
- installiertes Adapter-Manifest; für Twenty-One liegt eine Referenzintegration bei;
- ausdrückliche Bestätigung des Theme-Eingriffs;
- Versandkanal `sevdesk`. Der gespeicherte Wert `whmcs_template` bleibt migrationsverträglich erhalten, ist unter WHMCS 8.13 aber nicht ausführbar.

Für `sevdesk` werden Betreff und Text ausschließlich mit `{invoice_number}` und `{company_name}` als erlaubten Platzhaltern gepflegt. Eine bestehende Auswahl `whmcs_template` wird nicht automatisch geändert: Der Health Check meldet den Konflikt, und das Setup muss bewusst auf `sevdesk` gespeichert werden. Das Modul legt keine Mailvorlage an und verändert keine vorhandene.

Backfills und historische Bulk-Jobs versenden nie automatisch.

Nach Installation und Upgrade bleibt EU-B2B/Rule 3 standardmäßig gesperrt. Die Freigabe im Setup ist nur möglich, wenn der gesamte betroffene Geschäftsfall fachlich als innergemeinschaftliche Warenlieferung bestätigt wurde.

Für Hosting, Domains, Lizenzen und andere Dienstleistungen bleibt Rule 3 gesperrt. Entsprechende Rechnungen landen in der manuellen Prüfung.

Numerische Account-Datev-IDs müssen aus dem aktuellen Mandanten stammen und werden über `ReceiptGuidance` geprüft. IDs aus alten Dumps oder einem Testmandanten werden nicht übernommen.

## Invoice-API-Canary

Der Canary wird in einem getrennten sevDesk-Testmandanten mit synthetischen Daten durchgeführt. Er muss vor dem Setzen von `invoice_canary_confirmed` mindestens folgende Punkte beweisen:

1. Eine normale `RE` lässt sich im Draft-Status 100 mit Rule 19, kleingeschriebenem `deliveryAddressCountry`, exakt passender `StaticCountry`-Referenz, tatsächlichem WHMCS-Steuersatz und ohne `accountDatev` erstellen. Der Readback erfolgt mit `embed=addressCountry`.
2. Die effektive WHMCS-Rechnungsnummer bleibt als `invoiceNumber` unverändert; bei einem belegten Legacy-Leerwert ist das die interne Invoice-ID.
3. `[WHMCS-INVOICE:<id>]` bleibt lesbar und erlaubt einen eindeutigen Remote-Abgleich.
4. `SevUser`, `Unity`, Kontakt, Positionen und Adressen sind in genau der verwendeten Form gültig. Der normale Invoice-Request enthält die vollständige synthetische WHMCS-Rechnungsadresse und `takeDefaultAddress=false`; der vorhandene Testkontakt darf dafür keine Stammdatenänderung erhalten.
5. `sendBy`, `sendViaEmail`, `getPdf` und `/Invoice/{id}/bookAmount` verhalten sich wie vom Worker erwartet.
6. Die finalisierte PDF bleibt byte-stabil beziehungsweise jede zulässige Änderung ist verstanden und mit der Hashprüfung vereinbar.
7. Voucher- und Invoice-IDs können im lokalen Unique-Mapping nicht kollidieren.

Das vollständige Protokoll mit Datum, Mandant, synthetischen WHMCS-/Remote-IDs, Payloadvariante, Resultat und Prüfer bleibt außerhalb von Git. Im Repository wird nur das pseudonymisierte Gate-Ergebnis festgehalten. Token, E-Mail-Adressen, PDFs und Rohpayloads werden dort nicht abgelegt.

Scheitert die ID-Eindeutigkeit, Rule 19 oder der Markerabgleich, bleiben mindestens `invoice_for_oss` und alle davon abhängigen Rolloutschritte gesperrt. Es darf nicht durch manuelles Setzen der Checkbox „freigegeben“ werden; zuerst ist eine neue Architekturentscheidung nötig.

Teilstand vom 20.07.2026: Eine synthetische Rule-19-Invoice wurde erstellt und über den Recovery-Pfad ohne zweiten Create vollständig abgeschlossen. Nummer, Marker, Kontakt, `SevUser`, Rule, Steuersatz, Positionen, fehlendes `accountDatev`, eingebettetes Rechnungsland und PDF stimmten mit dem eingefrorenen Snapshot überein. `sendBy` und `sendViaEmail` wurden technisch ausgeführt; der direkte sevDesk-Versand kam an. Die geprüften Remote-IDs hatten beim jeweils anderen Dokumenttyp keinen Treffer. Invoice-`bookAmount` wurde noch nicht ausgeführt.

Teilstand vom 24.07.2026: Ein normaler Draft an einem vorhandenen Kontakt ohne sevDesk-`ContactAddress` wurde mit `takeDefaultAddress=true` zwar erstellt, enthielt remote aber keine Rechnungsadresse; `sendBy/VPDF` lehnte ihn mit HTTP 422 ab. Der korrigierte Vertrag überträgt die WHMCS-Rechnungsadresse deshalb immer direkt mit `takeDefaultAddress=false` und prüft ihren PII-freien Hash vor jedem weiteren Write. Der betroffene Altentwurf wird nicht automatisch verändert oder geöffnet. Der externe Invoice-Canary muss mit einem neuen synthetischen Beleg und unverändertem Kontakt erneut vollständig bestanden werden.

## Rule-11-Invoice-Canary

Dieses Gate ist vom allgemeinen Invoice-Canary und vom Rabatt-Canary getrennt. Es gilt nur für Rule-11-Invoices; Rule-11-Voucher verwenden weiterhin ihr konfiguriertes und durch Guidance geprüftes `accountDatev`.

Der Anlass ist ein reproduzierter Livebefund: sevDesk akzeptierte eine normale Rule-11-Invoice zunächst als Draft. `sendBy` scheiterte danach mit HTTP 422 und Code 7100, weil `KLEINUNTERNEHMER_P19` für das automatisch gewählte Konto beziehungsweise dessen Scope nicht zulässig war. Das Modul kann dieses Konto bei einer Invoice nicht über `InvoicePos.accountDatev` vorgeben.

[sevDesk empfiehlt beim Wechsel der Besteuerungsart](https://hilfe.sevdesk.de/de/articles/9382213-einstellung-zu-buchhaltung-steuer), offene Vorgänge aus dem Kleinunternehmerzeitraum vorher abzuschließen. Sind sie im aktuellen Mandanten nicht mehr mit Rule 11 finalisierbar, bleibt der Nachlauf gesperrt, bis sevDesk oder die Buchhaltung einen belastbaren Weg für diesen Bestand bestätigt hat. Das Modul ersetzt diese Klärung nicht durch Rule 1 oder ein geratenes Konto.

Vor `small_business_invoice_canary_confirmed=on` sind deshalb zwei Nachweise nötig:

1. Die frisch gelesene `ReceiptGuidance` enthält mindestens ein numerisches `REVENUE`-Konto, das Rule 11 mit 0 % erlaubt.
2. Eine neue synthetische Kleinunternehmer-Invoice ohne Rabatt besteht Create, vollständigen Readback, `sendBy`, `getPdf` und einen Recovery-Lauf ohne zweiten Create.

Das Setup prüft Punkt 1 beim Speichern erneut. Dry-Run, Remote-Health-Check und Worker prüfen dieselbe Fähigkeit vor Create. Fehlt sie trotz gesetztem Wert, lautet der Fehler `invoice_rule11_tenant_scope_unsupported`. Ist der Canary nicht bestätigt, lautet er `small_business_invoice_canary_not_confirmed`. Ein Item nach einem möglichen Write bleibt ohne weiteren Remote-Aufruf `ambiguous`.

Der Rabatt-Canary darf erst danach bestätigt werden. Beide Checkboxen sind Teil der geschützten Übergangsinventur; aktive oder ungeklärte Exportjobs sperren eine Änderung.

## ZUGFeRD-Canary

Der ZUGFeRD-Canary ist vom normalen Invoice-Canary getrennt. Er verwendet einen synthetischen deutschen B2B-Kunden und muss vor `e_invoice_canary_confirmed` belegen:

1. `propertyIsEInvoice=true`, strukturierte Adresse, `PaymentMethod`, `SevUser`, `Unity`, Kontakt und Rule 1 werden von sevDesk angenommen. Fehlt das Flag beim Readback, muss `getXml` den E-Rechnungspfad eindeutig bestätigen; ein ausdrücklich falsches Flag ist nicht zulässig.
2. `getXml` liefert wohlgeformtes CII-XML. Der Inhalt besteht zusätzlich eine externe EN-16931-Prüfung.
3. Die von sevDesk erzeugte ZUGFeRD-PDF lässt sich über `getPdf` stabil laden; PDF- und XML-Hashes bleiben im getesteten Lifecycle nachvollziehbar.
4. `sendBy` öffnet die Invoice ohne Vertragsabweichung.
5. `sendViaEmail` akzeptiert `sendXml=false` und versendet keine lose XML-Datei zusätzlich zur PDF.
6. Der direkte sevDesk-Versand liefert die finale Rechnung; der authentifizierte Kundendownload gibt dieselben geprüften PDF-Bytes nur an den Rechnungseigentümer aus.
7. Recovery nach Create, XML-/PDF-Abruf, Öffnung und Versand führt keinen zweiten Remote-Write aus.

Das Protokoll bleibt wie beim Invoice-Canary außerhalb von Git. Im Repository stehen weder Testadressen noch PDFs, XML-Dateien, IDs oder Rohpayloads. Ein gesetzter Setup-Schalter ersetzt den Canary nicht.

Teilstand vom 23.07.2026: Mit synthetischen deutschen B2B-Konten wurden Create, Readback, `getXml`, `getPdf`, `sendBy`, `sendViaEmail(sendXml=false)` und ein erneuter idempotenter Worker-Lauf geprüft. [Mustangproject 2.24.0](https://github.com/ZUGFeRD/mustangproject/releases/tag/core-2.24.0) meldete PDF/A und eingebettetes XML als gültig; das extrahierte XML war bytegleich zur `getXml`-Antwort. Eigene Kundensitzungen erhielten die erwarteten Download-Bytes, ein fremder Kunde und ein delegierter Benutzer ohne `invoices`-Recht erhielten keinen Dokumentzugriff. sevDesk ließ `propertyIsEInvoice` beim Readback aus. Außerdem lieferte die kombinierte `CommunicationWay`-Abfrage mit Kontakt, Typ und Hauptkennzeichen keine Zeile, während die kontaktgebundene Liste den korrekten Haupt-Mail-Eintrag enthielt. Beide Fälle sind im Modul jetzt explizit und fail-closed behandelt. Der WHMCS-Wiederholungsversand verbrauchte den vorgesehenen Anhangskontext, lieferte im Postfach aber erneut die WHMCS-Core-PDF. Damit ist bestätigt, dass WHMCS 8.13 den Binäranhang aus `EmailPreSend` ignoriert. Dieser Kanal bleibt gesperrt; `e_invoice_canary_confirmed` bleibt außerdem bis zu den übrigen Gates aus.

## Twenty-One-Adapter installieren

1. `modules/addons/sevdesk/theme-adapters/twenty-one/sevdesk-invoice-authority.tpl` in das Wurzelverzeichnis des aktiven Twenty-One-Child-Themes kopieren.
2. `modules/addons/sevdesk/theme-adapters/twenty-one/manifest.json` ebenfalls dorthin kopieren und die Kopie exakt `sevdesk-invoice-authority.json` nennen. Die Setupseite akzeptiert nur dieses Manifest im aktiven Theme und die darin benannte Partial-Datei.
3. Die Partial am Anfang von `viewinvoice.tpl` einbinden.
4. Mit einem synthetischen Kundenkonto die Zustände `proforma`, `pending`, `ready` und `failure` prüfen.
5. Bei `ready` sicherstellen, dass kein normaler sichtbarer WHMCS-PDF-Link verbleibt und der sevDesk-Download nur dem Eigentümer mit WHMCS-Benutzerrecht `invoices` funktioniert. Fremde Clients und delegierte Benutzer ohne dieses Recht müssen abgewiesen werden.
6. Erst wenn die Setupseite das installierte Manifest erkennt, `theme_adapter_confirmed` bestätigen.

Custom Themes implementieren denselben Vertrag über `sevdeskDocument.authority`, `.state`, `.invoiceNumber` und `.downloadUrl`. Ihr Manifest nennt `module: "sevdesk"`, `contractVersion: 1`, das aktive Theme oder `*`, exakt diese vier Vertragsfelder und eine sichere `.tpl`-Datei im Theme-Wurzelverzeichnis. Das Manifest bestätigt den installierten Adaptervertrag, kopiert aber keine Theme-Datei automatisch. Der Custom-Adapter der Zielumgebung wurde mit Ready-Invoices geprüft; der normale Core-PDF-Link war dort nicht sichtbar. Ein direkt erratener WHMCS-Core-PDF-Endpunkt kann ohne Core-Änderung weiter existieren; die Betriebszusage umfasst normale Kundenoberfläche und E-Mail-Auslieferung.

## Health Check vor Writes

Alle Punkte müssen grün sein:

- PHP 8.3 und WHMCS 8.13.4 erkannt;
- interne Modullaufzeit ist aktiviert; bei Neu-/Invoice-Rollout und einmalig
  nach dem signaturlosen 2.0→2.1-Upgrade bleibt automatische Synchronisation
  bis zur ausdrücklichen Health-/Canary-Freigabe deaktiviert;
- Datenbankschema vorhanden und Migration vollständig;
- `mod_sevdesk` lesbar, Unique-Constraints intakt;
- additive Dokumentfelder vorhanden; untypisierte Legacy-Mappings als Reviewfälle sichtbar;
- keine ungeklärten Schema- oder Mappingkonflikte;
- API-Token akzeptiert;
- sevDesk-Systemversion ist Update 2.0;
- benötigte Account-Datev-IDs existieren;
- freigegebene Tax-Rule-/Rate-/Voucher-Kombinationen sind laut Guidance zulässig;
- bei Invoice-Modus: Canary bestätigt, Modus/Hoheit gültig, SevUser und Unity lesbar und Rule freigegeben;
- bei Rule 19: Digitalprofil ausdrücklich bestätigt; Rules 18/20 bleiben gesperrt;
- bei ZUGFeRD: `invoice_only`, sevDesk-Hoheit, PHP XMLReader, eigener Canary, gültiges Admin-Tickbox-Feld, Aktivierungsdatum und PaymentMethod bestätigt;
- bei sevDesk-Hoheit: Proforma aktiv, Adapter-Manifest/Bestätigung vorhanden und Versandkanal vollständig konfiguriert;
- Client-Custom-Field existiert;
- im Voucher-Pfad lässt sich die WHMCS-PDF erzeugen; im Invoice-Pfad ist der getrennte sevDesk-PDF-Vertrag bestätigt;
- Cron läuft regelmäßig und verarbeitet einen Diagnosejob;
- keine globale Sperre durch 401/403 oder Migration aktiv.

Bewusst nicht bestätigte optionale Profile für Drittland, AddFunds oder einen
nicht als Kleinunternehmer geführten Mandanten werden als Warnung angezeigt und
bleiben fail-closed. Ist die Kleinunternehmerregel global aktiv, gilt ihr Profil
als erforderlich. Ein Fehler in einem bestätigten oder für den geplanten Export
benötigten Profil blockiert Remote-Writes weiterhin.

Für zeitlich begrenzte Kleinunternehmerzeiträume wird im Setup ein Enddatum gesetzt. `31.12.2025` schließt Rechnungen mit Datum 31. Dezember 2025 ein und lässt Rechnungen ab 1. Januar 2026 wieder durch die übrige Steuerklassifikation laufen. Bleibt das Feld leer, gilt der aktivierte Schalter wie bisher unbegrenzt; der Health Check weist darauf hin. Ein ungültiger Altwert erscheint als Fehler und muss vor dem Nachlauf korrigiert werden.

Ein fehlerhafter Health Check blockiert Remote-Writes, wenn der Fehler die Datenkonsistenz, Authentifizierung oder Steuerlogik betrifft.

### Runner-Smoke-Test bei leerer Queue

Fehlt der Runner-Heartbeat, obwohl das Modul aktiv ist, kann der reale CLI-Pfad
kontrolliert mit leerer Queue geprüft werden. Vorher müssen Job- und Itemanzahl
sowie insbesondere `pending`, `running` und `retry_wait` null sein. Andernfalls
würde der Aufruf echte Arbeit verarbeiten und braucht eine eigene Freigabe.

```bash
<PHP_83_BIN> -q <WHMCS_ROOT>/modules/addons/sevdesk/cli/worker.php 1 5
```

Bei leerer Queue enthält das bereinigte Ergebnis `processed: 0` sowie `locked: false`.
Der Aufruf führt die additive, idempotente Schemaprüfung aus und schreibt
`runner_last_seen`; er ist deshalb kein strikt read-only Test. Ohne claimbares
Item konstruiert der Runner keine sevdesk-Remote-Services und sendet keinen
API-Request. Mapping-, Job- und Itemzahlen müssen danach unverändert sein.

Bleibt ein `pending`-Item trotz abgelaufenem `available_at` liegen, zuerst
`available_at`, `CURRENT_TIMESTAMP` und `@@session.time_zone` vergleichen.
Zeitwerte nicht von Hand korrigieren. In `2.1.0-rc.2` verwendet der Runner
für Claim, Lease und Retry durchgehend die Datenbankzeit; ältere Prozesse mit
PHP-UTC konnten von PHP-FPM in Ortszeit angelegte Jobs bis zu zwei Stunden zu
spät sehen.

## Normaler Bulk-Ablauf

1. Im Adminbereich Zeitraum und Filter wählen.
2. Vorschau erzeugen.
3. Die Gruppen prüfen: eligible, bereits gemappt, skipped und manuell blockiert.
4. Zieltyp, Hoheit, Tax Rule und Delivery-Flag prüfen. Rule-19-Invoices dürfen nur aus der bestätigten digitalen Gruppe stammen.
5. Bei unerwartet großer oder fachlich gemischter Auswahl nicht starten, sondern Filter korrigieren.
6. Job anlegen. Danach darf der Browser geschlossen werden.
7. Fortschritt über Jobseite beobachten.
8. 401/403 und auffällige Serienfehler sofort untersuchen; einzelne 422 stoppen den restlichen Job nicht.
9. Nach Abschluss alle `permanent_failed`- und `ambiguous`-Items bearbeiten. Die UI kennzeichnet anhand des Fehlercodes, welche davon manuell geprüft werden müssen.
10. Endzahlen, Dokumenttypen, Ready-/Delivery-Zustand mit WHMCS und sevDesk abstimmen.

Ein Bulk- oder historischer Job löst nie automatisch Invoice-Versand aus, auch wenn `document_authority=sevdesk` konfiguriert ist. Eine Zustellung braucht einen separat bestätigten Vorgang. Historische Jobs erzeugen außerdem keine E-Rechnung.

Der bestätigte Altbestand wird als eigener Jobtyp `historical_backfill` eingereiht. Vor jedem Invoice-Create sucht der Worker read-only nach der exakten effektiven Rechnungsnummer. Danach prüft er mögliche Invoices mit demselben Datum, Kontakt, Währung und Betrag sowie Voucher-Kandidaten über Nummer, Datum, Kontakt, Betrag und `[WHMCS-INVOICE:<id>]`. Jeder mögliche Treffer, eine volle Suchseite oder ein nicht sicher abschließbarer Read blockiert die Neuanlage. Der Dublettenschutz setzt selbst kein Mapping; ein Kandidat muss separat geklärt werden.

Ein Cancel verhindert neue Claims, bricht laufende API-Operationen aber nicht gewaltsam ab. Deren Ergebnis wird gespeichert oder per Recovery geklärt. Cancel, Claim und Lease-Recovery sperren dabei stets Job vor Item und vergleichen die aktuelle Lease erneut. Liefert ein bereits laufendes Item anschließend nur einen sicheren Retry zurück, wird es stattdessen `cancelled` und gibt seinen Dedupe-Key frei. Liegt am fortzusetzenden Checkpoint ein möglicher oder bereits bestätigter Remote-Effekt vor, wird das Item `ambiguous` und behält den Dedupe-Key; so bleibt kein nicht mehr claimbares `retry_wait` zurück.

Der Buchungsassistent durchsucht positive WHMCS-Transaktionen im gewählten Transaktionszeitraum seitenweise. Die Vorschau muss bis zur letzten Seite geprüft werden. Neben Rechnungen mit Status `Paid` können auch teilbezahlte Rechnungen mit Status `Unpaid` erscheinen.

Bereits vollständig in sevDesk bezahlte Voucher oder Invoices werden nicht erneut als Kandidaten angeboten. Andere blockierte Fälle bleiben mit ihrem Prüfgrund sichtbar.

Vor dem Jobstart Mappingtyp, Referenz, Betrag und offenen Voucher-/Invoice-Stand prüfen.

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
- `succeeded`: gewähltes Remote-Dokument und typisiertes Mapping bestätigt; bei Zustellitems der konfigurierte Übergabeschritt nachgewiesen
- `skipped`: kein Write nötig oder bereits anderweitig eingeplant/gemappt
- `permanent_failed`: ohne fachliche/technische Korrektur nicht wiederholen
- `ambiguous`: möglicher Remote-Write; zuerst abgleichen
- `cancelled`: vor Ausführung abgebrochen

„Manuell prüfen“ ist eine UI-Aktion oder ein Fehlercode auf `permanent_failed` oder `ambiguous`, kein eigener Status.

## Buchhaltungsnachlauf

Für den Nachlauf zählt nur der aktuelle Datenbestand. Deshalb beginnt er mit einer Live-Inventur.

### 1. Klassifizieren

- vollständige Mappings mit existierender WHMCS-Invoice;
- davon getrennt: typisierte Voucher-/Invoice-Mappings und vollständige Legacy-Mappings mit `document_type=NULL`;
- verwaiste Mappings;
- `NULL`-Mappings;
- Invoice-Mappings ohne `document_ready_at` nach erwartetem Abschluss;
- offene oder unklare Delivery-/WHMCS-Mailcheckpoints;
- PDF-Hashabweichungen und Client-Downloadfehler;
- ZUGFeRD-Mappings mit fehlendem oder abweichendem XML-Hash;
- ungemappte, exportfähige Paid-Invoices;
- mit dokumentiertem Grund übersprungene Unpaid-/Datumsfälle;
- bestätigte Rule-19-Invoice-Kandidaten sowie Rule-18/20-, gemischte, Nullsummen-, Fremdwährungs- und sonstige manuelle Fälle;
- reine WHMCS-Sammelzahlungsbelege und die exakt mit ihnen verknüpften Originalrechnungen;
- sonstige Guthabenfälle, unvollständige Sammelzahlungsketten und widersprüchliche Beträge;
- eindeutig zugeordnete `PromoHosting`-Rabatte sowie alle übrigen negativen Positionen.

Ein vollständig stimmiger Sammelzahlungsbeleg ist kein eigener Umsatz und wird übersprungen. Bei den Originalrechnungen gilt centgenau `subtotal + tax + tax2 = total + credit`. Der Dokumentbrutto beträgt `total + credit`; `total` ist der direkt gezahlte Anteil und muss der Summe positiver `tblaccounts`-Zahlungen entsprechen. Bei Vollguthaben steht `total = 0`. Nach PDF- und Kontaktverarbeitung prüft der Worker den vollständigen Zahlungsgraphen ein letztes Mal, bevor er den Dokument-Write vormerkt. Die gemeinsame Banktransaktion wird nicht automatisch auf mehrere sevDesk-Belege verteilt und bleibt eine manuelle Zahlungszuordnung.

Zusätzlich friert der Worker den vollständigen lokalen Rechnungsvertrag als SHA-256 ein. Eine Änderung an Datum, Nummer, Steuerwerten, Positionen oder steuerlich relevanten Kundendaten endet vor dem ersten möglichen Remote-Write mit `whmcs_invoice_contract_changed`. Nach einem bereits möglichen Write wird daraus `whmcs_invoice_contract_changed_after_write`; fehlt dort der ursprüngliche Hash, lautet der Fehler `whmcs_invoice_contract_snapshot_missing_after_write`. Diese Fälle dürfen nicht normal wiederholt werden.

Die sevDesk-Kontakt-ID wird separat gespeichert. `whmcs_contact_link_changed` stoppt einen Beleg, wenn das WHMCS-Kundenfeld während des sicheren Vorlaufs von A zu B wechselt. Nach einem möglichen Dokument-Write lautet der Code `whmcs_contact_link_changed_after_write`; die Recovery verwendet dann ausschließlich die zuvor gespeicherte ID. `contact_pre_write_guard_failed` bedeutet, dass schon die Kontaktneuanlage vor ihrem ersten POST gestoppt wurde.

Der Paid-Hook verwendet dafür dieselbe vollständige lokale Prüfung wie der Worker. Nur `Invoice`-Positionen zu finden genügt nicht. Ein alter Elternbeleg wird nur ignoriert, wenn sein Status ausdrücklich `Unpaid` oder `Cancelled` ist und er weder Zahlung, Guthaben, Mapping noch Rückerstattung trägt. `Refunded`, `Collections`, `Draft` und unbekannte Zustände bleiben auch ohne verbliebene Transaktionszeile Prüffälle. Teilt ein Parent auch nur eines seiner Ziele mit einem zweiten aktiven Parent, bleibt der gesamte betroffene Graph gesperrt.

Die Parent-ID aus dieser Hook-Prüfung ist Teil des Zieljobs. Ein späterer Wechsel zu einem anderen Sammelbeleg oder zu einer gewöhnlichen Rechnung wird nicht übernommen. Wartet bereits ein Hybridjob aus `InvoiceCreated`, ergänzt der Paid-Hook die Parent-ID unter Lock. Eine widersprüchliche zweite ID wird nur als Konflikt markiert und nie über die erste geschrieben. Rückerstattungen sperren die Kette auch dann, wenn WHMCS die Refund-Zeile unter einer anderen `invoiceid` führt und nur `refundid` auf die ursprüngliche Zahlung verweist.

Andere Guthabenfälle bleiben aus Bulk-Jobs ausgeschlossen. Beim Voucher-Einzelexport zeigt das Modul Brutto, Guthaben und Zahlbetrag an und verlangt weiterhin die Bestätigung „voller Rechnungsbrutto-Voucher; Guthaben separat klären“. Ohne diese Bestätigung gibt es keinen Write. Eine proportionale Umsatzkürzung ist nicht vorgesehen.

Ein `PromoHosting`-Rabatt ist nur in `invoice_only` mit Rule 11, 0 %, EUR und eigenem Rabatt-Canary zulässig. Der Canary muss Create, den Marker `[WHMCS-DISCOUNT:<sha256>]`, `sumDiscounts`, Positionen, Gesamtsumme, PDF und eine rein lesende Recovery prüfen. Backfills bleiben auch für diesen Fall mailfrei. Andere negative Positionen werden nicht automatisch übertragen.

Ist das ZUGFeRD-Kundenprofil gesetzt und enthält die Rechnung angewendetes Guthaben, bleibt sie blockiert. Das gilt auch bei einer exakt erkannten Sammelzahlung. Es gibt keinen stillen Rückfall auf eine normale PDF-Invoice.

Ungemappte Invoices dürfen nicht pauschal in einen Job übernommen werden. Maßgeblich ist die aktuelle Live-Inventur. Die Übergangsinventur im Setup liefert dafür Zähler und Sperrhinweise; die fachliche Auswahl erfolgt anschließend in der gemeinsamen Vorschau.

### 2. Altzustände bereinigen

Alle `sevdesk_id=NULL`-Mappings werden einzeln geprüft. Vollständige untypisierte Legacy-Mappings werden separat read-only klassifiziert und nur nach Adminbestätigung ergänzt. Die Sammelbestätigung ist ausschließlich für eindeutige Markertreffer vorgesehen. Verwaiste Mappings werden nicht automatisch gelöscht.

### 3. Dry-Run

Der Dry-Run lädt die WHMCS-Daten, prüft Eligibility, klassifiziert Steuerfall und unveränderliches Dokumentziel und gleicht den Voucher-Pfad mit Guidance beziehungsweise den Invoice-Pfad mit seinen Fähigkeiten ab. Er schreibt weder Kontakte, PDFs, XML, Voucher noch Invoices. Der Report nennt Zieltyp, Hoheit, Rule, Delivery und Grund. In der historischen Vorschau bleibt der E-Rechnungsmodus aus; eingereihte Invoice-Backfills speichern `is_e_invoice=false`.

### 4. Canary

Zunächst nur wenige repräsentative WHMCS-Rechnungen pro freigegebener Steuerklasse exportieren. Für Voucher prüft die Buchhaltung WHMCS-PDF, Konto, Rule, Rate und Summen. Für Invoice prüft sie zusätzlich unveränderte Nummer, `RE`, SevUser, Unity, Landsteuersatz, fehlendes benutzerdefiniertes `accountDatev`, Öffnung/Versand und die finale sevDesk-PDF. Der allgemeine Invoice-API-Canary muss bereits vorher bestanden sein.

Enthält der Bestand den freigegebenen `PromoHosting`-Fall, folgt vor dem Bulk ein eigener synthetischer Rabatt-Canary. Solange dessen Setup-Gate aus ist, dürfen betroffene Rechnungen nur als blockierte Vorschaufälle erscheinen.

### 5. Abschnittsweise exportieren

Nach der Freigabe Jobs monats- oder quartalsweise starten. Der erste Lauf darf nicht den gesamten historischen Zeitraum umfassen. Nach jedem Abschnitt:

- Job-Endzustände prüfen;
- fehlgeschlagene/manuelle Items abarbeiten;
- Mappinganzahl und Remote-Belege vergleichen;
- Summenabstimmung dokumentieren;
- erst dann den nächsten Abschnitt starten.

## Recovery bei `sevdesk_id = NULL`

Eine leere Remote-ID bedeutet „Ausgang unbekannt“, nicht „nicht importiert“.

Dasselbe gilt für leere oder nur aus Leerzeichen bestehende Legacy-Werte. Sie gelten nirgends als vollständiges Mapping und dürfen weder einen leeren sevDesk-Link noch einen scheinbaren Exporterfolg erzeugen.

1. Weitere Verarbeitung dieser Invoice pausieren.
2. Jobhistorie, Zeitstempel und bereinigte Fehlermeldung prüfen.
3. In sevDesk anhand der stabilen WHMCS-Referenz und des gefrorenen Dokumenttyps suchen. Fehlt der Legacy-Typ, Voucher und Invoice getrennt nur lesend prüfen.
4. Kandidaten anhand von Rechnungsnummer, Datum, Betrag und Kontakt vergleichen.
5. Bei genau einem sicheren Treffer Mapping über die Recovery-Funktion ergänzen.
6. Nur wenn fachlich und technisch nachweisbar kein Remote-Dokument erstellt wurde, darf ein Administrator nach Vier-Augen-Prüfung über das weitere Vorgehen entscheiden. Eine leere Suche nach einem möglichen Write ist für sich kein Beweis.
7. Bei mehreren oder unsicheren Kandidaten `ambiguous` beibehalten. Der Dedupe-Key bleibt gesetzt; keine Remote- oder Mappingzeile löschen.

Direkte SQL-Änderungen sind nur im Notfall zulässig. Vor und nach der Änderung muss jeweils ein Backup erstellt werden. Vier-Augen-Prüfung und dokumentierte IDs sind ebenfalls Pflicht.

Für unbekannte Kontakt- oder Korrektur-POSTs gilt außerdem: Die UI-Aktion **Abgleichen** liest nur Daten. Bleibt die Suche ohne Treffer, ist damit nicht bewiesen, dass sevDesk nichts angelegt hat. Das Item bleibt `ambiguous`. Ein neuer Create darf nur in einem separat geprüften, neuen Vorgang erfolgen.
`correction_mapping_persist_failed` bedeutet, dass der Korrektur-Voucher beziehungsweise seine Remote-ID bereits bestätigt sein kann, aber die lokale CAS-Speicherung nicht abgeschlossen wurde. Auch hier ausschließlich per Refund-Marker lesen und dieselbe ID idempotent ergänzen; niemals einen zweiten Korrektur-Voucher erzeugen oder eine abweichende gespeicherte ID überschreiben.
Dasselbe gilt, wenn die lesende Kontaktsuche nach ihren begrenzten sicheren
Retries weiterhin mit 4xx/5xx oder einem Transportfehler endet: Der frühere
Kontakt-POST bleibt ungeklärt, das Item `ambiguous` und der Dedupe-Key reserviert.

Ein beschädigter Booking-/Korrektur-Snapshot oder ein früher fachlicher Preflight-Fehler wird nach `booking_write_requested` beziehungsweise einem Korrektur-Write-Checkpoint ebenfalls nicht zu einem frischen permanenten Fehler zurückgestuft. Er bleibt mit demselben Checkpoint und derselben Remote-ID `ambiguous`. Änderungen am `import_after`-Stichtag wirken nur vor dem ersten Dokument-Write; eine laufende Voucher-/Invoice-Recovery wird dadurch nicht übersprungen.

## Voucher-Recovery

Ab `voucher_write_requested` gelten ausschließlich die im Job eingefrorenen Werte
für Kontakt, `taxRule` und `accountDatev`. Fehlt einer dieser Nachweise, endet die
Recovery mit `voucher_verification_snapshot_missing` beziehungsweise
`voucher_reconciliation_context_missing`; aktuelle Setupwerte sind kein Ersatz.

Ohne bekannte Remote-ID sucht das Modul den exakten WHMCS-Marker seitenweise.
Anschließend liest es immer den konkreten Voucher und alle `VoucherPos` erneut.
Erst ein vollständig passender Header samt Positionen darf das typisierte Mapping
wiederherstellen. Mehrere Treffer, abweichende Rule-/Kontowerte oder eine volle
1.000-Kandidaten-Grenze bleiben `ambiguous`. In keinem dieser Fälle wird ein
zweiter Voucher erstellt.

## Invoice-Recovery

Der Checkpoint bestimmt die einzig zulässige Aktion:

- bei `invoice_payment_pending`: kein sevDesk-Dokument wurde geschrieben. Im Hybridmodus aktuellen WHMCS-Paid-Status neu lesen; `invoice_payment_event_followup` bedeutet, dass ein während des Abschlusses beobachtetes `InvoicePaid`-Ereignis denselben Dedupe-Besitzer einmal erneut prüfen lässt;
- nach `invoice_write_requested`: ausschließlich Invoice anhand Marker, Nummer, Kontakt, Währung, Rule, Betrag und Positionen suchen; kein zweiter Create;
- nach `invoice_created` vor `mapping_persisted`: bekannte Invoice-ID lesen, exakt prüfen und erst dann Typ/ID mappen; bei ZUGFeRD zusätzlich `getXml` prüfen und den ersten XML-Hash einfrieren;
- nach `invoice_xml_verified`: bekannte Invoice-ID, PaymentMethod, Kontakt, Adresshash und gespeicherten XML-Hash erneut lesen. Ein vorhandenes E-Rechnungsflag muss wahr sein; ein fehlendes Flag ist nur zusammen mit gültigem, unverändertem XML zulässig. Fehlendes oder abweichendes XML bleibt `ambiguous`;
- nach `mapping_persisted` ohne auffindbares lokales Mapping: nur den exakten Draft lesend suchen und typisiert wiederherstellen; niemals neu erstellen;
- nach `invoice_open_write_requested`: Status, `sendType`, Kontakt, vollständige Rechnungsadresse, Länderfelder und Positionen lesen; `sendBy` nicht automatisch wiederholen. Bei Rule 19 ist ein vorhandenes `deliveryAddressCountry` maßgeblich und darf von der Rechnungsadresse abweichen. Nur wenn es fehlt, muss das eingebettete `addressCountry` zum eingefrorenen Zielland passen. Fehlt der normale `invoiceAddressHash`/`invoiceAddressCountryId`-Snapshot oder weicht die Remote-Adresse ab, bleibt der Altjob `ambiguous`; weder Kontakt noch Draft werden automatisch geändert;
- nach `invoice_delivery_write_requested`: Status, `sendType`, `sendDate`, Kontakt, Länderfelder und Positionen lesen; `sendViaEmail` nicht automatisch wiederholen. Für Rule 19 gilt derselbe strenge Länderabgleich wie nach dem Öffnen;
- nach `whmcs_email_write_requested`: der Providerübergang kann unbekannt sein. Item bleibt `ambiguous`; manueller Resend nur nach Bestätigung des Doppelversandrisikos;
- nach `whmcs_email_handed_off`: ausschließlich lokale Ready-/Delivery-Metadaten vervollständigen; weder `SendEmail` noch PDF-/sevDesk-Endpunkte erneut aufrufen; als an WHMCS-Mailprovider übergeben markieren, nicht als im Empfängerpostfach zugestellt.

Die aktuelle SevUser- oder Unity-Liste wird in diesen Recovery-Zuständen nicht als neue Entscheidungsgrundlage gelesen. Maßgeblich bleiben die vor dem ersten Create eingefrorenen IDs; nur ein noch nicht begonnener Create prüft ihre aktuelle Existenz erneut.

Fehlt das Mapping erst nach einem Open-, Delivery- oder Mail-Checkpoint, bleibt der Job mit unverändertem Checkpoint `ambiguous`. Dieser Zustand wird nicht automatisch auf Create-Recovery zurückgestuft; Remote-Dokument und lokale Zuordnung müssen einzeln geprüft werden.

Nach einem erfolgreichen Ready-Schritt wird die finale PDF erneut über `getPdf` geladen, auf Signatur und Größe geprüft und gegen `pdf_sha256` verglichen. Ein anderer Hash ist ein Prüffall; eine lokale PDF-Kopie wird nicht angelegt.

Der PDF-Endpunkt kann entweder JSON/Base64 oder direkt `application/pdf` liefern. Ein `transport_error` nur bei `getPdf` kann von einem fehlerhaften `Content-Encoding` stammen. Ab 2.1.0-rc.2 nutzt der Download deshalb ausschließlich auf diesem Endpunkt einen nicht automatisch dekodierten, auf HTTP 200 und 10 MiB begrenzten Abruf. Ein zweiter Fallback-Request ist nicht vorgesehen.

Für eine E-Rechnung wird vor Öffnung und Zustellung zusätzlich `getXml` gegen `xml_sha256` geprüft. Ein neuer Hash ersetzt den gespeicherten Wert nicht. Die Recovery bleibt beim vorhandenen Remote-Dokument und darf weder eine normale Invoice noch eine zweite E-Rechnung erzeugen.

## Störungsmatrix

### HTTP 401

Symptom: Authentifizierung fehlgeschlagen.

Maßnahmen:

1. Prüfen, ob der globale Auth-Alarm gesetzt ist. Nach dem betroffenen Item beendet der Runner den aktuellen Batch und claimt auch in späteren Cronläufen keine weiteren Items.
   Kann die Alarmzeile nicht geschrieben werden, speichert das Modul Review-Marker und neuen Quarantäne-Token gemeinsam. Die gültige Signatur bleibt dabei für den lokalen Mail- und PDF-Schutz erhalten. Dieser erfolgreiche Fallback wird in demselben Fehlerpfad nicht später erneut gesetzt; eine inzwischen abgeschlossene Setup-Freigabe wird dadurch nicht überschrieben.
   Scheitert diese Speicherung, versucht das Modul Review-Marker und ungültige Laufzeitsignatur gemeinsam zu setzen. Nur wenn beide atomaren Fallbacks scheitern, folgt ein letzter, bestmöglicher Schreibversuch für den Review-Marker. Sync-Stopp und Jobpause werden unabhängig davon versucht.
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

### Voucher-PDF kann nicht erzeugt/hochgeladen werden

- WHMCS-Rechnung und Template lokal rendern;
- Dateirechte, temporäres Verzeichnis und PHP-Erweiterungen prüfen;
- sicherstellen, dass keine vollständigen PDFs im Modul-Log landen;
- erst nach erfolgreichem PDF-Test retryen.

### Invoice-PDF fehlt, ist ungültig oder hat einen anderen Hash

- Mappingtyp, Remote-ID, `document_ready_at` und Remote-Status prüfen;
- `getPdf` nur über den typisierten internen Pfad lesen;
- MIME, PDF-Signatur, EOF und Größenlimit prüfen;
- bei Hashabweichung keine ältere oder neue PDF still als offiziell ausliefern;
- Item/Mapping als Prüffall behandeln und sevDesk-PDF-Stabilität gegen den Canary bewerten;
- keine PDF-Bytes in Datenbank, Ticket oder Log kopieren.

### ZUGFeRD-XML fehlt oder hat einen anderen Hash

- `document_type=invoice`, `is_e_invoice`, Remote-ID, `xml_sha256` und den aktuellen Checkpoint prüfen;
- PHP XMLReader in der Web- und Cron-Laufzeit kontrollieren;
- `getXml` nur über den typisierten Workerpfad lesen; keine XML-Datei aus einem Ticket oder lokalen Export als Ersatz einspielen;
- bei ungültigem CII, DTD-/Entity-Inhalt, Größenüberschreitung oder Hashabweichung das Item `ambiguous` lassen;
- Kontakt, PaymentMethod und strukturierte Adresse gegen den eingefrorenen Kontext prüfen;
- fehlt nur `propertyIsEInvoice` in der Invoice-Antwort, `getXml` trotzdem ausschließlich lesend prüfen; ein ausdrücklich falsches Flag bleibt ein Vertragsfehler;
- weder den Soll-Hash überschreiben noch eine normale Invoice als Fallback erzeugen;
- keine XML-Bytes in Datenbank, Log, Supportexport oder Repository ablegen.

### Versand unklar

Nach `invoice_delivery_write_requested` oder `whmcs_email_write_requested` keinen automatischen Resend auslösen. Remote-Status beziehungsweise WHMCS-Mailprovider nur lesend prüfen. Kann der Ausgang nicht bewiesen werden, bleibt `ambiguous`. Ein manueller Resend erfordert eine sichtbare Bestätigung, dass der Kunde die Rechnung möglicherweise doppelt erhält.

Ein gespeicherter Kanal `whmcs_template` wird unter WHMCS 8.13 mit `whmcs_email_attachment_unsupported` gestoppt. Das geschieht bei neuen Vorgängen vor dem ersten Invoice-Write. Der Wert wird weder gelöscht noch still auf sevDesk umgestellt. Bereits versendete Testnachrichten mit `whmcs_email_handed_off` müssen anhand des Postfachanhangs manuell geklärt werden; der verbrauchte In-Memory-Kontext beweist unter WHMCS 8.13 nicht, dass der Mailer die PDF übernommen hat.

Bei sevDesk-Dokumenthoheit muss `sync_enabled` im regulären Setup aktiv bleiben. Setzt ein Authentifizierungsalarm oder eine Bestandsprüfung die Synchronisation vorübergehend aus, schützt der lokale `InvoicePaidPreEmail`-Guard die konfigurierte Hoheit weiterhin: Es wird keine WHMCS-Endrechnungs-Mail als Fallback versendet. Bei einem neuen Paid-Ereignis unter bereits aktivem Authentifizierungsalarm speichert der Hook unter gültiger Signatur, Review aus und bestätigtem Canary genau das normale deduplizierte Pending-Item, obwohl Sync alarmbedingt aus ist. Der Runner claimt wegen des Alarms nichts; der Clientbereich bleibt dauerhaft Pending, bis die Mandantenprüfung erfolgreich war. Ohne Alarm erzeugt ein ausgeschalteter Sync weiterhin keinen Job.

Der Schutz gilt auch bei einem späteren manuellen Versand. Bei neuen Invoices ist die Hoheit im Mapping maßgeblich. Nur ältere RC-Zeilen ohne diesen Wert dürfen vorübergehend auf einen eindeutig eingefrorenen Jobkontext zurückgreifen. Belegt keine der beiden Quellen ausdrücklich die WHMCS-Hoheit, wird die Mail blockiert. Voucher und untypisierte Altzuordnungen werden dadurch nicht nachträglich der sevDesk-Hoheit zugeschlagen.

Schlägt bei einer später manuell ausgelösten Invoice-Mail die lokale Template-,
Mapping- oder Kontextabfrage fehl, ist ohne request-lokalen Payment-Guard die
gespeicherte beziehungsweise eingefrorene Dokumenthoheit nicht beweisbar. Der Hook darf weder die aktuelle
globale Hoheit als historischen Ersatz verwenden noch blind alle WHMCS-Mails
unterdrücken. Bis Datenbank und WHMCS-Mailzustand wieder gesund sind, deshalb
keine manuelle Invoice- oder Serienmail auslösen; ein möglicherweise bereits
erfolgter Versand wird anschließend einzeln geprüft.

### sevDesk-Dokumenthoheit zeigt falschen Zustand

1. Prüfen, ob `invoice_only`, Proforma, Adapter-Manifest und Bestätigung weiterhin aktiv sind.
2. Mapping muss `document_type=invoice` und für Ready `document_ready_at` besitzen.
3. Bei Pending zuerst Job/checkpoint prüfen; keinen parallelen Export oder Versand starten.
4. Bei Failure die WHMCS-Proforma erhalten und keine ungeprüfte WHMCS-Endrechnung versenden.
5. Bei PDF-404 neben Eigentümer und Mapping auch die aktive Clientzuordnung und das WHMCS-Benutzerrecht `invoices` prüfen. Proxyfehler nicht durch direkte Remote-ID-URLs umgehen.

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
- XML-Inhalte;
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
3. prüfen, ob seit dem Wechsel ein Invoice-/E-Rechnungs-Mapping, ein ungemappter oder unklarer Invoice-Write oder sevDesk-Dokumenthoheit entstanden ist.
4. Nur wenn Punkt 3 sicher verneint wurde, neue Moduldateien aus dem Scanpfad nehmen und zuvor gesicherte, mit der PHP-Runtime kompatible Moduldateien wiederherstellen. Andernfalls den Rewrite installiert, aber deaktiviert lassen.
5. `mod_sevdesk` und neue Jobtabellen nicht löschen.
6. Additive Mappingfelder und typisierte Zuordnungen einschließlich `is_e_invoice`, `pdf_sha256` und `xml_sha256` nicht zurücksetzen; sie werden für Cross-Type-Idempotenz und Recovery benötigt.
7. Bei vorheriger sevDesk-Hoheit den Clientbereich kontrolliert auf Proforma/Pending setzen; nicht ungeprüft alte WHMCS-Endrechnungslinks oder Mails reaktivieren.
8. Health der verbleibenden WHMCS-Installation prüfen.
9. Remote-Writes und mögliche Versandübergaben seit Deployment anhand der Jobs erfassen und mit sevDesk abstimmen.

Ist die bisherige Version nicht mit der Ziel-Runtime kompatibel, bleibt das Addon bis zur Behebung deaktiviert.

Das Originalmodul kennt `document_type` nicht. Nach dem ersten neuen sevDesk-`Invoice`-Write ist ein bloßer Dateirückwechsel deshalb kein sicherer Rollback: Die gleiche Remote-ID könnte vom Altcode über einen Voucher-Endpunkt gelesen, gebucht oder korrigiert werden. Auch ein `ambiguous` Item nach `invoice_write_requested` zählt als möglicher Invoice-Write. Dafür ist ein separat entworfener, lesend verifizierter Downgrade nötig; weder das Entfernen der Typ-Spalte noch das Umschreiben der ID ist zulässig.

## Deaktivierung und Deinstallation

Eine Deaktivierung stoppt Hooks und Worker, lässt die Daten aber bestehen. Bei der Deinstallation dürfen `mod_sevdesk` und die Jobreports nur nach einer separat bestätigten Export- und Löschentscheidung entfernt werden. Standardmäßig bleiben sie für Buchhaltungsnachweis und Idempotenz erhalten.
