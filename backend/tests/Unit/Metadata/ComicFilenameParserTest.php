<?php

namespace App\Tests\Unit\Metadata;

use App\Metadata\ComicFilenameParser;
use PHPUnit\Framework\TestCase;

/**
 * Filenames follow conventions rather than rules, so the interesting cases are
 * the ones this must refuse.
 */
final class ComicFilenameParserTest extends TestCase
{
    private ComicFilenameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ComicFilenameParser();
    }

    /**
     * @dataProvider realWorldNames
     */
    public function testReadsTheConventionsReleasesActuallyUse(
        string $filename,
        string $series,
        ?string $issueNumber,
        ?int $volume,
        ?int $year,
    ): void {
        $guess = $this->parser->parse($filename);

        self::assertNotNull($guess, "Expected a guess for: $filename");
        self::assertSame($series, $guess->series);
        self::assertSame($issueNumber, $guess->issueNumber);
        self::assertSame($volume, $guess->volume);
        self::assertSame($year, $guess->year);
    }

    public function realWorldNames(): iterable
    {
        yield 'dash separated with tags' => ['Batman - 001 (2011) (Digital) (Zone-Empire).cbz', 'Batman', '1', null, 2011];
        yield 'space separated' => ['Batman 001 (2011).cbz', 'Batman', '1', null, 2011];
        yield 'hash issue' => ['Saga #012 (2013).cbz', 'Saga', '12', null, 2013];
        yield 'volume marker' => ['Y The Last Man v01 (2003).cbz', 'Y The Last Man', null, 1, 2003];
        yield 'volume word' => ['Akira Vol. 3 (1990).cbz', 'Akira', null, 3, 1990];
        yield 'no year' => ['Invincible 001.cbz', 'Invincible', '1', null, null];
        yield 'decimal issue' => ['Amazing Spider-Man 700.5 (2013).cbz', 'Amazing Spider-Man', '700.5', null, 2013];
        yield 'underscores' => ['The_Sandman_017.cbz', 'The Sandman', '17', null, null];
        // An underscore is a word character, so every marker in this name was
        // invisible and the whole stem became the series.
        yield 'underscores around a volume' => ['theboys_vol2_getsome.cbz', 'theboys getsome', null, 2, null];
        yield 'dots as separators' => ['Saga.v01.001.cbz', 'Saga', '1', 1, null];
        yield 'underscored issue' => ['Preacher_012_(1996).cbz', 'Preacher', '12', null, 1996];
        yield 'title only' => ['Watchmen.cbz', 'Watchmen', null, null, null];
        yield 'bracketed year' => ['Preacher 001 [1995].cbz', 'Preacher', '1', null, 1995];
        yield 'volume and issue' => ['Hellboy v02 003 (2004).cbz', 'Hellboy', '3', 2, 2004];
        yield 'no extension' => ['Daredevil 181', 'Daredevil', '181', null, null];
        yield 'numeric series name survives' => ['100 Bullets 001 (1999).cbz', '100 Bullets', '1', null, 1999];
    }

    /**
     * @dataProvider unusableNames
     */
    public function testRefusesRatherThanGuessingWrong(string $filename): void
    {
        self::assertNull($this->parser->parse($filename));
    }

    public function unusableNames(): iterable
    {
        yield 'empty' => [''];
        yield 'extension only' => ['.cbz'];
        yield 'digits only' => ['12345.cbz'];
        yield 'punctuation only' => ['---.cbz'];
        yield 'tags only' => ['(Digital).cbz'];
        yield 'noise word only' => ['Digital.cbz'];
    }

    /**
     * A camera or scanner filename is all digits and no comic. Treating its
     * timestamp as an issue number would attach nonsense to a real comic.
     */
    public function testDoesNotReadATimestampAsAnIssueNumber(): void
    {
        $guess = $this->parser->parse('IMG_20230104_113355.cbz');

        self::assertSame('IMG 20230104 113355', $guess?->series);
        self::assertNull($guess?->issueNumber);
        self::assertNull($guess?->year);
    }

    public function testIgnoresAYearThatIsNotPlausible(): void
    {
        $guess = $this->parser->parse('Batman 001 (1234).cbz');

        self::assertSame('Batman', $guess?->series);
        self::assertNull($guess?->year);
    }

    /** A number in the middle of a name is part of the title, not the issue. */
    public function testOnlyTakesATrailingNumberAsTheIssue(): void
    {
        $guess = $this->parser->parse('Fantastic Four 4 Ever.cbz');

        self::assertSame('Fantastic Four 4 Ever', $guess?->series);
        self::assertNull($guess?->issueNumber);
    }

    public function testTreatsPaddedAndUnpaddedIssuesAsTheSame(): void
    {
        self::assertSame('7', $this->parser->parse('Batman 007.cbz')?->issueNumber);
        self::assertSame('7', $this->parser->parse('Batman 7.cbz')?->issueNumber);
        self::assertSame('0', $this->parser->parse('Batman 000.cbz')?->issueNumber);
    }

    public function testStripsAPathAndKeepsOnlyTheName(): void
    {
        self::assertSame('Batman', $this->parser->parse('/comics/dc/Batman 001 (2011).cbz')?->series);
    }
}
