<?php

declare(strict_types=1);

namespace WorldDirect\SolrContentApi\EventListener;

use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\BeforeFileDeletedEvent;
use TYPO3\CMS\Core\Resource\File;
use WorldDirect\SolrContentApi\Domain\Repository\FileDeletionRepository;

/**
 * Captures Solr document UIDs before TYPO3 removes a file and persists them after deletion.
 */
final class FileDeletionListener
{
    /** @var array<int, int[]> Metadata UIDs grouped by their FAL file UID for the current request. */
    private array $metadataUidsByFileUid = [];

    /**
     * Initializes access to the file metadata lookup and deletion-event store.
     *
     * @param FileDeletionRepository $fileDeletionRepository Repository resolving and recording Solr document UIDs.
     */
    public function __construct(private readonly FileDeletionRepository $fileDeletionRepository) {}

    /**
     * Captures metadata document UIDs before TYPO3 removes the file and its metadata records.
     *
     * @param BeforeFileDeletedEvent $event Event containing the FAL file about to be deleted.
     */
    public function captureMetadataUids(BeforeFileDeletedEvent $event): void
    {
        $file = $event->getFile();
        if (!$file instanceof File) {
            return;
        }

        $fileUid = (int)$file->getUid();
        $this->metadataUidsByFileUid[$fileUid] = $this->fileDeletionRepository->findMetadataUidsForFile($fileUid);
    }

    /**
     * Persists the captured metadata UIDs after TYPO3 has completed the FAL deletion.
     *
     * @param AfterFileDeletedEvent $event Event containing the deleted FAL file.
     */
    public function persistDeletionEvents(AfterFileDeletedEvent $event): void
    {
        $file = $event->getFile();
        if (!$file instanceof File) {
            return;
        }

        $fileUid = (int)$file->getUid();
        $metadataUids = $this->metadataUidsByFileUid[$fileUid] ?? [];
        unset($this->metadataUidsByFileUid[$fileUid]);

        $this->fileDeletionRepository->recordDeletedMetadataUids($metadataUids);
    }
}
