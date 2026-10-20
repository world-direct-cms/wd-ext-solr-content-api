<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use WorldDirect\SolrContentApi\Export\ExportFileWriter;
use WorldDirect\SolrContentApi\Service\ApiKeyVerificationService;
use WorldDirect\SolrContentApi\Service\SolrExportService;

/**
 * Routes and authenticates API requests for the pre-generated export and live changes.
 */
final class ApiMiddleware implements MiddlewareInterface
{
    private const API_PREFIX = '/api/v1';
    private const EXPORT_PATH = self::API_PREFIX . '/file-export';
    private const CHANGES_PATH = self::API_PREFIX . '/changes';
    private const ROUTES = [
        self::EXPORT_PATH => 'handleFileExport',
        self::CHANGES_PATH => 'handleChanges',
    ];

    /**
     * Initializes API authentication and error logging.
     *
     * @param ApiKeyVerificationService $apiKeyVerificationService Service validating the X-Api-Key header.
     * @param LoggerInterface $logger Logger receiving authentication and export delivery errors.
     * @param SolrExportService $solrExportService Source of live, field-filtered Solr changes.
     */
    public function __construct(
        private readonly ApiKeyVerificationService $apiKeyVerificationService,
        private readonly LoggerInterface $logger,
        private readonly SolrExportService $solrExportService,
    ) {}

    /**
     * Validates API routes, GET requests, and credentials; delegates requests outside the API prefix.
     *
     * @param ServerRequestInterface $request Incoming HTTP request with route and authentication headers.
     * @param RequestHandlerInterface $handler Next handler for requests outside the API prefix.
     * @return ResponseInterface Export response, JSON error, or the delegated handler's response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        if ($path !== self::API_PREFIX && !str_starts_with($path, self::API_PREFIX . '/')) {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'GET') {
            return $this->error(405, 'method_not_allowed', 'Nur GET-Anfragen sind erlaubt.', ['Allow' => 'GET']);
        }
        $route = self::ROUTES[$path] ?? null;
        if ($route === null) {
            return $this->error(404, 'endpoint_not_found', 'Der API-Endpunkt wurde nicht gefunden.');
        }
        try {
            $apiKey = $this->apiKeyVerificationService->verify($request->getHeaderLine('X-Api-Key'));
        } catch (\Throwable $exception) {
            $this->logger->error('Solr Content API authentication failed.', [
                'exceptionClass' => $exception::class,
            ]);
            return $this->error(500, 'internal_error', 'Die Anfrage konnte nicht verarbeitet werden.');
        }
        if ($apiKey === null) {
            return $this->error(401, 'unauthorized', 'Der API-Key fehlt oder ist ungültig.');
        }

        return $this->{$route}($request);
    }

    /**
     * Serves the existing export as a non-cacheable JSON stream, rejecting query parameters.
     *
     * @param ServerRequestInterface $request Authenticated request checked for unsupported query parameters.
     * @return ResponseInterface Streamed export or a JSON error if the request or file is unavailable.
     */
    private function handleFileExport(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getQueryParams() !== []) {
            return $this->error(400, 'invalid_request', 'Der Endpunkt akzeptiert keine Query-Parameter.');
        }

        if (!is_file(ExportFileWriter::FILE_PATH) || !is_readable(ExportFileWriter::FILE_PATH)) {
            return $this->error(404, 'file_export_unavailable', 'Es gibt keine Exportdatei.');
        }

        try {
            $size = filesize(ExportFileWriter::FILE_PATH);
            if ($size === false) {
                throw new \RuntimeException('Could not determine export file size.');
            }
            return (new Response())
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Length', (string)$size)
                ->withHeader('Cache-Control', 'no-store')
                ->withBody(new Stream(ExportFileWriter::FILE_PATH, 'rb'));
        } catch (\Throwable $exception) {
            $this->logger->error('Solr Content API request failed.', ['exceptionClass' => $exception::class]);
            return $this->error(500, 'internal_error', 'Die Anfrage konnte nicht verarbeitet werden.');
        }
    }

    private function handleChanges(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getQueryParams();
        $since = $parameters['since'] ?? null;
        if (
            array_diff(array_keys($parameters), ['since']) !== []
            || !is_string($since)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $since)
        ) {
            return $this->error(400, 'invalid_request', 'Der Parameter since muss ein ISO-8601-Zeitstempel mit Zeitzone sein.');
        }

        $timestamp = \DateTimeImmutable::createFromFormat(
            str_contains($since, '.') ? '!Y-m-d\\TH:i:s.uP' : '!Y-m-d\\TH:i:sP',
            $since
        );
        if ($timestamp === false || \DateTimeImmutable::getLastErrors() !== false) {
            return $this->error(400, 'invalid_request', 'Der Parameter since muss ein gueltiger ISO-8601-Zeitstempel sein.');
        }

        try {
            return new JsonResponse(
                $this->solrExportService->changes($since, $timestamp),
                200,
                ['Cache-Control' => 'no-store']
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Solr Content API changes request failed.', ['exceptionClass' => $exception::class]);
            return $this->error(500, 'internal_error', 'Die Anfrage konnte nicht verarbeitet werden.');
        }
    }

    /**
     * Builds a non-cacheable JSON response using the API's error envelope.
     *
     * @param int $status HTTP error status code.
     * @param string $code Machine-readable error identifier.
     * @param string $message Human-readable error message.
     * @param array<string, string> $headers Additional response headers, such as Allow for HTTP 405.
     * @return JsonResponse JSON error response with caching disabled.
     */
    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
            ['Cache-Control' => 'no-store'] + $headers
        );
    }
}
