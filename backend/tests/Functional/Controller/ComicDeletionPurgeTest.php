<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\PendingFileDeletion;
use App\Entity\User;
use App\Service\ComicService;
use App\Service\FileQuarantineService;
use App\Service\PendingFileDeletionService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * "Deleted" has to mean deleted. Files pass through quarantine only so a failed
 * transaction can put them back, and they leave it once the transaction commits.
 */
final class ComicDeletionPurgeTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    /** @var array<string, true> */
    private array $quarantinedBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->quarantinedBefore = array_fill_keys($this->filesInQuarantine(), true);
    }

    public function testDeletingAComicRemovesItsFilesForGood(): void
    {
        $owner = $this->createAndLoginUser();
        [$comic, $source, $cover] = $this->comicWithFiles($owner);

        $this->deleteJson(sprintf('/api/comics/%d', $comic->getId()));

        self::assertResponseIsSuccessful();
        $this->assertFilesAreGone($source, $cover);
    }

    /** An administrator reaches the same code, and must not leave a copy behind. */
    public function testAnAdministratorDeletingAComicRemovesItsFilesForGood(): void
    {
        [$comic, $source, $cover] = $this->comicWithFiles(UserFactory::createOne());
        $this->createAndLoginAdmin();

        $this->deleteJson(sprintf('/api/comics/%d', $comic->getId()));

        self::assertResponseIsSuccessful();
        $this->assertFilesAreGone($source, $cover);
    }

    public function testBulkDeletionRemovesEveryComicsFilesForGood(): void
    {
        $owner = $this->createAndLoginUser();
        [$first, $firstSource, $firstCover] = $this->comicWithFiles($owner);
        [$second, $secondSource, $secondCover] = $this->comicWithFiles($owner);

        $this->deleteJson('/api/comics', ['comicIds' => [$first->getId(), $second->getId()]]);

        self::assertResponseIsSuccessful();
        $this->assertFilesAreGone($firstSource, $firstCover, $secondSource, $secondCover);
    }

    /** The comic is gone either way, so what is left is a promise to finish the job. */
    public function testAPurgeThatFailsIsQueuedAndRetried(): void
    {
        $owner = $this->createAndLoginUser();
        [$comic, $source, $cover] = $this->comicWithFiles($owner);
        $comicId = $comic->getId();
        $held = $this->holdQuarantinedFilesInPlace([$source, $cover]);

        $this->deleteJson(sprintf('/api/comics/%d', $comicId));

        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($source);
        self::assertNull($this->entityManager()->find(Comic::class, $comicId));
        self::assertCount(2, $this->pendingDeletions());
        self::assertSame(1, $this->pendingDeletions()[0]->getAttempts());
        self::assertNotSame([], $this->newFilesInQuarantine(), 'The file could not be removed yet.');

        foreach ($held->directories as $directory) {
            chmod($directory, 0770);
        }

        $result = self::getContainer()->get(PendingFileDeletionService::class)->retryAll();

        self::assertSame(2, $result['deleted']);
        self::assertSame(0, $result['remaining']);
        self::assertSame([], $this->newFilesInQuarantine());
        self::assertSame([], $this->pendingDeletions());
    }

    /** Restore-on-failure is the reason quarantine still exists at all. */
    public function testAFailedDeletionPutsTheFilesBackAndQueuesNothing(): void
    {
        $owner = $this->createAndLoginUser();
        [$comic, $source, $cover] = $this->comicWithFiles($owner);
        $this->breakTheDeletionQueue();

        $payload = $this->deleteJson(sprintf('/api/comics/%d', $comic->getId()));

        self::assertResponseStatusCodeSame(500);
        self::assertSame('Failed to delete comic. No files were lost.', $payload['message']);
        $this->assertFilesWereRestored($comic->getId(), $source, $cover);
    }

    public function testAFailedBulkDeletionPutsTheFilesBackAndQueuesNothing(): void
    {
        $owner = $this->createAndLoginUser();
        [$comic, $source, $cover] = $this->comicWithFiles($owner);
        $this->breakTheDeletionQueue();

        $this->deleteJson('/api/comics', ['comicIds' => [$comic->getId()]]);

        self::assertResponseStatusCodeSame(500);
        $this->assertFilesWereRestored($comic->getId(), $source, $cover);
    }

    private function assertFilesAreGone(string ...$paths): void
    {
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertSame([], $this->newFilesInQuarantine(), 'A deleted comic left files in quarantine.');
        self::assertSame([], $this->pendingDeletions(), 'Nothing should still be waiting to be purged.');
    }

    private function assertFilesWereRestored(int $comicId, string ...$paths): void
    {
        foreach ($paths as $path) {
            self::assertFileExists($path);
        }
        self::assertNotNull($this->entityManager()->find(Comic::class, $comicId));
        self::assertSame([], $this->newFilesInQuarantine());
        self::assertSame([], $this->pendingDeletions());
    }

    /**
     * Quarantine the real way, then make the batch undeletable, which is what a
     * permissions problem on the server looks like to the purge.
     *
     * @param list<string> $paths
     */
    private function holdQuarantinedFilesInPlace(array $paths): \stdClass
    {
        $quarantine = self::getContainer()->get(FileQuarantineService::class);
        $held = new \stdClass();
        $held->directories = [];

        $comicService = $this->createMock(ComicService::class);
        $comicService->method('quarantineComicFiles')->willReturnCallback(
            static function () use ($quarantine, $paths, $held): array {
                $records = $quarantine->quarantine($paths);
                foreach ($records as $record) {
                    $directory = dirname($record['quarantinePath']);
                    chmod($directory, 0550);
                    $held->directories[] = $directory;
                }

                return $records;
            }
        );
        self::getContainer()->set(ComicService::class, $comicService);

        return $held;
    }

    private function breakTheDeletionQueue(): void
    {
        self::getContainer()->set(PendingFileDeletionService::class, new PendingFileDeletionService(
            self::getContainer()->get('doctrine'),
            [$this->temporaryDirectory()],
            new NullLogger(),
        ));
    }

    /** @return array{0: Comic, 1: string, 2: string} */
    private function comicWithFiles(User $owner): array
    {
        $name = uniqid('purge-', true);
        $comic = ComicFactory::new()->ownedBy($owner)->create([
            'filePath' => $name . '.cbz',
            'coverImagePath' => 'covers/' . $name . '.jpg',
        ]);

        $directory = self::getContainer()->getParameter('comics_directory') . '/' . $owner->getId();
        $source = $directory . '/' . $name . '.cbz';
        $cover = $directory . '/covers/' . $name . '.jpg';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($source, 'comic');
        $filesystem->dumpFile($cover, 'cover');
        $this->temporaryPaths[] = $directory;

        return [$comic, $source, $cover];
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/ppf-purge-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $this->temporaryPaths[] = $directory;

        return $directory;
    }

    /** @return list<PendingFileDeletion> */
    private function pendingDeletions(): array
    {
        return $this->entityManager()->getRepository(PendingFileDeletion::class)->findAll();
    }

    /** @return list<string> */
    private function newFilesInQuarantine(): array
    {
        return array_values(array_filter(
            $this->filesInQuarantine(),
            fn (string $path): bool => !isset($this->quarantinedBefore[$path])
        ));
    }

    /** @return list<string> */
    private function filesInQuarantine(): array
    {
        $root = (string) self::getContainer()->getParameter('quarantine_directory');
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->newFilesInQuarantine() as $path) {
            @chmod(dirname($path), 0770);
            $filesystem->remove($path);
        }
        $filesystem->remove($this->temporaryPaths);

        parent::tearDown();
    }
}
