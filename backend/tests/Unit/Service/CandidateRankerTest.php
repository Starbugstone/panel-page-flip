<?php

namespace App\Tests\Unit\Service;

use App\Enum\MatchConfidence;
use App\Metadata\Provider\ProviderCandidate;
use App\Metadata\Provider\ProviderQuery;
use App\Service\CandidateRanker;
use PHPUnit\Framework\TestCase;

/**
 * Confidence is a label on a row somebody still has to click. It is allowed to
 * be a heuristic; it is not allowed to be silently wrong about which row is
 * most likely, because that is the row a tired person accepts.
 */
final class CandidateRankerTest extends TestCase
{
    public function testSeriesAndIssueTogetherAreExact(): void
    {
        self::assertSame(
            MatchConfidence::Exact,
            $this->confidenceOf(series: 'The Boys', issue: '7', query: new ProviderQuery('The Boys', '7'))
        );
    }

    /** `001` and `1` are the same issue; the padding is a naming convention. */
    public function testIssueNumberPaddingDoesNotPreventAnExactMatch(): void
    {
        self::assertSame(
            MatchConfidence::Exact,
            $this->confidenceOf(series: 'The Boys', issue: '7', query: new ProviderQuery('The Boys', '007'))
        );
    }

    /** Punctuation and case are not what distinguishes two series. */
    public function testPunctuationDoesNotPreventAnExactMatch(): void
    {
        self::assertSame(
            MatchConfidence::Exact,
            $this->confidenceOf(series: 'Spider-Man', issue: '1', query: new ProviderQuery('spider man', '1'))
        );
    }

    public function testAnExactSeriesWithNoIssueToCompareIsHigh(): void
    {
        self::assertSame(
            MatchConfidence::High,
            $this->confidenceOf(series: 'The Boys', issue: '7', query: new ProviderQuery('The Boys'))
        );
    }

    /** The right series, the wrong issue. Worth showing, not worth trusting. */
    public function testTheWrongIssueOfTheRightSeriesIsAmbiguous(): void
    {
        self::assertSame(
            MatchConfidence::Ambiguous,
            $this->confidenceOf(series: 'The Boys', issue: '9', query: new ProviderQuery('The Boys', '7'))
        );
    }

    public function testAMerelyRelatedSeriesNameIsAmbiguous(): void
    {
        self::assertSame(
            MatchConfidence::Ambiguous,
            $this->confidenceOf(series: 'The Boys Presents', issue: '7', query: new ProviderQuery('The Boys', '7'))
        );
    }

    public function testAnUnrelatedResultIsLow(): void
    {
        self::assertSame(
            MatchConfidence::Low,
            $this->confidenceOf(series: 'Herogasm', issue: '1', query: new ProviderQuery('The Boys', '7'))
        );
    }

    /**
     * Relaunches and reprints share a series name and an issue number. A year
     * that disagrees is the cheapest signal that they are different comics.
     */
    public function testAContradictingYearDowngradesAnOtherwiseExactMatch(): void
    {
        self::assertSame(
            MatchConfidence::High,
            $this->confidenceOf(
                series: 'The Boys',
                issue: '7',
                query: new ProviderQuery('The Boys', '7', 2006),
                publishedAt: '2020-01-01'
            )
        );
    }

    /** A December issue filed under the next year is normal, not a mismatch. */
    public function testAYearOffByOneIsNotAContradiction(): void
    {
        self::assertSame(
            MatchConfidence::Exact,
            $this->confidenceOf(
                series: 'The Boys',
                issue: '7',
                query: new ProviderQuery('The Boys', '7', 2006),
                publishedAt: '2007-01-01'
            )
        );
    }

    /**
     * Metron matches a series name loosely: asking for "The Boys" really does
     * return "Adventures of The Dover Boys" among 165 results. Whoever is
     * choosing should not have to scroll past it.
     */
    public function testPutsTheClosestMatchFirst(): void
    {
        $candidates = [
            $this->candidate('Adventures of The Dover Boys', '1'),
            $this->candidate('The Boys Presents', '1'),
            $this->candidate('The Boys', '1'),
            $this->candidate('Herogasm', '1'),
        ];

        $order = array_map(
            static fn (ProviderCandidate $c): string => $c->series,
            (new CandidateRanker())->rank($candidates, new ProviderQuery('The Boys', '1'))
        );

        // "Adventures of The Dover Boys" does not contain the phrase "the boys"
        // — the words are not adjacent — so it ranks with the unrelated results
        // rather than above them.
        self::assertSame(
            ['The Boys', 'The Boys Presents', 'Adventures of The Dover Boys', 'Herogasm'],
            $order
        );
    }

    /** Equally plausible candidates keep the order the provider gave them. */
    public function testRankingIsStableWithinAConfidenceBand(): void
    {
        $candidates = [
            $this->candidate('Batman', '1', externalId: 'first'),
            $this->candidate('Batman', '1', externalId: 'second'),
            $this->candidate('Batman', '1', externalId: 'third'),
        ];

        $order = array_map(
            static fn (ProviderCandidate $c): string => $c->externalId,
            (new CandidateRanker())->rank($candidates, new ProviderQuery('Batman', '1'))
        );

        self::assertSame(['first', 'second', 'third'], $order);
    }

    private function confidenceOf(
        string $series,
        ?string $issue,
        ProviderQuery $query,
        ?string $publishedAt = null
    ): MatchConfidence {
        return (new CandidateRanker())->confidence($this->candidate($series, $issue, $publishedAt), $query);
    }

    private function candidate(
        string $series,
        ?string $issue,
        ?string $publishedAt = null,
        string $externalId = '1'
    ): ProviderCandidate {
        return new ProviderCandidate(
            provider: 'metron',
            externalId: $externalId,
            series: $series,
            issueNumber: $issue,
            publishedAt: $publishedAt !== null ? new \DateTimeImmutable($publishedAt) : null,
        );
    }
}
