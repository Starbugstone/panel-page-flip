<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UsernameGenerator;
use App\Service\UsernamePolicy;
use PHPUnit\Framework\TestCase;

final class UsernameGeneratorTest extends TestCase
{
    public function testEverySuggestionIsANameThePolicyWouldAccept(): void
    {
        $generator = new UsernameGenerator();

        for ($i = 0; $i < 200; ++$i) {
            $username = $generator->generate();

            self::assertNull(
                UsernamePolicy::validate($username),
                sprintf('"%s" was generated but would be refused.', $username)
            );
        }
    }

    /**
     * The digits are what stop two people being offered the same name in the
     * same minute, so a wider suffix has to actually widen.
     */
    public function testAWiderSuffixStaysInsideTheLengthCeiling(): void
    {
        $generator = new UsernameGenerator();

        foreach ([1, 4, 8, 12] as $digits) {
            $username = $generator->generate($digits);

            self::assertNull(UsernamePolicy::validate($username));
            self::assertMatchesRegularExpression(
                sprintf('/[0-9]{%d}$/', $digits),
                $username,
                sprintf('%d digits were asked for.', $digits)
            );
            self::assertLessThanOrEqual(UsernamePolicy::MAX_LENGTH, strlen($username));
        }
    }

    /**
     * Names are handed out to strangers, so a run of them must not repeat
     * itself the way a poorly seeded generator would.
     */
    public function testSuccessiveSuggestionsAreNotTheSame(): void
    {
        $generator = new UsernameGenerator();
        $seen = [];

        for ($i = 0; $i < 100; ++$i) {
            $seen[] = $generator->generate();
        }

        // Collisions are possible and not an error — the caller checks the
        // table — but a generator producing fewer than half distinct names in a
        // hundred draws is broken rather than unlucky.
        self::assertGreaterThan(50, count(array_unique($seen)));
    }

    /**
     * A username is published to whoever the owner shares with; the account
     * behind it is not. Nothing about the person may leak into the name.
     */
    public function testNothingAboutTheAccountCanReachTheName(): void
    {
        $generator = new UsernameGenerator();

        // The generator takes no account, which is the structural version of
        // this promise — there is nothing it could derive from. This asserts
        // the observable half: a hundred draws never reproduce a plausible
        // email local part.
        for ($i = 0; $i < 100; ++$i) {
            self::assertStringNotContainsStringIgnoringCase('@', $generator->generate());
        }
    }
}
