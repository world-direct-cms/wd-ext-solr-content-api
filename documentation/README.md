<!-- omit in toc -->
# Technische Dokumentation

<!-- omit in toc -->
## Inhaltsverzeichnis
- [Zweck und Umfang](#zweck-und-umfang)
- [Architektur und Datenfluss](#architektur-und-datenfluss)
- [Solr-Verbindungen und Dokumentfelder](#solr-verbindungen-und-dokumentfelder)
- [Komponenten](#komponenten)
- [API-Endpunkte](#api-endpunkte)
  - [file-export](#file-export)
  - [changes](#changes)
  - [detail](#detail)
  - [index](#index)
- [Löschungen und Aktualität](#löschungen-und-aktualität)
- [Sicherheit](#sicherheit)
- [Betrieb und Voraussetzungen](#betrieb-und-voraussetzungen)

## Zweck und Umfang

`EXT:solr-content-api` stellt für TYPO3 eine authentifizierte REST-Schnittstelle bereit, die Inhalte aus den konfigurierten Solr-Cores ausliefert. Solr ist die einzige Quelle für exportierte Dokumente. Die Extension lädt deren Inhalt nicht aus TYPO3 nach und normalisiert die Solr-Feldnamen oder -Werte nicht.

Die Extension unterstützt PHP ab 8.1, TYPO3 12.4 und 13.4 sowie EXT:solr 12.1. Die API verwendet den Prefix `/api/v1` und unterstützt ausschließlich `GET`.

## Architektur und Datenfluss

1. TYPO3 und die konfigurierten EXT:solr-Indexer schreiben Inhalte in Solr. Die Aktualität hängt daher von den TYPO3-Index-Queues und deren Workern ab.
2. `SolrExportService` ermittelt alle TYPO3-Sites und deren konfigurierte Solr-Verbindungen je Sprache. Verbindungsdaten werden aus der EXT:solr-Site-Konfiguration gelesen.
3. Die Middleware authentifiziert API-Aufrufe und delegiert sie an den passenden Handler. Live-Abfragen gehen von der Extension direkt an den Solr-Select-Endpunkt.
4. Der File-Export-Command liest Solr paginiert und erstellt daraus eine JSON-Datei. Der API-Endpunkt `/file-export` liefert ausschließlich diese Datei aus.

Solr-Abfragen werden mit Cursor-Paginierung in Seiten von 500 Dokumenten ausgeführt und nach `id asc` sortiert. Der HTTP-Aufruf hat ein festes Timeout von 10 Sekunden. Optional konfigurierte Solr-Zugangsdaten werden als HTTP Basic Authentication verwendet. Site, Sprache und Core werden nicht an API-Clients ausgegeben.

## Solr-Verbindungen und Dokumentfelder

Alle Sites und die von EXT:solr bereitgestellten Sprachverbindungen werden berücksichtigt. Die API verwendet deren Leseverbindung; Schema, Host, Port, Pfad, Core und optionale Zugangsdaten werden nicht separat in dieser Extension konfiguriert.

Die Feld-Allowlist befindet sich in [`Configuration/ExportFields.php`](../Configuration/ExportFields.php). `default` enthält gemeinsame Felder; `types` ergänzt je Solr-Type weitere Felder. Aktuell sind folgende Types konfiguriert:

| Type                        | Zusätzliche Felder                                              |
| --------------------------- | --------------------------------------------------------------- |
| `pages`                     | `url`, `title`, `content`                                       |
| `fe_users`                  | `url`, `title`, `content`                                       |
| `tx_news_domain_model_news` | `url`, `author`, `title`, `teaser`, `content`, `year_intS`      |
| `sys_file_metadata`         | `fileExtension`, `title_stringS`, `size_intS`, `content`, `url` |

Die gemeinsamen Felder sind `type`, `uid`, `pid`, `created`, `changed` und `indexed`. Ausgegeben werden nur konfigurierte Felder, die im jeweiligen Solr-Dokument vorhanden sind. Feldnamen und Werte bleiben erhalten. Weitere interne Solr-Felder wie `id` werden nicht exportiert. Die URL eines Datei-Dokuments ist keine Zusicherung, dass der Client die Datei ohne zusätzliche Berechtigungen abrufen kann.

Die Type-Liste steuert sowohl die Feldfilterung als auch die Erkennung von TYPO3-Soft-Deletes. Types müssen dafür den jeweiligen TYPO3-Tabellennamen entsprechen.

## Komponenten

- `ApiMiddleware` prüft den API-Prefix, die HTTP-Methode, Route, API-Key und Query-Parameter. Anfragen außerhalb des Prefixes werden an die nächste Middleware weitergegeben.
- `ApiKeyVerificationService` und `ApiKeyRepository` gleichen den Header `X-Api-Key` mit allen aktiven, gehashten API-Key-Datensätzen ab.
- `SolrExportService` ermittelt Site-/Sprachverbindungen, liest und filtert Solr-Dokumente und ermittelt Delete-Ereignisse.
- `FileExportCommand` stellt den TYPO3-Befehl `solr-content-api:file-export` bereit.
- `ExportFileWriter` schreibt die Ausgabe zunächst in temporäre Dateien. Erst nach erfolgreichem Abschluss wird `export.json` atomar ersetzt.
- `FileDeletionListener` erfasst vor einer FAL-Dateilöschung die zugehörigen `sys_file_metadata`-UIDs und speichert nach erfolgreicher Löschung ein Ereignis für die `changes`-API.

## API-Endpunkte

Alle Endpunkte benötigen `X-Api-Key: <API-Key>`. Antworten sind JSON; erfolgreiche Live-Abfragen und Fehler tragen `Cache-Control: no-store`.

### file-export

`GET /api/v1/file-export` liest `/var/www/html/var/json/export.json`. Der Endpoint startet keinen Export und baut keine Solr-Verbindung auf. Die Datei enthält `exportTimestamp` und `types`; pro Type gibt es `count` und `documents`. Ein leerer Export enthält ein leeres `types`-Objekt. Query-Parameter sind nicht erlaubt. Ist die Datei nicht vorhanden oder nicht lesbar, folgt `404 file_export_unavailable`.

Der Export wird mit `vendor/bin/typo3 solr-content-api:file-export` gestartet. Bei einem Fehler bleibt eine vorherige erfolgreiche Datei bestehen. Der Zeitstempel ist der Startzeitpunkt des Exports; ein konsistenter Snapshot über mehrere Cores wird nicht garantiert.

### changes

`GET /api/v1/changes?since=<ISO-8601-Zeitstempel>` liest Solr live. `since` ist obligatorisch und muss Sekunden sowie Zeitzone enthalten; bis zu sechs Nachkommastellen sind zulässig. Beispiele: `2026-10-16T10:00:00Z` oder `2026-10-16T12:00:00%2B02:00`. Weitere Parameter sind unzulässig.

Die Antwort enthält Solr-Dokumente mit `changed` größer oder gleich dem Zeitpunkt als `action: "upsert"` sowie erkannte Löschungen als `action: "delete"`. Sie ist unter `types` nach Type gruppiert; Metadaten enthalten den übergebenen `since`-Wert. Dateilöschungen beziehen sich auf die UID des `sys_file_metadata`-Dokuments.

### detail

`GET /api/v1/detail/{type}/{uid}` liefert ein einzelnes, aktuell in Solr vorhandenes Dokument. `type` muss konfiguriert sein; `uid` ist eine positive Ganzzahl ohne führende Nullen. Query-Parameter sind nicht erlaubt. Die Antwort enthält `requestedAt` (UTC, ISO 8601) und `document`. Nicht gefundene Dokumente ergeben `404 resource_not_found`.

Die Abfrage sucht in der konfigurierten Site-/Sprach-Core-Reihenfolge und verwendet den ersten Treffer. Bei Dateien ist `uid` die UID aus `sys_file_metadata`, nicht aus `sys_file`. Die Abfrage nutzt den Lucene-Parser und exakte Type-/UID-Filter.

### index

`GET /api/v1/index` liefert alle aktuell in den konfigurierten Solr-Cores gefundenen Dokumente, gruppiert nach `type`. Pro Type enthält die Antwort `count` und `documents`; jedes Dokument enthält nur `type`, `uid` und `title`. Für Dateien wird `title_stringS` als Titel verwendet. Die Antwort enthält zusätzlich `requestedAt` in UTC. Query-Parameter sind nicht erlaubt.

Der Endpoint liest live aus Solr, erzeugt aber keine Exportdatei. Derselbe Eintrag in mehreren Cores wird nicht dedupliziert.

## Löschungen und Aktualität

- Solr-Upserts sind nur so aktuell wie der Solr-Index. Änderungen, die noch in einer Index-Queue liegen, sind nicht sichtbar.
- `changes` verwendet das Solr-Feld `changed`; eine reine Neuindexierung ohne Änderung dieses Felds wird nicht gemeldet.
- TYPO3-Soft-Deletes werden anhand der TCA-Felder `delete` und `tstamp` gesucht. Bei `fe_users` gilt `withdraw_date > 0` als Rücknahme; für den Zeitfilter wird `tstamp` verwendet.
- FAL-Dateilöschungen werden über Datei-Events erfasst und dauerhaft in `tx_solrcontentapi_file_deletion` gespeichert.
- Andere Hard-Deletes und ausgeblendete Datensätze ohne erfasstes Soft-Delete werden nicht als Löschung erkannt.
- `changes` liefert aktuelle Dokumentinhalte und Löschereignisse, keine vollständige Änderungshistorie. Da der Filter inklusiv ist, kann ein Client Grenzfälle erneut erhalten und sollte Ereignisse idempotent verarbeiten.

## Sicherheit

API-Keys werden als Datensätze in `tx_solrcontentapi_domain_model_apikey` im TYPO3-Backend verwaltet. Das TCA-Feld verwendet TYPO3-Passwort-Hashing; der Klartext ist nach dem Speichern nicht wiederherstellbar. Ein ausgeblendeter oder gelöschter Key ist nicht aktiv. Mehrere Keys können parallel existieren, was eine Rotation ohne gleichzeitigen Ausfall ermöglicht.

Der Klartext-Key wird ausschließlich beim Client im Header `X-Api-Key` übertragen und darf weder in Quellcode, URLs, Prompts, Antworten noch Logs übernommen werden. Solr-Zugangsdaten, rohe Solr-Antworten und Exception-Details werden nicht an API-Clients zurückgegeben. Fehlerantworten verwenden `{ "error": { "code": "...", "message": "..." } }`.

## Betrieb und Voraussetzungen

- Der TYPO3-Prozess benötigt eine erreichbare EXT:solr-Leseverbindung für alle relevanten Sites und Sprachen.
- Das Verzeichnis `/var/www/html/var/json` muss für den TYPO3-Prozess beschreibbar sein; es wird bei Bedarf angelegt.
- Der File-Export-Command sollte regelmäßig als TYPO3 Scheduled Task oder externer Cronjob gestartet werden, wenn `/file-export` aktuell gehalten werden soll.
- Die TYPO3-Index-Queue-Worker und der Datei-Indexer müssen laufen, damit Änderungen und neue Dateien zeitnah in Solr erscheinen.
- Datenbankschema und Event-Listener der Extension müssen über den regulären TYPO3-Installations-/Cache-Prozess aktiv sein.