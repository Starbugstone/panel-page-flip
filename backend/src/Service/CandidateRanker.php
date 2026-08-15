<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\MatchConfidence;
use App\Metadata\Provider\ProviderCandidate;
use App\Metadata\Provider\ProviderQuery;

/**
 * Puts the likely answer first and says how likely it is.
 *
 * Series name and issue number decide it; year and volume only break ties or
 * contradict. Nothing here ever picks a candidate — a confidence of `exact` is
 * a label on a row a person still has to click, which is why the ranking is
 * allowed to be a heuristic rather than having to be right.
 */
final class CandidateRanker
{
    /**
     * @param list<ProviderCandidate> $candidates
     * @return list<ProviderCandidate>
     */
    public function rank(array $candidates, ProviderQuery $query): array
    {
        $scored = [];

        foreach (array_values($candidates) as $position => $candidate) {
            $confidence = $this->confidence($candidate, $query);
            $scored[] = [
                $confidence->rank(),
                $this->yearDistance($candidate, $query),
                $position,
                $candidate->withConfidence($confidence),
            ];
        }

        // Stable within a confidence band: equally plausible candidates keep
        // the order the provider gave them, which is its own relevance ranking.
        usort($scored, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $row): ProviderCandidate => $row[3], $scored);
    }

    public function confidence(ProviderCandidate $candidate, ProviderQuery $query): MatchConfidence
    {
        $wanted = self::normaliseName($query->series);
        $found = self::normaliseName($candidate->series);

        $seriesExact = $wanted !== '' && $wanted === $found;
        $seriesRelated = $wanted !== '' && $found !== ''
            && (str_starts_with($found, $wanted) || str_contains($found, $wanted) || str_contains($wanted, $found));

        $wantedIssue = self::normaliseIssue($query->issueNumber);
        $foundIssue = self::normaliseIssue($candidate->issueNumber);
        $issueKnown = $wantedIssue !== null && $foundIssue !== null;

        $confidence = match (true) {
            $seriesExact && $issueKnown && $wantedIssue === $foundIssue => MatchConfidence::Exact,
            $seriesExact && $wantedIssue === null => MatchConfidence::High,
            $seriesExact => MatchConfidence::Ambiguous,
            $seriesRelated => MatchConfidence::Ambiguous,
            default => MatchConfidence::Low,
        };

        // A year that disagrees is the cheapest way to notice that two comics
        // sharing a series name are different comics — reprints and relaunches
        // are exactly where this feature would otherwise be confidently wrong.
        if ($this->contradictsYear($candidate, $query)) {
            $confidence = match ($confidence) {
                MatchConfidence::Exact => MatchConfidence::High,
                MatchConfidence::High => MatchConfidence::Ambiguous,
                default => MatchConfidence::Low,
            };
        }

        return $confidence;
    }

    private function contradictsYear(ProviderCandidate $candidate, ProviderQuery $query): bool
    {
        $wanted = $query->year;
        $found = $candidate->year();

        // One year of slack: a comic dated December turns up in a collection
        // filed under the following year often enough to be normal.
        return $wanted !== null && $found !== null && abs($wanted - $found) > 1;
    }

    private function yearDistance(ProviderCandidate $candidate, ProviderQuery $query): int
    {
        $wanted = $query->year;
        $found = $candidate->year();

        return $wanted !== null && $found !== null ? abs($wanted - $found) : \PHP_INT_MAX;
    }

    /** Punctuation and case are not what distinguishes two series. */
    private static function normaliseName(string $value): string
    {
        $folded = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));

        return trim((string) preg_replace('/\s+/u', ' ', $folded));
    }

    /**
     * `001`, `1` and ` 1 ` are the same issue; `1.5` and `1AU` are not `1`.
     * Leading zeros are dropped without touching anything else.
     */
    private static function normaliseIssue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = mb_strtolower(trim($value));
        if ($trimmed === '') {
            return null;
        }

        $unpadded = ltrim($trimmed, '0');

        return $unpadded === '' ? '0' : $unpadded;
    }
}
