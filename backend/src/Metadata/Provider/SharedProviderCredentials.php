<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

/**
 * The installation's own provider credentials and switches, and nothing else.
 *
 * Narrow on purpose: whatever decides who may spend an allowance has no
 * business reaching the entity these come from, or the entity manager behind
 * it.
 */
interface SharedProviderCredentials
{
    public function metronToken(): ?string;

    public function isMetronSharedEnabled(): bool;

    public function comicVineApiKey(): ?string;

    public function isComicVineEnabled(): bool;
}
