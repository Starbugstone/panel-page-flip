<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Spatie\Dropbox\Client as DropboxClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dropbox listing and import logic shared by the HTTP controller and the CLI
 * sync command. Both used to carry their own copy of this; keeping it in one
 * place is what lets duplicate detection and temp-file cleanup stay correct.
 */
class DropboxImportService
{
    public const IMPORT_TAG = 'Dropbox';

    private const IMPORT_DESCRIPTION = 'Synced from Dropbox';

    public function __construct(
        private readonly ComicService $comicService,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $dropboxAppFolder
    ) {
    }

    /**
     * Recursively collect every CBZ under the configured app folder.
     *
     * @return list<array{path: string, name: string, size: int, modified: ?string, tags: list<string>}>
     */
    public function listCbzFiles(DropboxClient $client, ?string $path = null): array
    {
        $path = $path ?? $this->dropboxAppFolder;
        $files = [];

        try {
            $response = $client->listFolder($path);

            // Dropbox pages large folders. Stopping at the first page would
            // silently hide every file past the page size from the sync.
            while (true) {
                foreach ($response['entries'] ?? [] as $entry) {
                    $tag = $entry['.tag'] ?? null;

                    if ($tag === 'folder') {
                        array_push($files, ...$this->listCbzFiles($client, $entry['path_display']));
                        continue;
                    }

                    if ($tag !== 'file' || strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) !== 'cbz') {
                        continue;
                    }

                    $files[] = [
                        'path' => $entry['path_display'],
                        'name' => $entry['name'],
                        'size' => (int) ($entry['size'] ?? 0),
                        'modified' => $entry['client_modified'] ?? null,
                        'tags' => $this->convertPathToTags(trim(dirname($entry['path_display']), '/')),
                    ];
                }

                if (empty($response['has_more']) || !isset($response['cursor'])) {
                    break;
                }

                $response = $client->listFolderContinue((string) $response['cursor']);
            }
        } catch (\Throwable $e) {
            // Callers already handle failures. Swallowing here would report a
            // partial listing as a complete one, so re-import decisions and the
            // "already synced" flags would be made against missing files.
            $this->logger->error('Error listing Dropbox folder.', ['path' => $path, 'exception' => $e]);

            throw $e;
        }

