<?php

namespace App\Service;

use App\Enum\ShareCodeType;

/**
 * The shape of every sharing code, and the only place that decides it.
 *
 * Three different things are written in this format — the permanent user code
 * that identifies somebody as a recipient, the comic code that hands over one
 * comic, and the group code that hands over a package of them. They are told
 * apart by a letter on the front rather than by which field they were pasted
 * into, so a code can be classified before anything is looked up and somebody
 * holding the wrong one can be told which one they are holding.
 *
 * Crockford's alphabet, so a code read off a screen and typed into a phone
 * survives the trip: no I, L, O or U, and the pairs people confuse anyway are
 * folded back on the way in. Twelve characters is a little over 60 bits, which
 * is far past guessable and still short enough to read aloud. The prefix sits
 * outside those twelve, so it costs nothing.
 */
final class SharingCodeFormat
{
    /** Crockford base32: no I, L, O or U, so nothing reads as something else. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Random characters in a code, excluding the prefix and the dashes. */
    public const LENGTH = 12;

    private const GROUP = 4;

    /** A new random token, already normalised. Carries no type on its own. */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }

    /**
     * What somebody typed, split into the type it claims and its token.
     *
     * Lowercase, stray spaces, missing dashes and the characters the alphabet
     * leaves out are all somebody transcribing a code by hand rather than
     * somebody holding the wrong one, so they are corrected. A missing or
     * unrecognised prefix is neither: it is a code this application does not
     * issue, and it is refused rather than guessed at.
     */
    public static function parse(string $input): ?ParsedShareCode
    {
        $condensed = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');

        if (strlen($condensed) !== self::LENGTH + 1) {
            return null;
        }

        $type = ShareCodeType::fromPrefix(substr($condensed, 0, 1));
        if ($type === null) {
            return null;
        }

        $token = self::normaliseToken(substr($condensed, 1));

        return $token === '' ? null : new ParsedShareCode($type, $token);
    }

    /**
     * The token part alone, reduced to the one form everything else compares.
     *
     * @return string the normalised token, or '' when it cannot be one
     */
    public static function normaliseToken(string $input): string
    {
        $candidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        $candidate = strtr($candidate, ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V']);

        if (strlen($candidate) !== self::LENGTH) {
            return '';
        }

        return strspn($candidate, self::ALPHABET) === self::LENGTH ? $candidate : '';
    }

    /** The grouped form, which is the only form a person is ever shown. */
    public static function forDisplay(ShareCodeType $type, string $token): string
    {
        return $type->value . '-' . implode('-', str_split($token, self::GROUP));
    }

    /**
     * The stored form of a content code.
     *
     * Content codes are hashed because they carry a capability, exactly like an
     * invitation token: the plaintext exists once, in the message the owner
     * sends, and nothing here can reproduce it afterwards. User codes are
     * stored in the clear instead — they are an address, they grant nothing,
     * and their owner has to be able to look them up again.
     *
     * The type goes into the hash along with the token, so a comic code and a
     * group code that happened to draw the same twelve characters are two
     * different rows and neither can be redeemed as the other.
     *
     * A raw 60-bit token needs no work factor: there is no dictionary to slow
     * an attacker down through, and redemption is rate limited besides.
     */
    public static function hash(ShareCodeType $type, string $token): string
    {
        return hash('sha256', $type->value . ':' . $token);
    }
}
