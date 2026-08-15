<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Entity\User;

/** One user's own provider tokens, and nothing else. */
interface PersonalProviderCredentials
{
    public function metronToken(User $user): ?string;

    public function comicVineApiKey(User $user): ?string;
}
