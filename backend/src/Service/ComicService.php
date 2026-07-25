<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use ZipArchive;

class ComicService
{
    public function __construct(
        private readonly string $comicsDirectory,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly int $uploadMaxTotalBytes,
        private readonly int $uploadUserQuotaBytes
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

        if ($extension !== 'cbz') {
            throw new \RuntimeException('Only CBZ files are allowed.');
        }

        $incomingSize = (int) ($file->getSize() ?? filesize($file->getPathname()) ?: 0);
        if ($incomingSize <= 0) {
            throw new \RuntimeException('Uploaded file is empty.');
        }

        if ($incomingSize > $this->uploadMaxTotalBytes) {
            throw new \RuntimeException('Uploaded file is too large.');
        }

        if ($this->getUserStorageBytes($user) + $incomingSize > $this->uploadUserQuotaBytes) {
            throw new \RuntimeException('User storage quota exceeded.');
        }

        $this->ensureDirectory($this->comicsDirectory);
        $userDirectory = $this->comicsDirectory . '/' . $user->getId();
        $this->ensureDirectory($userDirectory);

        $safeFilename = (string) $this->slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid('', true) . '.cbz';
        $absolutePath = $userDirectory . '/' . $newFilename;

        try {
            if (!copy($file->getPathname(), $absolutePath)) {
                throw new \RuntimeException('Failed to copy uploaded file.');
            }
            chmod($absolutePath, 0644);
            $this->validateCbzArchive($absolutePath);
        } catch (\Throwable $e) {
            if (file_exists($absolutePath)) {
                unlink($absolutePath);
            }
            $this->logger->warning('Comic upload rejected.', ['reason' => $e->getMessage()]);
            throw new \RuntimeException('Uploaded file is not a valid CBZ archive.');
        }

        $fileSize = filesize($absolutePath) ?: $incomingSize;
        $pageCount = $this->countPagesInCbz($absolutePath);

        $comic = new Comic();
        $comic->setTitle($title);
        $comic->setFilePath($newFilename);
        $comic->setFileSize($fileSize);
        $comic->setPageCount($pageCount);
        $comic->setOwner($user);

        if ($author) {
            $comic->setAuthor($author);
        }

        if ($publisher) {
            $comic->setPublisher($publisher);
        }

        if ($description) {
            $comic->setDescription($description);
        }

        foreach ($this->normaliseTagNames($tags) as $tagName) {
            $tag = $this->entityManager->getRepository(Tag::class)->findOneBy([
                'name' => $tagName,
                'creator' => $user,
            ]);

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

        $baseCoverFilename = (string) $this->slugger->slug(pathinfo($originalName, PATHINFO_FILENAME));
        $comic->setCoverImagePath($this->extractCoverImage($absolutePath, $user, $comic->getId(), $baseCoverFilename));
        $this->entityManager->flush();

        return $comic;
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
        $comicArchive = $this->findComicArchive($comic);
        if ($comicArchive !== null) {
            $paths[] = $comicArchive;
        }

        if ($comic->getCoverImagePath()) {
            $paths[] = $this->comicsDirectory . '/' . $user->getId() . '/' . ltrim($comic->getCoverImagePath(), '/');
        }

        return $this->fileQuarantine->quarantine($paths);
    }

    public function comicArchiveExists(Comic $comic): bool
    {
        return $this->findComicArchive($comic) !== null;
    }

    /** @param list<array{originalPath: string, quarantinePath: string}> $records */
    public function restoreQuarantinedFiles(array $records): void
    {
        $this->fileQuarantine->restore($records);
    }

    private function findComicArchive(Comic $comic): ?string
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

    private function validateCbzArchive(string $absolutePath): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($absolutePath);
        if (!in_array($mime, ['application/zip', 'application/x-cbz', 'application/octet-stream'], true)) {
            throw new \RuntimeException('Invalid archive MIME type.');
        }

        if (count($this->getImageFilesFromArchive($absolutePath)) === 0) {
            throw new \RuntimeException('Archive contains no supported image files.');
        }
    }

    private function extractCoverImage(string $cbzAbsPath, User $user, int $comicId, string $baseCoverFilename): ?string
    {
        $imageFiles = $this->getImageFilesFromArchive($cbzAbsPath);
        if (empty($imageFiles)) {
            throw new \RuntimeException('No image files found in the CBZ archive.');
        }

        $zip = new ZipArchive();
        if ($zip->open($cbzAbsPath) !== true) {
            throw new \RuntimeException('Failed to open CBZ file.');
        }

        $firstImageNameInZip = $imageFiles[0];
        $coverExtension = strtolower(pathinfo($firstImageNameInZip, PATHINFO_EXTENSION));
        $actualCoverFilename = $baseCoverFilename . '-cover-' . uniqid('', true) . '.' . $coverExtension;
        $coverStorageDirAbs = $this->comicsDirectory . '/' . $user->getId() . '/covers/' . $comicId;
        $this->ensureDirectory($coverStorageDirAbs);

        $extractedImageData = $zip->getFromName($firstImageNameInZip);
        $zip->close();

        if ($extractedImageData === false) {
            throw new \RuntimeException('Failed to extract cover image.');
        }

        $fullCoverPathOnDisk = $coverStorageDirAbs . '/' . $actualCoverFilename;
        if (file_put_contents($fullCoverPathOnDisk, $extractedImageData) === false) {
            throw new \RuntimeException('Failed to save cover image.');
        }
        chmod($fullCoverPathOnDisk, 0644);

        return 'covers/' . $comicId . '/' . $actualCoverFilename;
    }

    private function countPagesInCbz(string $cbzPath): int
    {
        return count($this->getImageFilesFromArchive($cbzPath));
    }

    /**
     * @return list<string>
     */
    private function getImageFilesFromArchive(string $cbzPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($cbzPath) !== true) {
            throw new \RuntimeException('Failed to open CBZ file.');
        }

        $imageFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (!$filename || str_starts_with($filename, '__MACOSX/') || str_starts_with(basename($filename), '.')) {
                continue;
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $imageFiles[] = $filename;
            }
        }

        $zip->close();
        usort($imageFiles, 'strnatcmp');

        return $imageFiles;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Failed to create required storage directory.');
        }
    }

    private function getUserStorageBytes(User $user): int
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
