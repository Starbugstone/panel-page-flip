<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SetupUploadDirectoriesCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupUploadDirectoriesCommandTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    public function testItCreatesTheSharedAndPerUserDirectories(): void
    {
        $root = sys_get_temp_dir().'/panel-page-flip-uploads-'.bin2hex(random_bytes(6));
        $this->paths[] = $root;
        $first = $this->user(17);
        $second = $this->user(42);

        $tester = new CommandTester($this->command($root, [$first, $second]));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertDirectoryExists($root);
        self::assertDirectoryExists($root.'/covers');
        self::assertDirectoryExists($root.'/17');
        self::assertDirectoryExists($root.'/42');
    }

    public function testItFailsWhenAnUploadDirectoryCannotBeCreated(): void
    {
        $blockingFile = tempnam(sys_get_temp_dir(), 'panel-page-flip-blocked-');
        self::assertIsString($blockingFile);
        $this->paths[] = $blockingFile;

        $tester = new CommandTester($this->command($blockingFile.'/uploads', []));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Failed to create directory', $tester->getDisplay());
    }

    /** @param list<User> $users */
    private function command(string $directory, array $users): SetupUploadDirectoriesCommand
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('findAll')->willReturn($users);

        return new SetupUploadDirectoriesCommand($directory, $repository);
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) {
                unlink($path);
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($path);
        }

        parent::tearDown();
    }
}
