<?php

namespace App\Service;

final class FolderDeletionConfirmationRequired extends \RuntimeException
{
    /** @param array{folderCount:int, comicCount:int, destinationFolderId:?int} $summary */
    public function __construct(private readonly array $summary)
    {
        parent::__construct('This folder is not empty and requires confirmation.');
    }

    /** @return array{folderCount:int, comicCount:int, destinationFolderId:?int} */
    public function summary(): array
    {
        return $this->summary;
    }
}
