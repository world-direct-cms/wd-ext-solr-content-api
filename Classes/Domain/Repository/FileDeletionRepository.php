<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Domain\Repository;

use DateTimeImmutable;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Stores file deletion events and returns them to the changes API.
 */
final class FileDeletionRepository
{
    /** Database table retaining file deletion events for later API requests. */
    public const TABLE = 'tx_solrcontentapi_file_deletion';

    /** TYPO3 table whose records are indexed as file documents in Solr. */
    private const FILE_METADATA_TABLE = 'sys_file_metadata';

    /**
     * Initializes database access for file metadata and deletion events.
     *
     * @param ConnectionPool $connectionPool Pool providing TYPO3 database connections.
     */
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Finds indexed metadata records belonging to a FAL file before it is removed.
     *
     * @param int $fileUid UID of the FAL record in the `sys_file` table.
     * @return int[] Metadata record UIDs used as Solr document UIDs.
     */
    public function findMetadataUidsForFile(int $fileUid): array
    {
        if ($fileUid <= 0) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FILE_METADATA_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $uids = $queryBuilder
            ->select('uid')
            ->from(self::FILE_METADATA_TABLE)
            ->where($queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid)))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $uids);
    }

    /**
     * Persists deletion events after TYPO3 successfully removes a file.
     *
     * @param int[] $metadataUids Metadata record UIDs to report as deleted.
     */
    public function recordDeletedMetadataUids(array $metadataUids): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach (array_unique(array_filter(array_map('intval', $metadataUids))) as $metadataUid) {
            if ($metadataUid <= 0) {
                continue;
            }
            $connection->insert(self::TABLE, [
                'document_uid' => $metadataUid,
                'deleted_at' => time(),
            ]);
        }
    }

    /**
     * Returns file deletion events at or after the requested timestamp.
     *
     * @param DateTimeImmutable $timestamp Inclusive lower bound for the deletion timestamp.
     * @return array<int, array{action: string, type: string, uid: int}> Deletion entries for the changes response.
     */
    public function findDeletedSince(DateTimeImmutable $timestamp): array
    {
        $minimumTimestamp = $timestamp->getTimestamp();
        if ($timestamp->format('u') !== '000000') {
            ++$minimumTimestamp;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $uids = $queryBuilder
            ->select('document_uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->gte('deleted_at', $queryBuilder->createNamedParameter($minimumTimestamp)))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            static fn(int|string $uid): array => [
                'action' => 'delete',
                'type' => self::FILE_METADATA_TABLE,
                'uid' => (int)$uid,
            ],
            $uids
        );
    }
}
