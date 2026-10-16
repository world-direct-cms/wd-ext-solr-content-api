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

final class SolrExportService
{
    private const REQUIRED_FIELDS = ['type', 'uid', 'pid', 'created', 'changed', 'indexed', 'url'];
    private const PAGE_SIZE = 500;
    private const REQUEST_TIMEOUT = 10;

    /** @var array{default: string[], types: array<string, string[]>} */
    private array $fieldConfiguration;

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly RequestFactory $requestFactory,
        private readonly ExportFileWriter $fileWriter,
        private readonly LoggerInterface $logger,
    ) {
        $this->fieldConfiguration = require dirname(__DIR__, 2) . '/Configuration/ExportFields.php';
    }

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
            ]);
            throw new RuntimeException('The Solr file export failed.', 0, $exception);
        }
    }

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
     * @return array<int, array{site: string, languageId: int, read: array<string, mixed>}>
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

    /** @param array{site: string, languageId: int, read: array<string, mixed>} $connection */
    private function exportConnection(array $connection): void
    {
        $cursor = '*';
        $page = $this->query($connection['read'], $cursor, self::PAGE_SIZE);
        $response = $page['response'] ?? null;
        if (!is_array($response) || !isset($response['numFound']) || !is_array($response['docs'] ?? null)) {
            throw new RuntimeException('Solr returned an invalid response.');
        }

        $this->fileWriter->startCore(
            $connection['site'],
            $connection['languageId'],
            (string)$connection['read']['core'],
            (int)$response['numFound']
        );

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

        $this->fileWriter->finishCore();
    }

    /** @param array<string, mixed> $document
     *  @return array<string, mixed>
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

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $readConfiguration
     *  @param array<string, string> $parameters
     *  @return array<string, mixed>
     */
    private function request(array $readConfiguration, array $parameters): array
    {
        $host = (string)$readConfiguration['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $path = trim((string)($readConfiguration['path'] ?? ''), '/');
        $core = rawurlencode((string)$readConfiguration['core']);
        $endpointPath = ($path === '' ? '' : '/' . $path) . '/' . $core . '/select';
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
