<!-- omit in toc -->
# EXT:solr-content-api

<!-- omit in toc -->
## Inhaltsverzeichnis
- [Bezeichnungen](#bezeichnungen)
- [Aufgabenstellung](#aufgabenstellung)
- [Datengrundlage](#datengrundlage)
- [File Export](#file-export)
- [API](#api)
  - [Solr-Verbindung und Dokumentauswahl](#solr-verbindung-und-dokumentauswahl)
  - [Sicherheit](#sicherheit)
  - [API-Key-Verwaltung](#api-key-verwaltung)
  - [file-export](#file-export-1)
  - [changes](#changes)
  - [Fehlerverhalten](#fehlerverhalten)
- [Nicht funktionale Anforderungen](#nicht-funktionale-anforderungen)
- [Phase 2](#phase-2)
  - [API](#api-1)
    - [detail](#detail)
    - [index](#index)

## Bezeichnungen
- `LLM`: Large Language Model (eine KI im Prinzip).
- `Documents`: Ein einzelner Solr-Eintrag, unabhängig von der TYPO3-Tabelle.
- `type`: Solr-Property, das den Index Type bzw. Tabellennamen des Documents enthält.
- `Core`: Eine Solr-Collection, die einer TYPO3-Site-Konfiguration zugeordnet ist.

## Aufgabenstellung
- Es geht darum, eine REST-Schnittstelle in TYPO3 bereitzustellen, die Daten aus den konfigurierten Solr-Cores liest und für ein LLM zurückgibt.
- `worlddirect/solr-content-api` ist eine eigenständige TYPO3-Extension und kommuniziert ausschließlich mit Solr. Eine Abstraktion für weitere Datenquellen ist nicht vorgesehen.

## Datengrundlage
- Als Datengrundlage dienen die Solr Documents, wie sie für die Verarbeitung durch die KI benötigt werden.
- Alle in der TYPO3 Site Configuration konfigurierten Sites und deren Solr-Cores werden berücksichtigt. Verbindungsdaten wie Schema, Host, Port, Pfad, Core und Zugangsdaten kommen aus der jeweiligen Site Configuration der EXT:solr und werden nicht separat in der Extension hinterlegt.
- Alle in EXT:solr konfigurierten Index-Queue-Konfigurationen (Index Types) werden berücksichtigt. Maßgeblich ist die für die jeweilige Site wirksame EXT:solr-Konfiguration.
- Es werden ausschließlich Solr Documents und deren Felder ausgegeben. Ein Nachladen aus der TYPO3-Datenbank sowie eine Umbenennung oder sonstige Normalisierung der Solr-Felder findet nicht statt.
- Alle exportierten Felder werden in `Configuration/ExportFields.php` konfiguriert. `default` definiert gemeinsame Felder für alle Types, `types` ergänzt die Felder des jeweiligen Index Types. Standardmäßig sind `type`, `uid`, `pid`, `created`, `changed` und `indexed` gemeinsame Felder.
- `url` wird nur für `pages`, `fe_users` und `tx_news_domain_model_news` ausgegeben, nicht für `sys_file_metadata`, da die Download-URL eine SAML-Authentifizierung erfordert. Feldnamen und Werte bleiben unverändert; nicht konfigurierte oder in Solr fehlende Felder werden nicht ausgegeben.

## File Export
- Der Console Command trägt den Namen `Solr Content API - File Export`. Sein technischer TYPO3-CLI-Bezeichner lautet `solr-content-api:file-export`.
- Der Command exportiert alle konfigurierten Index Types aus allen Solr-Cores als JSON-Datei. Documents werden unter `types` nach ihrem Index Type gruppiert und coreübergreifend zusammengeführt. Site-Kennung, Core-Name und Core-Metadaten werden nicht ausgegeben.
- Die Datei `/var/www/html/var/json/export.json` enthält den Startzeitpunkt des Commands als UTC-Zeitstempel (`exportTimestamp`) sowie je Index Type die Anzahl der exportierten Einträge (`count`) und das Array `documents`. Jedes Document enthält die konfigurierten gemeinsamen und type-spezifischen Felder, soweit diese in Solr vorhanden sind.
- Der Export liest Solr-Ergebnisse paginiert. Die Sortierung muss je Core stabil und eindeutig sein, damit bei großen Datenmengen keine Documents übersprungen oder doppelt ausgegeben werden.
- Ein fehlgeschlagener Export darf eine zuvor erfolgreich erzeugte Datei nicht ersetzen.
- Der Console Command kann regelmäßig als `Scheduled Task` ausgeführt oder bei Bedarf händisch gestartet werden. Das Verzeichnis `/var/www/html/var/json` muss dafür existieren oder angelegt werden können und für den TYPO3-Prozess beschreibbar sein.
- Eine TYPO3-Backend-Sperre verhindert keine Änderungen am Solr-Index und garantiert daher keinen konsistenten Solr-Snapshot. Der Zeitstempel bezeichnet den Exportstart; eine atomare Momentaufnahme über mehrere Cores ist nicht zugesichert.

## API
- Die API hat den Prefix `/api/v1`.
- Es werden ausschließlich HTTP-GET-Anfragen unterstützt. Die Authentifizierung erfolgt über den Header `X-Api-Key`.

### Solr-Verbindung und Dokumentauswahl
- Für jede TYPO3-Site werden Verbindungsdaten und Core aus der Site Configuration der EXT:solr gelesen. Die API gibt keine Verbindungsdetails an Clients zurück.
- Die Auswahl der Index Types folgt den für die jeweilige Site konfigurierten EXT:solr-Index-Queues.
- Pro Type gilt die gemeinsame Standard-Feldliste; zusätzlich werden konfigurierte Type-Felder ausgegeben. Documents desselben Types werden unabhängig vom jeweiligen Core in einem gemeinsamen Array ausgegeben.

### Sicherheit
- Zugriffe auf die API werden mittels API-Keys abgesichert.
- API-Key, Solr-Zugangsdaten, interne URLs, rohe Solr-Antworten und Exception-Details dürfen nicht in API-Responses oder regulären Logs ausgegeben werden.

### API-Key-Verwaltung
- API-Keys werden als Datensätze der Tabelle `tx_solrcontentapi_domain_model_apikey` verwaltet, nicht als statische Extension-Konfiguration oder Umgebungsvariable.
- Die Verwaltung erfolgt über das TYPO3-Standard-List-Modul. Ein eigenes Backend-Modul oder Dashboard-Widget ist nicht erforderlich.
- Jeder Datensatz enthält mindestens `label` als Freitext-Kommentar, `api_key` als gehashten Key und `hidden` zur sofortigen Invalidierung. TYPO3 stellt `uid`, `pid`, `tstamp`, `crdate` und `deleted` über das Standardschema bereit.
- Das TCA-Feld `api_key` verwendet den Core-Feldtyp `password` mit `hashed => true`. Der DataHandler hasht den Wert beim Speichern; Klartext wird nicht in der Datenbank abgelegt.
- Der TYPO3-Passwortgenerator muss explizit über `fieldControl.passwordGenerator` aktiviert werden:
  ```php
  'api_key' => [
      'exclude' => false,
      'label' => 'LLL:EXT:solr_content_api/Resources/Private/Language/locallang_db.xlf:tx_solrcontentapi_domain_model_apikey.api_key',
      'config' => [
          'type' => 'password',
          'size' => 40,
          'hashed' => true,
          'required' => true,
          'fieldControl' => [
              'passwordGenerator' => [
                  'renderType' => 'passwordGenerator',
                  'options' => ['allowEdit' => true],
              ],
          ],
      ],
  ],
  ```
- Das zugehörige `ext_tables.sql` ergänzt nur die fachlichen Spalten:
  ```sql
  CREATE TABLE tx_solrcontentapi_domain_model_apikey (
      label varchar(255) DEFAULT '' NOT NULL,
      api_key varchar(255) DEFAULT '' NOT NULL,
  );
  ```
- Mehrere Keys können parallel existieren. Jeder kann unabhängig gesperrt werden, sodass eine Rotation ohne Unterbrechung möglich ist.
- Einen neuen Key legt ein berechtigter Backend-Benutzer im List-Modul an, erzeugt ihn mit dem Passwortgenerator und kopiert ihn unmittelbar. Nach dem Speichern ist nur der Hash verfügbar.
- Der Zugriff auf die Tabelle wird über reguläre TYPO3-Backend-Benutzergruppen-Rechte auf einen begrenzten Personenkreis eingeschränkt.
- Die Middleware prüft den eingehenden Key gegen alle nicht ausgeblendeten (`hidden = 0`) Datensätze mittels `checkPassword()` der `PasswordHashFactory`.
- Für Logging- und Audit-Zwecke dürfen `uid` und `label` des passenden Datensatzes protokolliert werden, niemals der Key selbst.

### file-export
- `GET /api/v1/file-export` liefert die zuletzt erfolgreich erzeugte Exportdatei als JSON. Der Aufruf startet keinen Export; dieser wird über den Console Command `solr-content-api:file-export` erzeugt. Der Endpoint akzeptiert keine Query-Parameter.
- Der Endpoint liest ausschließlich die vorhandene Exportdatei. Er baut keine Verbindung zu Solr auf; eine aktuell fehlende Solr-Verbindung hat keinen Einfluss auf das Ausliefern eines bereits erzeugten Exports.
- Wenn noch keine Exportdatei vorhanden oder die Datei nicht lesbar ist, antwortet der Endpoint mit `404 Not Found` und `file_export_unavailable`.
- Die Antwort enthält `exportTimestamp` und das Objekt `types`. Dessen Schlüssel sind die exportierten Index Types; jeder Wert enthält `count` und `documents`. `count` entspricht der Anzahl der Einträge im jeweiligen Document-Array über alle Cores hinweg. Es gibt keine Sektion `cores`, kein globales `documents`-Array und keine Site- oder Core-Metadaten. Ein leerer Export enthält `"types": {}`. Beispiel:
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
            "url": "https://example.org/beispiel",
            "title": "Beispiel"
          }
        ]
      }
    }
  }
  ```

### changes
- `GET /api/v1/changes?since=2026-10-16T10:00:00Z` liefert aktuell in Solr vorhandene Documents, deren Feld `changed` groesser oder gleich `since` ist, sowie TYPO3-Datensaetze mit Soft-Delete, deren TCA-Zeitstempel `since` erreicht oder ueberschritten hat. Solr-Documents erhalten `action: "upsert"`; Soft-Deletes erhalten `action: "delete"` und mindestens `type` und `uid`.
- `since` ist erforderlich und muss ein gueltiger ISO-8601-Zeitstempel mit Sekunden und Zeitzone sein, z. B. `2026-10-16T10:00:00Z` oder `2026-10-16T12:00:00+02:00`. Optional sind bis zu sechs Nachkommastellen erlaubt. Ein `+` im Query-Parameter muss als `%2B` URL-kodiert werden. Fuer die Solr-Abfrage wird der Wert nach UTC umgerechnet. Weitere Query-Parameter werden abgelehnt.
- Authentifizierung und Feldkonfiguration entsprechen `file-export`. Die Antwort enthaelt Upserts mit den konfigurierten Inhalten und Delete-Eintraege mit `type`, `uid` und `action`, gruppiert unter `types` mit `count` und `documents`; sie enthaelt den uebergebenen Wert `since` statt `exportTimestamp`. Ohne Treffer lautet die Antwort `{"since":"2026-10-16T10:00:00Z","types":{}}`.
- Beispiel:
  ```json
  {
    "since": "2026-10-16T10:00:00Z",
    "types": {
      "pages": {
        "count": 1,
        "documents": [
          {
            "action": "upsert",
            "type": "pages",
            "uid": 123,
            "pid": 1,
            "created": "2026-10-15T08:00:00Z",
            "changed": "2026-10-16T10:30:00Z",
            "indexed": "2026-10-16T10:31:00Z",
            "url": "https://example.org/beispiel",
            "title": "Beispiel"
          }
        ]
      }
    }
  }
  ```
- Fehlendes oder ungueltiges `since` sowie unbekannte Parameter ergeben `400 Bad Request` mit `invalid_request`. Nicht konfigurierte oder nicht erreichbare Solr-Cores ergeben `500 Internal Server Error` mit `internal_error`; es werden keine Teilergebnisse zurueckgegeben. Antworten sind mit `Cache-Control: no-store` versehen.
- Soft-Deletes werden aus den TYPO3-Tabellen anhand des in der TCA definierten Delete-Felds und Zeitstempelfelds ermittelt. Der Tabellenname wird direkt aus dem Solr-Type abgeleitet; die Types muessen daher den TYPO3-Tabellennamen entsprechen. FAL-Dateilöschungen werden über TYPO3-Datei-Events erfasst: Die betroffenen `sys_file_metadata`-UIDs werden vor dem Entfernen gepuffert und nach dem Löschen dauerhaft für `changes` gespeichert. Andere Hard-Deletes, versteckte Datensaetze ohne Soft-Delete und benutzerdefinierte Ausschlussfelder wie `withdraw_date` werden nicht als Delete-Eintraege erkannt.
- Die Methode liefert aktuelle Inhalte geaenderter Documents und Soft-Delete-Ereignisse, keine vollstaendige Aenderungshistorie; reine Neuindexierungen ohne Aenderung von `changed` werden nicht beruecksichtigt.

### Fehlerverhalten
- Fehler werden als JSON mit einem maschinenlesbaren `code` und einer verständlichen, nicht-technischen `message` zurückgegeben. Technische Details werden ausschließlich serverseitig protokolliert.
- Beispiel, wenn keine Exportdatei vorhanden ist:
  ```json
  {
    "error": {
      "code": "file_export_unavailable",
      "message": "Es gibt keine Exportdatei."
    }
  }
  ```
- Verbindliche Fehlerfälle:
  - `400 Bad Request` (`invalid_request`): ungültige Anfrage oder Parameter.
  - `401 Unauthorized` (`unauthorized`): API-Key fehlt oder ist ungültig.
  - `404 Not Found` (`endpoint_not_found`): unbekannter API-Pfad.
  - `404 Not Found` (`file_export_unavailable`): es gibt keine lesbare Exportdatei.
  - `405 Method Not Allowed` (`method_not_allowed`): HTTP-Methode ist nicht `GET`; der Response enthält `Allow: GET`.
  - `500 Internal Server Error` (`internal_error`): unerwarteter interner Fehler ohne Offenlegung technischer Details.
- Ist Solr beim Erstellen des Exports nicht verfügbar, schlägt der Console Command fehl und die vorherige Exportdatei bleibt unverändert. Der API-Endpunkt kann diese Datei weiterhin ausliefern.

## Nicht funktionale Anforderungen
- Es soll eine TYPO3-Extension `worlddirect/solr-content-api` erstellt werden.
- Sie soll mit TYPO3 Version 12 und 13 kompatibel sein. TYPO3 Version 14 ist optional, wenn es leicht möglich ist.

## Phase 2
- Phase 2 ist aktuell nicht Teil der Umsetzung. Die folgenden Punkte sind für eine spätere Phase vorgemerkt.

### API

#### detail
- Diese Methode wird in Phase 1 nicht implementiert.
- Sie liefert die Details eines Documents zurück.

#### index
- Diese Methode wird in Phase 1 nicht implementiert.
- Sie liefert eine Gesamtliste aller Documents ab. Das Ergebnis entspricht dem File Export ohne Inhalte; ausgegeben werden nur `uid` und `type`.
- Sie soll dem LLM helfen abzugleichen, welche Inhalte aktuell sind, da Löschungen andernfalls nicht erkannt werden.
