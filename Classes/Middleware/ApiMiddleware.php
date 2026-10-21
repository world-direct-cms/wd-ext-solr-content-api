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
use WorldDirect\SolrContentApi\Localization\Translation;
use WorldDirect\SolrContentApi\Service\ApiKeyVerificationService;
use WorldDirect\SolrContentApi\Service\SolrExportService;

/**
 * Routes and authenticates API requests for exports, changes, the index, and individual resources.
 */
final class ApiMiddleware implements MiddlewareInterface
{
    private const API_PREFIX = '/api/v1';
    private const EXPORT_PATH = self::API_PREFIX . '/file-export';
    private const CHANGES_PATH = self::API_PREFIX . '/changes';
    private const INDEX_PATH = self::API_PREFIX . '/index';
    private const DETAIL_PATH = self::API_PREFIX . '/detail';
    private const ROUTES = [
        self::EXPORT_PATH => 'handleFileExport',
        self::CHANGES_PATH => 'handleChanges',
        self::INDEX_PATH => 'handleIndex',
        self::DETAIL_PATH => 'handleDetail',
    ];

    /**
     * Initializes API authentication and error logging.
     *
     * @param ApiKeyVerificationService $apiKeyVerificationService Service validating the X-Api-Key header.
     * @param LoggerInterface $logger Logger receiving authentication and export delivery errors.
     * @param SolrExportService $solrExportService Source of live, field-filtered Solr documents.
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
            return $this->error(405, 'method_not_allowed', 'api.method_not_allowed', ['Allow' => 'GET']);
        }
        $route = self::ROUTES[$path] ?? null;
        if ($route === null && str_starts_with($path, self::DETAIL_PATH . '/')) {
            $route = 'handleDetail';
        }
        if ($route === null) {
            return $this->error(404, 'endpoint_not_found', 'api.endpoint_not_found');
        }
        try {
            $apiKey = $this->apiKeyVerificationService->verify($request->getHeaderLine('X-Api-Key'));
        } catch (\Throwable $exception) {
            $this->logger->error(Translation::get('log.authentication_failed'), [
                'exceptionClass' => $exception::class,
            ]);
            return $this->error(500, 'internal_error', 'api.internal_error');
        }
        if ($apiKey === null) {
            return $this->error(401, 'unauthorized', 'api.unauthorized');
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
            return $this->error(400, 'invalid_request', 'api.query_parameters_not_allowed');
        }

        if (!is_file(ExportFileWriter::FILE_PATH) || !is_readable(ExportFileWriter::FILE_PATH)) {
            return $this->error(404, 'file_export_unavailable', 'api.file_export_unavailable');
        }

        try {
            $size = filesize(ExportFileWriter::FILE_PATH);
            if ($size === false) {
                throw new \RuntimeException(Translation::get('exception.export_file_size'));
            }
            return (new Response())
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Length', (string)$size)
                ->withHeader('Cache-Control', 'no-store')
                ->withBody(new Stream(ExportFileWriter::FILE_PATH, 'rb'));
        } catch (\Throwable $exception) {
            $this->logger->error(Translation::get('log.request_failed'), ['exceptionClass' => $exception::class]);
            return $this->error(500, 'internal_error', 'api.internal_error');
        }
    }

    /**
     * Serves the current minimal Solr index as non-cacheable JSON, rejecting query parameters.
     *
     * @param ServerRequestInterface $request Authenticated request checked for unsupported query parameters.
     * @return ResponseInterface Index grouped by type, or a JSON error for invalid parameters or retrieval failures.
     */
    private function handleIndex(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getQueryParams() !== []) {
            return $this->error(400, 'invalid_request', 'api.query_parameters_not_allowed');
        }

        try {
            return new JsonResponse(
                $this->solrExportService->index(),
                200,
                ['Cache-Control' => 'no-store']
            );
        } catch (\Throwable $exception) {
            $this->logger->error(Translation::get('log.index_failed'), ['exceptionClass' => $exception::class]);
            return $this->error(500, 'internal_error', 'api.internal_error');
        }
    }

    /**
     * Serves one configured resource as non-cacheable JSON, including the UTC request timestamp.
     *
     * Accepts /api/v1/detail/{type}/{uid} with a positive integer UID and no query parameters.
     *
     * @param ServerRequestInterface $request Authenticated request containing the resource path.
     * @return ResponseInterface Single document, or a JSON error for invalid input, missing resources, or Solr failures.
     */
    private function handleDetail(ServerRequestInterface $request): ResponseInterface
    {
        $requestedAt = gmdate('Y-m-d\TH:i:s\Z');
        if ($request->getQueryParams() !== []) {
            return $this->error(400, 'invalid_request', 'api.query_parameters_not_allowed');
        }

        $path = rtrim($request->getUri()->getPath(), '/');
        if (!preg_match('#^' . preg_quote(self::DETAIL_PATH, '#') . '/([^/]+)/([1-9][0-9]*)$#D', $path, $matches)) {
            return $this->error(400, 'invalid_request', 'api.detail_parameters_invalid');
        }
        $uid = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($uid === false) {
            return $this->error(400, 'invalid_request', 'api.detail_parameters_invalid');
        }

        try {
            $document = $this->solrExportService->detail($matches[1], $uid);
            if ($document === null) {
                return $this->error(404, 'resource_not_found', 'api.resource_not_found');
            }

            return new JsonResponse(
                ['requestedAt' => $requestedAt, 'document' => $document],
                200,
                ['Cache-Control' => 'no-store']
            );
        } catch (\InvalidArgumentException) {
            return $this->error(400, 'invalid_request', 'api.detail_parameters_invalid');
        } catch (\Throwable $exception) {
            $this->logger->error(Translation::get('log.detail_failed'), ['exceptionClass' => $exception::class]);
            return $this->error(500, 'internal_error', 'api.internal_error');
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
            return $this->error(400, 'invalid_request', 'api.since_format_invalid');
        }

        $timestamp = \DateTimeImmutable::createFromFormat(
            str_contains($since, '.') ? '!Y-m-d\\TH:i:s.uP' : '!Y-m-d\\TH:i:sP',
            $since
        );
        if ($timestamp === false || \DateTimeImmutable::getLastErrors() !== false) {
            return $this->error(400, 'invalid_request', 'api.since_value_invalid');
        }

        try {
            return new JsonResponse(
                $this->solrExportService->changes($since, $timestamp),
                200,
                ['Cache-Control' => 'no-store']
            );
        } catch (\Throwable $exception) {
            $this->logger->error(Translation::get('log.changes_failed'), ['exceptionClass' => $exception::class]);
            return $this->error(500, 'internal_error', 'api.internal_error');
        }
    }

    /**
     * Builds a non-cacheable JSON response using the API's error envelope.
     *
     * @param int $status HTTP error status code.
     * @param string $code Machine-readable error identifier.
     * @param string $messageKey Translation key for the German API error message.
     * @param array<string, string> $headers Additional response headers, such as Allow for HTTP 405.
     * @return JsonResponse JSON error response with caching disabled.
     */
    private function error(int $status, string $code, string $messageKey, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => Translation::get($messageKey, 'de')]],
            $status,
            ['Cache-Control' => 'no-store'] + $headers
        );
    }
}
