<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Functional\AbstractApiTestCase;

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
        self::assertStringContainsString('Invalid OAuth state', $payload['error']);
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
}
