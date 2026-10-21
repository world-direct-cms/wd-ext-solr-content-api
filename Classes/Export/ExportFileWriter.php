<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Export;

use RuntimeException;
use WorldDirect\SolrContentApi\Localization\Translation;

/**
 * Streams a JSON export to a temporary file and atomically publishes it on completion.
 */
final class ExportFileWriter
{
    public const FILE_PATH = '/var/www/html/var/json/export.json';

    private mixed $stream = null;
    private ?string $temporaryPath = null;
    /** @var array<string, resource> */
    private array $typeStreams = [];
    /** @var array<string, int> */
    private array $typeCounts = [];

    /**
     * Creates a restricted temporary file and writes the export header.
     *
     * @param string $exportTimestamp UTC export timestamp included in the JSON metadata.
     * @throws RuntimeException If the directory or temporary file cannot be prepared or written.
     * @throws \JsonException If the timestamp cannot be JSON-encoded.
     */
    public function start(string $exportTimestamp): void
    {
        $directory = dirname(self::FILE_PATH);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException(Translation::get('exception.export_directory'));
        }

        $this->temporaryPath = tempnam($directory, '.solr-content-api-');
        if ($this->temporaryPath === false) {
            $this->temporaryPath = null;
            throw new RuntimeException(Translation::get('exception.temporary_file_create'));
        }

        if (!chmod($this->temporaryPath, 0640)) {
            $this->abort();
            throw new RuntimeException(Translation::get('exception.temporary_file_permissions'));
        }

        $this->stream = fopen($this->temporaryPath, 'wb');
        if ($this->stream === false) {
            $this->abort();
            throw new RuntimeException(Translation::get('exception.temporary_file_open'));
        }

        $this->write('{"exportTimestamp":' . $this->encode($exportTimestamp) . ',"types":{');
    }

    /**
     * Appends a filtered document to its type's temporary stream across all cores.
     *
     * @param array<string, mixed> $document Exportable Solr field names and their values.
     * @throws RuntimeException If writing to the export stream fails.
     * @throws \JsonException If the document cannot be JSON-encoded.
     */
    public function writeDocument(array $document): void
    {
        $type = $document['type'] ?? '';
        if (is_array($type)) {
            $type = reset($type) ?: '';
        }
        $type = (string)$type;

        if (!isset($this->typeStreams[$type])) {
            $stream = tmpfile();
            if ($stream === false) {
                throw new RuntimeException(Translation::get('exception.type_stream_create'));
            }
            $this->typeStreams[$type] = $stream;
            $this->typeCounts[$type] = 0;
        }

        $stream = $this->typeStreams[$type];
        $content = (ftell($stream) > 0 ? ',' : '') . $this->encode($document);
        if (fwrite($stream, $content) !== strlen($content)) {
            throw new RuntimeException(Translation::get('exception.type_stream_write'));
        }
        $this->typeCounts[$type]++;
    }

    /**
     * Closes the export and atomically replaces the previously published file.
     *
     * @return string Absolute path of the completed export file.
     * @throws RuntimeException If writing, closing, or publishing the export fails.
     */
    public function finish(): string
    {
        $firstType = true;
        foreach ($this->typeStreams as $type => $stream) {
            $this->write(($firstType ? '' : ',') . $this->encode((string)$type)
                . ':{"count":' . $this->typeCounts[$type] . ',"documents":[');
            $firstType = false;
            if (!rewind($stream) || stream_copy_to_stream($stream, $this->stream) === false) {
                throw new RuntimeException(Translation::get('exception.documents_copy'));
            }
            $this->write(']}');
            fclose($stream);
            unset($this->typeStreams[$type], $this->typeCounts[$type]);
        }

        $this->write('}}');
        if (!fclose($this->stream)) {
            $this->stream = null;
            $this->abort();
            throw new RuntimeException(Translation::get('exception.export_file_close'));
        }
        $this->stream = null;

        if ($this->temporaryPath === null || !rename($this->temporaryPath, self::FILE_PATH)) {
            $this->abort();
            throw new RuntimeException(Translation::get('exception.export_file_replace'));
        }

        $this->temporaryPath = null;
        return self::FILE_PATH;
    }

    /**
     * Closes the stream and removes the temporary file without replacing the published export.
     */
    public function abort(): void
    {
        foreach ($this->typeStreams as $stream) {
            fclose($stream);
        }
        $this->typeStreams = [];
        $this->typeCounts = [];

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;

        if ($this->temporaryPath !== null && is_file($this->temporaryPath)) {
            unlink($this->temporaryPath);
        }
        $this->temporaryPath = null;
    }

    /**
     * Writes raw JSON content to the open temporary file.
     *
     * @param string $content Serialized JSON fragment to append to the export.
     * @throws RuntimeException If no stream is open or writing fails.
     */
    private function write(string $content): void
    {
        if (!is_resource($this->stream) || fwrite($this->stream, $content) === false) {
            throw new RuntimeException(Translation::get('exception.export_file_write'));
        }
    }

    /**
     * Encodes a value as JSON while preserving readable Unicode and URL slashes.
     *
     * @param mixed $value JSON-serializable value to encode.
     * @return string Encoded JSON representation of the value.
     * @throws \JsonException If the value cannot be JSON-encoded.
     */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
