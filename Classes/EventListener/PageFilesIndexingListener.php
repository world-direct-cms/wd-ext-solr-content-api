<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\EventListener;

use ApacheSolrForTypo3\Solr\Event\Indexing\AfterPageDocumentIsCreatedForIndexingEvent;
use WorldDirect\SolrContentApi\Domain\Repository\PageFileRepository;

/**
 * Enriches page documents in Solr with the files used on the page.
 *
 * EXT:solr dispatches the event while indexing a page in the frontend, after the page document has been created
 * and before it is sent to Solr. The listener stores the `sys_file_metadata` UIDs determined by
 * PageFileRepository in the multivalued field `fileUids_intM`, which the API exports as `files`
 * (see `fieldAliases` in Configuration/ExportFields.php). Changes take effect when a page is reindexed.
 */
final class PageFilesIndexingListener
{
    /** Multivalued integer Solr field exported as `files`. */
    public const FIELD = 'fileUids_intM';

    /**
     * Initializes the lookup of files used on a page.
     *
     * @param PageFileRepository $pageFileRepository Repository resolving the files used on a page.
     */
    public function __construct(private readonly PageFileRepository $pageFileRepository) {}

    /**
     * Writes the file metadata UIDs into the page document before it is sent to Solr.
     *
     * Uses the queue item UID (default-language page) and the language of the indexed document.
     * The field is left out for pages without files, as Solr does not store empty multivalued fields.
     *
     * @param AfterPageDocumentIsCreatedForIndexingEvent $event Event containing the page document.
     */
    public function __invoke(AfterPageDocumentIsCreatedForIndexingEvent $event): void
    {
        $metadataUids = $this->pageFileRepository->findMetadataUidsForPage(
            $event->getIndexQueueItem()->getRecordUid(),
            $event->getSiteLanguage()->getLanguageId(),
        );
        if ($metadataUids !== []) {
            $event->getDocument()->setField(self::FIELD, $metadataUids);
        }
    }
}
