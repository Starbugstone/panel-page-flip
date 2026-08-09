<?php

namespace App\Service;

use App\ComicSource\ComicPageProviderFactory;
use App\ComicSource\PageResult;
use App\Entity\Comic;
use App\Enum\ComicSourceType;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class ComicService
{
    public function __construct(
        private readonly string $comicsDirectory,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly int $uploadMaxTotalBytes,
        private readonly int $uploadUserQuotaBytes,
        private readonly ComicPageProviderFactory $pageProviderFactory,
        private readonly ComicFormatService $comicFormatService
    ) {
    }

    public function uploadComic(
        UploadedFile $file,
        User $user,
        string $title,
        ?string $author = null,
        ?string $publisher = null,
        ?string $description = null,
        array $tags = []
    ): Comic {
        if (!$file->isValid()) {
            throw new \RuntimeException('Invalid uploaded file.');
        }

        $originalName = $file->getClientOriginalName();
        $originalFilename = pathinfo($originalName, PATHINFO_FILENAME);
        $extension = strtolower($file->getClientOriginalExtension());

        $sourceType = ComicSourceType::tryFrom($extension)
            ?? throw new \RuntimeException('Unsupported comic source format.');
        if (!$this->comicFormatService->isEnabled($sourceType)) {
            throw new \RuntimeException(sprintf('%s uploads are not enabled by the administrator.', strtoupper($sourceType->value)));
        }

        $incomingSize = (int) ($file->getSize() ?? filesize($file->getPathname()) ?: 0);
        if ($incomingSize <= 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }

        if ($incomingSize > $this->uploadMaxTotalBytes) {
            throw new \RuntimeException('Uploaded file is too large.');
        }

        if ($this->wouldExceedQuota($user, $incomingSize)) {
            throw new \RuntimeException('User storage quota exceeded.');
        }

        $this->ensureDirectory($this->comicsDirectory);
        $userDirectory = $this->comicsDirectory . '/' . $user->getId();
        $this->ensureDirectory($userDirectory);

        $safeFilename = (string) $this->slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid('', true) . '.' . $sourceType->value;
        // TODO: Shard comic archives into nested directories before large
        // libraries put enough files in one user directory to degrade OS/filesystem performance.
        $absolutePath = $userDirectory . '/' . $newFilename;

        try {
            if (!copy($file->getPathname(), $absolutePath)) {
                throw new \RuntimeException('Failed to copy uploaded file.');
            }
            chmod($absolutePath, 0644);
            $provider = $this->pageProviderFactory->for($sourceType);
            $sourceInfo = $provider->inspect($absolutePath, $sourceType);
            if ($sourceInfo->pageCount < 1) {
                throw new \RuntimeException('Comic source contains no pages.');
            }
            $cover = $provider->readPage($absolutePath, $sourceType, 1);
        } catch (\Throwable $e) {
            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }
            $this->logger->warning('Comic upload rejected.', ['reason' => $e->getMessage()]);
            throw new \RuntimeException('Uploaded file is not a valid or supported comic source.');
        }

        $fileSize = filesize($absolutePath) ?: $incomingSize;
        $pageCount = $sourceInfo->pageCount;

        $connection = $this->entityManager->getConnection();
        $coverPath = null;

        try {
            $connection->beginTransaction();
            $comic = new Comic();
            $comic->setTitle($title);
            $comic->setFilePath($newFilename);
            $comic->setFileSize($fileSize);
            $comic->setPageCount($pageCount);
            $comic->setSourceType($sourceType);
            $comic->setOwner($user);

            if ($author) $comic->setAuthor($author);
            if ($publisher) $comic->setPublisher($publisher);
            if ($description) $comic->setDescription($description);

            foreach ($this->normaliseTagNames($tags) as $tagName) {
                /** @var TagRepository $tagRepository */
                $tagRepository = $this->entityManager->getRepository(Tag::class);
                $tag = $tagRepository->findAvailableByName($tagName, $user);

                if (!$tag) {
                    $tag = new Tag();
                    $tag->setName($tagName);
                    $tag->setCreator($user);
                    $this->entityManager->persist($tag);
                }

                $comic->addTag($tag);
            }

            $this->entityManager->persist($comic);
            $this->entityManager->flush();

            $comicId = $comic->getId() ?? throw new \RuntimeException('Comic identifier was not assigned.');
            $baseCoverFilename = (string) $this->slugger->slug(pathinfo($originalName, PATHINFO_FILENAME));
            $coverPath = $this->storeCover($cover, $user, $comicId, $baseCoverFilename);
            $comic->setCoverImagePath($coverPath);
            $this->entityManager->flush();
            $connection->commit();

            return $comic;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) $connection->rollBack();
            if ($coverPath !== null) @unlink($userDirectory . '/' . ltrim($coverPath, '/'));
            if (is_file($absolutePath)) @unlink($absolutePath);
            $this->logger->error('Comic upload finalization failed.', ['reason' => $e->getMessage()]);
            throw new \RuntimeException('Comic upload could not be finalized.', previous: $e);
        }
    }

    /** @return list<array{originalPath: string, quarantinePath: string}> */
    public function quarantineComicFiles(Comic $comic): array
    {
        $user = $comic->getOwner();
        if (!$user) {
            $this->logger->warning('Cannot quarantine comic files because comic has no owner.', ['comic_id' => $comic->getId()]);
            return [];
        }

        $paths = [];
        $comicSource = $this->findComicSource($comic);
        if ($comicSource !== null) {
            $paths[] = $comicSource;
        }

        if ($comic->getCoverImagePath()) {
            $paths[] = $this->comicsDirectory . '/' . $user->getId() . '/' . ltrim($comic->getCoverImagePath(), '/');
        }

        return $this->fileQuarantine->quarantine($paths);
    }

    public function comicSourceExists(Comic $comic): bool
    {
        return $this->findComicSource($comic) !== null;
    }

    /**
     * Absolute path of a comic's archive on disk, or null when it is missing.
     *
     * Callers serve the file from here; the path itself is never handed to a
     * client.
     */
    public function locateComicArchive(Comic $comic): ?string
    {
        return $this->findComicSource($comic);
    }

    public function locateComicSource(Comic $comic): ?string
    {
        return $this->findComicSource($comic);
    }

    public function readPage(Comic $comic, int $page): PageResult
    {
        $path = $this->locateComicSource($comic) ?? throw new \RuntimeException('Comic source is missing.');
        return $this->pageProviderFactory->for($comic->getSourceType())->readPage($path, $comic->getSourceType(), $page);
    }

    /** @param list<array{originalPath: string, quarantinePath: string}> $records */
    public function restoreQuarantinedFiles(array $records): void
    {
        $this->fileQuarantine->restore($records);
    }

    private function findComicSource(Comic $comic): ?string
    {
        $filePath = $comic->getFilePath();
        $owner = $comic->getOwner();
        if (!$filePath || !$owner) {
            return null;
        }

        $relativePath = ltrim($filePath, '/\\');
        $candidates = [
            $this->comicsDirectory . '/' . $owner->getId() . '/' . $relativePath,
            $this->comicsDirectory . '/' . $owner->getId() . '/' . basename($relativePath),
            $this->comicsDirectory . '/' . $relativePath,
        ];

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function storeCover(PageResult $cover, User $user, int $comicId, string $base): string
    {
        $extension = match ($cover->mimeType) { 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'jpg' };
        $directory = $this->comicsDirectory . '/' . $user->getId() . '/covers/' . $comicId;
        $this->ensureDirectory($directory);
        $filename = $base . '-cover-' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (file_put_contents($directory . '/' . $filename, $cover->content, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to save cover image.');
        }
        chmod($directory . '/' . $filename, 0644);
        return 'covers/' . $comicId . '/' . $filename;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create required storage directory.');
        }
    }

    /**
     * Whether storing an extra $additionalBytes for this user would push them
     * past their quota. Every path that adds a comic must consult this.
     */
    public function wouldExceedQuota(User $user, int $additionalBytes): bool
    {
        return $this->getUserStorageBytes($user) + $additionalBytes > $this->uploadUserQuotaBytes;
    }

    public function getUserStorageBytes(User $user): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(c.fileSize), 0)')
            ->from(Comic::class, 'c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<int, mixed> $tags
     * @return list<string>
     */
    private function normaliseTagNames(array $tags): array
    {
        $names = [];
        foreach ($tags as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? '') : $tag;
            $name = trim((string) $name);
            if ($name !== '') {
                $names[strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }
}
