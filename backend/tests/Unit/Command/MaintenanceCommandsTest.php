<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\BackfillComicFileSizeCommand;
use App\Command\CleanupContentReportsCommand;
use App\Command\ComicFormatsCheckCommand;
use App\Command\DropboxSyncCommand;
use App\Command\ImportComicsCommand;
use App\Command\MigrateDropboxTokensCommand;
use App\Command\PruneComicPagesCommand;
use App\Command\TestEmailVerificationCommand;
use App\Entity\User;
use App\Repository\ComicRepository;
use App\Repository\UserRepository;
use App\Service\AppDataEncryptionService;
use App\Service\ComicFormatService;
use App\Service\ComicPageCache;
use App\Service\ComicPageDelivery;
use App\Service\ComicService;
use App\Service\ContentReportRetentionService;
use App\Service\DropboxClientFactory;
use App\Service\DropboxImportService;
use App\Service\EmailVerificationMailer;
use App\Service\EmailVerificationService;
use App\Service\PublicUrl;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Spatie\Dropbox\Client as DropboxClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MaintenanceCommandsTest extends TestCase
{
    public function testBackfillCompletesCleanlyWhenNothingNeedsMeasuring(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->with(['fileSize' => null])->willReturn([]);
        $entityManager = $this->entityManagerWithRepository($repository);
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new BackfillComicFileSizeCommand($entityManager, '/comics'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Backfilled 0 comic(s)', $tester->getDisplay());
    }

    public function testContentReportCleanupReportsTheRemovedCount(): void
    {
        $retention = $this->createMock(ContentReportRetentionService::class);
        $retention->method('cleanup')->willReturn(4);
        $tester = new CommandTester(new CleanupContentReportsCommand($retention));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Removed 4 expired content report(s).', $tester->getDisplay());
    }

    public function testFormatCheckSucceedsForAHealthyEssentialRuntime(): void
    {
        $formats = $this->createMock(ComicFormatService::class);
        $formats->method('runtimeReport')->with(true)->willReturn([
            'cbz' => [
                'available' => true,
                'essential' => true,
                'requirements' => ['PHP ZIP extension'],
                'hint' => '',
                'note' => '',
            ],
        ]);
        $formats->method('status')->willReturn(['cbz' => ['enabled' => true]]);
        $delivery = $this->createMock(ComicPageDelivery::class);
        $delivery->method('describe')->willReturn([
            'format' => 'webp',
            'healthy' => true,
            'summary' => 'healthy',
            'hint' => '',
        ]);
        $tester = new CommandTester(new ComicFormatsCheckCommand($formats, $delivery));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Every supported comic format can be served', $tester->getDisplay());
    }

    public function testDropboxSyncRejectsAnUnknownRequestedUser(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->with('999')->willReturn(null);
        $entityManager = $this->entityManagerWithRepository($repository);
        $tester = new CommandTester(new DropboxSyncCommand(
            $entityManager,
            $this->createMock(DropboxClientFactory::class),
            $this->createMock(DropboxImportService::class),
            10,
        ));

        self::assertSame(Command::FAILURE, $tester->execute(['--user-id' => '999']));
        self::assertStringContainsString('User with ID 999 not found', $tester->getDisplay());
    }

    public function testDropboxSyncScheduledQueryIncludesRefreshOnlyConnections(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);
        $builder = $this->createMock(QueryBuilder::class);
        $builder->expects(self::once())->method('where')->with(self::stringContains('dropboxAccessToken'))->willReturnSelf();
        $builder->expects(self::once())->method('orWhere')->with(self::stringContains('dropboxRefreshToken'))->willReturnSelf();
        $builder->method('andWhere')->willReturnSelf();
        $builder->method('setParameter')->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->with('u')->willReturn($builder);
        $tester = new CommandTester(new DropboxSyncCommand(
            $this->entityManagerWithRepository($repository),
            $this->createMock(DropboxClientFactory::class),
            $this->createMock(DropboxImportService::class),
            10,
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No users with Dropbox connections found', $tester->getDisplay());
    }

    public function testDropboxSyncAcceptsARequestedUserWithOnlyARefreshToken(): void
    {
        $user = (new User())
            ->setEmail('reader@example.test')
            ->setDropboxRefreshToken('stored-refresh-token');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->with('7')->willReturn($user);
        $entityManager = $this->entityManagerWithRepository($repository);
        $entityManager->expects(self::once())->method('flush');
        $client = $this->createMock(DropboxClient::class);
        $factory = $this->createMock(DropboxClientFactory::class);
        $factory->expects(self::once())->method('createForUser')->with($user)->willReturn($client);
        $import = $this->createMock(DropboxImportService::class);
        $import->expects(self::once())->method('syncUser')->willReturn(['newFiles' => 0, 'failed' => 0]);
        $tester = new CommandTester(new DropboxSyncCommand($entityManager, $factory, $import, 10));

        self::assertSame(Command::SUCCESS, $tester->execute(['--user-id' => '7']));
    }

    public function testDropboxSyncDoesNotRecordAConnectionFailureAsSuccessfullySynced(): void
    {
        $user = (new User())
            ->setEmail('reader@example.test')
            ->setDropboxRefreshToken('stored-refresh-token');
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->with('7')->willReturn($user);
        $entityManager = $this->entityManagerWithRepository($repository);
        $entityManager->expects(self::never())->method('flush');
        $factory = $this->createMock(DropboxClientFactory::class);
        $factory->method('createForUser')->with($user)->willThrowException(new \RuntimeException('Token refresh failed'));
        $import = $this->createMock(DropboxImportService::class);
        $import->expects(self::never())->method('syncUser');
        $tester = new CommandTester(new DropboxSyncCommand($entityManager, $factory, $import, 10));

        self::assertSame(Command::FAILURE, $tester->execute(['--user-id' => '7']));
        self::assertNull($user->getDropboxLastSyncedAt());
        self::assertStringContainsString('1 errors encountered', $tester->getDisplay());
    }

    public function testDirectoryImportRejectsAMissingDirectoryBeforeQueryingTheDatabase(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $tester = new CommandTester(new ImportComicsCommand(
            $entityManager,
            $this->createMock(ComicService::class),
            $this->createMock(ComicFormatService::class),
        ));
        $missing = sys_get_temp_dir().'/missing-comic-import-'.bin2hex(random_bytes(6));

        self::assertSame(Command::FAILURE, $tester->execute([
            'directory' => $missing,
            'user_email' => 'reader@example.test',
        ]));
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testDropboxTokenMigrationHandlesAnEmptyInstallation(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);
        $builder = $this->createMock(QueryBuilder::class);
        $builder->method('where')->willReturnSelf();
        $builder->method('andWhere')->willReturnSelf();
        $builder->expects(self::once())->method('orWhere')->with(self::stringContains('dropboxRefreshToken'))->willReturnSelf();
        $builder->method('setParameter')->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($builder);
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static function (callable $work) use ($connection): mixed {
            return $work($connection);
        });
        $entityManager = $this->entityManagerWithRepository($repository);
        $entityManager->method('getConnection')->willReturn($connection);
        $tester = new CommandTester(new MigrateDropboxTokensCommand(
            $entityManager,
            $this->createMock(AppDataEncryptionService::class),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Encrypted Dropbox tokens for 0 user(s)', $tester->getDisplay());
    }

    public function testPagePruneDryRunReportsWhatWouldBeReclaimed(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn([]);
        $builder = $this->createMock(QueryBuilder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);
        $comics = $this->createMock(ComicRepository::class);
        $comics->method('createQueryBuilder')->willReturn($builder);
        $cache = $this->createMock(ComicPageCache::class);
        $cache->expects(self::once())->method('prune')->with(self::isInstanceOf(\DateTimeImmutable::class), [], true)->willReturn([
            'stale' => 2,
            'orphans' => 1,
            'bytes' => 2048,
        ]);
        $tester = new CommandTester(new PruneComicPagesCommand($cache, $comics));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('2.0 KB', $tester->getDisplay());
        self::assertStringContainsString('nothing was deleted', $tester->getDisplay());
    }

    public function testEmailVerificationDiagnosticRefusesAnExistingAccount(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn(new User());
        $tester = new CommandTester(new TestEmailVerificationCommand(
            $this->createMock(EntityManagerInterface::class),
            $users,
            $this->createMock(EmailVerificationService::class),
            $this->createMock(EmailVerificationMailer::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(PublicUrl::class),
        ));

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'existing@example.test']));
        self::assertStringContainsString('Refusing to modify an existing account', $tester->getDisplay());
    }

    private function entityManagerWithRepository(EntityRepository $repository): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return $entityManager;
    }
}
