<?php

declare(strict_types=1);

use WorldDirect\SolrContentApi\Middleware\ApiMiddleware;

return [
    'frontend' => [
        'worlddirect/solr-content-api/api' => [
            'target' => ApiMiddleware::class,
            'before' => [
                'typo3/cms-frontend/authentication',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
