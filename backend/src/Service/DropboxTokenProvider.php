<?php

namespace App\Service;

use App\Entity\User;
use GuzzleHttp\Exception\ClientException;
use Spatie\Dropbox\RefreshableTokenProvider;

/**
 * Supplies one user's Dropbox access token, and replaces it when Dropbox says
 * it is no longer any good.
 *
 * The Spatie client asks a provider for the token on every call, and — when the
 * provider is refreshable — hands it any 4xx it gets back. Returning true means
 * "I have a new token, try that again", and the client retries the request once
 * with it. That is what makes this refresh-on-rejection rather than
 * refresh-first: the stored token is used until Dropbox actually objects.
 *
 * It also means every endpoint is covered by construction — listing, download,
 * temporary links, account info — without a retry wrapper at any call site.
 */
final class DropboxTokenProvider implements RefreshableTokenProvider
{
    public function __construct(
        private readonly User $user,
        private readonly DropboxClientFactory $factory,
    ) {
    }

    public function getToken(): string
    {
        return (string) $this->user->getDropboxAccessToken();
    }

    public function refresh(ClientException $exception): bool
    {
        // Only a rejected credential is worth a refresh. A 429 or a 5xx says
        // nothing about the token, and swapping it out on those would turn a
        // Dropbox hiccup into an extra round trip on top of it.
        if (!$this->factory->isCredentialRejection($exception)) {
            return false;
        }

        if (!$this->user->getDropboxRefreshToken()) {
            return false;
        }

        // False rather than an exception on failure: the client is asking
        // whether to retry, and the original Dropbox error is the more useful
        // one to let through.
        return $this->factory->refreshAccessToken($this->user);
    }
}
