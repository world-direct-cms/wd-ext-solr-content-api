<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Service;

use ApacheSolrForTypo3\Solr\System\Util\SiteUtility;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use WorldDirect\SolrContentApi\Domain\Repository\FileDeletionRepository;
use WorldDirect\SolrContentApi\Export\ExportFileWriter;
use WorldDirect\SolrContentApi\Localization\Translation;

/**
 * Discovers site-specific Solr cores and exports configured fields using cursor pagination.
 */
final class SolrExportService
{
    private const PAGE_SIZE = 500;
    private const REQUEST_TIMEOUT = 10;
    // EXT:solr marks unrestricted documents with access "c:0" (pages) or "r:0" (records); everything else carries fe_group restrictions.
    private const PUBLIC_ACCESS_FILTER = '-access:[* TO "c:0"} -access:{"c:0" TO "r:0"} -access:{"r:0" TO *]';
    private const PROTECTED_ACCESS_FILTER = '+(access:[* TO "c:0"} access:{"c:0" TO "r:0"} access:{"r:0" TO *])';

    /** @var array{default: string[], types: array<string, string[]>, withdrawalFields: array<string, string>, fieldAliases: array<string, string>} */
    private array $fieldConfiguration;

    /** Whether the project restricts the export to an explicit `exportTypes` selection. */
    private bool $exportTypesSelected = false;

    /** @var string[] Names selected in `exportTypes` that are not configured in `exportFields`. */
    private array $unknownExportTypes = [];

