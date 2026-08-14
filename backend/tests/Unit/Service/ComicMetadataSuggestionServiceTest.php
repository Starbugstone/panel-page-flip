<?php

namespace App\Tests\Unit\Service;

use App\Entity\Comic;
use App\Enum\MetadataSource;
use App\Metadata\ComicFilenameParser;
use App\Service\ComicMetadataSuggestionService;
use PHPUnit\Framework\TestCase;

final class ComicMetadataSuggestionServiceTest extends TestCase
{
    private ComicMetadataSuggestionService $service;

    protected function setUp(): void
    {
        $this->service = new ComicMetadataSuggestionService(new ComicFilenameParser());
    }

    public function testProposesWhatTheFilenameImpliesForAnEmptyComic(): void
    {
        $suggestions = $this->service->for(
            (new Comic())->setOriginalFilename('Batman - 007 (2011) (Digital).cbz')
        );

        self::assertSame(
            [
                ['series', 'Batman'],
                ['issueNumber', '7'],
                ['publishedYear', 2011],
            ],
            array_map(static fn ($s) => [$s->field, $s->suggested], $suggestions)
        );
        self::assertSame(MetadataSource::Filename, $suggestions[0]->source);
        self::assertTrue($suggestions[0]->fillsAGap());
    }

    /**
     * ComicInfo has already been applied by the time this runs, so a field it
     * filled needs no proposal unless the filename disagrees.
     */
    public function testSaysNothingAboutFieldsThatAlreadyAgree(): void
    {
        $comic = (new Comic())
            ->setOriginalFilename('Batman - 007 (2011).cbz')
            ->setSeries('Batman')
            ->setIssueNumber('7');

        self::assertSame(
            ['publishedYear'],
            array_map(static fn ($s) => $s->field, $this->service->for($comic))
        );
    }

    public function testReportsADisagreementRatherThanHidingIt(): void
    {
        $comic = (new Comic())
            ->setOriginalFilename('Batman - 007 (2011).cbz')
            ->setSeries('Detective Comics');

        $series = $this->service->for($comic)[0];

        self::assertSame('series', $series->field);
        self::assertSame('Detective Comics', $series->current);
        self::assertSame('Batman', $series->suggested);
        self::assertFalse($series->fillsAGap());
    }

    public function testHasNothingToSayWithoutAUsableFilename(): void
    {
        self::assertSame([], $this->service->for(new Comic()));
        self::assertSame([], $this->service->for((new Comic())->setOriginalFilename('12345.cbz')));
    }

    public function testSerialisesWithItsProvenance(): void
    {
        $suggestion = $this->service->for((new Comic())->setOriginalFilename('Saga #12.cbz'))[0];

        self::assertSame(
            ['field' => 'series', 'current' => null, 'suggested' => 'Saga', 'source' => 'filename', 'fillsGap' => true],
            $suggestion->jsonSerialize()
        );
    }
}
