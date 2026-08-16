<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UsernamePolicy;
use PHPUnit\Framework\TestCase;

/**
 * What a username may be.
 *
 * The rules exist for one reason: a username is what a sender reads before
 * handing a comic over, so it has to identify exactly one account and it must
 * not be able to impersonate the service.
 */
final class UsernamePolicyTest extends TestCase
{
    /**
     * @dataProvider acceptableNames
     */
    public function testAnOrdinaryNameIsAccepted(string $username): void
    {
        self::assertNull(UsernamePolicy::validate($username), sprintf('"%s" should be usable.', $username));
    }

    /** @return iterable<string, array{string}> */
    public static function acceptableNames(): iterable
    {
        yield 'a generated one' => ['SilverOtter4821'];
        yield 'the shortest allowed' => ['abc'];
        yield 'the longest allowed' => [str_repeat('a', UsernamePolicy::MAX_LENGTH)];
        yield 'with an underscore' => ['quiet_falcon'];
        yield 'with a hyphen' => ['copper-mantis'];
        yield 'starting with a digit' => ['2000AD'];
    }

    /**
     * @dataProvider unacceptableNames
     */
    public function testAnUnusableNameSaysWhy(string $username, string $expectedFragment): void
    {
        $problem = UsernamePolicy::validate($username);

        self::assertNotNull($problem, sprintf('"%s" should be refused.', $username));
        self::assertStringContainsString($expectedFragment, $problem);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unacceptableNames(): iterable
    {
        yield 'empty' => ['', 'Choose a username'];
        // Derived from the policy, so widening the range updates the cases
        // rather than leaving two tests asserting the old numbers.
        $lengths = sprintf('between %d and %d', UsernamePolicy::MIN_LENGTH, UsernamePolicy::MAX_LENGTH);

        yield 'too short' => [str_repeat('a', UsernamePolicy::MIN_LENGTH - 1), $lengths];
        yield 'too long' => [str_repeat('a', UsernamePolicy::MAX_LENGTH + 1), $lengths];
        yield 'with a space' => ['silver otter', 'letters, numbers'];
        yield 'starting with a hyphen' => ['-silverotter', 'must start with'];
        yield 'starting with an underscore' => ['_silverotter', 'must start with'];
        yield 'with an at sign' => ['@silverotter', 'letters, numbers'];
        yield 'with a dot' => ['silver.otter', 'letters, numbers'];
        yield 'reserved' => ['admin', 'reserved'];
        // Reservation is judged canonically, so shouting it does not free it.
        yield 'reserved, shouted' => ['ADMIN', 'reserved'];
        yield 'reserved, mixed' => ['PanelPageFlip', 'reserved'];
    }

    /**
     * The rule people actually care about: two accounts cannot differ only in
     * capitalisation, because a confirmation screen showing @SilverOtter while
     * the comics go to @silverotter has confirmed nothing.
     */
    public function testUniquenessIsJudgedWithoutRegardToCase(): void
    {
        self::assertSame(
            UsernamePolicy::canonicalise('SilverOtter4821'),
            UsernamePolicy::canonicalise('silverotter4821')
        );
        self::assertSame('silverotter4821', UsernamePolicy::canonicalise('  SilverOtter4821  '));
    }

    /**
     * Only case is folded. Punctuation is part of the name, and folding it
     * would claim that @silver-otter and @silverotter are the same person.
     */
    public function testPunctuationIsNotFoldedAway(): void
    {
        self::assertNotSame(
            UsernamePolicy::canonicalise('silver-otter'),
            UsernamePolicy::canonicalise('silverotter')
        );
    }

    public function testAUsernameIsWrittenWithItsAtSign(): void
    {
        self::assertSame('@SilverOtter4821', UsernamePolicy::forDisplay('SilverOtter4821'));
    }

    /**
     * Somebody pasting a handle out of a chat window has typed the right name,
     * sigil and surrounding whitespace included.
     */
    public function testTheAtSignIsNotPartOfTheName(): void
    {
        self::assertSame('SilverOtter4821', UsernamePolicy::stripPrefix('@SilverOtter4821'));
        self::assertSame('SilverOtter4821', UsernamePolicy::stripPrefix('  @SilverOtter4821  '));
        self::assertSame('SilverOtter4821', UsernamePolicy::stripPrefix('SilverOtter4821'));
    }

    /**
     * A name is never *only* its sigil, so stripping one must not leave
     * something that then reads as a valid username.
     */
    public function testStrippingLeavesNothingBehindForABareSigil(): void
    {
        self::assertSame('', UsernamePolicy::stripPrefix('@'));
        self::assertNotNull(UsernamePolicy::validate(UsernamePolicy::stripPrefix('@')));
    }
}
