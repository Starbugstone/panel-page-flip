<?php

declare(strict_types=1);

namespace App\OAuth;

final readonly class OAuthProfile
{
    public function __construct(
        public string $provider,
        public string $subject,
        public string $email,
        public ?string $name,
        public bool $emailVerified,
        public bool $emailAuthoritative,
    ) {
    }
}
