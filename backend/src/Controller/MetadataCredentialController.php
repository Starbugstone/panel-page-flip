<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserMetadataCredential;
use App\Service\MetadataProviderRegistry;
use App\Service\UserMetadataCredentialService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    /** Long enough for every token these providers issue. */
    private const MAX_SECRET_LENGTH = 512;

    private const FIELDS = [
        'metronToken' => ['metron', 'getMetronToken', 'setMetronToken'],
        'comicVineApiKey' => ['comicvine', 'getComicVineApiKey', 'setComicVineApiKey'],
    ];

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(MetadataProviderRegistry $providers, UserMetadataCredentialService $credentials): JsonResponse
    {
        $user = $this->authenticatedUser();

        return $this->json($this->describe($user, $providers, $credentials));
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
        UserMetadataCredentialService $credentials
    ): JsonResponse {
        $user = $this->authenticatedUser();

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $credential = $credentials->editable($user);

        foreach (self::FIELDS as $field => [, , $setter]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if ($value !== null && !is_string($value)) {
                return $this->json(['message' => sprintf('%s must be a string or null.', $field)], Response::HTTP_BAD_REQUEST);
            }

            if (is_string($value) && mb_strlen(trim($value)) > self::MAX_SECRET_LENGTH) {
                return $this->json(['message' => sprintf('%s is longer than a token from this provider.', $field)], Response::HTTP_BAD_REQUEST);
            }

            $credential->{$setter}($value);
        }

        $credentials->save($user, $credential);

        return $this->json($this->describe($user, $providers, $credentials));
    }

    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function delete(MetadataProviderRegistry $providers, UserMetadataCredentialService $credentials): JsonResponse
    {
        $user = $this->authenticatedUser();
        $credentials->remove($user);

        return $this->json($this->describe($user, $providers, $credentials));
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
        UserMetadataCredentialService $credentials
    ): JsonResponse {
        $user = $this->authenticatedUser();

        if (!$user->isMetadataApiEnabled()) {
            return $this->json(
                ['message' => 'External metadata lookups are turned off for this account.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        $provider = $data['provider'] ?? null;
        $field = null;
        foreach (self::FIELDS as $name => [$providerKey]) {
            if ($provider === $providerKey) {
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

        $result = $providers->verifyOne((string) $provider, $secret);
        if ($result === null) {
            return $this->json(['message' => 'Unknown metadata provider.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['result' => $result]);
    }

    /** @return array<string, mixed> */
    private function describe(User $user, MetadataProviderRegistry $providers, UserMetadataCredentialService $credentials): array
    {
        $credential = $credentials->for($user);

        $configured = [];
        foreach (self::FIELDS as $field => [$providerKey, $getter]) {
            $configured[$providerKey] = $credential?->{$getter}() !== null;
        }

        return [
            'configured' => $configured,
            'updatedAt' => $credential?->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'metadataApiEnabled' => $user->isMetadataApiEnabled(),
            // Which provider a search would actually use, and why not when none
            // would. The same view the comic editor gets, so the two pages
            // cannot disagree about what is switched on.
            'providers' => $providers->statusFor($user),
        ];
    }

    private function storedSecret(?UserMetadataCredential $credential, string $field): ?string
    {
        $getter = self::FIELDS[$field][1] ?? null;

        return $getter === null ? null : $credential?->{$getter}();
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated');
        }

        return $user;
    }
}
