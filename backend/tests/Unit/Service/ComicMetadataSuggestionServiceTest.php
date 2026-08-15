<?php

namespace App\Tests\Unit\Service;

use App\Entity\Comic;
use App\Enum\MetadataSource;
use App\Metadata\ComicFilenameParser;
use App\Metadata\Provider\ProviderCandidate;
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
                ['publishedAt', '2011-01-01'],
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
            ['publishedAt'],
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

    /**
     * A search row carries a fraction of what a provider knows. The fields the
     * detail lookup adds are the ones worth reviewing, so all of them have to
     * reach the review UI rather than only the handful the first cut mapped.
     */
    public function testProposesEveryFieldAChosenRecordOffers(): void
    {
        $candidate = new ProviderCandidate(
            provider: 'metron',
            externalId: '123',
            series: 'The Boys',
            issueNumber: '7',
            title: 'Get Some',
            volume: 1,
            issueCount: 72,
            publisher: 'Dynamite Entertainment',
            summary: 'Hughie meets the Female.',
            publishedAt: new \DateTimeImmutable('2006-11-01'),
            creators: ['writer' => ['Garth Ennis']],
            languageCode: 'en',
            ageRating: 'Explicit',
            isDetailed: true,
        );

        $fields = array_map(
            static fn ($s): string => $s->field,
            $this->service->fromCandidate(new Comic(), $candidate)
        );

        self::assertSame(
            ['title', 'series', 'issueNumber', 'issueCount', 'volume', 'publisher', 'description', 'publishedAt', 'languageCode', 'ageRating', 'creators'],
            $fields
        );
    }

    /**
     * The explicit-content flag is the owner's declaration about their own
     * library. No age rating from a database is grounds for setting it on their
     * behalf, so it is not a field a suggestion can even name.
     */
    public function testNeverProposesTheExplicitContentFlag(): void
    {
        $candidate = new ProviderCandidate(
            provider: 'metron',
            externalId: '123',
            series: 'The Boys',
            ageRating: 'Explicit',
            isDetailed: true,
        );

        $fields = array_map(
            static fn ($s): string => $s->field,
            $this->service->fromCandidate(new Comic(), $candidate)
        );

        self::assertNotContains('explicitContent', $fields);
    }

    /** Role order and case are not what makes two credit lists different. */
    public function testDoesNotProposeCreditsTheComicAlreadyHas(): void
    {
        $comic = (new Comic())->setCreators(['Writer' => ['Garth Ennis']]);
        $candidate = new ProviderCandidate(
            provider: 'metron',
            externalId: '123',
            series: 'The Boys',
            creators: ['writer' => ['Garth Ennis']],
            isDetailed: true,
        );

        $fields = array_map(
            static fn ($s): string => $s->field,
            $this->service->fromCandidate($comic, $candidate)
        );

        self::assertNotContains('creators', $fields);
    }

    public function testProposesCreditsWhenTheyDiffer(): void
    {
        $comic = (new Comic())->setCreators(['writer' => ['Somebody Else']]);
        $candidate = new ProviderCandidate(
            provider: 'metron',
            externalId: '123',
            series: 'The Boys',
            creators: ['writer' => ['Garth Ennis']],
            isDetailed: true,
        );

        $creators = array_values(array_filter(
            $this->service->fromCandidate($comic, $candidate),
            static fn ($s): bool => $s->field === 'creators'
        ));

        self::assertCount(1, $creators);
        self::assertSame(['writer' => ['Garth Ennis']], $creators[0]->suggested);
    }
}
