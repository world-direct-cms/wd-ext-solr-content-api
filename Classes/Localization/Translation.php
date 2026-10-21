<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Localization;

use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves extension messages through TYPO3 localization in HTTP and CLI contexts.
 */
final class Translation
{
    /**
     * Translates a message and optionally replaces its sprintf placeholders.
     *
     * @param string $key Message identifier in locallang.xlf.
     * @param string $language TYPO3 language key, or default for English.
     * @param array<int, scalar> $arguments Values for sprintf placeholders.
     * @return string Translated message, or its identifier if the label is unavailable.
     */
    public static function get(string $key, string $language = 'default', array $arguments = []): string
    {
        $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create($language);
        $message = $languageService->sL('LLL:EXT:solr_content_api/Resources/Private/Language/locallang.xlf:' . $key) ?: $key;

        return $arguments === [] ? $message : vsprintf($message, $arguments);
    }
}
