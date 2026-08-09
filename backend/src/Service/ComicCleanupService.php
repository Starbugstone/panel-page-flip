<?php

namespace App\Service;

use App\Entity\Comic;
use App\Enum\ComicSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;

class ComicCleanupService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly string $comicsDirectory
    ) {
    }

    public function scan(): array
    {
        if (!is_dir($this->comicsDirectory)) {
            return [
                'orphanedComics' => [],
                'orphanedCovers' => [],
                'totals' => ['orphanedComics' => 0, 'orphanedCovers' => 0],
                'error' => sprintf('Comics directory "%s" does not exist.', $this->comicsDirectory),
            ];
        }

        $dbComics = $this->entityManager->getRepository(Comic::class)->findAll();
        $diskComicFiles = $this->findDiskComicFiles();
        $diskCoverFiles = $this->findDiskCoverFiles();

        $orphanedComics = array_values(array_filter($diskComicFiles, function (array $diskComic) use ($dbComics): bool {
            foreach ($dbComics as $dbComic) {
                if ($dbComic->getFilePath() === $diskComic['filename']) {
                    $owner = $dbComic->getOwner();
                    if ($diskComic['userId'] === null || ($owner && $owner->getId() === $diskComic['userId'])) {
                        return false;
                    }
                }
            }

            return true;
        }));

        $orphanedCovers = array_values(array_filter($diskCoverFiles, function (array $diskCover) use ($dbComics): bool {
            foreach ($dbComics as $dbComic) {
                $coverPath = $dbComic->getCoverImagePath();
                if ($coverPath && basename($coverPath) === $diskCover['filename']) {
                    if ($diskCover['comicId'] === null || $dbComic->getId() === $diskCover['comicId']) {
                        return false;
                    }
                }
            }

            return true;
        }));

        return [
            'orphanedComics' => $orphanedComics,
            'orphanedCovers' => $orphanedCovers,
            'totals' => [
                'orphanedComics' => count($orphanedComics),
                'orphanedCovers' => count($orphanedCovers),
            ],
        ];
    }

    public function apply(): array
    {
        $scan = $this->scan();
        if (isset($scan['error'])) {
            return $scan;
        }

        $quarantinedComics = $this->quarantineFiles($scan['orphanedComics']);
        $quarantinedCovers = $this->quarantineFiles($scan['orphanedCovers']);

        return [
            ...$scan,
            'quarantined' => [
                'orphanedComics' => $quarantinedComics,
                'orphanedCovers' => $quarantinedCovers,
            ],
        ];
    }

    /**
     * Every extension the application can hold on disk, derived from the enum
     * rather than spelled out, so orphan sweeping does not start ignoring a
     * format the day one is added.
     *
     * Every supported format is matched, not only the currently enabled ones:
     * turning a format off must not strand the files already imported under it.
     */
    private function comicSourceNamePattern(): string
    {
        return '/\.(?:'.implode('|', array_map('preg_quote', ComicSourceType::extensions())).')$/i';
    }

    private function findDiskComicFiles(): array
    {
        $files = [];

        if (!is_dir($this->comicsDirectory)) {
            return $files;
        }

        $namePattern = $this->comicSourceNamePattern();

        $rootFinder = new Finder();
        foreach ($rootFinder->files()->name($namePattern)->in($this->comicsDirectory)->depth(0) as $file) {
            $files[] = [
                'filename' => $file->getFilename(),
                'path' => $file->getRealPath(),
                'userId' => null,
            ];
        }

        $userDirFinder = new Finder();
        foreach ($userDirFinder->directories()->in($this->comicsDirectory)->depth(0) as $userDir) {
            if ($userDir->getFilename() === 'covers' || !is_numeric($userDir->getFilename())) {
                continue;
            }

            $userComicsFinder = new Finder();
            foreach ($userComicsFinder->files()->name($namePattern)->in($userDir->getRealPath())->depth(0) as $file) {
                $files[] = [
                    'filename' => $file->getFilename(),
                    'path' => $file->getRealPath(),
                    'userId' => (int) $userDir->getFilename(),
                ];
            }
        }

        return $files;
    }

    private function findDiskCoverFiles(): array
    {
        $files = [];
        $this->collectCoverFilesFromDirectory($this->comicsDirectory . '/covers', $files);

        $userDirFinder = new Finder();
        foreach ($userDirFinder->directories()->in($this->comicsDirectory)->depth(0) as $userDir) {
            if ($userDir->getFilename() === 'covers' || !is_numeric($userDir->getFilename())) {
                continue;
            }

            $this->collectCoverFilesFromDirectory($userDir->getRealPath() . '/covers', $files);
        }

        return $files;
    }

    private function collectCoverFilesFromDirectory(string $coversDirectory, array &$files): void
    {
        if (!is_dir($coversDirectory)) {
            return;
        }

        $rootFinder = new Finder();
        foreach ($rootFinder->files()->in($coversDirectory)->depth(0) as $file) {
            $files[] = [
                'filename' => $file->getFilename(),
                'path' => $file->getRealPath(),
                'comicId' => null,
            ];
        }

        $comicDirFinder = new Finder();
        foreach ($comicDirFinder->directories()->in($coversDirectory)->depth(0) as $comicDir) {
            if (!is_numeric($comicDir->getFilename())) {
                continue;
            }

            $comicCoversFinder = new Finder();
            foreach ($comicCoversFinder->files()->in($comicDir->getRealPath())->depth(0) as $file) {
                $files[] = [
                    'filename' => $file->getFilename(),
                    'path' => $file->getRealPath(),
                    'comicId' => (int) $comicDir->getFilename(),
                ];
            }
        }
    }

    private function quarantineFiles(array $files): int
    {
        $paths = array_values(array_filter(array_column($files, 'path'), 'is_string'));

        return count($this->fileQuarantine->quarantine($paths));
    }
}