    /**
     * Initializes export dependencies and loads the configured field allowlist.
     *
     * @param SiteFinder $siteFinder Source of TYPO3 sites whose Solr connections are exported.
     * @param ConnectionPool $connectionPool Database connection pool used to query TYPO3 deletion fields.
     * @param FileDeletionRepository $fileDeletionRepository Source of persisted FAL file deletion events.
     * @param RequestFactory $requestFactory HTTP client factory used to query Solr.
     * @param ExportFileWriter $fileWriter Writer streaming and publishing the JSON export.
     * @param LoggerInterface $logger Logger receiving export and core health-check failures.
     */
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
        private readonly FileDeletionRepository $fileDeletionRepository,
        private readonly RequestFactory $requestFactory,
        private readonly ExportFileWriter $fileWriter,
        private readonly LoggerInterface $logger,
    ) {
        $this->fieldConfiguration = $this->mergeFieldConfiguration(
            require dirname(__DIR__, 2) . '/Configuration/ExportFields.php',
            $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr_content_api']['exportFields'] ?? [],
        );
        $this->fieldConfiguration['types'] = $this->selectConfiguredTypes(
            $this->fieldConfiguration['types'],
            $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr_content_api']['exportTypes'] ?? [],
        );
    }

    /**
     * Extends the extension defaults with the project configuration.
     *
     * `default` fields are appended; entries of `types`, `withdrawalFields` and `fieldAliases`
     * are added or replace the default entry with the same key.
     *
     * @param array{default?: string[], types?: array<string, string[]>, withdrawalFields?: array<string, string>, fieldAliases?: array<string, string>} $defaults Configuration from ExportFields.php.
     * @param mixed $project Value of $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr_content_api']['exportFields'].
     * @return array{default: string[], types: array<string, string[]>, withdrawalFields: array<string, string>, fieldAliases: array<string, string>}
     */
    private function mergeFieldConfiguration(array $defaults, mixed $project): array
    {
        $project = is_array($project) ? $project : [];
        $section = static fn(array $configuration, string $key): array => is_array($configuration[$key] ?? null) ? $configuration[$key] : [];

        return [
            'default' => array_values(array_unique([...$section($defaults, 'default'), ...$section($project, 'default')])),
            'types' => array_filter(
                array_replace($section($defaults, 'types'), $section($project, 'types')),
                'is_array',
            ),
            'withdrawalFields' => array_replace($section($defaults, 'withdrawalFields'), $section($project, 'withdrawalFields')),
            'fieldAliases' => array_replace($section($defaults, 'fieldAliases'), $section($project, 'fieldAliases')),
        ];
    }

    /**
     * Restricts the type allowlist to the types selected by the project configuration.
     *
     * @param array<string, string[]> $types All configured types (ExportFields.php merged with the project configuration).
     * @param mixed $selection Value of $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr_content_api']['exportTypes'] (list of table names).
     * @return array<string, string[]> Selected types in their definition order, or all types if none are selected.
     * @throws RuntimeException If the selection contains no configured type.
     */
    private function selectConfiguredTypes(array $types, mixed $selection): array
    {
        $selected = array_values(array_filter(array_map(
            static fn (mixed $type): string => trim((string)$type),
            is_array($selection) ? $selection : [],
        )));
        if ($selected === []) {
            return $types;
        }
        $this->exportTypesSelected = true;

        $unknown = array_diff($selected, array_keys($types));
        $this->unknownExportTypes = array_values($unknown);
        if ($unknown !== []) {
            $this->logger->warning(Translation::get('log.unknown_export_types', 'default', [implode(', ', $unknown)]));
        }

        $selectedTypes = array_intersect_key($types, array_flip($selected));
        if ($selectedTypes === []) {
            throw new RuntimeException(Translation::get('exception.no_export_types'));
        }

        return $selectedTypes;
    }

    /**
     * Exports all configured site and language connections into a single JSON file.
     *
     * @return string Absolute path to the successfully published export file.
     * @throws RuntimeException If no cores are configured or the export cannot be completed.
     */
    public function export(): string
    {
        $connections = $this->getConnections();
        if ($connections === []) {
            throw new RuntimeException(Translation::get('exception.no_cores'));
        }

        $this->fileWriter->start(gmdate('Y-m-d\\TH:i:s\\Z'));
        try {
            foreach ($connections as $connection) {
                foreach ($this->getDocuments($connection) as $document) {
                    $this->fileWriter->writeDocument($document);
                }
            }
            return $this->fileWriter->finish();
        } catch (Throwable $exception) {
            $this->fileWriter->abort();
            $this->logger->error(Translation::get('log.export_failed'), [
                'exceptionClass' => $exception::class,
                'exception' => $exception,
            ]);
            throw new RuntimeException(Translation::get('exception.export_failed'), 0, $exception);
        }
    }

    /**
     * Returns all currently indexed documents with only type, uid, and title.
     *
     * Reads every configured site/language core without deduplicating documents between cores.
     * Uses title_stringS as a fallback for file titles and an empty string when no title is available.
     * Captures requestedAt in UTC before querying Solr.
     *
     * @return array{requestedAt: string, types: object} Request timestamp and minimal documents grouped by type.
     * @throws RuntimeException If no cores are configured or Solr returns an unsuccessful or invalid response.
     * @throws \JsonException If a Solr response cannot be decoded.
     */
    public function index(): array
    {
        $requestedAt = gmdate('Y-m-d\TH:i:s\Z');
        $connections = $this->getConnections();
        if ($connections === []) {
            throw new RuntimeException(Translation::get('exception.no_cores'));
        }

        $types = [];
        foreach ($connections as $connection) {
            foreach ($this->getDocuments($connection, null, ['type', 'uid', 'title', 'title_stringS']) as $document) {
                $type = $document['type'] ?? '';
                if (is_array($type)) {
                    $type = reset($type) ?: '';
                }
                $type = (string)$type;
                $types[$type] ??= ['count' => 0, 'documents' => []];
                $types[$type]['documents'][] = [
                    'type' => $type,
                    'uid' => $document['uid'] ?? null,
                    'title' => $document['title'] ?? $document['title_stringS'] ?? '',
                ];
                $types[$type]['count']++;
            }
        }

        return ['requestedAt' => $requestedAt, 'types' => (object)$types];
    }

    /**
     * Finds one indexed resource by its configured table name and positive UID.
     *
     * Searches site/language cores in configuration order and returns the first match,
     * restricted to the same default and type-specific fields as the export.
     * Uses the Lucene parser so the core's default eDisMax parser cannot interpret filters as full text.
     *
     * @param string $type Table name present in the configured type allowlist.
     * @param int $uid Positive TYPO3 record UID to match exactly in Solr.
     * @return array<string, mixed>|null Filtered document, or null if no configured core contains it.
     * @throws \InvalidArgumentException If the type is not configured or the UID is not positive.
     * @throws RuntimeException If no cores are configured or Solr returns an unsuccessful or invalid response.
     * @throws \JsonException If a Solr response cannot be decoded.
     */
    public function detail(string $type, int $uid): ?array
    {
        if (!array_key_exists($type, $this->fieldConfiguration['types']) || $uid <= 0) {
            throw new \InvalidArgumentException(Translation::get('api.detail_parameters_invalid'));
        }

        $connections = $this->getConnections();
        if ($connections === []) {
            throw new RuntimeException(Translation::get('exception.no_cores'));
        }

        $fields = array_unique(array_merge(
            ['type', 'uid'],
            $this->fieldConfiguration['default'] ?? [],
            $this->fieldConfiguration['types'][$type],
        ));
        foreach ($connections as $connection) {
            $page = $this->request($connection['read'], [
                'defType' => 'lucene',
                'q' => '{!field f=type v=$documentType}',
                'documentType' => $type,
                'fq' => '+uid:' . $uid . ' ' . self::PUBLIC_ACCESS_FILTER,
                'fl' => implode(',', $fields),
                'rows' => '1',
                'sort' => 'id asc',
                'wt' => 'json',
            ]);
            $response = $page['response'] ?? null;
            if (!is_array($response) || !isset($response['numFound']) || !is_array($response['docs'] ?? null)) {
                throw new RuntimeException(Translation::get('exception.solr_response_invalid'));
            }
            if ($response['docs'] === []) {
                continue;
            }
            $document = $response['docs'][0] ?? null;
            if (!is_array($document)) {
                throw new RuntimeException(Translation::get('exception.solr_response_invalid'));
            }

            return $this->filterDocument($document);
        }

        return null;
    }

    /**
     * Returns changed Solr documents, TYPO3 deletions, and FAL file deletions at or after the timestamp.
     *
     * @param string $since Original timestamp included unchanged in the response metadata.
     * @param \DateTimeImmutable $timestamp Validated timestamp used for the inclusive Solr filter.
     * @return array{since: string, types: object} Upserts and deletions grouped by document type.
     */
    public function changes(string $since, \DateTimeImmutable $timestamp): array
    {
        $connections = $this->getConnections();
        if ($connections === []) {
            throw new RuntimeException(Translation::get('exception.no_cores'));
        }

        $solrTimestamp = $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
        $types = [];
        $deletedDocuments = array_merge(
            $this->getDeletedDocuments($timestamp),
            $this->fileDeletionRepository->findDeletedSince($timestamp)
        );
        $deletedKeys = [];
        foreach ($deletedDocuments as $deletedDocument) {
            $deletedKeys[$deletedDocument['type'] . ':' . $deletedDocument['uid']] = true;
        }

        foreach ($connections as $connection) {
            foreach ($this->getDocuments($connection, $solrTimestamp) as $document) {
                $type = $document['type'] ?? '';
                if (is_array($type)) {
                    $type = reset($type) ?: '';
                }
                $type = (string)$type;
                $uid = $document['uid'] ?? null;
                if (is_scalar($uid) && isset($deletedKeys[$type . ':' . $uid])) {
                    continue;
                }

                $document = ['action' => 'upsert'] + $document;
                $types[$type] ??= ['count' => 0, 'documents' => []];
                $types[$type]['documents'][] = $document;
                $types[$type]['count']++;
                if (is_scalar($uid)) {
                    $deletedKeys[$type . ':' . $uid] = true;
                }
            }
        }

        // Documents that became access-restricted must be removed by API consumers.
        foreach ($connections as $connection) {
            foreach ($this->getDocuments($connection, $solrTimestamp, ['type', 'uid'], true) as $document) {
                $type = $document['type'] ?? '';
                if (is_array($type)) {
                    $type = reset($type) ?: '';
                }
                $type = (string)$type;
                $uid = $document['uid'] ?? null;
                if (!is_scalar($uid) || isset($deletedKeys[$type . ':' . $uid])) {
                    continue;
                }
                $deletedKeys[$type . ':' . $uid] = true;
                $deletedDocuments[] = ['action' => 'delete', 'type' => $type, 'uid' => (int)$uid];
            }
        }

        foreach ($deletedDocuments as $document) {
            $type = $document['type'];
            $types[$type] ??= ['count' => 0, 'documents' => []];
            $types[$type]['documents'][] = $document;
            $types[$type]['count']++;
        }

        return ['since' => $since, 'types' => (object)$types];
    }

    /**
     * Loads withdrawn users and TCA soft-deleted records changed at or after the requested timestamp.
     *
     * @param \DateTimeImmutable $timestamp Inclusive lower bound for the table timestamp.
     * @return array<int, array{type: string, uid: int, action: string}> Minimal deletion events.
     */
    private function getDeletedDocuments(\DateTimeImmutable $timestamp): array
    {
        $deletedDocuments = [];
        $minimumTimestamp = $timestamp->getTimestamp();
        if ($timestamp->format('u') !== '000000') {
            ++$minimumTimestamp;
        }

        foreach (array_keys($this->fieldConfiguration['types'] ?? []) as $type) {
            $table = (string)$type;
            $withdrawalField = $this->fieldConfiguration['withdrawalFields'][$type] ?? null;
            $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
            $deleteField = $ctrl['delete'] ?? null;
            $timestampField = $ctrl['tstamp'] ?? null;

            if (is_string($withdrawalField) && !is_string($timestampField)) {
                continue;
            }
            if (!is_string($withdrawalField) && (!is_string($deleteField) || !is_string($timestampField))) {
                continue;
            }

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $conditions = is_string($withdrawalField)
                ? [
                    $queryBuilder->expr()->gt($withdrawalField, $queryBuilder->createNamedParameter(0)),
                    $queryBuilder->expr()->gte($timestampField, $queryBuilder->createNamedParameter($minimumTimestamp)),
                ]
                : [
                    $queryBuilder->expr()->eq($deleteField, $queryBuilder->createNamedParameter(1)),
                    $queryBuilder->expr()->gte($timestampField, $queryBuilder->createNamedParameter($minimumTimestamp)),
                ];
            $uids = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(...$conditions)
                ->executeQuery()
                ->fetchFirstColumn();

            foreach ($uids as $uid) {
                $deletedDocuments[] = [
                    'action' => 'delete',
                    'type' => (string)$type,
                    'uid' => (int)$uid,
                ];
            }
        }

        return $deletedDocuments;
    }

    /**
     * Returns whether the project explicitly selects the exported types via `exportTypes`.
     *
     * @return bool True if `exportTypes` contains at least one table name.
     */
    public function hasExportTypeSelection(): bool
    {
        return $this->exportTypesSelected;
    }

    /**
     * Returns table names selected in `exportTypes` that have no field configuration and are therefore ignored.
     *
     * @return string[] Unknown table names in selection order.
     */
    public function getUnknownExportTypes(): array
    {
        return $this->unknownExportTypes;
    }

    /**
     * Counts public documents per active export type across all configured cores.
     *
     * Uses a type facet with rows=0, so no documents are transferred.
     *
     * @return array<string, int> Number of public documents per active export type, including types without documents.
     * @throws RuntimeException If no cores are configured or Solr returns an unsuccessful or invalid response.
     * @throws \JsonException If a Solr response cannot be decoded.
     */
    public function countPublicDocumentsByType(): array
    {
        $connections = $this->getConnections();
        if ($connections === []) {
            throw new RuntimeException(Translation::get('exception.no_cores'));
        }

        $counts = array_fill_keys(array_keys($this->fieldConfiguration['types']), 0);
        foreach ($connections as $connection) {
            $response = $this->request($connection['read'], [
                'q' => '*:*',
                'rows' => '0',
                'fq' => $this->getTypeFilter() . ' ' . self::PUBLIC_ACCESS_FILTER,
                'facet' => 'true',
                'facet.field' => 'type',
                'facet.limit' => '-1',
                'facet.mincount' => '1',
                'json.nl' => 'map',
                'wt' => 'json',
            ]);

            $typeCounts = $response['facet_counts']['facet_fields']['type'] ?? null;
            if (!is_array($typeCounts)) {
                throw new RuntimeException(Translation::get('exception.solr_response_invalid'));
            }
            foreach ($typeCounts as $type => $count) {
                if (isset($counts[$type])) {
                    $counts[$type] += (int)$count;
                }
            }
        }

        return $counts;
    }

    /**
     * Checks each configured core by issuing a document query without retrieving documents.
     *
     * @return bool Whether at least one core is configured and every core responds successfully.
     */
    public function areAllCoresAvailable(): bool
    {
        $connections = $this->getConnections();
        if ($connections === []) {
            return false;
        }

        foreach ($connections as $connection) {
            try {
                $this->request($connection['read'], [
                    'q' => '*:*',
                    'rows' => '0',
                    'wt' => 'json',
                ]);
            } catch (Throwable $exception) {
                $this->logger->error(Translation::get('log.core_unavailable'), [
                    'site' => $connection['site'],
                    'core' => (string)$connection['read']['core'],
                    'exceptionClass' => $exception::class,
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Collects Solr read connections for every configured TYPO3 site and language.
     *
     * @return array<int, array{site: string, languageId: int, read: array<string, mixed>}> Site/language connections.
     */
    private function getConnections(): array
    {
        $connections = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach (SiteUtility::getAllSolrConnectionConfigurations($site) as $languageId => $configuration) {
                $connections[] = [
                    'site' => $site->getIdentifier(),
                    'languageId' => (int)$languageId,
                    'read' => $configuration['read'],
                ];
            }
        }

        return $connections;
    }

    /**
     * Reads filtered documents from one connection using cursor pagination.
     *
     * @param array{site: string, languageId: int, read: array<string, mixed>} $connection Site, language, and Solr read settings.
     * @param string|null $since Inclusive UTC lower bound, or null for the full export.
     * @param string[]|null $fields Explicit fields to retrieve instead of the configured export fields.
     * @param bool $protected Whether to read only access-restricted documents instead of public ones.
     * @return \Generator<int, array<string, mixed>> Documents containing only the selected fields.
     * @throws RuntimeException If querying Solr, validating its response, or writing the export fails.
     * @throws \JsonException If a response cannot be decoded or export data cannot be encoded.
     */
    private function getDocuments(array $connection, ?string $since = null, ?array $fields = null, bool $protected = false): \Generator
    {
        $cursor = '*';
        $page = $this->query($connection['read'], $cursor, self::PAGE_SIZE, $since, $fields, $protected);
        $response = $page['response'] ?? null;
        if (!is_array($response) || !isset($response['numFound']) || !is_array($response['docs'] ?? null)) {
            throw new RuntimeException(Translation::get('exception.solr_response_invalid'));
        }

        while (true) {
            foreach ($response['docs'] as $document) {
                if (is_array($document)) {
                    yield $fields === null
                        ? $this->filterDocument($document)
                        : array_intersect_key($document, array_flip($fields));
                }
            }

            $nextCursor = (string)($page['nextCursorMark'] ?? $cursor);
            if ($nextCursor === $cursor) {
                break;
            }

            $cursor = $nextCursor;
            $page = $this->query($connection['read'], $cursor, self::PAGE_SIZE, $since, $fields, $protected);
            $response = $page['response'] ?? null;
            if (!is_array($response) || !is_array($response['docs'] ?? null)) {
                throw new RuntimeException(Translation::get('exception.solr_response_invalid'));
            }
        }
    }

    /**
     * Retains configured default and type-specific fields from a document.
     *
     * Fields listed in `fieldAliases` are renamed for the API response, e.g. `fileUids_intM` becomes `files`.
     *
     * @param array<string, mixed> $document Raw Solr document, optionally containing a multivalued type field.
     * @return array<string, mixed> Document containing only fields allowed for its type, using API field names.
     */
    private function filterDocument(array $document): array
    {
        $type = $document['type'] ?? '';
        if (is_array($type)) {
            $type = reset($type) ?: '';
        }

        $allowedFields = array_unique(array_merge(
            $this->fieldConfiguration['default'] ?? [],
            $this->fieldConfiguration['types'][(string)$type] ?? [],
        ));

        $filteredDocument = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $document)) {
                $filteredDocument[$this->fieldConfiguration['fieldAliases'][$field] ?? $field] = $document[$field];
            }
        }

        return $filteredDocument;
    }

    /**
     * Retrieves one cursor page with the union of all fields needed by the export.
     *
     * @param array<string, mixed> $readConfiguration Solr read endpoint settings, including optional credentials.
     * @param string $cursor Solr cursor mark, initially * and subsequently the previous nextCursorMark.
     * @param int $rows Maximum number of documents requested for the page.
     * @param string|null $since Inclusive UTC lower bound for the changed field.
     * @param string[]|null $fields Explicit fields to retrieve instead of the configured export fields.
     * @param bool $protected Whether to match only access-restricted documents instead of public ones.
     * @return array<string, mixed> Decoded Solr response containing document data and cursor metadata.
     * @throws RuntimeException If Solr returns a non-success status or a non-array JSON value.
     * @throws \JsonException If the response body is not valid JSON.
     */
    private function query(array $readConfiguration, string $cursor, int $rows, ?string $since = null, ?array $fields = null, bool $protected = false): array
    {
        $fields ??= array_unique(array_merge(
            ['id', 'type'],
            $this->fieldConfiguration['default'] ?? [],
            ...array_values($this->fieldConfiguration['types'] ?? []),
        ));

        $parameters = [
            'q' => '*:*',
            'fl' => implode(',', $fields),
            'rows' => (string)$rows,
            'sort' => 'id asc',
            'cursorMark' => $cursor,
            'fq' => ($since !== null ? '+changed:[' . $since . ' TO *] ' : '')
                . $this->getTypeFilter() . ' '
                . ($protected ? self::PROTECTED_ACCESS_FILTER : self::PUBLIC_ACCESS_FILTER),
            'wt' => 'json',
        ];

        return $this->request($readConfiguration, $parameters);
    }

    /**
     * Builds the Solr filter matching all active export types.
     *
     * @return string Required-clause filter on the type field.
     */
    private function getTypeFilter(): string
    {
        return '+type:(' . implode(' OR ', array_map(
            static fn (string $type): string => '"' . $type . '"',
            array_keys($this->fieldConfiguration['types']),
        )) . ')';
    }

    /**
     * Queries a Solr select endpoint with optional Basic authentication and a fixed timeout.
     *
     * @param array<string, mixed> $readConfiguration Scheme, host, port, core, and optional path and credentials.
     * @param array<string, string> $parameters Solr query parameters encoded into the request URL.
     * @return array<string, mixed> Solr response decoded as an associative array.
     * @throws RuntimeException If Solr returns a non-success status or a non-array JSON value.
     * @throws \JsonException If the response body is not valid JSON.
     */
    private function request(array $readConfiguration, array $parameters): array
    {
        $host = (string)$readConfiguration['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $path = trim((string)($readConfiguration['path'] ?? ''), '/');
        $core = rawurlencode((string)$readConfiguration['core']);
        $endpointPath = ($path === '' ? '/solr' : '/' . $path) . '/' . $core . '/select';
        $url = sprintf(
            '%s://%s:%d%s?%s',
            (string)$readConfiguration['scheme'],
            $host,
            (int)$readConfiguration['port'],
            $endpointPath,
            http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)
        );

        $headers = ['Accept' => 'application/json'];
        $username = (string)($readConfiguration['username'] ?? '');
        if ($username !== '') {
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . (string)$readConfiguration['password']);
        }

        $response = $this->requestFactory->request($url, 'GET', [
            'headers' => $headers,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(Translation::get('exception.solr_status_invalid'));
        }

        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException(Translation::get('exception.solr_json_invalid'));
        }

        return $data;
    }
}
