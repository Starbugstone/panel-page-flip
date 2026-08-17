<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ShareCodeType;

/**
 * A sharing code somebody typed, after it has been recognised.
 *
 * Exists so "what kind of code is this?" and "which code is it?" are answered
 * once, together, at the edge — before any repository is asked anything. A
 * caller that wants a user code and is handed a comic code can then say so,
 * which is the whole reason the prefix is there.
 */
final class ParsedShareCode
{
    public function __construct(
        public readonly ShareCodeType $type,
        public readonly string $token,
    ) {
    }

    public function is(ShareCodeType $type): bool
    {
        return $this->type === $type;
    }

    public function hash(): string
    {
        return SharingCodeFormat::hash($this->type, $this->token);
    }

    public function forDisplay(): string
    {
        return SharingCodeFormat::forDisplay($this->type, $this->token);
    }
}
