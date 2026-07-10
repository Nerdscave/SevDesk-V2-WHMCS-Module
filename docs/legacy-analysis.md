# Legacy-Datenvertrag

## Zweck

Diese Datei beschreibt nur die technischen Altzustände, die für Migration, Recovery und Betrieb relevant sind. Produktive Bestandszahlen, Zeiträume, Statusverteilungen und konkrete Einstellungswerte werden nicht im Repository dokumentiert.

Für den aktuellen API-Vertrag gilt die versionierte OpenAPI-Datei.

## Bekannte Fehlerklassen

### Laufzeitkompatibilität

Die bisherige, nicht mehr gepflegte Erweiterung ist nicht mit der Zielplattform WHMCS 8.13.4 und PHP 8.3 kompatibel. Der Rewrite kommt ohne verschleierte Laufzeitdateien und ohne externe Lizenzprüfung aus.

### EU B2C mit falscher Steuerregel

Ein EU-Privatkunde darf nicht allein wegen seines Landes wie ein innergemeinschaftlicher B2B-Fall behandelt werden. Eine solche Fehlklassifikation führt zu einer unzulässigen Kombination aus Steuerregel und Erlöskonto und kann von sevDesk mit HTTP 422 abgewiesen werden.

Land, Organisation, USt-ID und `taxexempt` müssen gemeinsam bewertet werden. Fehlen die nötigen Nachweise, bleibt der Fall B2C oder wird zur manuellen Prüfung blockiert.

### Konto und Steuerregel passen nicht zusammen

Eine statische Zuordnung von Steuerregeln reicht nicht aus, weil Erlöskonten mandantenspezifisch sind. Der Rewrite prüft daher Konto-ID, Steuerregel, Steuersatz und Dokumenttyp gemeinsam gegen `ReceiptGuidance`.

## Bestehender Datenvertrag

### Zuordnungstabelle

Die bestehende Datenbank enthält die Tabelle `mod_sevdesk`:

```sql
id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
invoice_id  INT NULL UNIQUE
sevdesk_id  VARCHAR(255) NULL UNIQUE
```

Es gibt keinen Foreign Key zu `tblinvoices`. Die Tabelle ordnet einer WHMCS-Invoice höchstens ein sevDesk-Objekt zu und dient zugleich als Duplikatschutz.

Bei Migration und Recovery muss das Modul vier Zustände unterscheiden:

- vollständige Zuordnungen mit lokaler und entfernter ID;
- Reservierungen mit `sevdesk_id = NULL`;
- verwaiste Zuordnungen, deren WHMCS-Invoice nicht mehr existiert;
- Invoices ohne Mapping.

Eine Invoice ohne Mapping ist nicht automatisch ein fehlender Export. Status, Importdatum und fachliche Sonderfälle müssen anhand des aktuellen Datenbestands neu geprüft werden.

### Einstellungen

Die funktionalen Einstellungen liegen in `tbladdonmodules` unter `module = 'sevdesk'`. Der Rewrite übernimmt unterstützte Werte additiv und lässt nicht mehr benötigte Lizenzfelder unangetastet.

Konkrete API-Token, Custom-Field-IDs, Konto-IDs, Importgrenzen und Schalter stehen bewusst nicht hier. Sie gehören zur jeweiligen Umgebung. Account-Datev-IDs aus einer bestehenden Installation dürfen auch nicht in Code oder Tests landen.

## Bisheriger Importablauf

Der bisherige Import lief vereinfacht so:

1. Invoice über die WHMCS Local API laden.
2. Client laden und die sevDesk-Kontakt-ID aus einem WHMCS-Custom-Field lesen.
3. Fehlenden Kontakt anlegen und seine Remote-ID in WHMCS speichern.
4. Steuerfall aus Rechnungs- und Kundendaten bestimmen.
5. Eine Mappingzeile mit leerer `sevdesk_id` als Reservierung anlegen.
6. Das WHMCS-Rechnungs-PDF erzeugen und hochladen.
7. Den Voucher anlegen.
8. Die Remote-ID im Mapping ergänzen.

Der Rewrite bleibt deshalb zunächst bei Voucher-first. Nicht jeder fachliche Sonderfall lässt sich dabei automatisch abbilden.

