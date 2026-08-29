<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pagination;

use App\Service\Pagination\LikePattern;
use PHPUnit\Framework\TestCase;

final class LikePatternTest extends TestCase
{
    /** @dataProvider escapingProvider */
    public function testContainsEscapesWildcardsAndLowercases(string $term, string $expected): void
    {
        self::assertSame($expected, LikePattern::contains($term));
    }

    /** @return iterable<string, array{string, string}> */
    public static function escapingProvider(): iterable
    {
        yield 'plain term is wrapped and lowercased' => ['Batman', '%batman%'];
        yield 'percent matches itself' => ['50%', '%50\%%'];
        yield 'underscore matches itself' => ['a_b', '%a\_b%'];
        yield 'backslash is escaped first' => ['a\\b', '%a\\\\b%'];
        yield 'a term of only wildcards matches nothing broad' => ['%_', '%\%\_%'];
    }

    public function testMinTermLengthRejectsSingleCharacterSearches(): void
    {
        self::assertSame(2, LikePattern::MIN_TERM_LENGTH);
    }
}
