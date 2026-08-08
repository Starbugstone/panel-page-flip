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
    public function __construct(
        private readonly string $dropboxAppKey,
        private readonly string $dropboxAppSecret,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * A Dropbox client for this user, refreshing its token only when Dropbox
     * rejects it.
     *
     * The client is given a token *provider* rather than a token string. That is
     * what lets the stored access token be used as-is and swapped out only on a
     * rejection: this used to refresh before every single call, putting a
     * blocking round trip to Dropbox in front of every status check, listing,
     * import and sync — for a token good for hours.
     */
    public function createForUser(User $user): DropboxClient
    {
        if (!$user->getDropboxAccessToken() && !$user->getDropboxRefreshToken()) {
            throw new \RuntimeException('Dropbox is not connected.');
        }

        // A connection with only a refresh token — one whose access token was
        // cleared, or which has not been used since it was granted — needs one
        // up front, because there is nothing for the provider to present.
        if (!$user->getDropboxAccessToken() && !$this->refreshAccessToken($user)) {
            throw new \RuntimeException('Dropbox is not connected.');
        }

        return new DropboxClient(new DropboxTokenProvider($user, $this));
    }

    /**
     * Exchange the refresh token for a new access token and store it.
     *
     * Returns whether it worked, because the caller is usually deciding whether
     * to retry a request rather than handling an error.
     */
    public function refreshAccessToken(User $user): bool
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

            $accessToken = $response->toArray(false)['access_token'] ?? null;
            if (!is_string($accessToken) || $accessToken === '') {
                throw new \RuntimeException('Dropbox token refresh response did not include an access token.');
            }

            $user->setDropboxAccessToken($accessToken);
            $this->entityManager->flush();

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Dropbox access token refresh failed.', [
                'user_id' => $user->getId(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Whether a failed Dropbox call means "this token is no good" rather than
     * "Dropbox could not be reached".
     *
     * A rejected token is 401 `invalid_access_token`. Dropbox also answers 400
     * for a malformed one, which the Spatie client turns into a BadRequest
     * carrying the tag. A transport error, a 5xx or a rate limit says nothing
     * about the credential and must not cost a refresh.
     */
    public function isCredentialRejection(\Throwable $exception): bool
    {
        if ($exception instanceof BadRequest) {
            return in_array($exception->dropboxCode, ['invalid_access_token', 'expired_access_token'], true);
        }

        if ($exception instanceof BadResponseException) {
            return $exception->getResponse()->getStatusCode() === 401;
        }

        return false;
    }
}
