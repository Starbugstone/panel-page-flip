<?php

namespace App\Service;

/**
 * The shape of every sharing code, and the only place that decides it.
 *
 * Two different things are written in this format — the permanent receiver code
 * that identifies somebody as a recipient, and the disposable claim code that
 * hands out a comic — because a person copying one out of a chat window should
 * not have to know which kind they were given. What they mean is decided by the
 * field they are pasted into; this only decides what they look like.
 *
 * Crockford's alphabet, so a code read off a screen and typed into a phone
 * survives the trip: no I, L, O or U, and the pairs people confuse anyway are
 * folded back on the way in. Twelve characters is a little over 60 bits, which
 * is far past guessable and still short enough to read aloud.
 */
final class SharingCodeFormat
{
    /** Crockford base32: no I, L, O or U, so nothing reads as something else. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Characters in a code, excluding the grouping dashes. */
    public const LENGTH = 12;

    private const GROUP = 4;

    /** A new random code, already normalised. */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * What somebody typed, reduced to the one form everything else compares.
     *
     * Lowercase, spaces, missing dashes and the characters the alphabet leaves
     * out are all somebody transcribing a code by hand rather than somebody
     * holding the wrong one, so they are corrected instead of rejected.
     *
     * @return string the normalised code, or '' when it cannot be one
     */
    public static function normalise(string $input): string
    {
        $candidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        $candidate = strtr($candidate, ['I' => '1', 'L' => '1', 'O' => '0', 'U' => 'V']);

        if (strlen($candidate) !== self::LENGTH) {
            return '';
        }

        return strspn($candidate, self::ALPHABET) === self::LENGTH ? $candidate : '';
    }

    public static function isValid(string $input): bool
    {
        return self::normalise($input) !== '';
    }

    /** The grouped form, which is the only form a person is ever shown. */
    public static function forDisplay(string $normalised): string
    {
        return implode('-', str_split($normalised, self::GROUP));
    }

    /**
     * The stored form of a claim code.
     *
     * Claim codes are hashed because they carry a capability, exactly like an
     * invitation token: the plaintext exists once, in the message the owner
     * sends, and nothing here can reproduce it afterwards. Receiver codes are
     * stored in the clear instead — they are an address, they grant nothing,
     * and their owner has to be able to look them up again.
     *
     * A raw 60-bit code needs no work factor: there is no dictionary to slow an
     * attacker down through, and redemption is rate limited besides.
     */
    public static function hash(string $normalised): string
    {
        return hash('sha256', $normalised);
    }
}
