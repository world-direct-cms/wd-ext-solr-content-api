<?php

$EM_CONF['solr_content_api'] = [
    'title' => 'Solr Content API',
    'description' => 'Solr-backed file export API for TYPO3.',
    'category' => 'be',
    'author' => 'World Direct',
    'state' => 'alpha',
    'clearCacheOnLoad' => 1,
    'version' => '0.2.0',
    'constraints' => [
        'depends' => [
            'php' => '8.1.0-0.0.0',
            'typo3' => '12.4.0-13.9.99',
            'solr' => '12.1.0-12.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
