<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Retrieves enabled, non-deleted API keys and their stored password hashes.
 */
final class ApiKeyRepository
{
    public const TABLE = 'tx_solrcontentapi_domain_model_apikey';

    /**
     * Initializes database access for API-key records.
     *
     * @param ConnectionPool $connectionPool Pool providing the API-key table connection.
     */
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Loads active API-key records with consistently typed database values.
     *
     * @return array<int, array{uid: int, label: string, api_key: string}> Active keys with hashed credentials.
     */
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

        return array_map(
            /**
             * Normalizes the database driver's values for a single API-key record.
             *
             * @param array<string, mixed> $row Database row containing the identifier, label, and hash.
             * @return array{uid: int, label: string, api_key: string} Normalized API-key record.
             */
            static fn(array $row): array => [
                'uid' => (int)$row['uid'],
                'label' => (string)$row['label'],
                'api_key' => (string)$row['api_key'],
            ],
            $rows
        );
    }
}
