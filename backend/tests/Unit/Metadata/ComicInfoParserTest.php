<?php

namespace App\Tests\Unit\Metadata;

use App\Enum\ComicPageType;
use App\Enum\ReadingDirection;
use App\Metadata\ComicInfoParser;
use PHPUnit\Framework\TestCase;

/**
 * ComicInfo.xml arrives inside an uploaded file, so this parser is the boundary
 * between a stranger's XML and the database.
 */
final class ComicInfoParserTest extends TestCase
{
    private ComicInfoParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ComicInfoParser();
    }

    public function testReadsTheFieldsAComicDescribesItselfWith(): void
    {
        $info = $this->parser->parse($this->comicInfo(<<<'XML'
            <Title>The Long Halloween</Title>
            <Series>Batman</Series>
            <Number>7</Number>
            <Count>13</Count>
            <Volume>1996</Volume>
            <Publisher>DC</Publisher>
            <Summary>A killer strikes on holidays.</Summary>
            <Year>1997</Year>
            <Month>4</Month>
            <Day>9</Day>
            <LanguageISO>en</LanguageISO>
            <AgeRating>Teen</AgeRating>
            <Writer>Jeph Loeb</Writer>
            <Penciller>Tim Sale, Someone Else</Penciller>
        XML));

        self::assertNotNull($info);
        self::assertSame('The Long Halloween', $info->title);
        self::assertSame('Batman', $info->series);
        self::assertSame('7', $info->issueNumber);
        self::assertSame(13, $info->issueCount);
        self::assertSame(1996, $info->volume);
        self::assertSame('DC', $info->publisher);
        self::assertSame('A killer strikes on holidays.', $info->summary);
        self::assertSame('1997-04-09', $info->publishedAt?->format('Y-m-d'));
        self::assertSame('en', $info->languageCode);
        self::assertSame('Teen', $info->ageRating);
        self::assertSame(['writer' => ['Jeph Loeb'], 'penciller' => ['Tim Sale', 'Someone Else']], $info->creators);
    }

    /**
     * The reason this slice comes before the spread work: these three facts are
     * what stop the reader guessing at layout.
     */
    public function testReadsThePageFactsTheReaderNeeds(): void
    {
        $info = $this->parser->parse($this->comicInfo(<<<'XML'
            <Manga>YesAndRightToLeft</Manga>
            <Pages>
              <Page Image="0" Type="FrontCover" ImageWidth="1200" ImageHeight="1800" />
              <Page Image="1" Type="Story" />
              <Page Image="2" DoublePage="true" ImageWidth="2400" ImageHeight="1800" />
            </Pages>
        XML));

        self::assertNotNull($info);
        self::assertSame(ReadingDirection::RightToLeft, $info->readingDirection);
        self::assertCount(3, $info->pages);

        // ComicInfo counts from zero; everything else in the app counts from one.
        self::assertSame(1, $info->pages[0]->page);
        self::assertSame(ComicPageType::FrontCover, $info->pages[0]->type);
        self::assertSame(1200, $info->pages[0]->width);
        self::assertFalse($info->pages[0]->doublePage);

        self::assertTrue($info->pages[2]->doublePage);
    }

    /**
     * Dimensions are recorded whether or not the page claims to be a spread.
     * What a consumer makes of them is its own business; the parser's job is to
     * report what the file said.
     */
    public function testRecordsPageDimensionsWithoutTheDoublePageFlag(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Pages><Page Image="0" ImageWidth="2400" ImageHeight="1800" /></Pages>'
        ));

        self::assertSame(2400, $info?->pages[0]->width);
        self::assertSame(1800, $info?->pages[0]->height);
        self::assertFalse($info?->pages[0]->doublePage);
    }

    public function testDefaultsToLeftToRightWhenMangaIsAbsentOrMerelyYes(): void
    {
        self::assertSame(ReadingDirection::LeftToRight, $this->parser->parse($this->comicInfo('<Series>A</Series>'))?->readingDirection);
        self::assertSame(ReadingDirection::LeftToRight, $this->parser->parse($this->comicInfo('<Manga>Yes</Manga><Series>A</Series>'))?->readingDirection);
    }

    public function testKeepsPagesInOrderRegardlessOfDocumentOrder(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Pages><Page Image="4" /><Page Image="0" /><Page Image="2" /></Pages>'
        ));

        self::assertSame([1, 3, 5], array_map(static fn ($p) => $p->page, $info?->pages ?? []));
    }

    /**
     * A file listing the same page twice must not make the result depend on
     * how the parser walked it.
     */
    public function testKeepsTheFirstEntryForADuplicatedPage(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Pages><Page Image="0" Type="FrontCover" /><Page Image="0" Type="Story" DoublePage="true" /></Pages>'
        ));

        self::assertCount(1, $info?->pages ?? []);
        self::assertSame(ComicPageType::FrontCover, $info?->pages[0]->type);
        self::assertFalse($info?->pages[0]->doublePage);
    }

    /** @dataProvider unusableDocuments */
    public function testRefusesWhatItCannotUse(string $xml): void
    {
        self::assertNull($this->parser->parse($xml));
    }

    public function unusableDocuments(): iterable
    {
        yield 'empty' => [''];
        yield 'not xml' => ['this is not xml at all'];
        yield 'truncated' => ['<ComicInfo><Series>Batman'];
        yield 'wrong root' => ['<NotComicInfo><Series>Batman</Series></NotComicInfo>'];
        yield 'no usable field' => ['<ComicInfo></ComicInfo>'];
        yield 'only blank fields' => ['<ComicInfo><Series>   </Series><Publisher></Publisher></ComicInfo>'];
        yield 'oversized' => ['<ComicInfo><Series>'.str_repeat('a', 2_100_000).'</Series></ComicInfo>'];
    }

    /**
     * An external entity must resolve to nothing rather than to the contents of
     * a server file. Parsing may succeed; leaking must not.
     */
    public function testDoesNotResolveExternalEntities(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'xxe');
        file_put_contents($target, 'TOP_SECRET_VALUE');

        try {
            $info = $this->parser->parse(
                '<?xml version="1.0"?><!DOCTYPE ComicInfo [<!ENTITY xxe SYSTEM "file://'.$target.'">]>'
                .'<ComicInfo><Series>&xxe;</Series><Publisher>DC</Publisher></ComicInfo>'
            );

            self::assertStringNotContainsString('TOP_SECRET_VALUE', json_encode($info) ?: '');
            self::assertNotSame('TOP_SECRET_VALUE', $info?->series);
        } finally {
            @unlink($target);
        }
    }

    public function testDoesNotExpandRecursiveEntities(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE ComicInfo ['
            .'<!ENTITY a "aaaaaaaaaa">'
            .'<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            .'<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">'
            .'<!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">'
            .'<!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;">'
            .']><ComicInfo><Series>&e;</Series></ComicInfo>';

        $before = memory_get_usage();
        $info = $this->parser->parse($xml);

        self::assertLessThan(10_000_000, memory_get_usage() - $before);
        self::assertNull($info?->series);
    }

    public function testBoundsHostileFieldLengths(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Series>'.str_repeat('x', 50_000).'</Series>'
        ));

        self::assertSame(2_000, mb_strlen($info?->series ?? ''));
    }

    public function testDropsValuesThatAreNotWhatTheyClaimToBe(): void
    {
        $info = $this->parser->parse($this->comicInfo(<<<'XML'
            <Series>Batman</Series>
            <Count>not a number</Count>
            <Volume>-3</Volume>
            <Year>99</Year>
            <LanguageISO>definitely not a language code</LanguageISO>
            <Pages><Page Type="Story" /><Page Image="-1" /><Page Image="1" ImageWidth="0" /></Pages>
        XML));

        self::assertNotNull($info);
        self::assertSame('Batman', $info->series);
        self::assertNull($info->issueCount);
        self::assertNull($info->volume);
        self::assertNull($info->publishedAt);
        self::assertNull($info->languageCode);

        // A page with no usable index is dropped; a bad width is simply unknown.
        self::assertCount(1, $info->pages);
        self::assertSame(2, $info->pages[0]->page);
        self::assertNull($info->pages[0]->width);
    }

    public function testClampsAnImpossibleDateRatherThanDiscardingIt(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Series>A</Series><Year>2001</Year><Month>77</Month><Day>99</Day>'
        ));

        self::assertSame('2001-12-31', $info?->publishedAt?->format('Y-m-d'));
    }

    public function testIgnoresUnknownFieldsInsteadOfFailing(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Series>Batman</Series><SomethingNobodyHasHeardOf>x</SomethingNobodyHasHeardOf>'
        ));

        self::assertSame('Batman', $info?->series);
    }

    private function comicInfo(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ComicInfo>'.$body.'</ComicInfo>';
    }

    public function testIgnoresOverflowingNumbersWithoutLosingUsableMetadata(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Title>Still readable</Title><Count>2147483648</Count><Volume>'.PHP_INT_MAX.'</Volume>'
            .'<Pages><Page Image="'.PHP_INT_MAX.'" /><Page Image="99999999999999999999999" />'
            .'<Page Image="20000" /><Page Image="0" ImageWidth="2147483648" ImageHeight="1800" /></Pages>'
        ));

        self::assertSame('Still readable', $info?->title);
        self::assertNull($info?->issueCount);
        self::assertNull($info?->volume);
        self::assertCount(1, $info?->pages ?? []);
        self::assertSame(1, $info?->pages[0]->page);
        self::assertNull($info?->pages[0]->width);
        self::assertSame(1800, $info?->pages[0]->height);
    }

    public function testAcceptsBoundariesAndLeadingZeroes(): void
    {
        $info = $this->parser->parse($this->comicInfo(
            '<Count>00012</Count><Volume>2147483647</Volume>'
            .'<Pages><Page Image="19999" ImageWidth="001200" /></Pages>'
        ));

        self::assertSame(12, $info?->issueCount);
        self::assertSame(2_147_483_647, $info?->volume);
        self::assertSame(20_000, $info?->pages[0]->page);
        self::assertSame(1200, $info?->pages[0]->width);
    }

    /**
     * Genre and the free-text Tags list are read, but only as suggestions.
     * Neither is trusted enough to reorganise a library on import.
     */
    public function testReadsClassificationWithoutTreatingItAsTags(): void
    {
        $info = (new ComicInfoParser())->parse(<<<'XML'
            <?xml version="1.0"?>
            <ComicInfo>
              <Series>The Boys</Series>
              <Genre>Superhero, Crime</Genre>
              <Tags>mature, satire</Tags>
              <Characters>Billy Butcher, Hughie Campbell</Characters>
              <Teams>The Seven</Teams>
              <Locations>New York</Locations>
              <StoryArc>Herogasm</StoryArc>
            </ComicInfo>
            XML);

        self::assertSame(['Superhero', 'Crime', 'mature', 'satire'], $info?->classification?->genres);
        self::assertSame(['Billy Butcher', 'Hughie Campbell'], $info?->classification?->characters);
        self::assertSame(['The Seven'], $info?->classification?->teams);
        self::assertSame(['New York'], $info?->classification?->locations);
        self::assertSame(['Herogasm'], $info?->classification?->storyArcs);
    }

    /** Everything here came out of an uploaded archive. */
    public function testBoundsAndDeduplicatesClassificationValues(): void
    {
        $genres = implode(', ', array_merge(
            ['Superhero', 'superhero', 'SUPERHERO'],
            array_map(static fn (int $i): string => 'Genre '.$i, range(1, 100))
        ));

        $info = (new ComicInfoParser())->parse(sprintf(
            '<?xml version="1.0"?><ComicInfo><Series>X</Series><Genre>%s</Genre></ComicInfo>',
            $genres
        ));

        $found = $info?->classification?->genres ?? [];
        self::assertLessThanOrEqual(40, count($found));
        self::assertSame(['Superhero'], array_values(array_filter(
            $found,
            static fn (string $g): bool => strcasecmp($g, 'superhero') === 0
        )));
    }

    /** A file whose only content is classification is still worth reading. */
    public function testAFileCarryingOnlyClassificationIsNotEmpty(): void
    {
        $info = (new ComicInfoParser())->parse(
            '<?xml version="1.0"?><ComicInfo><Genre>Horror</Genre></ComicInfo>'
        );

        self::assertNotNull($info);
        self::assertSame(['Horror'], $info->classification?->genres);
    }
}
