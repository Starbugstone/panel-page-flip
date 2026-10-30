<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserMetadataCredential;
use App\Http\JsonRequestDecoder;
use App\Metadata\Provider\ProviderAccess;
use App\Metadata\Provider\ProviderVerification;
use App\Service\AppDataEncryptionService;
use App\Service\MetadataProviderConfigurationService;
use App\Service\MetadataProviderRegistry;
use App\Service\ProviderCircuitBreaker;
use App\Service\UserMetadataCredentialService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A user's own metadata-provider tokens.
 *
 * Write-only: the response says whether a token is configured and when it last
 * changed, never what it is. There is deliberately no read endpoint, because
 * there is no question a user can ask about their own token that requires
 * getting it back out of the server — if they have lost it, they replace it.
 *
 * Only ever a token. Nothing here accepts a provider account password.
 */
#[Route('/api/me/metadata-credentials', name: 'api_me_metadata_credentials_')]
class MetadataCredentialController extends AbstractController
{
    use RequiresAuthenticatedUser;

    /**
     * The column holds ciphertext, which is longer than the token that went
     * into it, so the limit is derived from the column rather than guessed.
     * Counted in bytes: a multibyte value can pass a character count and still
     * overflow, and the failure would land as a database error at flush time.
     */
    private const SECRET_COLUMN_LENGTH = 1024;

    private const FIELDS = [
        'metronToken' => [
            'provider' => 'metron',
            'getter' => 'getMetronToken',
            'setter' => 'setMetronToken',
            'label' => 'Metron API token',
        ],
        'comicVineApiKey' => [
            'provider' => 'comicvine',
            'getter' => 'getComicVineApiKey',
            'setter' => 'setComicVineApiKey',
            'label' => 'Comic Vine API key',
        ],
    ];

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(
        MetadataProviderRegistry $providers,
        UserMetadataCredentialService $credentials,
        MetadataProviderConfigurationService $configuration
    ): JsonResponse {
        $user = $this->requireUser();

        return $this->json($this->describe($user, $providers, $credentials, $configuration));
    }

