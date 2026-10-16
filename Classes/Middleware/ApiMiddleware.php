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

final class ApiMiddleware implements MiddlewareInterface
{
    private const API_PREFIX = '/api/v1';
    private const EXPORT_PATH = '/api/v1/file-export';

    public function __construct(
        private readonly ApiKeyVerificationService $apiKeyVerificationService,
        private readonly SolrExportService $solrExportService,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        if ($path !== self::API_PREFIX && !str_starts_with($path, self::API_PREFIX . '/')) {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== 'GET') {
            return $this->error(405, 'method_not_allowed', 'Nur GET-Anfragen sind erlaubt.', ['Allow' => 'GET']);
        }
        if ($path !== self::EXPORT_PATH) {
            return $this->error(404, 'endpoint_not_found', 'Der API-Endpunkt wurde nicht gefunden.');
        }
        if ($request->getQueryParams() !== []) {
            return $this->error(400, 'invalid_request', 'Der Endpunkt akzeptiert keine Query-Parameter.');
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

        try {
            $solrAvailable = $this->solrExportService->areAllCoresAvailable();
        } catch (\Throwable $exception) {
            $this->logger->error('Solr core health check failed.', [
                'exceptionClass' => $exception::class,
            ]);
            return $this->error(503, 'solr_unavailable', 'Solr ist vorübergehend nicht verfügbar.');
        }
        if (!$solrAvailable) {
            return $this->error(503, 'solr_unavailable', 'Solr ist vorübergehend nicht verfügbar.');
        }
        if (!is_file(ExportFileWriter::FILE_PATH) || !is_readable(ExportFileWriter::FILE_PATH)) {
            return $this->error(404, 'file_export_unavailable', 'Es ist noch kein File Export verfügbar.');
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

    /** @param array<string, string> $headers */
    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $status,
            ['Cache-Control' => 'no-store'] + $headers
        );
    }
}
