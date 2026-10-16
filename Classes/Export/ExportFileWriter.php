<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Export;

use RuntimeException;

final class ExportFileWriter
{
    public const FILE_PATH = '/var/www/html/var/json/export.json';

    private mixed $stream = null;
    private ?string $temporaryPath = null;
    private bool $firstCore = true;
    private bool $firstDocument = true;

    public function start(string $exportTimestamp): void
    {
        $directory = dirname(self::FILE_PATH);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the export directory.');
        }

        $this->temporaryPath = tempnam($directory, '.solr-content-api-');
        if ($this->temporaryPath === false) {
            $this->temporaryPath = null;
            throw new RuntimeException('Could not create a temporary export file.');
        }

        $this->stream = fopen($this->temporaryPath, 'wb');
        if ($this->stream === false) {
            $this->abort();
            throw new RuntimeException('Could not open the temporary export file.');
        }

        $this->write('{"exportTimestamp":' . $this->encode($exportTimestamp) . ',"cores":[');
    }

    public function startCore(string $site, int $languageId, string $core, int $count): void
    {
        if (!$this->firstCore) {
            $this->write(',');
        }
        $this->firstCore = false;
        $this->firstDocument = true;
        $this->write('{"site":' . $this->encode($site));
        $this->write(',"languageId":' . $languageId);
        $this->write(',"core":' . $this->encode($core));
        $this->write(',"count":' . $count . ',"documents":[');
    }

    /** @param array<string, mixed> $document */
    public function writeDocument(array $document): void
    {
        if (!$this->firstDocument) {
            $this->write(',');
        }
        $this->firstDocument = false;
        $this->write($this->encode($document));
    }

    public function finishCore(): void
    {
        $this->write(']}');
    }

    public function finish(): string
    {
        $this->write(']}');
        if (!fclose($this->stream)) {
            $this->stream = null;
            $this->abort();
            throw new RuntimeException('Could not close the export file.');
        }
        $this->stream = null;

        if ($this->temporaryPath === null || !rename($this->temporaryPath, self::FILE_PATH)) {
            $this->abort();
            throw new RuntimeException('Could not replace the export file.');
        }

        $this->temporaryPath = null;
        return self::FILE_PATH;
    }

    public function abort(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;

        if ($this->temporaryPath !== null && is_file($this->temporaryPath)) {
            unlink($this->temporaryPath);
        }
        $this->temporaryPath = null;
    }

    private function write(string $content): void
    {
        if (!is_resource($this->stream) || fwrite($this->stream, $content) === false) {
            throw new RuntimeException('Could not write the export file.');
        }
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
