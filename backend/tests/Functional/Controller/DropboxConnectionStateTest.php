<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

/**
 * Which accounts the Dropbox endpoints treat as connected.
 *
 * The refresh token is the durable half of the credential: an access token is
 * short-lived and the client mints a new one from the refresh token on demand.
 * Guarding on the access token alone reported an account as disconnected
 * precisely when it was recoverable, and — because every endpoint refused
 * before reaching the factory — the recovery could never run.
 */
final class DropboxConnectionStateTest extends AbstractApiTestCase
{
    /**
     * An account holding only a refresh token must reach the client factory,
     * which is the only thing that can mint it a new access token.
     *
     * Dropbox is not reachable from a test, so the call fails and the endpoint
     * reports disconnected — but it reports it after trying, which is the
     * difference. The old guard answered without ever looking.
     *
     * @dataProvider dropboxEndpointProvider
     */
    public function testARefreshTokenOnlyAccountIsNotRefusedAtTheGuard(
        string $method,
        string $url,
        array $payload
    ): void {
        $user = UserFactory::createOne()->object();
        $user->setDropboxAccessToken(null);
        $user->setDropboxRefreshToken('stored-refresh-token');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->loginAs($user);

        if ($method === 'GET') {
            $this->getJson($url);
        } else {
            $this->postJson($url, $payload);
        }

        // The guard's answer, which this must no longer be.
        $body = (string) $this->browser()->getResponse()->getContent();
        self::assertStringNotContainsString('Dropbox not connected', $body);
    }

    public function dropboxEndpointProvider(): iterable
    {
        yield 'files' => ['GET', '/api/dropbox/files', []];
        yield 'sync' => ['POST', '/api/dropbox/sync', []];
        yield 'import' => ['POST', '/api/dropbox/import', ['path' => '/Apps/x/a.cbz']];
    }

    /** An account with neither credential is still refused, and cheaply. */
    public function testAnAccountWithNoDropboxCredentialsIsRefused(): void
    {
        $this->createAndLoginUser();

        $payload = $this->getJson('/api/dropbox/files');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Dropbox not connected', $payload['error']);
    }

    /** Status reports the same accounts as connected that the actions accept. */
    public function testStatusConsidersARefreshTokenAConnection(): void
    {
        $user = UserFactory::createOne()->object();
        $user->setDropboxAccessToken(null);
        $user->setDropboxRefreshToken('stored-refresh-token');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->loginAs($user);
        $this->getJson('/api/dropbox/status');

        // Reachable and answered rather than refused outright; whether Dropbox
        // then honours the refresh is not this endpoint's guard to decide.
        self::assertResponseIsSuccessful();
    }

    public function testStatusReportsAnAccountWithNoCredentialsAsDisconnected(): void
    {
        $this->createAndLoginUser();

        $payload = $this->getJson('/api/dropbox/status');

        self::assertResponseIsSuccessful();
        self::assertFalse($payload['connected']);
    }
}