## Implizite Zustände

Die Tabelle wurde gleichzeitig als Historie und als temporäre Sperre verwendet:

| Zustand | Bedeutung |
| --- | --- |
| keine Zeile | noch nie importiert, durch Filter ausgelassen, Mapping entfernt oder früh gescheitert |
| Invoice-ID, Remote-ID `NULL` | reserviert, laufend oder unterbrochen |
| beide IDs vorhanden | als importiert behandelt |
| Remote-ID vorhanden, Invoice fehlt | verwaiste historische Zuordnung |

Leere Reservierungen können nach einem Prozessabbruch, Timeout oder Laufzeitfehler stehen bleiben. Jede solche Zeile muss einzeln mit dem aktuellen Remote-Bestand abgeglichen werden. Das Modul darf weder Erfolg annehmen noch automatisch einen zweiten Beleg anlegen.

## Bulk- und UI-Verhalten

Der bisherige Massenimport lief vollständig im abschließenden Browser-Request. Er speicherte weder einen Job noch einen serverseitigen Fortschritt. Nach einem Abbruch fehlten deshalb ein verlässlicher Fortsetzungspunkt und eine sichere Wiederaufnahme.

Ein Fehler war außerdem nicht immer auf die betroffene Rechnung begrenzt. Netzwerk-, Laufzeit-, Typ- oder Datenbankfehler konnten den gesamten Request abbrechen. Ausgelassene Exporte konnten zugleich wie ein Erfolg erscheinen, etwa bei einer unbezahlten Rechnung oder einem Rechnungsdatum vor der Importgrenze.

Der Rewrite speichert jeden Lauf als Job, grenzt Fehler pro Item ab und meldet ausgelassene Rechnungen mit einem eindeutigen Grund.

## Hook-Verhalten

Automatische Exporte liefen teilweise direkt in WHMCS-Benutzerrequests rund um Rechnungserstellung, Zahlung und Checkout. Integrationsfehler konnten dadurch den eigentlichen WHMCS-Ablauf stören.

Hooks dürfen im Rewrite nur idempotent einen Job einplanen. Remote-I/O findet ausschließlich im Worker statt.

## API-Basis

Ältere Unterlagen verwendeten „V2“ nicht einheitlich. Die API bleibt unter `/api/v1`. „sevDesk-Update 2.0“ bezeichnet hier die Buchhaltungslogik mit `taxRule` und `accountDatev`, nicht eine neue API-Version.

## Lokale Restore- und Dump-Dateien

Lokale Restore- oder Dump-Dateien sind keine Migration. Sie können veraltete Daten, Zugangswerte oder destruktive Anweisungen enthalten und dürfen weder veröffentlicht noch in einer laufenden Umgebung ausgeführt werden.

## Vorgaben für den Rewrite

- Bestehende Mappings bleiben erhalten und werden nicht neu aufgebaut.
- `NULL`- und verwaiste Mappings erscheinen im Recovery-Bericht und werden nicht automatisch bereinigt.
- Invoices ohne Mapping werden anhand der aktuellen Eignungsregeln neu klassifiziert.
- Steuerentscheidung und `ReceiptGuidance` laufen vor jedem Write.
- Jobs und Ergebnisse werden dauerhaft gespeichert.
- Der Worker fängt Fehler pro Item ab und speichert nur bereinigte Diagnosedaten.
- Hook-Fehler bleiben vollständig vom WHMCS-Kern getrennt.
- Ein unbekannter Remote-Ausgang wird lesend abgeglichen, bevor ein Mensch über das weitere Vorgehen entscheidet.
- Nicht benötigte Anbieter- und Lizenzabhängigkeiten werden nicht übernommen.

## Offene fachliche Punkte

Vor der Produktivfreigabe sind weiterhin zu klären:

- die steuerlich freigegebene Matrix für Hosting-Leistungen, EU B2B, EU B2C und Drittland;
- welche aktuell ungemappten Invoices tatsächlich exportpflichtig sind;
- ob historische Mappings auf Voucher oder auf verschiedene Objektarten zeigen;
- ob verwaiste Zuordnungen dauerhaft aufbewahrt werden sollen.
