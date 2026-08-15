<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\UserMetadataCredential;
use App\Metadata\Provider\PersonalProviderCredentials;
use App\Repository\UserMetadataCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A user's own provider credentials, fetched deliberately.
 *
 * Deliberately not an association on User. The inverse side of a one-to-one
 * cannot be lazy-loaded, so a property there would put an extra query behind
 * every user hydration in the application to serve the handful of places that
 * actually want a token.
 *
 * Deliberately holds no memo of what it resolved. The lookup is one indexed
 * row by a unique key, and a cached entity outlives the entity manager that
 * loaded it whenever the kernel is reused — which then reaches flush() with a
 * detached user and fails in a way that has nothing to do with credentials.
 */
final class UserMetadataCredentialService implements PersonalProviderCredentials
{
    public function __construct(
        private readonly UserMetadataCredentialRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function for(User $user): ?UserMetadataCredential
    {
        return $user->getId() === null ? null : $this->repository->findForUser($user);
    }

    public function metronToken(User $user): ?string
    {
        return $this->for($user)?->getMetronToken();
    }

    public function comicVineApiKey(User $user): ?string
    {
        return $this->for($user)?->getComicVineApiKey();
    }

    /**
     * An existing row to edit, or a new unsaved one for this user.
     *
     * A reference rather than the User the security token carries: that one can
     * be detached, and Doctrine would then read the association as a brand new
     * entity and refuse the flush.
     */
    public function editable(User $user): UserMetadataCredential
    {
        return $this->for($user)
            ?? (new UserMetadataCredential())->setUser($this->entityManager->getReference(User::class, $user->getId()));
    }

    /**
     * A row holding no secrets is only a row: removing the last token removes
     * the record rather than leaving an empty one behind, so "has a personal
     * credential" never answers yes to an empty shell.
     */
    public function save(User $user, UserMetadataCredential $credential): void
    {
        if ($credential->isEmpty()) {
            if ($credential->getId() !== null) {
                $this->entityManager->remove($credential);
            }
            $this->entityManager->flush();

            return;
        }

        $credential->touch();
        $this->entityManager->persist($credential);
        $this->entityManager->flush();
    }

    public function remove(User $user): void
    {
        $credential = $this->for($user);
        if ($credential !== null) {
            $this->entityManager->remove($credential);
            $this->entityManager->flush();
        }
    }
}
