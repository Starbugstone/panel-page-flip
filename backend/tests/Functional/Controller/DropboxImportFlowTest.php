<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\User;
use App\Service\ComicFormatService;
use App\Service\ComicService;
use App\Service\DropboxClientFactory;
use App\Service\DropboxConfiguration;
use App\Service\DropboxImportService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Spatie\Dropbox\Client as DropboxClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Dropbox is optional. An installation that configured it gets working imports,
 * through the real service, database and comic storage with only Dropbox itself
 * replaced. One that did not gets a clear refusal and an otherwise normal server.
 */
final class DropboxImportFlowTest extends AbstractApiTestCase
{
    private const FOLDER = '/comics';

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function testAConfiguredInstallationImportsAFileAndKnowsItDidSo(): void
    {
        $user = $this->connectedUser();
        $this->replaceDropbox($this->listing(['Batman 01.cbz' => 2_000]), new MockResponse($this->archive()));

        $payload = $this->postJson('/api/dropbox/import', ['path' => self::FOLDER.'/Batman 01.cbz']);

        self::assertResponseIsSuccessful();
        self::assertSame('Batman 01', $payload['comic']['title']);
        $comic = $this->comic($payload['comic']['id']);
        $this->temporaryFiles[] = self::getContainer()->getParameter('comics_directory').'/'.$user->getId().'/'.$comic->getFilePath();
        self::assertSame(self::FOLDER.'/Batman 01.cbz', $comic->getDropboxPath());

        self::assertTrue($this->getJson('/api/dropbox/files')['files'][0]['synced']);

        $this->postJson('/api/dropbox/import', ['path' => self::FOLDER.'/Batman 01.cbz']);
        self::assertResponseStatusCodeSame(400);
    }

    /** The account is told why, in words about their own file, and nothing is kept. */
    public function testAFileOverTheUploadMaximumIsRefusedAsTooLarge(): void
    {
        $user = $this->connectedUser();
        $maximum = (int) self::getContainer()->getParameter('upload_max_total_bytes');
        $this->replaceDropbox($this->listing(['Huge.cbz' => $maximum + 1]));

        $payload = $this->postJson('/api/dropbox/import', ['path' => self::FOLDER.'/Huge.cbz']);

        self::assertResponseStatusCodeSame(413);
        self::assertSame('Huge.cbz is larger than the 500 MB upload limit.', $payload['error']);
        self::assertSame(0, $this->comicCount($user));
    }

    /**
     * Against the real query. A legacy import keeps its file marked as imported,
     * while an upload or a comic imported from elsewhere does not hide a file
     * that merely derives the same title.
     */
    public function testOnlyALegacyImportStandsInForAFileWithItsTitle(): void
    {
        $user = $this->connectedUser();
        $importTag = TagFactory::new()->createdBy($user)->create(['name' => DropboxImportService::IMPORT_TAG]);

        ComicFactory::new()->ownedBy($user)->create(['title' => 'Legacy By Description', 'description' => DropboxImportService::IMPORT_DESCRIPTION]);
        ComicFactory::new()->ownedBy($user)->create(['title' => 'Legacy By Tag', 'description' => 'Edited since'])->addTag($importTag);
        ComicFactory::new()->ownedBy($user)->create(['title' => 'Ordinary Upload', 'description' => 'Bought at a signing']);
        ComicFactory::new()->ownedBy($user)->create([
            'title' => 'Imported Elsewhere',
            'description' => DropboxImportService::IMPORT_DESCRIPTION,
            'dropboxPath' => self::FOLDER.'/Elsewhere/Imported Elsewhere.cbz',
        ])->addTag($importTag);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->replaceDropbox($this->listing([
            'Legacy By Description.cbz' => 1,
            'Legacy By Tag.cbz' => 1,
            'Ordinary Upload.cbz' => 1,
            'Imported Elsewhere.cbz' => 1,
        ]));

        $files = $this->getJson('/api/dropbox/files')['files'];

        self::assertSame([
            'Legacy By Description.cbz' => true,
            'Legacy By Tag.cbz' => true,
            'Ordinary Upload.cbz' => false,
            'Imported Elsewhere.cbz' => false,
        ], array_column($files, 'synced', 'name'));
    }