    /**
     * Save or replace a token.
     *
     * A field the body does not mention is left alone, so replacing a Metron
     * token does not require re-entering a Comic Vine key that is already
     * there. An explicit null removes one.
     */
    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(
        Request $request,
        MetadataProviderRegistry $providers,
        UserMetadataCredentialService $credentials,
        MetadataProviderConfigurationService $configuration
    ): JsonResponse {
        $user = $this->requireUser();
        $data = JsonRequestDecoder::decode($request);

        // Clearing is always allowed, even when the server has stopped
        // accepting personal tokens: somebody whose stored token is no longer
        // being used should be able to take it off this server. Only *setting*
        // one is refused.
        if (!$configuration->arePersonalCredentialsEnabled() && !$this->onlyClears($data)) {
            return $this->json(
                ['message' => 'This server does not accept personal provider tokens.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $credential = $credentials->editable($user);

        foreach (self::FIELDS as $field => $definition) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if ($value !== null && !is_string($value)) {
                return $this->json(
                    ['message' => sprintf('%s must be text or empty.', $definition['label'])],
                    Response::HTTP_BAD_REQUEST
                );
            }

            if (is_string($value) && strlen(trim($value)) > AppDataEncryptionService::maxPlaintextBytes(self::SECRET_COLUMN_LENGTH)) {
                return $this->json(
                    ['message' => sprintf('%s is too long.', $definition['label'])],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $credential->{$definition['setter']}($value);
        }

        $credentials->save($user, $credential);

        return $this->json($this->describe($user, $providers, $credentials, $configuration));
    }

    /**
     * Deliberately still allowed when personal tokens are switched off. Somebody
     * whose stored token has stopped being used should be able to take it off
     * this server rather than be told the button is unavailable.
     */
    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function delete(
        MetadataProviderRegistry $providers,
        UserMetadataCredentialService $credentials,
        MetadataProviderConfigurationService $configuration
    ): JsonResponse {
        $user = $this->requireUser();
        $credentials->remove($user);

        return $this->json($this->describe($user, $providers, $credentials, $configuration));
    }

    /**
     * Try a token against the live service before committing to it.
     *
     * Takes the typed value where there is one and the stored one otherwise, so
     * "test what I have saved" and "test what I have just typed" are the same
     * request.
     */
    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(
        Request $request,
        MetadataProviderRegistry $providers,
        UserMetadataCredentialService $credentials,
        MetadataProviderConfigurationService $configuration,
        ProviderCircuitBreaker $circuitBreaker,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $user = $this->requireUser();

        if (!$user->isMetadataApiEnabled()) {
            return $this->json(
                ['message' => 'External metadata lookups are turned off for this account.'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Testing a token this server would never use is a question with no
        // useful answer, and it spends a request finding it out.
        if (!$configuration->arePersonalCredentialsEnabled()) {
            return $this->json(
                ['message' => 'This server does not accept personal provider tokens.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $data = JsonRequestDecoder::decode($request);

        $provider = $data['provider'] ?? null;
        $field = null;
        foreach (self::FIELDS as $name => $definition) {
            if ($provider === $definition['provider']) {
                $field = $name;
                break;
            }
        }

        if ($field === null) {
            return $this->json(['message' => 'Unknown metadata provider.'], Response::HTTP_BAD_REQUEST);
        }

        $typed = $data['secret'] ?? null;
        $secret = is_string($typed) && trim($typed) !== ''
            ? trim($typed)
            : $this->storedSecret($credentials->for($user), $field);

        // With no credential the provider answers without asking upstream, so
        // there is nothing to back off from and nothing to charge for.
        if ($secret !== null) {
            // The account this credential spends may already have been told to
            // wait. Hashing is how a typed token and a stored one, or two people
            // holding the same one, land on the same pause.
            $paused = $circuitBreaker->pausedFor(ProviderAccess::granted((string) $provider, 'personal', $secret)->accountKey());
            if ($paused !== null) {
                $minutes = max(1, (int) ceil($paused / 60));

                return $this->json(['result' => ProviderVerification::rateLimited(sprintf(
                    'This token cannot be tested for about %d more minute%s, because the provider asked this server to slow down.',
                    $minutes,
                    $minutes === 1 ? '' : 's'
                ))]);
            }

            // The allowance searches spend. A test is an upstream request like
            // any other, and a different typed value each time misses every
            // cache, so it cannot be a free way to spend the provider's patience.
            if (!$metadataProviderUserLimiter->create((string) $user->getId())->consume()->isAccepted()) {
                return $this->json(
                    ['message' => 'You have run a lot of metadata lookups recently. Try again shortly.'],
                    Response::HTTP_TOO_MANY_REQUESTS
                );
            }
        }

        $result = $providers->verifyOne((string) $provider, $secret);
        if ($result === null) {
            return $this->json(['message' => 'Unknown metadata provider.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['result' => $result]);
    }

    /**
     * Whether a payload only removes tokens, rather than setting any.
     *
     * @param array<string, mixed> $data
     */
    private function onlyClears(array $data): bool
    {
        foreach (self::FIELDS as $field => $_) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function describe(
        User $user,
        MetadataProviderRegistry $providers,
        UserMetadataCredentialService $credentials,
        MetadataProviderConfigurationService $configuration
    ): array {
        $credential = $credentials->for($user);

        $configured = [];
        foreach (self::FIELDS as $definition) {
            $configured[$definition['provider']] = $credential?->{$definition['getter']}() !== null;
        }

        return [
            'configured' => $configured,
            'updatedAt' => $credential?->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'metadataApiEnabled' => $user->isMetadataApiEnabled(),
            // A stored token that is no longer being used still shows as
            // configured, because it still exists and can still be removed.
            'personalCredentialsEnabled' => $configuration->arePersonalCredentialsEnabled(),
            // Which provider a search would actually use, and why not when none
            // would. The same view the comic editor gets, so the two pages
            // cannot disagree about what is switched on.
            'providers' => $providers->statusFor($user),
        ];
    }

    private function storedSecret(?UserMetadataCredential $credential, string $field): ?string
    {
        $getter = self::FIELDS[$field]['getter'] ?? null;

        return $getter === null ? null : $credential?->{$getter}();
    }
}
