<!-- omit in toc -->
# Solr Content API (`solr_content_api`)

TYPO3 extension that provides an authenticated, read-only REST API for the content indexed in the configured Apache Solr cores (EXT:solr). Solr is the only data source: the API does not load additional data from TYPO3 and does not normalize Solr values.

Detailed German documentation is available in [documentation/](documentation/):
[technical documentation](documentation/README.md), [API user guide](documentation/README-USER.md), [instructions for AI clients](documentation/AI-Instructions.md).

<!-- omit in toc -->
## Table of contents
- [Requirements](#requirements)
- [Installation](#installation)
- [Architecture](#architecture)
- [Configuration](#configuration)
  - [Solr connections](#solr-connections)
  - [Exported fields](#exported-fields)
  - [Files of a page](#files-of-a-page)
  - [Access-restricted content](#access-restricted-content)
- [Authentication](#authentication)
- [API endpoints](#api-endpoints)
  - [file-export](#file-export)
  - [changes](#changes)
  - [detail](#detail)
  - [index](#index)
- [Errors](#errors)
- [Data freshness and deletions](#data-freshness-and-deletions)
- [Operation](#operation)

## Requirements

- PHP 8.1 or later
- TYPO3 12.4 or 13.4
- EXT:solr (`apache-solr-for-typo3/solr`) 12.1
- Optional: `hmmh/solr-file-indexer` for indexing files as `sys_file_metadata` documents

## Installation

```bash
composer require worlddirect/solr-content-api
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

After installation, reindex the page queue so that page documents receive the `files` field.

## Architecture

1. TYPO3 and the EXT:solr indexers write content to Solr. Data freshness therefore depends on the TYPO3 index queues and their workers.
2. `SolrExportService` determines all TYPO3 sites and their Solr read connections per language from the EXT:solr site configuration.
3. `ApiMiddleware` authenticates requests under `/api/v1` and dispatches them. Live endpoints query the Solr select endpoint directly.
4. The `solr-content-api:file-export` command reads Solr with cursor pagination and writes a JSON file, which is served by `/api/v1/file-export`.

Solr queries use cursor pagination (500 documents per page, sorted by `id asc`) and a fixed HTTP timeout of 10 seconds. Optional Solr credentials are sent as HTTP Basic Authentication. Site, language and core are never exposed to API clients.

| Component                                         | Responsibility                                                                       |
| ------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `ApiMiddleware`                                   | Checks prefix, HTTP method, route, API key and query parameters.                     |
| `ApiKeyVerificationService`, `ApiKeyRepository`   | Verify `X-Api-Key` against all active, hashed API key records.                       |
| `SolrExportService`                               | Reads and filters Solr documents and determines deletion events.                     |
| `FileExportCommand`, `ExportFileWriter`           | Write the export to temporary files and atomically replace `export.json` on success. |
| `FileDeletionListener`                            | Records deleted FAL files for the `changes` endpoint.                                |
| `PageFilesIndexingListener`, `PageFileRepository` | Add the files used on a page to the page document (`fileUids_intM`).                 |

## Configuration

### Solr connections

All sites and all language connections provided by EXT:solr are used. Scheme, host, port, path, core and credentials are taken from the EXT:solr site configuration; the extension has no separate connection settings.

### Exported fields

The field allowlist is defined in [Configuration/ExportFields.php](Configuration/ExportFields.php):

| Type                        | Additional fields                                               |
| --------------------------- | --------------------------------------------------------------- |
| `pages`                     | `url`, `title`, `content`, `fileUids_intM` (as `files`)         |
| `fe_users`                  | `url`, `title`, `content`                                       |
| `tx_news_domain_model_news` | `url`, `author`, `title`, `teaser`, `content`, `year_intS`      |
| `sys_file_metadata`         | `fileExtension`, `title_stringS`, `size_intS`, `content`, `url` |

Common fields for all types are `type`, `uid`, `pid`, `created`, `changed` and `indexed`. Only configured fields present in the Solr document are returned. Values are passed through unchanged; field names are only renamed according to `fieldAliases` (`fileUids_intM` → `files`). Internal fields such as `id` are not exported.

Type names must match TYPO3 table names, because the list also controls the detection of soft deletes. `withdrawalFields` defines tables that use a withdrawal field instead of the TCA `delete` field (`fe_users.withdraw_date`).

The URL of a file document does not guarantee that the client may download the file without further authorization.

### Files of a page

When a page is indexed, `PageFilesIndexingListener` adds the multivalued Solr field `fileUids_intM`, exported as `files`. It contains the default-language `sys_file_metadata` UIDs (identical to the UIDs of file documents) of all files

- referenced by the page record itself (e.g. `pages.media`) or by visible content elements on the page (`tt_content` in the page language or `-1`, frontend restrictions applied), and
- linked in fields of these content elements (e.g. RTE links `t3://file?uid=…`) according to `sys_refindex`.

Not included are files rendered dynamically by plugins, folder-based file collections, and content included from other pages via shortcut/reference elements. Pages without files have no `files` field. Link detection requires an up-to-date reference index.

### Access-restricted content

Documents with frontend group restrictions are never returned by any endpoint. EXT:solr stores permissions in the Solr field `access`; only documents with `c:0` (pages) or `r:0` (records and files) are public. Everything else — restrictions on the page, a parent page, a content element or a record — is excluded.

EXT:solr indexes pages containing restricted content elements in several variants (one per group combination). Only the public variant is returned, so `files` never contains files from restricted content elements.

## Authentication

Every request requires the header `X-Api-Key`. API keys are records of `tx_solrcontentapi_domain_model_apikey`, managed by authorized backend users in the List module. TYPO3 stores only a password hash; the plain key cannot be recovered after saving. Hidden or deleted keys are inactive. Several keys can be active at the same time, which allows rotation without downtime.

Never put API keys in URLs, source code, chat messages or logs.

```http
GET /api/v1/index HTTP/1.1
Host: www.example.com
X-Api-Key: <API-KEY>
Accept: application/json
```

## API endpoints

All endpoints are under `/api/v1`, support `GET` only and return JSON with `Cache-Control: no-store`. Unsupported query parameters result in `400 invalid_request`.

### file-export

```http
GET /api/v1/file-export
```

Returns the last successfully generated export from `/var/www/html/var/json/export.json`. The request neither starts an export nor queries Solr. The response contains `exportTimestamp` (start time of the export) and `types`; each type contains `count` and `documents`. If no readable export exists, the response is `404 file_export_unavailable`.

The export is generated with:

```bash
vendor/bin/typo3 solr-content-api:file-export
```

A failed export keeps the previous file. A consistent snapshot across several cores is not guaranteed.

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
          "url": "https://www.example.com/example",
          "title": "Example",
          "content": "Example content",
          "files": [456, 789]
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

`since` is required and must be an ISO 8601 timestamp with seconds and time zone; up to six fractional digits are allowed. A `+` in the offset must be URL-encoded, e.g. `since=2026-10-16T12:00:00%2B02:00`.

The response contains `since` and `types`, grouped by type with `count` and `documents`:

- Documents currently in Solr with `changed >= since`, marked `action: "upsert"` and containing the configured fields.
- Detected deletions, marked `action: "delete"` with `type` and `uid`.

### detail

```http
GET /api/v1/detail/{type}/{uid}
```

Returns a single public document currently in Solr. `type` must be a configured type; `uid` must be a positive integer without leading zeros. The response contains `requestedAt` (UTC) and `document`. Cores are searched in configuration order and the first match is returned. For files, `uid` is the `sys_file_metadata` UID, not the `sys_file` UID. Unknown documents result in `404 resource_not_found`.

```json
{
  "requestedAt": "2026-10-21T10:00:00Z",
  "document": {
    "type": "tx_news_domain_model_news",
    "uid": 234,
    "title": "Latest news",
    "teaser": "Short description",
    "content": "News content",
    "url": "https://www.example.com/news/latest"
  }
}
```

### index

```http
GET /api/v1/index
```

Returns all public documents currently in the configured cores, grouped by type. Each document contains only `type`, `uid` and `title` (`title_stringS` for files, empty string if missing). The response also contains `requestedAt` (UTC). Documents present in several cores are not deduplicated.

```json
{
  "requestedAt": "2026-10-21T10:00:00Z",
  "types": {
    "pages": {
      "count": 1,
      "documents": [
        {"type": "pages", "uid": 123, "title": "Home"}
      ]
    }
  }
}
```

## Errors

Errors use the format `{ "error": { "code": "...", "message": "..." } }`. Messages are in German and never contain Solr credentials or exception details.

| Status | Code                      | Meaning                                                           |
| ------ | ------------------------- | ----------------------------------------------------------------- |
| `400`  | `invalid_request`         | Invalid path parameter, timestamp or unsupported query parameter. |
| `401`  | `unauthorized`            | API key missing or invalid.                                       |
| `404`  | `endpoint_not_found`      | Unknown API path.                                                 |
| `404`  | `file_export_unavailable` | No readable export file.                                          |
| `404`  | `resource_not_found`      | No matching public document in the configured cores.              |
| `405`  | `method_not_allowed`      | HTTP method other than `GET`; `Allow: GET` is set.                |
| `500`  | `internal_error`          | Internal error or unreachable/invalid Solr endpoint.              |

## Data freshness and deletions

- `detail`, `index` and `changes` query Solr live, i.e. they reflect the current index, not necessarily the current database state. Changes still waiting in an index queue are not visible.
- `changes` filters inclusively on the Solr field `changed` (record `tstamp` or page `SYS_LASTCHANGED`). Reindexing without a change of `changed` is not reported. Entries at time boundaries may be repeated; clients should process events idempotently.
- TYPO3 soft deletes are detected via the TCA fields `delete` and `tstamp`. For `fe_users`, `withdraw_date > 0` counts as deletion, ordered in time by `tstamp`.
- Deleted FAL files are recorded via file events in `tx_solrcontentapi_file_deletion` and reported as deletion of the `sys_file_metadata` UID.
- Documents that became access-restricted since `since` are reported as deletion, unless a public variant of the same document exists.
- Other hard deletes and hidden records without soft delete are not reliably reported.
- `changes` returns current content and detected deletions, not a full change history. For synchronization, use overlapping time windows and occasionally reconcile with `/index`.

## Operation

- TYPO3 needs a reachable EXT:solr read connection for all relevant sites and languages.
- `/var/www/html/var/json` must be writable by the TYPO3 process; it is created if missing.
- Run `solr-content-api:file-export` regularly (TYPO3 scheduler or cron) to keep `/file-export` up to date.
- The EXT:solr index queue worker and the file indexer must be running so that changes appear in Solr promptly.
