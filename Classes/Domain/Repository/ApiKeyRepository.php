<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

final class ApiKeyRepository
{
    public const TABLE = 'tx_solrcontentapi_domain_model_apikey';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /** @return array<int, array{uid: int, label: string, api_key: string}> */
    public function findActive(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('uid', 'label', 'api_key')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'uid' => (int)$row['uid'],
            'label' => (string)$row['label'],
            'api_key' => (string)$row['api_key'],
        ], $rows);
    }
}
