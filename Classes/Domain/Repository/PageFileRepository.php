<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\Domain\Repository;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;

/**
 * Determines which files are used on a page, so API clients can relate page documents to file documents.
 *
 * Called while EXT:solr indexes a page. A file counts as used on the page if it is
 * - attached to the page record itself (e.g. `pages.media`),
 * - attached to a visible content element on the page (images, media, downloads), or
 * - linked in a field of such a content element (e.g. RTE link `t3://file?uid=…`, taken from `sys_refindex`).
 *
 * The result consists of `sys_file_metadata` UIDs because hmmh/solr-file-indexer uses them as Solr document UIDs.
 * Files rendered dynamically by plugins, folder-based file collections, and content included from other pages
 * via shortcut elements are not detected.
 */
final class PageFileRepository
{
    /**
     * Initializes database access for content, file reference, and reference index lookups.
     *
     * @param ConnectionPool $connectionPool Pool providing TYPO3 database connections.
     */
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Finds metadata UIDs of files attached to the page, its content elements, or linked in their fields.
     *
     * Uses frontend restrictions of the current context, so only content visible during indexing is considered.
     *
     * @param int $pageUid UID of the default-language page record.
     * @param int $languageId Language of the indexed page document.
     * @return int[] Default-language `sys_file_metadata` UIDs, which are used as Solr file document UIDs.
     */
    public function findMetadataUidsForPage(int $pageUid, int $languageId): array
    {
        if ($pageUid <= 0) {
            return [];
        }

        $contentUids = $this->findContentUids($pageUid, $languageId);
        $fileUids = array_merge(
            $this->findReferencedFileUids($pageUid, $contentUids),
            $this->findLinkedFileUids($contentUids),
        );

        return $this->findMetadataUids(array_values(array_unique($fileUids)));
    }

    /**
     * Loads the content elements of a page that are visible in the frontend for the given language.
     *
     * Includes elements valid for all languages (`sys_language_uid = -1`). Hidden, deleted, timed-out,
     * and access-restricted elements are excluded by the frontend restrictions of the indexing request.
     *
     * @param int $pageUid UID of the page whose content elements are loaded.
     * @param int $languageId Language of the indexed page document.
     * @return int[] UIDs of the matching `tt_content` records.
     */
    private function findContentUids(int $pageUid, int $languageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->setRestrictions(new FrontendRestrictionContainer());
        $uids = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid)),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter([$languageId, -1], ArrayParameterType::INTEGER)
                ),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $uids);
    }

    /**
     * Loads files attached as FAL references to the page record or to its content elements.
     *
     * Covers all `sys_file_reference` fields, e.g. page media, content images, media, and download lists.
     * Hidden or deleted references are ignored.
     *
     * @param int $pageUid UID of the page whose own file references are included.
     * @param int[] $contentUids UIDs of the visible content elements on the page.
     * @return int[] `sys_file` UIDs, possibly containing duplicates.
     */
    private function findReferencedFileUids(int $pageUid, array $contentUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->setRestrictions(new FrontendRestrictionContainer());
        $conditions = [
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($pageUid)),
            ),
        ];
        if ($contentUids !== []) {
            $conditions[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->in(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($contentUids, ArrayParameterType::INTEGER)
                ),
            );
        }

        $uids = $queryBuilder
            ->select('uid_local')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->or(...$conditions))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $uids);
    }

    /**
     * Loads files linked in fields of the content elements, such as RTE text (`t3://file?uid=…`).
     *
     * Reads the TYPO3 reference index of the live workspace, so the result is only complete
     * if the reference index is up to date.
     *
     * @param int[] $contentUids UIDs of the visible content elements on the page.
     * @return int[] `sys_file` UIDs, possibly containing duplicates.
     */
    private function findLinkedFileUids(array $contentUids): array
    {
        if ($contentUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $uids = $queryBuilder
            ->select('ref_uid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->in(
                    'recuid',
                    $queryBuilder->createNamedParameter($contentUids, ArrayParameterType::INTEGER)
                ),
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter('sys_file')),
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter(0)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $uids);
    }

    /**
     * Maps FAL file UIDs to the default-language metadata UIDs used as Solr file document UIDs.
     *
     * Files without a metadata record are dropped, as they cannot have a corresponding file document.
     *
     * @param int[] $fileUids Unique `sys_file` UIDs.
     * @return int[] `sys_file_metadata` UIDs in ascending order.
     */
    private function findMetadataUids(array $fileUids): array
    {
        if ($fileUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();
        $uids = $queryBuilder
            ->select('uid')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->in(
                    'file',
                    $queryBuilder->createNamedParameter($fileUids, ArrayParameterType::INTEGER)
                ),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $uids);
    }
}
