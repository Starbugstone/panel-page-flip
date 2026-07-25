<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Spatie\Dropbox\Client as DropboxClient;
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

    public function createForUser(User $user): DropboxClient
    {
        if ($user->getDropboxRefreshToken()) {
            $this->refreshAccessToken($user);
        }

        $accessToken = $user->getDropboxAccessToken();
        if (!$accessToken) {
            throw new \RuntimeException('Dropbox is not connected.');
        }

        return new DropboxClient($accessToken);
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
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Dropbox access token refresh failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            throw new \RuntimeException('Dropbox token refresh failed.');
        }
    }
}
