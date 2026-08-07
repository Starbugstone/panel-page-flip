<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\DropboxClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * When a Dropbox access token is exchanged for a fresh one.
 *
 * Regression: the factory refreshed on every call, so a status check, a file
 * listing, an import and a sync each paid a blocking round trip to Dropbox for
 * a token that was valid for hours.
 */
final class DropboxClientFactoryTest extends TestCase
{
    public function testDoesNotRefreshAnAccessTokenThatIsStillValid(): void
    {
        $user = $this->connectedUser('live-token', new \DateTimeImmutable('+3 hours'));
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('A valid access token must not be exchanged for another one.');
        });

        $this->factory($client)->createForUser($user);

        self::assertSame('live-token', $user->getDropboxAccessToken());
    }

    public function testRefreshesAnExpiredAccessToken(): void
    {
        $user = $this->connectedUser('stale-token', new \DateTimeImmutable('-1 minute'));

        $this->factory($this->tokenResponse('fresh-token', 14400))->createForUser($user);

        self::assertSame('fresh-token', $user->getDropboxAccessToken());
        self::assertGreaterThan(new \DateTimeImmutable('+3 hours'), $user->getDropboxTokenExpiresAt());
    }

    /**
     * A token about to lapse is treated as lapsed, so a call that starts inside
     * the window cannot finish outside it.
     */
    public function testRefreshesWithinTheExpirySkew(): void
    {
        $user = $this->connectedUser('nearly-stale', new \DateTimeImmutable('+60 seconds'));

        $this->factory($this->tokenResponse('fresh-token', 14400))->createForUser($user);

        self::assertSame('fresh-token', $user->getDropboxAccessToken());
    }

    /**
     * Accounts connected before the expiry was recorded have none. Refresh once,
     * which stores one; after that they behave like everybody else.
     */
    public function testRefreshesWhenTheExpiryIsUnknown(): void
    {
        $user = $this->connectedUser('legacy-token', null);

        $this->factory($this->tokenResponse('fresh-token', 14400))->createForUser($user);

        self::assertSame('fresh-token', $user->getDropboxAccessToken());
        self::assertNotNull($user->getDropboxTokenExpiresAt());
    }

    /**
     * A refresh response without a usable `expires_in` leaves the expiry unknown
     * rather than inventing one, so the next call refreshes again instead of
     * trusting a token whose lifetime nobody stated.
     */
    public function testAMissingExpiresInLeavesTheExpiryUnknown(): void
    {
        $user = $this->connectedUser('legacy-token', null);

        $this->factory($this->tokenResponse('fresh-token', null))->createForUser($user);

        self::assertNull($user->getDropboxTokenExpiresAt());
    }

    public function testInvalidatingForcesTheNextCallToRefresh(): void
    {
        $user = $this->connectedUser('revoked-token', new \DateTimeImmutable('+3 hours'));
        $factory = $this->factory($this->tokenResponse('fresh-token', 14400));

        $factory->invalidateAccessToken($user);
        $factory->createForUser($user);

        self::assertSame('fresh-token', $user->getDropboxAccessToken());
    }

    public function testAnAccountWithoutATokenIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->factory(new MockHttpClient())->createForUser(new User());
    }

    private function connectedUser(string $accessToken, ?\DateTimeImmutable $expiresAt): User
    {
        return (new User())
            ->setDropboxAccessToken($accessToken)
            ->setDropboxRefreshToken('refresh-token')
            ->setDropboxTokenExpiresAt($expiresAt);
    }

    private function tokenResponse(string $accessToken, ?int $expiresIn): MockHttpClient
    {
        $body = ['access_token' => $accessToken];
        if ($expiresIn !== null) {
            $body['expires_in'] = $expiresIn;
        }

        return new MockHttpClient(new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]));
    }

    private function factory(MockHttpClient $httpClient): DropboxClientFactory
    {
        return new DropboxClientFactory(
            'app-key',
            'app-secret',
            $httpClient,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger()
        );
    }
}
