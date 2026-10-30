<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\ComicRepository;
use App\Service\ComicUploadStagingService;
use App\Service\StagedUploadRefusedException;
use App\Service\StorageQuotaService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class ComicUploadStagingServiceTest extends TestCase
{
    private const IDLE_SECONDS = 3600;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ppf-staging-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/locks', 0770, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** Past MAX_PARALLEL_FILE_UPLOADS, the disk is protected whatever the quota says. */
    public function testChunksAreRefusedOnceTheAccountHasStagedTheLimitAcrossUploads(): void
    {
        $user = $this->user();
        $service = $this->service(maxStagedBytes: 100);
        $this->stage($service, $user, 'upload-one', 60);
        $this->stage($service, $user, 'upload-two', 30);

        $service->admitChunk($user, 10, 0);

        $this->expectExceptionObject(new StagedUploadRefusedException(
            'Too much unfinished upload data on this account. Wait for an upload to finish and try again. Cancelled uploads are released automatically.',
            413
        ));
        $service->admitChunk($user, 11, 0);
    }

    public function testAnotherAccountsBytesAreNotCounted(): void
    {
        $service = $this->service(maxStagedBytes: 100);
        $this->stage($service, $this->user(7), 'upload-one', 100);

        $service->admitChunk($this->user(8), 100, 0);

        $this->addToAssertionCount(1);
    }

    public function testAResendOnlyCountsTheDifference(): void
    {
        $user = $this->user();
        $service = $this->service(maxStagedBytes: 100);
        $this->stage($service, $user, 'upload-one', 100);

        $service->admitChunk($user, 100, 100);

        $this->addToAssertionCount(1);
    }

    public function testStagedBytesCountAgainstTheRemainingQuota(): void
    {
        $user = $this->user();
        $service = $this->service(quotaBytes: 100, storedBytes: 40);
        $this->stage($service, $user, 'upload-one', 50);

        $service->admitChunk($user, 10, 0);

        $this->expectException(StagedUploadRefusedException::class);
        $this->expectExceptionMessage('once unfinished uploads are counted');
        $service->admitChunk($user, 11, 0);
    }

    /**
     * Its comic row and its staged copy exist together for a moment. Counting both
     * would refuse a parallel upload that fits. The disk limit keeps counting it,
     * because the bytes are still on the disk.
     */
    public function testAFinalizingUploadLeavesTheQuotaCheckButNotTheDiskLimit(): void
    {
        $user = $this->user();
        $service = $this->service(maxStagedBytes: 100, quotaBytes: 100);
        $directory = $this->stage($service, $user, 'upload-one', 90);
        $service->markFinalizing($directory);

        $service->admitChunk($user, 10, 0);

        $this->expectException(StagedUploadRefusedException::class);
        $this->expectExceptionMessage('Too much unfinished upload data');
        $service->admitChunk($user, 11, 0);
    }

    public function testAnAccountWithNoRoomAtAllIsRefusedANewUpload(): void
    {
        $user = $this->user();
        $service = $this->service(quotaBytes: 100, storedBytes: 100);

        $this->expectException(StagedUploadRefusedException::class);
        $this->expectExceptionMessage('User storage quota exceeded.');
        $service->admitNewUpload($user, time());
    }

    public function testTheActiveUploadLimitCountsEveryUnfinishedUpload(): void
    {
        $user = $this->user();
        $service = $this->service(maxActiveUploads: 2);
        $this->stage($service, $user, 'upload-one', 1);
        $service->admitNewUpload($user, time());
        $this->stage($service, $user, 'upload-two', 1);

        try {
            $service->admitNewUpload($user, time());
            self::fail('The third upload should have been refused.');
        } catch (StagedUploadRefusedException $refused) {
            self::assertSame(429, $refused->status);
        }
    }

    /** Cancelling leaves an upload behind, and the client has no way to say so. */
    public function testAnAccountsOwnExpiredUploadsAreClearedBeforeItIsRefused(): void
    {
        $user = $this->user();
        $service = $this->service(maxActiveUploads: 1);
        $directory = $this->stage($service, $user, 'upload-cancelled', 1);
        $this->age($directory, self::IDLE_SECONDS + 1);

        $service->admitNewUpload($user, time());

        self::assertDirectoryDoesNotExist($directory);
    }

    public function testTheSweepRemovesOnlyWhatHasBeenIdleLongEnough(): void
    {
        $service = $this->service();
        $abandoned = $this->stage($service, $this->user(7), 'upload-abandoned', 8);
        $this->age($abandoned, self::IDLE_SECONDS + 1);
        $slowButAlive = $this->stage($service, $this->user(8), 'upload-alive', 8);
        $this->age($slowButAlive, self::IDLE_SECONDS - 60);

        $counts = $service->expireAbandoned(time());

        self::assertSame(['expired' => 1, 'remaining' => 0], $counts);
        self::assertDirectoryDoesNotExist($abandoned);
        self::assertDirectoryDoesNotExist(dirname($abandoned), 'Nothing should be left for an account with no uploads.');
        self::assertDirectoryExists($slowButAlive);
    }

    /** No chunks for an hour is normal for a comic being validated, not abandonment. */
    public function testTheSweepLeavesAnUploadARequestIsHolding(): void
    {
        $service = $this->service();
        $directory = $this->stage($service, $this->user(), 'upload-busy', 8);
        $lock = $service->lockUpload($directory);
        $this->age($directory, self::IDLE_SECONDS + 1);

        try {
            self::assertSame(['expired' => 0, 'remaining' => 0], $service->expireAbandoned(time()));
            self::assertDirectoryExists($directory);
        } finally {
            $service->unlockUpload($lock);
        }

        self::assertSame(['expired' => 1, 'remaining' => 0], $service->expireAbandoned(time()));
    }

    public function testAnInitThatNeverWroteItsMetadataIsSweptToo(): void
    {
        $service = $this->service();
        $directory = $this->root . '/staging/7/upload-crashed';
        $service->ensureDirectory($directory);
        touch($directory, time() - self::IDLE_SECONDS - 1);

        self::assertSame(['expired' => 1, 'remaining' => 0], $service->expireAbandoned(time()));
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testAnUploadThatCannotBeRemovedIsReportedForALaterRun(): void
    {
        $service = $this->service();
        $directory = $this->stage($service, $this->user(), 'upload-stuck', 8);
        $this->age($directory, self::IDLE_SECONDS + 1);
        chmod($directory, 0550);

        try {
            self::assertSame(['expired' => 0, 'remaining' => 1], $service->expireAbandoned(time()));
        } finally {
            chmod($directory, 0770);
        }
    }

    public function testNothingStagedIsNothingToDo(): void
    {
        self::assertSame(['expired' => 0, 'remaining' => 0], $this->service()->expireAbandoned(time()));
    }

    /**
     * The sweep usually runs as the deploy user, in the web server's group, and
     * removing a file takes write permission on its directory.
     */
    public function testStagingDirectoriesAreGroupWritableWhateverTheUmask(): void
    {
        $directory = $this->root . '/staging/7/upload-perms';
        $previous = umask(0022);

        try {
            $this->service()->ensureDirectory($directory);
        } finally {
            umask($previous);
        }

        self::assertSame(0770, fileperms($directory) & 0777);
        self::assertSame(0770, fileperms(dirname($directory)) & 0777);
    }

    /** The lock is what stops two parallel uploads spending the same room. */
    public function testTheAccountLockIsExclusivePerAccountAndReleased(): void
    {
        $service = $this->service();
        $factory = $this->lockFactory();

        $held = $service->lockAccount($this->user(7));
        self::assertFalse($factory->createLock('upload-staging:user:7')->acquire(false));
        self::assertTrue($factory->createLock('upload-staging:user:8')->acquire(false));

        $held->release();
        self::assertTrue($factory->createLock('upload-staging:user:7')->acquire(false));
    }

    private function stage(ComicUploadStagingService $service, User $user, string $fileId, int $bytes): string
    {
        $directory = $service->uploadDirectory($user, $fileId);
        $service->ensureDirectory($directory);
        file_put_contents($directory . '/metadata.json', '');
        file_put_contents($directory . '/chunk_0', str_repeat('X', $bytes));

        return $directory;
    }

    private function age(string $directory, int $seconds): void
    {
        touch($directory . '/metadata.json', time() - $seconds);
        touch($directory, time() - $seconds);
    }

    private function user(int $id = 7): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getStorageQuotaOverrideBytes')->willReturn(null);

        return $user;
    }

    private function lockFactory(): LockFactory
    {
        return new LockFactory(new FlockStore($this->root . '/locks'));
    }

    private function service(
        int $maxStagedBytes = 1_000_000,
        int $maxActiveUploads = 10,
        int $quotaBytes = 0,
        int $storedBytes = 0,
    ): ComicUploadStagingService {
        $comics = $this->createMock(ComicRepository::class);
        $comics->method('getStorageBytesForOwner')->willReturn($storedBytes);

        return new ComicUploadStagingService(
            $this->root . '/staging',
            $maxStagedBytes,
            $maxActiveUploads,
            self::IDLE_SECONDS,
            new StorageQuotaService($comics, $this->lockFactory(), $quotaBytes),
            $this->lockFactory(),
            new NullLogger(),
        );
    }
}
