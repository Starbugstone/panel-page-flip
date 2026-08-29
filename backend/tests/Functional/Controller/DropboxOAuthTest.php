<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Service\DropboxClientFactory;
use App\Service\DropboxImportService;
use App\Tests\Functional\AbstractApiTestCase;
use Spatie\Dropbox\Client as DropboxClient;

final class DropboxOAuthTest extends AbstractApiTestCase
{
    public function testConnectRedirectsAnAuthenticatedUserToDropbox(): void
    {
        $this->createAndLoginUser();

        $this->browser()->request('GET', '/api/dropbox/connect');

        self::assertResponseRedirects();
        $location = (string) $this->browser()->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://www.dropbox.com/oauth2/authorize?', $location);
        self::assertStringContainsString('token_access_type=offline', $location);
        self::assertNotEmpty($this->browser()->getRequest()->getSession()->get('dropbox_oauth2_state'));
    }

    public function testConnectRefusesAnonymousCallers(): void
    {
        $this->browser()->request('GET', '/api/dropbox/connect');

        self::assertResponseStatusCodeSame(401);
    }

    public function testCallbackRejectsAMismatchedOAuthState(): void
    {
        $this->createAndLoginUser();
        $this->browser()->request('GET', '/api/dropbox/connect');
        $this->browser()->request('GET', '/api/dropbox/callback?code=abc&state=forged');

        self::assertResponseStatusCodeSame(401);
        $payload = json_decode((string) $this->browser()->getResponse()->getContent(), true);
        self::assertStringStartsWith('Dropbox authorization expired or the session ended.', $payload['error']);
        self::assertStringNotContainsString('CSRF', $payload['error']);
        self::assertStringNotContainsString('attack', strtolower($payload['error']));
    }

    public function testDisconnectClearsStoredTokens(): void
    {
        $user = $this->createAndLoginUser();
        $user->setDropboxAccessToken('access');
        $user->setDropboxRefreshToken('refresh');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $payload = $this->postJson('/api/dropbox/disconnect');

        self::assertResponseIsSuccessful();
        self::assertSame('Dropbox disconnected successfully', $payload['message']);

        // The controller clears the tokens on the instance the firewall loaded,
        // not on this one, so assert against the stored row.
        $stored = self::getContainer()->get('doctrine')->getManager()
            ->getRepository(User::class)->find($user->getId());
        self::assertFalse($stored->hasDropboxConnection());
    }

    public function testFilesWithoutAConnectionAreRefused(): void
    {
        $this->createAndLoginUser();

        $payload = $this->getJson('/api/dropbox/files');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Dropbox not connected', $payload['error']);
    }

    public function testImportRequiresAPathOrLegacyFileName(): void
    {
        $user = $this->createAndLoginUser();
        $user->setDropboxAccessToken('access');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $payload = $this->postJson('/api/dropbox/import');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('path or fileName is required', $payload['error']);
    }

    public function testPartialSyncReportsImportedAndFailedCountsWithoutClaimingSuccess(): void
    {
        $user = $this->createAndLoginUser();
        $user->setDropboxAccessToken('access');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $dropboxClient = $this->createMock(DropboxClient::class);
        $clientFactory = $this->createMock(DropboxClientFactory::class);
        $clientFactory->method('createForUser')->willReturn($dropboxClient);
        static::getContainer()->set(DropboxClientFactory::class, $clientFactory);

        $dropboxImport = $this->createMock(DropboxImportService::class);
        $dropboxImport->method('syncUser')->willReturn(['newFiles' => 2, 'failed' => 1]);
        static::getContainer()->set(DropboxImportService::class, $dropboxImport);

        $payload = $this->postJson('/api/dropbox/sync');

        self::assertResponseIsSuccessful();
        self::assertSame(2, $payload['newFiles']);
        self::assertSame(1, $payload['failedFiles']);
        self::assertSame('Dropbox import partially completed: 2 imported, 1 failed.', $payload['message']);
        self::assertStringNotContainsString('success', strtolower($payload['message']));
    }

    public function testFailedImportUsesImportTerminology(): void
    {
        $user = $this->createAndLoginUser();
        $user->setDropboxAccessToken('access');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $clientFactory = $this->createMock(DropboxClientFactory::class);
        $clientFactory->method('createForUser')->willThrowException(new \RuntimeException('Unavailable'));
        static::getContainer()->set(DropboxClientFactory::class, $clientFactory);

        $payload = $this->postJson('/api/dropbox/sync');

        self::assertResponseStatusCodeSame(500);
        self::assertSame('Dropbox import failed.', $payload['error']);
    }
}
