<!-- omit in toc -->
# API-Handbuch für Verwender

<!-- omit in toc -->
## Inhaltsverzeichnis
- [Überblick](#überblick)
- [Voraussetzungen und Authentifizierung](#voraussetzungen-und-authentifizierung)
- [Endpunkte](#endpunkte)
  - [file-export](#file-export)
  - [changes](#changes)
  - [detail](#detail)
  - [index](#index)
- [Antworten und Fehler](#antworten-und-fehler)
- [Datenaktualität und Löschungen](#datenaktualität-und-löschungen)
- [Beispiele](#beispiele)

## Überblick

Die Schnittstelle stellt Solr-Dokumente aus allen für TYPO3 konfigurierten Sites und Sprachen bereit. Solr ist die Datenquelle: TYPO3-Daten werden von der API nicht nachgeladen. Die API bietet vier `GET`-Endpunkte unter `/api/v1`.

Ersetze in den Beispielen `https://www.example.com` durch die öffentliche Basis-URL deiner TYPO3-Installation und verwende einen API-Key, den ein berechtigter Administrator bereitgestellt hat.

## Voraussetzungen und Authentifizierung

Jede Anfrage benötigt den Header `X-Api-Key`. API-Keys werden von berechtigten TYPO3-Backend-Benutzern im List-Modul verwaltet. Beim Anlegen wird der Passwortgenerator verwendet; TYPO3 speichert nur einen Hash. Der Klartext ist nach dem Speichern nicht wiederherstellbar und muss sicher beim API-Client hinterlegt werden.

Mehrere Keys können gleichzeitig aktiv sein. Zum Sperren eines Keys wird der Datensatz ausgeblendet oder gelöscht. Keys gehören nicht in URLs, Quellcode, Chatnachrichten oder Logs.

Beispiel für eine Anfrage:

```http
GET /api/v1/index HTTP/1.1
Host: www.example.com
X-Api-Key: <API-KEY>
Accept: application/json
```

Alle Endpunkte unterstützen nur `GET`. Nicht erlaubte Query-Parameter führen zu `400 invalid_request`. Antworten enthalten `Cache-Control: no-store`.

## Endpunkte

### file-export

```http
GET /api/v1/file-export
```

Liefert den zuletzt erfolgreich erzeugten Gesamtexport. Der Abruf startet keinen Export und fragt Solr nicht ab. Der TYPO3-Befehl `solr-content-api:file-export` erzeugt die Exportdatei; er kann regelmäßig als Scheduled Task oder Cronjob ausgeführt werden.

Die Antwort enthält `exportTimestamp` sowie `types`. Jeder Type enthält `count` und ein Array `documents`. Darin stehen die konfigurierten gemeinsamen und typabhängigen Felder, soweit sie im Solr-Dokument vorhanden sind. Bei einem leeren Export ist `types` leer. Wenn keine lesbare Exportdatei existiert, kommt `404 file_export_unavailable`.

```json
{
  "exportTimestamp": "2026-10-16T10:00:00Z",
  "types": {
    "pages": {
      "count": 1,
      "documents": [
        {
          "type": "pages",
          "uid": 123,
          "pid": 1,
          "created": "2026-10-15T08:00:00Z",
          "changed": "2026-10-16T09:30:00Z",
          "indexed": "2026-10-16T09:31:00Z",
          "url": "https://www.example.com/beispiel",
          "title": "Beispiel",
          "content": "Beispielinhalt"
        }
      ]
    }
  }
}
```

### changes

```http
GET /api/v1/changes?since=2026-10-16T10:00:00Z
```

`since` ist erforderlich und muss ein ISO-8601-Zeitstempel mit Sekunden und Zeitzone sein. Bis zu sechs Nachkommastellen sind möglich. Ein Zeitzonen-Pluszeichen muss URL-kodiert werden, zum Beispiel `since=2026-10-16T12:00:00%2B02:00`. Weitere Query-Parameter sind nicht erlaubt.

Die Antwort enthält:

- Aktuell in Solr vorhandene Dokumente mit `changed >= since`, jeweils mit `action: "upsert"` und den konfigurierten Exportfeldern.
- Erkannte Löschungen mit `action: "delete"` sowie mindestens `type` und `uid`.

Beide Arten sind in `types` nach Type gruppiert; pro Eintrag enthält die Gruppe `count` und `documents`. Die Antwort gibt den angefragten Wert im Feld `since` zurück. Eine leere Antwort enthält `"types": {}`.

Upserts beruhen auf dem aktuellen Stand des Solr-Dokuments und stellen keine Änderungshistorie dar. TYPO3-Soft-Deletes und FAL-Dateilöschungen werden erkannt; andere Hard-Deletes und reine Ausblendungen sind nicht allgemein erfasst. Details stehen unter [Datenaktualität und Löschungen](#datenaktualität-und-löschungen).

### detail

```http
GET /api/v1/detail/tx_news_domain_model_news/234
```

Liefert ein einzelnes, aktuell in Solr vorhandenes Dokument. `type` muss einer der konfigurierten Types sein: `pages`, `fe_users`, `tx_news_domain_model_news` oder `sys_file_metadata`. `uid` muss eine positive Ganzzahl ohne führende Nullen sein. Query-Parameter sind nicht erlaubt.

Die Antwort enthält `requestedAt` (UTC, ISO 8601) und `document`. Die Felder des Dokuments richten sich nach der konfigurierten Allowlist; nicht vorhandene Solr-Felder werden ausgelassen. Bei Dateien ist die UID die von `sys_file_metadata`, nicht von `sys_file`.

```json
{
  "requestedAt": "2026-10-21T10:00:00Z",
  "document": {
    "type": "tx_news_domain_model_news",
    "uid": 234,
    "title": "Aktuelle Nachricht",
    "teaser": "Kurzbeschreibung",
    "content": "Nachrichteninhalt",
    "url": "https://www.example.com/news/aktuell"
  }
}
```

### index

```http
GET /api/v1/index
```

Liefert den aktuellen Solr-Bestand aus allen konfigurierten Site-/Sprach-Cores. Die Antwort enthält `requestedAt` (UTC, ISO 8601) und `types`. Pro Type gibt es `count` und `documents`; jedes Dokument enthält nur `type`, `uid` und `title`. Bei Dateien wird `title_stringS` als `title` verwendet; wenn kein Titel vorhanden ist, ist der Wert eine leere Zeichenkette.

Die Abfrage liest live aus Solr und erzeugt keine Exportdatei. Dokumente, die in mehreren Cores vorhanden sind, werden nicht dedupliziert. Noch ausstehende TYPO3-Indexierungen sind nicht enthalten.

```json
{
  "requestedAt": "2026-10-21T10:00:00Z",
  "types": {
    "pages": {
      "count": 1,
      "documents": [
        {"type": "pages", "uid": 123, "title": "Startseite"}
      ]
    }
  }
}
```

## Antworten und Fehler

Erfolgreiche Antworten sind JSON. Solr-Feldnamen und -Werte werden nicht umbenannt. Gemeinsame Felder sind `type`, `uid`, `pid`, `created`, `changed` und `indexed`; zusätzliche Felder hängen vom Type ab. Nicht konfigurierte und im Solr-Dokument fehlende Felder werden nicht ausgegeben.

Fehler haben die Form:

```json
{
  "error": {
    "code": "resource_not_found",
    "message": "Die angeforderte Ressource wurde nicht gefunden."
  }
}
```

| HTTP-Status | Fehlercode                | Bedeutung                                                                   |
| ----------- | ------------------------- | --------------------------------------------------------------------------- |
| `400`       | `invalid_request`         | Ungültiger Pfadparameter, Zeitstempel oder nicht erlaubter Query-Parameter. |
| `401`       | `unauthorized`            | API-Key fehlt oder ist ungültig.                                            |
| `404`       | `endpoint_not_found`      | Unbekannter API-Pfad.                                                       |
| `404`       | `file_export_unavailable` | Es gibt keine lesbare Exportdatei.                                          |
| `404`       | `resource_not_found`      | Kein passendes Dokument in den konfigurierten Solr-Cores.                   |
| `405`       | `method_not_allowed`      | Andere HTTP-Methode als `GET`; der Header `Allow: GET` wird gesetzt.        |
| `500`       | `internal_error`          | Interner Fehler oder nicht erreichbarer/ungültiger Solr-Endpunkt.           |

Fehlermeldungen enthalten keine Solr-Zugangsdaten oder technischen Exception-Details.

## Datenaktualität und Löschungen

- `detail`, `index` und `changes` fragen Solr bei jedem Aufruf live ab. Live bedeutet den aktuellen Index, nicht zwingend den aktuellen TYPO3-Datenbankstand.
- Änderungen werden erst sichtbar, nachdem TYPO3s Index-Queue-Worker beziehungsweise Datei-Indexer sie in Solr verarbeitet haben.
- `file-export` liefert einen Snapshot mit `exportTimestamp`. Dieser kann älter als der Live-Solr-Index sein. Ein fehlgeschlagener Export ersetzt die zuletzt erfolgreich erzeugte Datei nicht.
- `changes` verwendet den inklusiven Filter `changed >= since`. Wiederholte Einträge an Zeitgrenzen sind möglich; Clients sollten Upserts und Deletes idempotent verarbeiten.
- TYPO3-Soft-Deletes werden anhand der TCA-Felder `delete` und `tstamp` ermittelt. Bei `fe_users` wird `withdraw_date > 0` als Rücknahme behandelt und über `tstamp` zeitlich eingeordnet.
- Löschungen von FAL-Dateien werden über TYPO3-Datei-Events gespeichert und als Delete der betroffenen `sys_file_metadata`-UID gemeldet.
- Andere Hard-Deletes und ausgeblendete Datensätze ohne Soft-Delete werden nicht zuverlässig gemeldet. Reine Neuindexierungen ohne Änderung des Solr-Felds `changed` erscheinen ebenfalls nicht in `changes`.
- `changes` liefert aktuelle Inhalte und erkannte Löschungen, keine vollständige Änderungshistorie. Für regelmäßige Synchronisation sind überlappende Zeitfenster, idempotente Verarbeitung und gelegentlicher Abgleich mit `/index` sinnvoll.

## Beispiele

Mit `curl` kann ein Endpunkt so aufgerufen werden:

```bash
curl --fail-with-body \
  -H 'X-Api-Key: <API-KEY>' \
  -H 'Accept: application/json' \
  'https://www.example.com/api/v1/detail/pages/123'
```

Änderungen ab einem URL-kodierten Zeitpunkt abrufen:

```bash
curl --fail-with-body \
  -H 'X-Api-Key: <API-KEY>' \
  -H 'Accept: application/json' \
  'https://www.example.com/api/v1/changes?since=2026-10-16T12:00:00%2B02:00'
```