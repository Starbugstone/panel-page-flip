<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

final class EmailVerificationResult
{
    public const INVALID = 'invalid';
    public const ALREADY_VERIFIED = 'already_verified';
    public const VERIFIED = 'verified';

    public function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
    ) {
    }
}
