<?php

namespace App\Service;

use App\Entity\PendingFileDeletion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final class PendingFileDeletionService
{
    /**
     * @param list<string> $allowedRoots
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly array $allowedRoots,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queues deletion records in the caller's database transaction. Other
     * workers cannot observe them unless that transaction commits.
     *
     * @param list<string> $paths
     * @return list<PendingFileDeletion>
     */
    public function queue(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            $paths,
            static fn ($path): bool => is_string($path) && $path !== '',
        )));

        $records = [];
        $entityManager = $this->entityManager();
        foreach ($paths as $path) {
            // Queue even when the file is temporarily missing so a later retry
            // can confirm removal; purge() treats an absent path as success.
            $this->assertAllowedPath($path);
            $record = new PendingFileDeletion($path);
            $entityManager->persist($record);
            $records[] = $record;
        }

        return $records;
    }

    /**
     * Detaches uncommitted records after the caller rolls its transaction back.
     *
     * @param list<PendingFileDeletion> $records
     */
    public function cancel(array $records): void
    {
        $entityManager = $this->entityManager();
        foreach ($records as $record) {
            if ($entityManager->contains($record)) {
                $entityManager->detach($record);
            }
        }
    }

    /**
     * @param list<PendingFileDeletion> $records
     * @return array{deleted: int, remaining: int}
     */
    public function purge(array $records): array
    {
        $deleted = 0;
        $remaining = 0;
        $entityManager = $this->entityManager();

        foreach ($records as $record) {
            $path = $record->getPath();
            if (!file_exists($path)) {
                $entityManager->remove($record);
                ++$deleted;
                continue;
            }

            try {
                $this->assertAllowedPath($path);
                if (!is_file($path) || !@unlink($path)) {
                    throw new \RuntimeException(sprintf('Unable to delete "%s".', $path));
                }

                $entityManager->remove($record);
                ++$deleted;
            } catch (\Throwable $exception) {
                ++$remaining;
                $record->recordFailure();
                $this->logger->error('A personal-data file remains pending deletion.', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
            }
        }

        try {
            $entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Personal-data file deletion state could not be updated.', [
                'exception' => $exception,
            ]);

            return ['deleted' => 0, 'remaining' => count($records)];
        }

        return ['deleted' => $deleted, 'remaining' => $remaining];
    }

    /**
     * @return array{batches: int, deleted: int, remaining: int}
     */
    public function retryAll(): array
    {
        $records = $this->entityManager()->getRepository(PendingFileDeletion::class)->findAll();
        $result = $this->purge($records);

        return [
            'batches' => count($records),
            'deleted' => $result['deleted'],
            'remaining' => $result['remaining'],
        ];
    }

    private function assertAllowedPath(string $path): void
    {
        $normalizedPath = $this->resolveManagedPath($path);
        foreach ($this->allowedRoots as $root) {
            $resolvedRoot = realpath($root);
            if ($resolvedRoot === false) {
                continue;
            }

            $normalizedRoot = rtrim($this->normalizePath($resolvedRoot), '/') . '/';
            if (str_starts_with($normalizedPath, $normalizedRoot)
                || $normalizedPath === rtrim($normalizedRoot, '/')
            ) {
                return;
            }
        }

        throw new \RuntimeException('Refusing to delete a file outside the managed personal-data directories.');
    }

    private function resolveManagedPath(string $path): string
    {
        $resolvedPath = realpath($path);
        if ($resolvedPath !== false) {
            return $this->normalizePath($resolvedPath);
        }

        // realpath() fails for missing files; resolve via an existing parent so
        // queue()/purge() can still enforce the allowed-root boundary.
        $parent = dirname($path);
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false) {
            throw new \RuntimeException('Unable to resolve a pending personal-data file path.');
        }

        return $this->normalizePath($resolvedParent . DIRECTORY_SEPARATOR . basename($path));
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Expected a Doctrine ORM entity manager.');
        }

        return $entityManager;
    }
}
