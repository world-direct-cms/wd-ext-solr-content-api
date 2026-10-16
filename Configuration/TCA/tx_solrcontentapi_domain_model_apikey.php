<?php

declare(strict_types=1);

defined('TYPO3') or die();

$languageFile = 'LLL:EXT:solr_content_api/Resources/Private/Language/locallang_db.xlf:tx_solrcontentapi_domain_model_apikey';

return [
    'ctrl' => [
        'title' => $languageFile,
        'label' => 'label',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'default_sortby' => 'label ASC',
        'searchFields' => 'label',
        'rootLevel' => -1,
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_tca.xlf:hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'label' => [
            'exclude' => false,
            'label' => $languageFile . '.label',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'api_key' => [
            'exclude' => false,
            'label' => $languageFile . '.api_key',
            'config' => [
                'type' => 'password',
                'size' => 40,
                'hashed' => true,
                'required' => true,
                'fieldControl' => [
                    'passwordGenerator' => [
                        'renderType' => 'passwordGenerator',
                        'options' => [
                            'allowEdit' => true,
                        ],
                    ],
                ],
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => 'label, api_key, --div--;LLL:EXT:core/Resources/Private/Language/locallang_tca.xlf:tabs.access, hidden',
        ],
    ],
];
