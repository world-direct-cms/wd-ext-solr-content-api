<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Probe;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WorldDirect\Healthcheck\Probe\ProbeBase;
use WorldDirect\Healthcheck\Probe\ProbeInterface;
use WorldDirect\SolrContentApi\Export\ExportFileWriter;

/**
 * Checks that the published export file exists and was generated within the last 26 hours.
 */
final class ExportFileProbe extends ProbeBase implements ProbeInterface
{
    private const MAX_AGE_HOURS = 26;

    private const COMMAND_NAME = 'solr-content-api:file-export';

    /**
     * The probe has no optional dependencies and always runs.
     *
     * @return bool Always true.
     */
    public function useProbe(): bool
    {
        return true;
    }

    /**
     * Returns the probe title shown in the healthcheck output.
     *
     * @return string Translated probe title.
     */
    public function getTitle(): string
    {
        return $this->label('probe.export_file.title');
    }

    /**
     * Checks existence, readability, and age of the export file.
     *
     * The modification time is used because the file is only replaced after a successful export.
     */
    public function run(): void
    {
        parent::start();

        try {
            $path = ExportFileWriter::FILE_PATH;
            clearstatcache(true, $path);
            $modified = is_file($path) && is_readable($path) ? filemtime($path) : false;

            if ($modified === false) {
                $this->result->addErrorMessage($this->label('probe.export_file.missing'));
                $this->addSchedulerHint();
            } elseif (time() - $modified > self::MAX_AGE_HOURS * 3600) {
                $this->result->addErrorMessage(
                    $this->label('probe.export_file.stale', gmdate('Y-m-d\TH:i:s\Z', $modified), self::MAX_AGE_HOURS)
                );
                $this->addSchedulerHint();
            } else {
                $this->result->addSuccessMessage(
                    $this->label('probe.export_file.success', gmdate('Y-m-d\TH:i:s\Z', $modified))
                );
            }
        } catch (\Throwable) {
            $this->result->addErrorMessage($this->label('probe.export_file.exception'));
        }

        parent::stop();
    }

    /**
     * Adds a hint when no active scheduler task runs the export command, as the export may also run via cron.
     */
    private function addSchedulerHint(): void
    {
        try {
            if (!$this->hasActiveSchedulerTask()) {
                $this->result->addErrorMessage($this->label('probe.export_file.no_scheduler_task', self::COMMAND_NAME));
            }
        } catch (\Throwable) {
            // The hint is optional; the actual check result has already been reported.
        }
    }

    /**
     * Looks for an enabled scheduler task whose serialized command task references the export command.
     *
     * @return bool True if such a task exists.
     */
    private function hasActiveSchedulerTask(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('scheduler')) {
            return false;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_scheduler_task');
        $count = $queryBuilder
            ->count('uid')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('disable', 0),
                $queryBuilder->expr()->like(
                    'serialized_task_object',
                    $queryBuilder->createNamedParameter(
                        '%' . $queryBuilder->escapeLikeWildcards('"' . self::COMMAND_NAME . '"') . '%'
                    )
                )
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * Translates a probe label in the healthcheck language and optionally replaces its sprintf placeholders.
     *
     * @param string $key Message identifier in locallang.xlf.
     * @param string|int ...$arguments Values for sprintf placeholders.
     * @return string Translated message, or its identifier if the label is unavailable.
     */
    private function label(string $key, string|int ...$arguments): string
    {
        $label = $this->langService->sL('LLL:EXT:solr_content_api/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;

        return $arguments === [] ? $label : vsprintf($label, $arguments);
    }
}
