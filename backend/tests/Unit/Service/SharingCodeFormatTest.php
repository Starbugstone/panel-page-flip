<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\ShareCodeType;
use App\Service\SharingCodeFormat;
use PHPUnit\Framework\TestCase;

/**
 * The shape of a sharing code.
 *
 * Everything here is about what happens between somebody's clipboard and a
 * repository lookup. A code is transcribed by hand often enough that the
 * forgiving parts matter, and it is pasted into the wrong box often enough that
 * the strict parts matter more.
 */
final class SharingCodeFormatTest extends TestCase
{
    public function testAGeneratedTokenIsTwelveCharactersOfTheAmbiguityFreeAlphabet(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $token = SharingCodeFormat::generate();

            self::assertSame(SharingCodeFormat::LENGTH, strlen($token));
            self::assertSame(
                strlen($token),
                strspn($token, SharingCodeFormat::ALPHABET),
                'A generated token must not contain a character the alphabet excludes.'
            );
        }
    }

    public function testACodeIsDisplayedWithItsTypeAndInGroupsOfFour(): void
    {
        self::assertSame(
            'U-ABCD-EFGH-JKMN',
            SharingCodeFormat::forDisplay(ShareCodeType::USER, 'ABCDEFGHJKMN')
        );
        self::assertSame(
            'G-0123-4567-89AB',
            SharingCodeFormat::forDisplay(ShareCodeType::GROUP, '0123456789AB')
        );
    }

    /**
     * A code read off one screen and typed into another survives the trip.
     * Lowercase, missing dashes, stray spaces and the letters the alphabet
     * leaves out are all transcription, not somebody holding the wrong code.
     */
    public function testTranscriptionMistakesAreCorrectedRatherThanRefused(): void
    {
        $canonical = SharingCodeFormat::parse('C-ABCD-EFGH-JKMN');
        self::assertNotNull($canonical);

        foreach ([
            'c-abcd-efgh-jkmn',
            'CABCDEFGHJKMN',
            '  C-ABCD EFGH JKMN  ',
            'C.ABCD.EFGH.JKMN',
        ] as $typed) {
            $parsed = SharingCodeFormat::parse($typed);

            self::assertNotNull($parsed, sprintf('"%s" should still parse.', $typed));
            self::assertSame($canonical->token, $parsed->token);
            self::assertSame(ShareCodeType::COMIC, $parsed->type);
        }
    }

    public function testTheConfusablePairsAreFoldedBackTheWayTheAlphabetExpects(): void
    {
        // I and L read as 1, O reads as 0, U reads as V. Somebody who wrote a
        // code down by hand has produced one of these, not a different code.
        $parsed = SharingCodeFormat::parse('U-ILOU-1234-5678');

        self::assertNotNull($parsed);
        self::assertSame('110V12345678', $parsed->token);
    }

    /**
     * @dataProvider unrecognisableCodes
     */
    public function testAnythingThatIsNotOneOfTheThreeShapesIsRefused(string $input): void
    {
        self::assertNull(SharingCodeFormat::parse($input));
    }

    /** @return iterable<string, array{string}> */
    public static function unrecognisableCodes(): iterable
    {
        // The unprefixed form is the important one: it is what this application
        // used to issue, and accepting it would be the compatibility layer the
        // clean break exists to avoid.
        yield 'the old unprefixed form' => ['ABCD-EFGH-JKMN'];
        yield 'an unknown prefix' => ['X-ABCD-EFGH-JKMN'];
        yield 'too short' => ['C-ABCD-EFGH-JKM'];
        yield 'too long' => ['C-ABCD-EFGH-JKMNP'];
        yield 'empty' => [''];
        yield 'prose' => ['not a code at all'];
    }

    /**
     * The prefix is not decoration. Two codes drawn from the same twelve
     * characters are two different capabilities, and hashing the token alone
     * would let one be redeemed as the other.
     */
    public function testTheTypeIsPartOfTheStoredHash(): void
    {
        $token = 'ABCDEFGHJKMN';

        self::assertNotSame(
            SharingCodeFormat::hash(ShareCodeType::COMIC, $token),
            SharingCodeFormat::hash(ShareCodeType::GROUP, $token)
        );
        self::assertSame(
            SharingCodeFormat::hash(ShareCodeType::COMIC, $token),
            SharingCodeFormat::parse(SharingCodeFormat::forDisplay(ShareCodeType::COMIC, $token))?->hash()
        );
    }

    public function testEachTypeSaysWhereItActuallyBelongs(): void
    {
        self::assertStringContainsString('Shared with me', ShareCodeType::COMIC->misuseGuidance());
        self::assertStringContainsString('Shared with me', ShareCodeType::GROUP->misuseGuidance());
        self::assertStringContainsString('sharing directly', ShareCodeType::USER->misuseGuidance());

        self::assertFalse(ShareCodeType::USER->isContentCode());
        self::assertTrue(ShareCodeType::COMIC->isContentCode());
        self::assertTrue(ShareCodeType::GROUP->isContentCode());
    }
}
