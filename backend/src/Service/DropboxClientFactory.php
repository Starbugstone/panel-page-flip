<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Log\LoggerInterface;
use Spatie\Dropbox\Client as DropboxClient;
use Spatie\Dropbox\Exceptions\BadRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DropboxClientFactory
{
    /**
     * Refresh this long before the token actually lapses, so a call that starts
     * just inside the window does not finish just outside it.
     */
    private const EXPIRY_SKEW_SECONDS = 300;

    public function __construct(
        private readonly string $dropboxAppKey,
        private readonly string $dropboxAppSecret,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function createForUser(User $user): DropboxClient
    {
        // Only when the stored token has actually run out. This used to refresh
        // unconditionally, which put a blocking round trip to Dropbox in front
        // of every status check, file listing, import and sync — for a token
        // that is valid for hours.
        if ($user->getDropboxRefreshToken() && $this->needsRefresh($user)) {
            $this->refreshAccessToken($user);
        }

        $accessToken = $user->getDropboxAccessToken();
        if (!$accessToken) {
            throw new \RuntimeException('Dropbox is not connected.');
        }

        return new DropboxClient($accessToken);
    }

    /**
     * Force the next {@see createForUser()} to mint a fresh access token.
     *
     * For callers that discover the token is dead before it was due to expire —
     * a grant revoked from Dropbox's side, or a clock that disagreed.
     *
     * Only for those. Clearing the expiry after a timeout or a Dropbox outage
     * would put the refresh back in front of every later request, which is the
     * cost this expiry exists to avoid; {@see isCredentialRejection()} is how
     * callers tell the two apart.
     */
    public function invalidateAccessToken(User $user): void
    {
        $user->setDropboxTokenExpiresAt(null);
        $this->entityManager->flush();
    }

    /**
     * Whether a failed Dropbox call means "this token is no good" rather than
     * "Dropbox could not be reached".
     *
     * A rejected token is 401 `invalid_access_token`. Dropbox also answers 400
     * for a malformed one, which the Spatie client turns into a BadRequest
     * carrying the tag. A transport error, a 5xx or a timeout says nothing about
     * the credential and must leave the recorded expiry alone.
     */
    public function isCredentialRejection(\Throwable $exception): bool
    {
        // Our own signal that the refresh token itself was refused. The access
        // token is already past its expiry in that case, so this is belt and
        // braces rather than the main path.
        if ($exception instanceof \RuntimeException
            && str_contains($exception->getMessage(), 'Dropbox token refresh failed')
        ) {
            return true;
        }

        if ($exception instanceof BadRequest) {
            return in_array($exception->dropboxCode, ['invalid_access_token', 'expired_access_token'], true);
        }

        if ($exception instanceof BadResponseException) {
            return $exception->getResponse()->getStatusCode() === 401;
        }

        return false;
    }

    private function needsRefresh(User $user): bool
    {
        if (!$user->getDropboxAccessToken()) {
            return true;
        }

        $expiresAt = $user->getDropboxTokenExpiresAt();

        // Unknown expiry means an account connected before the expiry was
        // recorded: refresh once, which stores one and settles it.
        return $expiresAt === null
            || $expiresAt <= new \DateTimeImmutable(sprintf('+%d seconds', self::EXPIRY_SKEW_SECONDS));
    }

    private function refreshAccessToken(User $user): void
    {
        try {
            $response = $this->httpClient->request('POST', 'https://api.dropboxapi.com/oauth2/token', [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $user->getDropboxRefreshToken(),
                    'client_id' => $this->dropboxAppKey,
                    'client_secret' => $this->dropboxAppSecret,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Dropbox token refresh failed.');
            }

            $data = $response->toArray(false);
            $accessToken = $data['access_token'] ?? null;
            if (!$accessToken) {
                throw new \RuntimeException('Dropbox token refresh response did not include an access token.');
            }

            $user->setDropboxAccessToken($accessToken);
            $user->setDropboxTokenExpiresAt($this->expiryFrom($data['expires_in'] ?? null));
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Dropbox access token refresh failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            throw new \RuntimeException('Dropbox token refresh failed.');
        }
    }

    /**
     * Turn Dropbox's `expires_in` into an absolute moment.
     *
     * A missing or nonsensical value yields null, which {@see needsRefresh()}
     * reads as "expired" — so a malformed response costs an extra refresh next
     * time rather than pinning a token that has actually lapsed.
     */
    public function expiryFrom(mixed $expiresIn): ?\DateTimeImmutable
    {
        if (!is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('+%d seconds', (int) $expiresIn));
    }
}
