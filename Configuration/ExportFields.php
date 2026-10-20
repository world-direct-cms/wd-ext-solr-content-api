<?php

return [
    'default' => ['type', 'uid', 'pid', 'created', 'changed', 'indexed'],
    'types' => [
        'pages' => ['url', 'title', 'content'],
        'fe_users' => ['url', 'title', 'content'],
        'tx_news_domain_model_news' => ['url', 'author', 'title', 'teaser', 'content', 'year_intS'],
        'sys_file_metadata' => ['fileExtension', 'title_stringS', 'size_intS', 'content', 'url'],
    ],
];