    /**
     * Each action is refused up front, so nothing tries to reach a Dropbox that
     * was never configured. Listing the import service here would fail the test
     * if the refusal ever moved behind it.
     *
     * @dataProvider dropboxActionProvider
     */
    public function testAnUnconfiguredInstallationRefusesEachDropboxAction(string $method, string $url, array $body): void
    {
        $user = $this->connectedUser();
        $this->withoutDropboxConfigured();

        $factory = $this->createMock(DropboxClientFactory::class);
        $factory->expects(self::never())->method('createForUser');
        static::getContainer()->set(DropboxClientFactory::class, $factory);

        $payload = $method === 'GET' ? $this->getJson($url) : $this->postJson($url, $body);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(DropboxConfiguration::UNAVAILABLE_MESSAGE, $payload['error']);
        self::assertSame(0, $this->comicCount($user));
    }

    public function dropboxActionProvider(): iterable
    {
        yield 'listing' => ['GET', '/api/dropbox/files', []];
        yield 'one import' => ['POST', '/api/dropbox/import', ['path' => self::FOLDER.'/Batman 01.cbz']];
        yield 'bulk import' => ['POST', '/api/dropbox/sync', []];
    }

    /** The rest of the server does not care, and a stored token can still be removed. */
    public function testAnUnconfiguredInstallationOtherwiseWorksNormally(): void
    {
        $user = $this->connectedUser();
        ComicFactory::new()->ownedBy($user)->create(['title' => 'Uploaded By Hand']);
        $this->withoutDropboxConfigured();

        $status = $this->getJson('/api/dropbox/status');
        self::assertResponseIsSuccessful();
        self::assertFalse($status['configured']);
        self::assertFalse($status['connected']);

        $comics = $this->getJson('/api/comics');
        self::assertResponseIsSuccessful();
        self::assertSame(['Uploaded By Hand'], array_column($comics['comics'], 'title'));

        $this->getJson('/api/config');
        self::assertResponseIsSuccessful();

        $this->postJson('/api/dropbox/disconnect');
        self::assertResponseIsSuccessful();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function connectedUser(): User
    {
        $user = $this->createAndLoginUser();
        $user->setDropboxAccessToken('stored-access-token');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }

    private function withoutDropboxConfigured(): void
    {
        static::getContainer()->set(DropboxConfiguration::class, new DropboxConfiguration('', ''));
    }

    /**
     * @param array<string, int> $sizes file name => the size Dropbox declares
     *
     * @return array<string, mixed>
     */
    private function listing(array $sizes): array
    {
        $entries = [];
        foreach ($sizes as $name => $size) {
            $entries[] = ['.tag' => 'file', 'name' => $name, 'path_display' => self::FOLDER.'/'.$name, 'size' => $size];
        }

        return ['entries' => $entries, 'has_more' => false];
    }

    /**
     * Everything real except Dropbox: the Spatie client and the HTTP client that
     * would have gone to it. A MockHttpClient with no responses left throws, so
     * a test that downloads what it should not fails rather than reaching out.
     *
     * @param array<string, mixed> $listing
     */
    private function replaceDropbox(array $listing, MockResponse ...$downloads): void
    {
        $client = $this->createMock(DropboxClient::class);
        $client->method('listFolder')->willReturn($listing);
        $client->method('getTemporaryLink')->willReturn('https://dl.dropbox.test/file');
        $client->method('getAccessToken')->willReturn('stored-access-token');

        $factory = $this->createMock(DropboxClientFactory::class);
        $factory->method('createForUser')->willReturn($client);
        static::getContainer()->set(DropboxClientFactory::class, $factory);

        $container = static::getContainer();
        $container->set(DropboxImportService::class, new DropboxImportService(
            $container->get(ComicService::class),
            $container->get(EntityManagerInterface::class),
            new MockHttpClient($downloads),
            new NullLogger(),
            $container->get(ComicFormatService::class),
            self::FOLDER,
            (int) $container->getParameter('upload_max_total_bytes'),
        ));
    }

    private function archive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dropbox-flow-').'.cbz';
        $this->temporaryFiles[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('page-01.jpg', $this->onePixelJpeg());
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function onePixelJpeg(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true
        );
    }

    private function comic(int $id): Comic
    {
        $comic = self::getContainer()->get(EntityManagerInterface::class)->find(Comic::class, $id);
        self::assertInstanceOf(Comic::class, $comic);

        return $comic;
    }

    private function comicCount(User $user): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comic::class)->count(['owner' => $user]);
    }
}
