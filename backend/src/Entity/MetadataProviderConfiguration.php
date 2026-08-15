<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The installation's own provider credentials and switches, held once.
 *
 * Metron authenticates with a revocable bearer token rather than an account
 * username and password: a token can be withdrawn without touching the account
 * behind it, and Metron recommends it for integrations. There is deliberately
 * nowhere here to put a Metron password.
 *
 * The secrets are encrypted at rest by MetadataProviderSecretsSubscriber and
 * never leave the server: the admin panel is told whether a provider is
 * configured, never what it was configured with.
 */
#[ORM\Entity]
class MetadataProviderConfiguration
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $metronToken = null;

    /**
     * The administrator's half of the shared-Metron switch. The environment
     * holds the other half and has the final word — see MetadataAccessResolver.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $metronSharedEnabled = false;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $comicVineApiKey = null;

    /**
     * On by default, and switchable separately from Metron.
     *
     * Comic Vine's published terms are non-commercial only, which the ordinary
     * self-hosted deployment satisfies. An installation that stops satisfying
     * them turns this off — the switch exists so that is one click rather than a
     * code change, not so that every operator has to find it before the feature
     * works at all.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $comicVineEnabled = true;

    public function getMetronToken(): ?string
    {
        return $this->metronToken;
    }

    public function setMetronToken(?string $metronToken): static
    {
        $this->metronToken = self::blankToNull($metronToken);

        return $this;
    }

    public function isMetronSharedEnabled(): bool
    {
        return $this->metronSharedEnabled;
    }

    public function setMetronSharedEnabled(bool $metronSharedEnabled): static
    {
        $this->metronSharedEnabled = $metronSharedEnabled;

        return $this;
    }

    public function getComicVineApiKey(): ?string
    {
        return $this->comicVineApiKey;
    }

    public function setComicVineApiKey(?string $comicVineApiKey): static
    {
        $this->comicVineApiKey = self::blankToNull($comicVineApiKey);

        return $this;
    }

    public function isComicVineEnabled(): bool
    {
        return $this->comicVineEnabled;
    }

    public function setComicVineEnabled(bool $comicVineEnabled): static
    {
        $this->comicVineEnabled = $comicVineEnabled;

        return $this;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
