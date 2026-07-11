# Legacy-Datenvertrag

## Zweck

Diese Datei beschreibt nur die technischen Altzustände, die für Migration, Recovery und Betrieb relevant sind. Produktive Bestandszahlen, Zeiträume, Statusverteilungen und konkrete Einstellungswerte werden nicht im Repository dokumentiert.

Für den aktuellen API-Vertrag gilt die versionierte OpenAPI-Datei.

## Bekannte Fehlerklassen

### Laufzeitkompatibilität

Die bisherige, nicht mehr gepflegte Erweiterung ist nicht mit der Zielplattform WHMCS 8.13.4 und PHP 8.3 kompatibel. Der Rewrite benötigt keine zusätzlichen Lizenz- oder Laufzeitdienste.

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

Die funktionalen Einstellungen liegen in `tbladdonmodules` unter `module = 'sevdesk'`. Der Rewrite übernimmt unterstützte Werte additiv. Unbekannte oder nicht mehr verwendete Werte bleiben bei einem normalen Upgrade unangetastet.

Konkrete API-Token, Custom-Field-IDs, Konto-IDs, Importgrenzen und Schalter stehen bewusst nicht hier. Sie gehören zur jeweiligen Umgebung. Account-Datev-IDs aus einer bestehenden Installation dürfen auch nicht in Code oder Tests landen.

## Recovery-Grenze

Kontaktanlage, PDF-Upload, Voucher-Erstellung und lokales Mapping liegen nicht in einer gemeinsamen Transaktion. Bricht ein Prozess nach einem Remote-Write ab, kann dessen Ausgang unbekannt bleiben. Die Recovery liest deshalb zuerst den Remote-Bestand und legt weder Kontakt noch Voucher blind ein zweites Mal an. Eine neue Mappingzeile entsteht erst, wenn die Remote-ID bestätigt ist.

Der Rewrite arbeitet zunächst Voucher-first. Nicht jeder fachliche Sonderfall lässt sich dabei automatisch abbilden.

## Implizite Zustände

Die Tabelle kann neben vollständigen Zuordnungen auch unterbrochene oder verwaiste Zustände enthalten:

| Zustand | Bedeutung |
| --- | --- |
| keine Zeile | noch nie importiert, durch Filter ausgelassen, Mapping entfernt oder früh gescheitert |
| Invoice-ID, Remote-ID `NULL` | reserviert, laufend oder unterbrochen |
| beide IDs vorhanden | als importiert behandelt |
| Remote-ID vorhanden, Invoice fehlt | verwaiste historische Zuordnung |

Leere Reservierungen können nach einem Prozessabbruch, Timeout oder Laufzeitfehler stehen bleiben. Jede solche Zeile muss einzeln mit dem aktuellen Remote-Bestand abgeglichen werden. Das Modul darf weder Erfolg annehmen noch automatisch einen zweiten Beleg anlegen.

## Jobs und Hooks

Massenexporte werden als persistente Jobs verarbeitet. Der Fortschritt bleibt nach Browserende oder Prozessabbruch erhalten, und ein Fehler beendet nur das betroffene Item. Ausgelassene Rechnungen erhalten einen eindeutigen Grund.

Hooks planen Arbeit lediglich idempotent ein. Remote-I/O findet ausschließlich im Worker statt und darf den WHMCS-Benutzerrequest nicht stören.

## API-Basis

Die HTTP-API bleibt unter `/api/v1`. „sevDesk-Update 2.0“ bezeichnet hier die Buchhaltungslogik mit `taxRule` und `accountDatev`, nicht eine neue API-Version.

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

## Freigabe

Vor der Produktivfreigabe müssen Steuerfälle, Migration und Aufbewahrung für die jeweilige Zielumgebung geprüft werden. Bestandsdetails und Prüfergebnisse gehören nicht ins Repository.
