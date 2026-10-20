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

/**
 * Discovers site-specific Solr cores and exports configured fields using cursor pagination.
 */
final class SolrExportService
{
    private const PAGE_SIZE = 500;
    private const REQUEST_TIMEOUT = 10;

    /** @var array{default: string[], types: array<string, string[]>, withdrawalFields?: array<string, string>} */
    private array $fieldConfiguration;

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
        $this->fieldConfiguration = require dirname(__DIR__, 2) . '/Configuration/ExportFields.php';
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
            throw new RuntimeException('No Solr cores are configured.');
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
            $this->logger->error('Solr file export failed.', [
                'exceptionClass' => $exception::class,
                'exception' => $exception,
            ]);
            throw new RuntimeException('The Solr file export failed.', 0, $exception);
        }
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
            throw new RuntimeException('No Solr cores are configured.');
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
                $this->logger->error('Solr core health check failed.', [
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
     * @return \Generator<int, array<string, mixed>> Documents containing only configured export fields.
     * @throws RuntimeException If querying Solr, validating its response, or writing the export fails.
     * @throws \JsonException If a response cannot be decoded or export data cannot be encoded.
     */
    private function getDocuments(array $connection, ?string $since = null): \Generator
    {
        $cursor = '*';
        $page = $this->query($connection['read'], $cursor, self::PAGE_SIZE, $since);
        $response = $page['response'] ?? null;
        if (!is_array($response) || !isset($response['numFound']) || !is_array($response['docs'] ?? null)) {
            throw new RuntimeException('Solr returned an invalid response.');
        }

        while (true) {
            foreach ($response['docs'] as $document) {
                if (is_array($document)) {
                    yield $this->filterDocument($document);
                }
            }

            $nextCursor = (string)($page['nextCursorMark'] ?? $cursor);
            if ($nextCursor === $cursor) {
                break;
            }

            $cursor = $nextCursor;
            $page = $this->query($connection['read'], $cursor, self::PAGE_SIZE, $since);
            $response = $page['response'] ?? null;
            if (!is_array($response) || !is_array($response['docs'] ?? null)) {
                throw new RuntimeException('Solr returned an invalid response.');
            }
        }
    }

    /**
     * Retains configured default and type-specific fields from a document.
     *
     * @param array<string, mixed> $document Raw Solr document, optionally containing a multivalued type field.
     * @return array<string, mixed> Document containing only fields allowed for its type.
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
                $filteredDocument[$field] = $document[$field];
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
     * @return array<string, mixed> Decoded Solr response containing document data and cursor metadata.
     * @throws RuntimeException If Solr returns a non-success status or a non-array JSON value.
     * @throws \JsonException If the response body is not valid JSON.
     */
    private function query(array $readConfiguration, string $cursor, int $rows, ?string $since = null): array
    {
        $fields = array_unique(array_merge(
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
            'wt' => 'json',
        ];
        if ($since !== null) {
            $parameters['fq'] = 'changed:[' . $since . ' TO *]';
        }

        return $this->request($readConfiguration, $parameters);
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
            throw new RuntimeException('Solr returned a non-success status.');
        }

        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Solr returned invalid JSON.');
        }

        return $data;
    }
}
