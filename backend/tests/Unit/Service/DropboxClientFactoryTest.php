<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\DropboxClientFactory;
use App\Service\DropboxTokenProvider;
use App\Service\SecurityAuditLogger;
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
 * Regression: the factory refreshed before every call, so a status check, a
 * file listing, an import and a sync each paid a blocking round trip to Dropbox
 * for a token that was valid for hours. The stored token is now used as-is and
 * replaced only when Dropbox rejects it — which the Spatie client asks the
 * token provider about, and then retries the call once.
 */
final class DropboxClientFactoryTest extends TestCase
{
    public function testUsesTheStoredTokenWithoutRefreshingIt(): void
    {
        $user = $this->connectedUser('live-token');
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('Building a client must not exchange the stored token.');
        });

        $this->factory($client)->createForUser($user);

        self::assertSame('live-token', $user->getDropboxAccessToken());
    }

    /**
     * A connection holding only a refresh token has nothing to present, so one
     * token is fetched up front rather than waiting for a rejection.
     */
    public function testFetchesATokenWhenOnlyARefreshTokenIsStored(): void
    {
        $user = (new User())->setDropboxRefreshToken('refresh-token');

        $this->factory($this->tokenResponse('fresh-token'))->createForUser($user);

        self::assertSame('fresh-token', $user->getDropboxAccessToken());
    }

    public function testAnAccountWithNoTokensAtAllIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->factory(new MockHttpClient())->createForUser(new User());
    }

    public function testAnUnusableRefreshTokenIsRefused(): void
    {
        $user = (new User())->setDropboxRefreshToken('dead-refresh-token');

        $this->expectException(\RuntimeException::class);

        $this->factory($this->tokenResponse(null, 400))->createForUser($user);
    }

    public function testRefreshingStoresTheNewAccessToken(): void
    {
        $user = $this->connectedUser('stale-token');

        self::assertTrue($this->factory($this->tokenResponse('fresh-token'))->refreshAccessToken($user));
        self::assertSame('fresh-token', $user->getDropboxAccessToken());
    }

    public function testAFailedRefreshReportsFailureRatherThanThrowing(): void
    {
        $user = $this->connectedUser('stale-token');

        // False, not an exception: the caller is deciding whether to retry a
        // Dropbox call, and the original Dropbox error is the useful one.
        self::assertFalse($this->factory($this->tokenResponse(null, 500))->refreshAccessToken($user));
        self::assertSame('stale-token', $user->getDropboxAccessToken());
    }

    /**
     * The provider is the hook the Spatie client calls on a 4xx. True means
     * "token replaced, retry the call"; false means the error stands.
     */
    public function testTheProviderRefreshesOnARejectedToken(): void
    {
        $user = $this->connectedUser('rejected-token');
        $factory = $this->factory($this->tokenResponse('fresh-token'));

        $refreshed = (new DropboxTokenProvider($user, $factory))->refresh($this->clientException(401));

        self::assertTrue($refreshed);
        self::assertSame('fresh-token', $user->getDropboxAccessToken());
    }

    public function testTheProviderLeavesTheTokenAloneForTransientFailures(): void
    {
        $user = $this->connectedUser('live-token');
        $factory = $this->factory(new MockHttpClient(static function (): MockResponse {
            self::fail('A rate limit is not a reason to exchange the token.');
        }));

        $refreshed = (new DropboxTokenProvider($user, $factory))->refresh($this->clientException(429));

        self::assertFalse($refreshed);
        self::assertSame('live-token', $user->getDropboxAccessToken());
    }

    public function testTheProviderCannotRefreshWithoutARefreshToken(): void
    {
        $user = (new User())->setDropboxAccessToken('orphan-token');
        $factory = $this->factory(new MockHttpClient(static function (): MockResponse {
            self::fail('There is no refresh token to exchange.');
        }));

        self::assertFalse((new DropboxTokenProvider($user, $factory))->refresh($this->clientException(401)));
    }

    /**
     * Only a rejected credential may cost a refresh. A timeout or an outage
     * says nothing about the token.
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
        yield 'HTTP 401' => [$this->clientException(401), true];
        yield 'invalid_access_token' => [$this->dropboxBadRequest('invalid_access_token'), true];
        yield 'expired_access_token' => [$this->dropboxBadRequest('expired_access_token'), true];

        // Dropbox being unreachable or unwell, or the call being wrong — none of
        // it is evidence about the credential.
        yield 'HTTP 500' => [$this->clientException(500), false];
        yield 'HTTP 429' => [$this->clientException(429), false];
        yield 'HTTP 403' => [$this->clientException(403), false];
        yield 'transport failure' => [new \RuntimeException('Connection timed out'), false];
        yield 'unrelated Dropbox error' => [$this->dropboxBadRequest('path/not_found'), false];
    }

    private function clientException(int $status): BadResponseException
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

    private function connectedUser(string $accessToken): User
    {
        return (new User())
            ->setDropboxAccessToken($accessToken)
            ->setDropboxRefreshToken('refresh-token');
    }

    private function tokenResponse(?string $accessToken, int $status = 200): MockHttpClient
    {
        $body = $accessToken === null ? [] : ['access_token' => $accessToken];

        return new MockHttpClient(new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
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
            new NullLogger(),
            $this->createMock(SecurityAuditLogger::class)
        );
    }
}
