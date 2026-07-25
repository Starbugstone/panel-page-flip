<?php

namespace App\Service;

/**
 * Moves managed uploads out of the public tree without permanently deleting them.
 */
class FileQuarantineService
{
    public function __construct(
        private readonly string $managedDirectory,
        private readonly string $quarantineDirectory
    ) {
    }

    /**
     * @param list<string> $paths
     * @return list<array{originalPath: string, quarantinePath: string}>
     */
    public function quarantine(array $paths): array
    {
        $records = [];

        try {
            foreach (array_unique($paths) as $path) {
                if (is_file($path)) {
                    $records[] = $this->moveToQuarantine($path);
                }
            }
        } catch (\Throwable $exception) {
            $this->restore($records);
            throw $exception;
        }

        return $records;
    }

    /** @param list<array{originalPath: string, quarantinePath: string}> $records */
    public function restore(array $records): void
    {
        foreach (array_reverse($records) as $record) {
            if (!is_file($record['quarantinePath'])) {
                continue;
            }

            $this->ensureDirectory(dirname($record['originalPath']));
            $this->move($record['quarantinePath'], $record['originalPath']);
        }
    }

    /** @return array{originalPath: string, quarantinePath: string} */
    private function moveToQuarantine(string $path): array
    {
        $managedRoot = realpath($this->managedDirectory);
        $source = realpath($path);
        if ($managedRoot === false || $source === false) {
            throw new \RuntimeException('Unable to resolve the managed upload path.');
        }

        $managedPrefix = rtrim($managedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($source, $managedPrefix)) {
            throw new \RuntimeException('Refusing to quarantine a file outside the managed uploads directory.');
        }

        $relativePath = substr($source, strlen($managedPrefix));
        $batch = (new \DateTimeImmutable())->format('Y-m-d') . '/' . bin2hex(random_bytes(8));
        $destination = rtrim($this->quarantineDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $batch . DIRECTORY_SEPARATOR . $relativePath;

        $this->ensureDirectory(dirname($destination));
        $this->move($source, $destination);

        return ['originalPath' => $source, 'quarantinePath' => $destination];
    }

    private function move(string $source, string $destination): void
    {
        if (file_exists($destination)) {
            throw new \RuntimeException(sprintf('Refusing to overwrite existing file "%s".', $destination));
        }

        if (@rename($source, $destination)) {
            return;
        }

        if (!copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Unable to quarantine file "%s".', $source));
        }

        if (!unlink($source)) {
            @unlink($destination);
            throw new \RuntimeException(sprintf('Unable to remove original file "%s" after copying it.', $source));
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }
    }
}
