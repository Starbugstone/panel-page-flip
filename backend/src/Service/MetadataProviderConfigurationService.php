<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MetadataProviderConfiguration;
use App\Metadata\Provider\SharedProviderCredentials;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one row holding the installation's provider credentials and switches.
 *
 * Assigned id 1 and seeded by its migration, so a fresh install and an upgraded
 * one both find the same row rather than accumulating new ones.
 */
final class MetadataProviderConfigurationService implements SharedProviderCredentials
{
    private ?MetadataProviderConfiguration $configuration = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function get(): MetadataProviderConfiguration
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        return $this->configuration = $this->entityManager->find(MetadataProviderConfiguration::class, 1)
            ?? new MetadataProviderConfiguration();
    }

    /**
     * Persisting is deliberately deferred to here rather than done when the row
     * is first missed. prePersist runs the moment persist() is called, not at
     * flush, so persisting an empty row up front would encrypt nothing and then
     * write whatever the caller set afterwards in clear.
     *
     * The row is re-resolved because the instance held here can outlive the
     * manager that loaded it. Persisting a detached copy of a row that already
     * exists would collide on the fixed id rather than update it.
     */
    public function save(): void
    {
        $edited = $this->get();
        $managed = $this->entityManager->find(MetadataProviderConfiguration::class, 1);

        if ($managed === null) {
            $this->entityManager->persist($edited);
        } elseif ($managed !== $edited) {
            $managed
                ->setMetronToken($edited->getMetronToken())
                ->setMetronSharedEnabled($edited->isMetronSharedEnabled())
                ->setComicVineApiKey($edited->getComicVineApiKey())
                ->setComicVineEnabled($edited->isComicVineEnabled())
                ->setPersonalCredentialsEnabled($edited->arePersonalCredentialsEnabled());
            $this->configuration = $managed;
        }

        $this->entityManager->flush();
    }

    public function metronToken(): ?string
    {
        return $this->get()->getMetronToken();
    }

    public function isMetronSharedEnabled(): bool
    {
        return $this->get()->isMetronSharedEnabled();
    }

    public function comicVineApiKey(): ?string
    {
        return $this->get()->getComicVineApiKey();
    }

    public function isComicVineEnabled(): bool
    {
        return $this->get()->isComicVineEnabled();
    }

    public function arePersonalCredentialsEnabled(): bool
    {
        return $this->get()->arePersonalCredentialsEnabled();
    }
}