        return $files;
    }

    /**
     * Snapshot of what this user has already pulled in from Dropbox, used to
     * decide which remote files still need importing.
     *
     * @return array{paths: array<string, true>, titles: array<string, true>}
     */
    public function getImportedIndex(User $user): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.dropboxPath', 'c.title')
            ->from(Comic::class, 'c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $user)
            ->getQuery()
            ->getArrayResult();

        $paths = [];
        $titles = [];
        foreach ($rows as $row) {
            if ($row['dropboxPath'] !== null && $row['dropboxPath'] !== '') {
                $paths[mb_strtolower($row['dropboxPath'])] = true;
            }
            if ($row['title'] !== null && $row['title'] !== '') {
                $titles[$this->normaliseTitle($row['title'])] = true;
            }
        }

        return ['paths' => $paths, 'titles' => $titles];
    }

    /**
     * @param array{path: string, name: string, ...} $fileInfo
     * @param array{paths: array<string, true>, titles: array<string, true>} $index
     */
    public function isImported(array $fileInfo, array $index): bool
    {
        // Comics imported since dropbox_path was introduced record exactly where
        // they came from, so this is an exact answer.
        if (isset($index['paths'][mb_strtolower($fileInfo['path'])])) {
            return true;
        }

        // Comics imported before then have no recorded path. Fall back to the
        // title we would have generated for them, so an upgrade does not cause
        // the whole library to be re-imported.
        return isset($index['titles'][$this->normaliseTitle($this->titleFromFilename($fileInfo['name']))]);
    }

    /**
     * Download a Dropbox file and register it as a comic.
     *
     * The download is staged in the system temp directory rather than under the
     * comics directory: ComicService copies it to its final home, so anything
     * left in the comics tree would be a permanent duplicate.
     *
     * @param array{path: string, name: string, tags: list<string>, ...} $fileInfo
     */
    public function import(DropboxClient $client, User $user, array $fileInfo): Comic
    {
        $stagedPath = tempnam(sys_get_temp_dir(), 'dropbox_import_');
        if ($stagedPath === false) {
            throw new \RuntimeException('Could not create a temporary file for the Dropbox download.');
        }

        try {
            $this->downloadFile($client, $fileInfo['path'], $stagedPath);

            $comic = $this->comicService->uploadComic(
                new UploadedFile($stagedPath, $fileInfo['name'], 'application/zip', null, true),
                $user,
                $this->titleFromFilename($fileInfo['name']),
                null,
                null,
                self::IMPORT_DESCRIPTION,
                array_merge([self::IMPORT_TAG], $fileInfo['tags'])
            );

            $comic->setDropboxPath($fileInfo['path']);
            $this->entityManager->flush();

            return $comic;
        } finally {
            if (is_file($stagedPath)) {
                unlink($stagedPath);
            }
        }
    }

    /**
     * Import every not-yet-imported CBZ for a user, up to $limit attempts.
     *
     * The HTTP endpoint and the CLI command both drive this; they differ only in
     * how they report progress, which is what $report is for. Successes and
     * failures alike count towards the limit, so one repeatedly failing file
     * cannot make a sync run through the whole Dropbox account.
     *
     * @param callable(string, array<string, mixed>):void|null $report Receives
     *        'listed', 'skipped', 'importing', 'imported' and 'failed' events.
     *
     * @return array{newFiles: int, failed: int}
     */
    public function syncUser(
        DropboxClient $client,
        User $user,
        int $limit,
        bool $dryRun = false,
        ?callable $report = null
    ): array {
        $notify = $report ?? static function (string $event, array $context): void {
        };

        $files = $this->listCbzFiles($client);
        $notify('listed', ['count' => count($files)]);

        $importedIndex = $this->getImportedIndex($user);
        $newFiles = 0;
        $failed = 0;
        $processed = 0;

        foreach ($files as $fileInfo) {
            if ($this->isImported($fileInfo, $importedIndex)) {
                $notify('skipped', ['file' => $fileInfo]);
                continue;
            }

            if ($processed >= $limit) {
                $notify('limitReached', ['limit' => $limit]);
                break;
            }

            $processed++;
            $notify('importing', ['file' => $fileInfo, 'dryRun' => $dryRun]);

            if ($dryRun) {
                $newFiles++;
                continue;
            }

            try {
                $this->import($client, $user, $fileInfo);
                $importedIndex['paths'][mb_strtolower($fileInfo['path'])] = true;
                $newFiles++;
                $notify('imported', ['file' => $fileInfo]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to import a Dropbox file during sync.', [
                    'user_id' => $user->getId(),
                    'path' => $fileInfo['path'],
                    'exception' => $e,
                ]);
                $failed++;
                $notify('failed', ['file' => $fileInfo, 'exception' => $e]);
            }
        }

        return ['newFiles' => $newFiles, 'failed' => $failed];
    }

    /**
     * Dropbox serves large files better through a temporary link, so prefer that
     * and fall back to the API download endpoint.
     *
     * The archive is streamed into the staged file: a comic can be hundreds of
     * megabytes, and holding one in a PHP string exhausts the memory limit long
     * before the filesystem runs out of room.
     */
    private function downloadFile(DropboxClient $client, string $path, string $stagedPath): void
    {
        $staged = fopen($stagedPath, 'wb');
        if ($staged === false) {
            throw new \RuntimeException('Could not open the staged Dropbox download for writing.');
        }

        try {
            try {
                $response = $this->httpClient->request('GET', $client->getTemporaryLink($path));
                foreach ($this->httpClient->stream($response) as $chunk) {
                    fwrite($staged, $chunk->getContent());
                }

                return;
            } catch (\Throwable $e) {
                $this->logger->debug('Dropbox temporary link download failed, falling back to direct download.', [
                    'path' => $path,
                    'exception' => $e,
                ]);
            }

            // The failed attempt may have written part of the archive already,
            // so start the fallback from an empty file rather than appending to
            // a corrupt prefix.
            if (!ftruncate($staged, 0) || fseek($staged, 0) !== 0) {
                throw new \RuntimeException('Could not reset the staged Dropbox download before retrying.');
            }

            $source = $client->download($path);

            try {
                if (stream_copy_to_stream($source, $staged) === false) {
                    throw new \RuntimeException('Failed to stream the Dropbox download to disk.');
                }
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }
            }
        } finally {
            fclose($staged);
        }
    }

    /**
     * Derive a display title from a CBZ filename: "super_hero-01.cbz" -> "Super Hero 01".
     */
    public function titleFromFilename(string $filename): string
    {
        $title = str_replace(['_', '-'], ' ', pathinfo($filename, PATHINFO_FILENAME));

        return ucwords(trim(preg_replace('/\s+/', ' ', $title)));
    }

    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $power = (int) min(floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    /**
     * Turn the folder structure a file sits in into tags, relative to the app
     * folder: ".../Manga/spaceOpera" -> ["Manga", "Space Opera"].
     *
     * @return list<string>
     */
    private function convertPathToTags(string $path): array
    {
        if ($path === '' || $path === '.') {
            return [];
        }

        $relativePath = ltrim($path, '/');
        $appFolderPrefix = trim($this->dropboxAppFolder, '/') . '/';
        if (str_starts_with($relativePath, $appFolderPrefix)) {
            $relativePath = substr($relativePath, strlen($appFolderPrefix));
        }

        $tags = [];
        foreach (explode('/', $relativePath) as $folder) {
            $tag = $this->formatFolderName($folder);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * "superHero" -> "Super Hero", "sci-fi" -> "Sci Fi", "MANGA" -> "Manga".
     */
    private function formatFolderName(string $folderName): string
    {
        $formatted = str_replace(['_', '-'], ' ', $folderName);
        $formatted = preg_replace('/([a-z])([A-Z])/', '$1 $2', $formatted);
        $formatted = preg_replace('/\s+/', ' ', $formatted);

        return ucwords(strtolower(trim($formatted)));
    }

    private function normaliseTitle(string $title): string
    {
        $normalised = preg_replace('/[^a-z0-9\s]/', '', strtolower($title));

        return trim(preg_replace('/\s+/', ' ', $normalised));
    }
}
