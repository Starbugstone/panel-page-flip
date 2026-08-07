<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\DropboxClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Spatie\Dropbox\Exceptions\BadRequest;
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

    /**
     * Only a rejected credential may clear the recorded expiry.
     *
     * A timeout or a Dropbox outage says nothing about the token, and treating
     * one as a rejection puts the refresh back in front of every later request —
     * which is the whole cost the expiry exists to avoid.
     *
     * @dataProvider credentialRejectionProvider
     */
    public function testClassifiesFailuresThatMeanTheTokenIsNoGood(
        \Throwable $exception,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            $this->factory(new MockHttpClient())->isCredentialRejection($exception)
        );
    }

    public function credentialRejectionProvider(): iterable
    {
        yield 'HTTP 401' => [$this->badResponse(401), true];
        yield 'invalid_access_token' => [$this->dropboxBadRequest('invalid_access_token'), true];
        yield 'expired_access_token' => [$this->dropboxBadRequest('expired_access_token'), true];
        yield 'refresh token refused' => [new \RuntimeException('Dropbox token refresh failed.'), true];

        // Everything below is Dropbox being unreachable or unwell, or the call
        // being wrong — none of it is evidence about the credential.
        yield 'HTTP 500' => [$this->badResponse(500), false];
        yield 'HTTP 429' => [$this->badResponse(429), false];
        yield 'HTTP 403' => [$this->badResponse(403), false];
        yield 'transport failure' => [new \RuntimeException('Connection timed out'), false];
        yield 'unrelated Dropbox error' => [$this->dropboxBadRequest('path/not_found'), false];
    }

    private function badResponse(int $status): BadResponseException
    {
        return new ClientException(
            sprintf('HTTP %d', $status),
            new Request('POST', 'https://api.dropboxapi.com/2/users/get_current_account'),
            new Response($status, [], '{}')
        );
    }

    private function dropboxBadRequest(string $tag): BadRequest
    {
        return new BadRequest(new Response(409, [], json_encode([
            'error' => ['.tag' => $tag],
            'error_summary' => $tag . '/...',
        ], JSON_THROW_ON_ERROR)));
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
