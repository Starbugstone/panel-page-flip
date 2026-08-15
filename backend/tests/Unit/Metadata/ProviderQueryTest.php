<?php

namespace App\Tests\Unit\Metadata;

use App\Entity\Comic;
use App\Metadata\ComicFilenameParser;
use App\Metadata\Provider\ProviderQuery;
use PHPUnit\Framework\TestCase;

/**
 * The search runs off the values in front of the user.
 *
 * A filename suggestion accepted into the edit form is the whole reason the
 * search is worth running; making somebody save and reopen before they could
 * use it was the flow break this replaces.
 */
final class ProviderQueryTest extends TestCase
{
    public function testStagedValuesWinOverWhatIsSaved(): void
    {
        $comic = (new Comic())->setSeries('Saved Series')->setIssueNumber('1');

        $query = ProviderQuery::staged($comic, ['series' => 'The Boys', 'issueNumber' => '7']);

        self::assertSame('The Boys', $query?->series);
        self::assertSame('7', $query?->issueNumber);
    }

    public function testFallsBackToTheSavedComicForFieldsTheFormDidNotStage(): void
    {
        $comic = (new Comic())->setSeries('The Boys')->setIssueNumber('7');

        $query = ProviderQuery::staged($comic, ['series' => 'The Boys Presents']);

        self::assertSame('The Boys Presents', $query?->series);
        self::assertSame('7', $query?->issueNumber);
    }

    /**
     * A filename guess fills a gap for the search without being persisted,
     * so an unsaved comic is still searchable on what its name implies.
     */
    public function testFallsBackToTheFilenameGuessLast(): void
    {
        $comic = (new Comic())->setOriginalFilename('Batman - 007 (2011) (Digital).cbz');
        $guess = (new ComicFilenameParser())->parse((string) $comic->getOriginalFilename());

        $query = ProviderQuery::staged($comic, [], $guess);

        self::assertSame('Batman', $query?->series);
        self::assertSame('7', $query?->issueNumber);
        self::assertSame(2011, $query?->year);
    }

    public function testAComicWithNothingToSearchOnProducesNoQuery(): void
    {
        self::assertNull(ProviderQuery::staged(new Comic(), []));
        self::assertNull(ProviderQuery::staged(new Comic(), ['series' => '   ']));
    }

    /** Staged values arrive from a client, so they are bounded like any input. */
    public function testBoundsWhatAClientCanPutInASearchTerm(): void
    {
        $query = ProviderQuery::staged(new Comic(), [
            'series' => str_repeat('a', 5_000),
            'issueNumber' => str_repeat('9', 100),
            'year' => 99_999,
            'volume' => -4,
        ]);

        self::assertSame(200, mb_strlen((string) $query?->series));
        self::assertSame(20, mb_strlen((string) $query?->issueNumber));
        self::assertNull($query?->year);
        self::assertNull($query?->volume);
    }

    public function testIgnoresAStagedValueThatIsNotAScalar(): void
    {
        $comic = (new Comic())->setSeries('The Boys');

        $query = ProviderQuery::staged($comic, ['series' => ['not', 'a', 'string']]);

        self::assertSame('The Boys', $query?->series);
    }

    /**
     * The cache key is the question, not the asker: the answer to "what issues
     * are called this" is the same for everybody, and sharing it is how one
     * user's lookup saves another user's allowance.
     */
    public function testEquivalentQuestionsShareACacheKey(): void
    {
        $one = new ProviderQuery('The Boys', '7', 2006);
        $two = new ProviderQuery('the boys', '7', 2006);

        self::assertSame($one->cacheKey('metron'), $two->cacheKey('metron'));
        self::assertNotSame($one->cacheKey('metron'), $one->cacheKey('comicvine'));
    }
}
