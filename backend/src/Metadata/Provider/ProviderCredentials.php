<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

/**
 * The credentials a provider needs, and nothing else.
 *
 * Narrow on purpose: a provider has no business reaching the entity these come
 * from, or the entity manager behind it.
 */
interface ProviderCredentials
{
    public function metronUsername(): ?string;

    public function metronPassword(): ?string;

    public function comicVineApiKey(): ?string;
}
