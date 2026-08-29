<?php

namespace App\Tests\Functional\Controller;

use App\Controller\AdminController;
use App\Service\AdminAuditService;
use App\Service\ComicFormatService;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AdminControllerTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    /** @var list<string> */
    private array $stagedFiles = [];

    public function testEmptyComicFormatUpdateAlwaysKeepsCbzEnabled(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->putJson('/api/admin/comic-formats', ['enabled' => []]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['formats']['cbz']['enabled']);
    }

    public function testCbzFreeComicFormatUpdateAddsCbzToTheSavedConfiguration(): void
    {
        $this->createAndLoginAdmin();
        $status = self::getContainer()->get(ComicFormatService::class)->status(true);
        $optional = array_key_first(array_filter(
            $status,
            static fn (array $value, string $name): bool => $name !== 'cbz' && $value['available'],
            ARRAY_FILTER_USE_BOTH
        ));
        if ($optional === null) self::markTestSkipped('No optional comic runtime is installed.');

        $payload = $this->putJson('/api/admin/comic-formats', ['enabled' => [$optional]]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['formats']['cbz']['enabled']);
        self::assertTrue($payload['formats'][$optional]['enabled']);
    }

    public function testRegularUserCannotReadAdminStats(): void
    {
        $this->createAndLoginUser();
        $this->getJson('/api/admin/stats');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminStatsReflectStoredUsersAndComics(): void
    {
        $this->createAndLoginAdmin();
        $owner = UserFactory::createOne()->object();
        ComicFactory::new()->ownedBy($owner)->create(['fileSize' => 12345]);

        $payload = $this->getJson('/api/admin/stats');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $payload['stats']['totalUsers']);
        self::assertSame(2, $payload['stats']['verifiedUsers']);
        self::assertSame(1, $payload['stats']['totalComics']);
        self::assertSame(12345, $payload['stats']['storageUsed']);
    }

    public function testRegularUserCannotRunCleanupDryRun(): void
    {
        $this->createAndLoginUser();
        $this->postJson('/api/admin/cleanup/dry-run');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanReadEmptyAuditLog(): void
    {
        $this->createAndLoginAdmin();
        $payload = $this->getJson('/api/admin/audit-logs');

        self::assertResponseIsSuccessful();
        self::assertSame([], $payload['logs']);
    }

    public function testAdminCanReadComicFormats(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->getJson('/api/admin/comic-formats');

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['formats']['cbz']['enabled']);
        self::assertArrayHasKey('delivery', $payload);
    }

    public function testAdminCanVerifyComicFormats(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->postJson('/api/admin/comic-formats/verify');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('formats', $payload);
        self::assertArrayHasKey('delivery', $payload);
    }

    public function testAdminCleanupDryRunReportsOrphansWithoutRemovingThem(): void
    {
        $this->createAndLoginAdmin();
        $orphan = $this->stageOrphanedComic();

        $payload = $this->postJson('/api/admin/cleanup/dry-run');

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('error', $payload['cleanup']);
        self::assertContains(
            basename($orphan),
            array_column($payload['cleanup']['orphanedComics'], 'filename')
        );
        self::assertFileExists($orphan, 'A dry run must not remove anything.');
    }

    public function testAdminCleanupApplyQuarantinesTheOrphansItFound(): void
    {
        $this->createAndLoginAdmin();
        $orphan = $this->stageOrphanedComic();

        $payload = $this->postJson('/api/admin/cleanup/apply');

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('error', $payload['cleanup']);
        self::assertGreaterThanOrEqual(1, $payload['cleanup']['quarantined']['orphanedComics']);
        self::assertArrayHasKey('orphanedCovers', $payload['cleanup']['quarantined']);
        self::assertFileDoesNotExist($orphan);
    }

    public function testFailedDropboxSyncDoesNotExposeSubprocessOutput(): void
    {
        $target = UserFactory::createOne([
            'dropboxAccessToken' => 'example-invalid-dropbox-token',
        ])->object();
        $this->createAndLoginAdmin();
        $this->clearSecurityLog();
        $container = static::getContainer();
        $controller = new AdminController(new ArrayAdapter(), sys_get_temp_dir());
        $controller->setContainer($container);

        $response = $controller->forceDropboxSync(
            $target->getId(),
            $container->get(EntityManagerInterface::class),
            $container->get(AdminAuditService::class),
            $container->get(SecurityAuditLogger::class),
        );
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('Dropbox import failed. Please try again or review the server logs.', $payload['message']);
        self::assertArrayNotHasKey('output', $payload);
        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::DATA_INTEGRITY_FAILURE);
        self::assertSame('dropbox_force_sync', $record->context['operation']);
    }

    /**
     * A comic file on disk that no row points at, which is the whole of what
     * the sweep looks for.
     *
     * Staged by the test rather than assumed: the configured comics directory
     * does not exist in a fresh checkout, and a scan that cannot find it
     * reports an error instead of a result — so without this the assertions
     * below would pass against a directory that was never read.
     */
    private function stageOrphanedComic(): string
    {
        $directory = (string) self::getContainer()->getParameter('comics_directory');
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(sprintf('Could not create the comics directory "%s".', $directory));
        }

        $path = $directory . '/orphan-' . bin2hex(random_bytes(8)) . '.cbz';
        file_put_contents($path, 'not a real archive');
        $this->stagedFiles[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->stagedFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->stagedFiles = [];

        parent::tearDown();
    }
}
