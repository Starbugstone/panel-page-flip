<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

/**
 * Credentials that came from a request rather than from storage, so an
 * administrator can test what they typed before committing to it.
 *
 * Deliberately has no way to reach the database: whatever is being tested here
 * is not yet the installation's configuration, and must not be mistaken for it.
 */
final class StaticProviderCredentials implements ProviderCredentials
{
    public function __construct(
        private readonly ?string $metronUsername = null,
        private readonly ?string $metronPassword = null,
        private readonly ?string $comicVineApiKey = null,
    ) {
    }

    /**
     * The typed value where there is one, and the stored value otherwise, so
     * testing one provider does not require retyping the other's credentials.
     *
     * @param array<string, mixed> $submitted
     */
    public static function preferring(array $submitted, ProviderCredentials $stored): self
    {
        $typed = static function (string $field) use ($submitted): ?string {
            $value = $submitted[$field] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        return new self(
            $typed('metronUsername') ?? $stored->metronUsername(),
            $typed('metronPassword') ?? $stored->metronPassword(),
            $typed('comicVineApiKey') ?? $stored->comicVineApiKey(),
        );
    }

    public function metronUsername(): ?string
    {
        return $this->metronUsername;
    }

    public function metronPassword(): ?string
    {
        return $this->metronPassword;
    }

    public function comicVineApiKey(): ?string
    {
        return $this->comicVineApiKey;
    }
}
