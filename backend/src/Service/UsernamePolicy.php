<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a username may be, in one place.
 *
 * A username is the public half of an account: it is what a sender is shown
 * when they resolve a recipient, and the thing they check before handing a
 * comic over. That makes two properties load-bearing.
 *
 * It has to be **unique regardless of case**, because a confirmation screen
 * showing "@SilverOtter" while the comics go to `@silverotter` is a
 * confirmation of nothing. The canonical form is what the unique index holds;
 * the form the account chose is what everybody is shown.
 *
 * And it has to be **unmistakable for the service itself**. `@support` on a
 * confirmation screen reads as the operator, not as a stranger, so the names
 * that would read that way are not available to take.
 */
final class UsernamePolicy
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 32;

    /**
     * Names that would read as the service rather than as a person.
     *
     * Compared canonically, so `Admin` is as reserved as `admin`. Short enough
     * to be a literal list on purpose: a pattern would also catch the ordinary
     * names that merely contain one of these.
     */
    private const RESERVED = [
        'admin',
        'administrator',
        'root',
        'system',
        'support',
        'moderator',
        'panelpageflip',
        'help',
        'staff',
        'official',
        'security',
        'noreply',
    ];

    /**
     * The form the unique index holds.
     *
     * Lowercase only. Nothing else is folded: stripping dashes or underscores
     * here would make `silver-otter` and `silverotter` the same account, which
     * is a stronger claim than "the same name typed differently".
     */
    public static function canonicalise(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public static function isReserved(string $username): bool
    {
        return in_array(self::canonicalise($username), self::RESERVED, true);
    }

    /**
     * Why this username cannot be used, or null when it can.
     *
     * Returns the sentence rather than a code: every caller wants to show it,
     * and the ones that do not can still test for null.
     */
    public static function validate(string $username): ?string
    {
        $trimmed = trim($username);

        if ($trimmed === '') {
            return 'Choose a username.';
        }

        $length = mb_strlen($trimmed);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return sprintf(
                'A username must be between %d and %d characters.',
                self::MIN_LENGTH,
                self::MAX_LENGTH
            );
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $trimmed) !== 1) {
            return 'A username can use letters, numbers, hyphens and underscores, and must start with a letter or number.';
        }

        if (self::isReserved($trimmed)) {
            return 'That username is reserved. Please choose another.';
        }

        return null;
    }

    public static function isValid(string $username): bool
    {
        return self::validate($username) === null;
    }

    /** The form a username is written in when it is shown as an identity. */
    public static function forDisplay(string $username): string
    {
        return '@' . $username;
    }
}
