<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Friendly usernames nobody has to think up.
 *
 * Registration should not stall on "pick a public handle", and the accounts
 * that predate usernames have to be given one without anybody being asked. So
 * the default is generated: an adjective, a noun and four digits, which reads
 * as a name a person could have chosen rather than as a serial number.
 *
 * Two things it deliberately does not do. It never derives anything from the
 * account — no email local part, no display name, no address — because a
 * username is published to whoever the owner shares with and the account data
 * is not. And it never treats its own output as unique: the words come from
 * short lists and collisions are ordinary, so uniqueness is
 * {@see UsernameService}'s problem and the database index's ruling.
 */
final class UsernameGenerator
{
    /**
     * Curated rather than pulled from a dictionary.
     *
     * Every pair of these is shown to a stranger as somebody's public name, so
     * the list is short and hand-checked instead of long and merely filtered.
     */
    private const ADJECTIVES = [
        'Amber', 'Ancient', 'Azure', 'Bold', 'Brave', 'Bright', 'Bronze', 'Calm',
        'Clever', 'Copper', 'Coral', 'Cosmic', 'Crimson', 'Curious', 'Daring',
        'Dusty', 'Eager', 'Electric', 'Emerald', 'Fearless', 'Gentle', 'Gilded',
        'Golden', 'Hidden', 'Indigo', 'Iron', 'Jade', 'Keen', 'Lucky', 'Lunar',
        'Merry', 'Midnight', 'Nimble', 'Noble', 'Northern', 'Quiet', 'Rapid',
        'Restless', 'Roaming', 'Ruby', 'Rustic', 'Sapphire', 'Scarlet', 'Silent',
        'Silver', 'Solar', 'Steady', 'Stellar', 'Sunlit', 'Swift', 'Thunder',
        'Tidal', 'Timber', 'Velvet', 'Vivid', 'Wandering', 'Wild', 'Winter',
        'Wistful', 'Zephyr',
    ];

    private const NOUNS = [
        'Albatross', 'Antler', 'Badger', 'Beacon', 'Bison', 'Boulder', 'Canyon',
        'Cedar', 'Comet', 'Compass', 'Condor', 'Coyote', 'Cricket', 'Dolphin',
        'Ember', 'Falcon', 'Fern', 'Finch', 'Fjord', 'Glacier', 'Harbour',
        'Heron', 'Ibex', 'Jackal', 'Kestrel', 'Lantern', 'Lynx', 'Magpie',
        'Mantis', 'Marlin', 'Meadow', 'Meteor', 'Mongoose', 'Nebula', 'Otter',
        'Panther', 'Pelican', 'Pine', 'Quartz', 'Quill', 'Raven', 'Reef',
        'Rocket', 'Sable', 'Salmon', 'Sparrow', 'Stallion', 'Summit', 'Tempest',
        'Thistle', 'Tiger', 'Toucan', 'Vulture', 'Walrus', 'Willow', 'Wolf',
        'Wombat', 'Yak', 'Zebra', 'Zenith',
    ];

    /**
     * One candidate. Not checked against anything — that is the caller's job.
     *
     * `random_int` rather than `rand`: a username is not a secret, but a
     * generator whose next output can be predicted from its last one hands an
     * attacker the names of accounts created in a batch, and there is no reason
     * to accept that when the secure call is the same length.
     *
     * @param int $entropyDigits how many trailing digits to append; raised by
     *                           the caller when the four-digit space keeps
     *                           colliding
     */
    public function generate(int $entropyDigits = 4): string
    {
        $digits = max(1, min($entropyDigits, 12));
        $suffix = '';

        for ($i = 0; $i < $digits; ++$i) {
            $suffix .= (string) random_int(0, 9);
        }

        $adjective = self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)];
        $noun = self::NOUNS[random_int(0, count(self::NOUNS) - 1)];

        // Truncating the words rather than the digits: the digits are the part
        // carrying the entropy, and the policy ceiling is far above the longest
        // pair here anyway.
        $stem = substr($adjective . $noun, 0, UsernamePolicy::MAX_LENGTH - $digits);

        return $stem . $suffix;
    }
}
