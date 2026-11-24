<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use WorldDirect\SolrContentApi\Probe\ApiKeyProbe;
use WorldDirect\SolrContentApi\Probe\ExportFileProbe;
use WorldDirect\SolrContentApi\Probe\IndexDocumentsProbe;

defined('TYPO3') or die();

if (ExtensionManagementUtility::isLoaded('healthcheck')) {
    $GLOBALS['TYPO3_CONF_VARS']['EXT']['healthcheck']['probe'][] = ExportFileProbe::class;
    $GLOBALS['TYPO3_CONF_VARS']['EXT']['healthcheck']['probe'][] = IndexDocumentsProbe::class;
    $GLOBALS['TYPO3_CONF_VARS']['EXT']['healthcheck']['probe'][] = ApiKeyProbe::class;
}
