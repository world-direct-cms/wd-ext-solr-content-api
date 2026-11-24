<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Probe;

use WorldDirect\Healthcheck\Probe\ProbeBase;
use WorldDirect\Healthcheck\Probe\ProbeInterface;
use WorldDirect\SolrContentApi\Domain\Repository\ApiKeyRepository;

/**
 * Checks that at least one active API key exists.
 */
final class ApiKeyProbe extends ProbeBase implements ProbeInterface
{
    /**
     * @param ApiKeyRepository $apiKeyRepository Repository providing the active API keys.
     */
    public function __construct(private readonly ApiKeyRepository $apiKeyRepository)
    {
        parent::__construct();
    }

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
        return $this->label('probe.api_key.title');
    }

    /**
     * Fails if no enabled, non-deleted API key exists.
     */
    public function run(): void
    {
        parent::start();

        try {
            $count = count($this->apiKeyRepository->findActive());
            if ($count > 0) {
                $this->result->addSuccessMessage($this->label('probe.api_key.success', $count));
            } else {
                $this->result->addErrorMessage($this->label('probe.api_key.missing'));
            }
        } catch (\Throwable) {
            $this->result->addErrorMessage($this->label('probe.api_key.exception'));
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
