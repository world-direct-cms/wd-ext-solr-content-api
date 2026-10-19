<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Service;

use ApacheSolrForTypo3\Solr\System\Util\SiteUtility;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use WorldDirect\SolrContentApi\Export\ExportFileWriter;

/**
 * Discovers site-specific Solr cores and exports configured fields using cursor pagination.
 */
final class SolrExportService
{
    private const REQUIRED_FIELDS = ['type', 'uid', 'pid', 'created', 'changed', 'indexed', 'url'];
    private const PAGE_SIZE = 500;
    private const REQUEST_TIMEOUT = 10;

    /** @var array{default: string[], types: array<string, string[]>} */
    private array $fieldConfiguration;

    /**
     * Initializes export dependencies and loads the configured field allowlist.
     *
     * @param SiteFinder $siteFinder Source of TYPO3 sites whose Solr connections are exported.
     * @param RequestFactory $requestFactory HTTP client factory used to query Solr.
     * @param ExportFileWriter $fileWriter Writer streaming and publishing the JSON export.
     * @param LoggerInterface $logger Logger receiving export and core health-check failures.
     */
    public function __construct(
        private readonly SiteFinder $siteFinder,
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
                $this->exportConnection($connection);
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
     * Streams all documents from one connection into type groups using cursor pagination.
     *
     * @param array{site: string, languageId: int, read: array<string, mixed>} $connection Site, language, and Solr read settings.
     * @throws RuntimeException If querying Solr, validating its response, or writing the export fails.
     * @throws \JsonException If a response cannot be decoded or export data cannot be encoded.
     */
    private function exportConnection(array $connection): void
    {
        $cursor = '*';
        $page = $this->query($connection['read'], $cursor, self::PAGE_SIZE);
        $response = $page['response'] ?? null;
        if (!is_array($response) || !isset($response['numFound']) || !is_array($response['docs'] ?? null)) {
            throw new RuntimeException('Solr returned an invalid response.');
        }

        while (true) {
            foreach ($response['docs'] as $document) {
                if (is_array($document)) {
                    $this->fileWriter->writeDocument($this->filterDocument($document));
                }
            }

            $nextCursor = (string)($page['nextCursorMark'] ?? $cursor);
            if ($nextCursor === $cursor) {
                break;
            }

            $cursor = $nextCursor;
            $page = $this->query($connection['read'], $cursor, self::PAGE_SIZE);
            $response = $page['response'] ?? null;
            if (!is_array($response) || !is_array($response['docs'] ?? null)) {
                throw new RuntimeException('Solr returned an invalid response.');
            }
        }
    }

    /**
     * Retains required fields and configured default and type-specific fields from a document.
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
            self::REQUIRED_FIELDS,
            $this->fieldConfiguration['default'] ?? [],
            $this->fieldConfiguration['types'][(string)$type] ?? [],
        ));

        return array_intersect_key($document, array_fill_keys($allowedFields, true));
    }

    /**
     * Retrieves one cursor page with the union of all fields needed by the export.
     *
     * @param array<string, mixed> $readConfiguration Solr read endpoint settings, including optional credentials.
     * @param string $cursor Solr cursor mark, initially * and subsequently the previous nextCursorMark.
     * @param int $rows Maximum number of documents requested for the page.
     * @return array<string, mixed> Decoded Solr response containing document data and cursor metadata.
     * @throws RuntimeException If Solr returns a non-success status or a non-array JSON value.
     * @throws \JsonException If the response body is not valid JSON.
     */
    private function query(array $readConfiguration, string $cursor, int $rows): array
    {
        $fields = array_unique(array_merge(
            self::REQUIRED_FIELDS,
            ['id'],
            $this->fieldConfiguration['default'] ?? [],
            ...array_values($this->fieldConfiguration['types'] ?? []),
        ));

        return $this->request($readConfiguration, [
            'q' => '*:*',
            'fl' => implode(',', $fields),
            'rows' => (string)$rows,
            'sort' => 'id asc',
            'cursorMark' => $cursor,
            'wt' => 'json',
        ]);
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
