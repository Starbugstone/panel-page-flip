<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Credentials for the external metadata providers, held once for the whole
 * installation.
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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metronUsername = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $metronPassword = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $comicVineApiKey = null;

    public function getMetronUsername(): ?string
    {
        return $this->metronUsername;
    }

    public function setMetronUsername(?string $metronUsername): static
    {
        $this->metronUsername = self::blankToNull($metronUsername);

        return $this;
    }

    public function getMetronPassword(): ?string
    {
        return $this->metronPassword;
    }

    public function setMetronPassword(?string $metronPassword): static
    {
        $this->metronPassword = self::blankToNull($metronPassword);

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

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
