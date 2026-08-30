<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\LibraryFolder;
use App\Entity\LibraryFolderItem;
use App\Entity\User;
use App\Repository\LibraryFolderItemRepository;
use App\Repository\LibraryFolderRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

class LibraryFolderService
{
    public const MAX_DEPTH = 10;
    public const MAX_BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LibraryFolderRepository $folderRepository,
        private readonly LibraryFolderItemRepository $itemRepository,
        private readonly ComicLibraryQueryService $libraryQuery
    ) {
    }

    /** @return list<LibraryFolder> */
    public function list(User $owner): array
    {
        return $this->folderRepository->findForOwner($owner);
    }

    public function findOwned(User $owner, int $id): ?LibraryFolder
    {
        return $this->folderRepository->findOwned($id, $owner);
    }

    public function create(User $owner, string $name, ?int $parentId): LibraryFolder
    {
        $parent = $this->resolveParent($owner, $parentId);
        $name = $this->validateName($name);
        $this->assertAvailableName($owner, $parent, $name);

        if ($this->depth($parent) + 1 > self::MAX_DEPTH) {
            throw new \DomainException(sprintf('Folders may be nested at most %d levels deep.', self::MAX_DEPTH));
        }

        $folder = (new LibraryFolder())
            ->setOwner($owner)
            ->setParent($parent)
            ->setName($name);
        $this->entityManager->persist($folder);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('A folder with that name already exists here.');
        }

        return $folder;
    }

    public function update(
        User $owner,
        LibraryFolder $folder,
        ?string $name,
        bool $changeParent,
        ?int $parentId
    ): LibraryFolder {
        $parent = $changeParent ? $this->resolveParent($owner, $parentId) : $folder->getParent();
        $nextName = $name === null ? $folder->getName() : $this->validateName($name);

        if ($parent === $folder || ($parent !== null && $this->isDescendantOf($parent, $folder))) {
            throw new \DomainException('A folder cannot be moved inside itself or one of its descendants.');
        }

        $newTopDepth = $this->depth($parent) + 1;
        if ($newTopDepth + $this->subtreeHeight($owner, $folder) - 1 > self::MAX_DEPTH) {
            throw new \DomainException(sprintf('Folders may be nested at most %d levels deep.', self::MAX_DEPTH));
        }

        $this->assertAvailableName($owner, $parent, $nextName, $folder);
        $folder->setName($nextName)->setParent($parent);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('A folder with that name already exists here.');
        }

        return $folder;
    }

    /**
     * @param list<int> $comicIds
     * @return list<Comic>
     */
    public function moveComics(User $user, array $comicIds, ?int $folderId): array
    {
        $normalisedIds = [];
        foreach ($comicIds as $comicId) {
            if ((!is_int($comicId) && !(is_string($comicId) && ctype_digit($comicId))) || (int) $comicId < 1) {
                throw new \InvalidArgumentException(sprintf('Choose between 1 and %d valid comics.', self::MAX_BATCH_SIZE));
            }
            $normalisedIds[] = (int) $comicId;
        }
        $comicIds = array_values(array_unique($normalisedIds));
        if ($comicIds === [] || count($comicIds) > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException(sprintf('Choose between 1 and %d valid comics.', self::MAX_BATCH_SIZE));
        }

        $folder = $this->resolveParent($user, $folderId);
        $resolvedFolderId = $folder?->getId();
        $comics = $this->libraryQuery->findAccessibleByIds($user, $comicIds);
        if (count($comics) !== count($comicIds)) {
            throw new \DomainException('One or more comics were not found in your library.');
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $comics, $resolvedFolderId): void {
            $folder = null;
            if ($resolvedFolderId !== null) {
                $folder = $this->entityManager->find(
                    LibraryFolder::class,
                    $resolvedFolderId,
                    LockMode::PESSIMISTIC_READ
                );
                if (!$folder instanceof LibraryFolder || $folder->getOwner()?->getId() !== $user->getId()) {
                    throw new \DomainException('Folder not found.');
                }
            }

            foreach ($comics as $comic) {
                $item = $this->itemRepository->findOneBy(['user' => $user, 'comic' => $comic]);
                if ($folder === null) {
                    if ($item !== null) {
                        $this->entityManager->remove($item);
                    }
                    continue;
                }

                if ($item === null) {
                    $item = (new LibraryFolderItem())->setUser($user)->setComic($comic);
                    $this->entityManager->persist($item);
                }
                $item->setFolder($folder);
            }
        });

        return $comics;
    }

    /**
     * Place a freshly-created owned comic if its upload destination still
     * exists. A folder deleted during a long upload safely means root.
     */
    public function placeUploadedComic(User $user, Comic $comic, ?int $folderId): void
    {
        if ($folderId === null) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $comic, $folderId): void {
            $folder = $this->entityManager->find(LibraryFolder::class, $folderId, LockMode::PESSIMISTIC_READ);
            if (!$folder instanceof LibraryFolder || $folder->getOwner()?->getId() !== $user->getId()) {
                return;
            }

            $item = (new LibraryFolderItem())
                ->setUser($user)
                ->setComic($comic)
                ->setFolder($folder);
            $this->entityManager->persist($item);
        });
    }

    /**
     * Everything one viewer can currently see anywhere under a folder.
     *
     * The whole subtree, because a folder is what a person points at and its
     * subfolders are part of what they mean by it. Comics are resolved through
     * {@see ComicLibraryQueryService} rather than read off the placement rows,
     * so a placement left behind by a revoked or tombstoned share cannot put a
     * comic in an answer the viewer may no longer reach.
     *
     * @return array{comics: list<Comic>, folderCount: int}
     */
    public function subtreeContents(User $viewer, LibraryFolder $root): array
    {
        $subtree = $this->subtree($viewer, $root);
        $items = $this->itemRepository->findInFolders($viewer, $subtree);
        $comicIds = array_values(array_unique(array_map(
            static fn (LibraryFolderItem $item): int => (int) $item->getComic()?->getId(),
            $items
        )));

        return [
            'comics' => $this->libraryQuery->findVisibleByIds($viewer, $comicIds),
            'folderCount' => count($subtree),
        ];
    }

    /** @return array{folderCount:int, comicCount:int, destinationFolderId:?int} */
    public function deletionSummary(User $user, LibraryFolder $folder): array
    {
        $contents = $this->subtreeContents($user, $folder);

        return [
            'folderCount' => $contents['folderCount'],
            'comicCount' => count($contents['comics']),
            'destinationFolderId' => $folder->getParent()?->getId(),
        ];
    }

    /** @return array{folderCount:int, comicCount:int, destinationFolderId:?int} */
    public function delete(User $user, LibraryFolder $folder, bool $confirmed): array
    {
        $summary = $this->deletionSummary($user, $folder);
        $subtree = $this->subtree($user, $folder);
        $items = $this->itemRepository->findInFolders($user, $subtree);
        if (!$confirmed && ($summary['folderCount'] > 1 || $items !== [])) {
            throw new FolderDeletionConfirmationRequired($summary);
        }

        $destination = $folder->getParent();

        $this->entityManager->wrapInTransaction(function () use ($items, $destination, $folder): void {
            foreach ($items as $item) {
                if ($destination === null) {
                    $this->entityManager->remove($item);
                } else {
                    $item->setFolder($destination);
                }
            }
            $this->entityManager->remove($folder);
        });

        return $summary;
    }

    private function resolveParent(User $owner, ?int $parentId): ?LibraryFolder
    {
        if ($parentId === null) {
            return null;
        }

        $parent = $this->findOwned($owner, $parentId);
        if ($parent === null) {
            throw new \DomainException('Folder not found.');
        }

        return $parent;
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Folder names must contain between 1 and 100 characters.');
        }
        if (preg_match('/[\\/\\\\\p{Cc}]/u', $name)) {
            throw new \InvalidArgumentException('Folder names cannot contain slashes, backslashes, or control characters.');
        }

        return $name;
    }

    private function assertAvailableName(
        User $owner,
        ?LibraryFolder $parent,
        string $name,
        ?LibraryFolder $except = null
    ): void {
        if ($this->folderRepository->siblingNameExists($owner, $parent, $name, $except)) {
            throw new \DomainException('A folder with that name already exists here.');
        }
    }

    private function depth(?LibraryFolder $folder): int
    {
        $depth = 0;
        while ($folder !== null) {
            $depth++;
            $folder = $folder->getParent();
        }

        return $depth;
    }

    private function isDescendantOf(LibraryFolder $candidate, LibraryFolder $ancestor): bool
    {
        for ($current = $candidate; $current !== null; $current = $current->getParent()) {
            if ($current === $ancestor) {
                return true;
            }
        }

        return false;
    }

    private function subtreeHeight(User $owner, LibraryFolder $root): int
    {
        $folders = $this->folderRepository->findForOwner($owner);
        $children = [];
        foreach ($folders as $folder) {
            $children[$folder->getParent()?->getId() ?? 0][] = $folder;
        }

        $height = function (LibraryFolder $folder) use (&$height, $children): int {
            $maximum = 0;
            foreach ($children[$folder->getId()] ?? [] as $child) {
                $maximum = max($maximum, $height($child));
            }

            return 1 + $maximum;
        };

        return $height($root);
    }

    /** @return list<LibraryFolder> */
    private function subtree(User $owner, LibraryFolder $root): array
    {
        $folders = $this->folderRepository->findForOwner($owner);
        $children = [];
        foreach ($folders as $folder) {
            $children[$folder->getParent()?->getId() ?? 0][] = $folder;
        }

        $result = [];
        $visit = function (LibraryFolder $folder) use (&$visit, &$result, $children): void {
            $result[] = $folder;
            foreach ($children[$folder->getId()] ?? [] as $child) {
                $visit($child);
            }
        };
        $visit($root);

        return $result;
    }
}
