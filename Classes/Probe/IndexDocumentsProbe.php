<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Probe;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use WorldDirect\Healthcheck\Probe\ProbeBase;
use WorldDirect\Healthcheck\Probe\ProbeInterface;
use WorldDirect\SolrContentApi\Service\SolrExportService;

/**
 * Checks that every table selected in `exportTypes` has at least one public document in Solr.
 *
 * Without an explicit `exportTypes` selection nothing is counted and only a hint is reported.
 */
final class IndexDocumentsProbe extends ProbeBase implements ProbeInterface
{
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
        return $this->label('probe.index_documents.title');
    }

    /**
     * Reports the number of public documents per selected export type and fails for empty or unknown types.
     */
    public function run(): void
    {
        parent::start();

        try {
            // Resolved here instead of injected, so an invalid exportTypes configuration fails this probe only.
            $solrExportService = GeneralUtility::makeInstance(SolrExportService::class);

            if (!$solrExportService->hasExportTypeSelection()) {
                $this->result->addSuccessMessage($this->label('probe.index_documents.not_configured'));
            } else {
                foreach ($solrExportService->getUnknownExportTypes() as $type) {
                    $this->result->addErrorMessage($this->label('probe.index_documents.unknown', $type));
                }
                foreach ($solrExportService->countPublicDocumentsByType() as $type => $count) {
                    if ($count > 0) {
                        $this->result->addSuccessMessage($this->label('probe.index_documents.success', $type, $count));
                    } else {
                        $this->result->addErrorMessage($this->label('probe.index_documents.empty', $type));
                    }
                }
            }
        } catch (\Throwable) {
            $this->result->addErrorMessage($this->label('probe.index_documents.exception'));
        }

        parent::stop();
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
