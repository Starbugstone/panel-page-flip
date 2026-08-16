<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a sharing code is for, written on the front of it.
 *
 * Three codes travel through chat windows and none of them means the same
 * thing: one says "this is me", one hands over a single comic, one hands over a
 * package of them. They used to be indistinguishable — twelve characters, and
 * the field you pasted them into decided what they meant — which made every
 * wrong paste a lookup failure rather than an explanation.
 *
 * The letter is outside the random token, so it costs no entropy and buys the
 * one thing entropy cannot: a code can be classified before anything is looked
 * up, and somebody who pastes a comic code into the recipient box can be told
 * what they are holding instead of being told it is invalid.
 */
enum ShareCodeType: string
{
    case USER = 'U';
    case COMIC = 'C';
    case GROUP = 'G';

    /**
     * The type this code claims to be, from its prefix.
     *
     * Deliberately not a fallback: an unknown prefix is not a user code, and
     * treating it as one is how a legacy-compatibility layer starts.
     */
    public static function fromPrefix(string $prefix): ?self
    {
        return self::tryFrom(strtoupper($prefix));
    }

    /** What this kind of code is called wherever a person reads about it. */
    public function label(): string
    {
        return match ($this) {
            self::USER => 'user code',
            self::COMIC => 'comic code',
            self::GROUP => 'group code',
        };
    }

    /**
     * What to say when a code of this type turns up where another was wanted.
     *
     * Guidance rather than a rejection: the code is genuine and the person
     * holding it has simply pasted it into the wrong box, so the useful answer
     * names what they have and where it goes.
     */
    public function misuseGuidance(): string
    {
        return match ($this) {
            self::USER => 'This is a user code. Use it when sharing directly with another user.',
            self::COMIC => 'This is a comic code. Redeem it under Shared with me.',
            self::GROUP => 'This is a group code. Redeem it under Shared with me.',
        };
    }

    /** Whether this type carries comics rather than identifying a person. */
    public function isContentCode(): bool
    {
        return $this !== self::USER;
    }
}
