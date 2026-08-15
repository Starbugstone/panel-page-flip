<?php

declare(strict_types=1);

namespace App\Metadata;

/**
 * Reads what a comic's filename implies about it.
 *
 * Filenames follow conventions, not rules, so this refuses more readily than it
 * guesses: a wrong series silently attached to a comic is worse than no
 * suggestion at all. Everything it returns is a proposal for somebody to accept.
 */
final class ComicFilenameParser
{
    private const MAX_ISSUE_DIGITS = 5;
    private const EARLIEST_YEAR = 1900;
    private const LATEST_YEAR = 2100;

    /** Scanner and release tags, which say nothing about the comic itself. */
    private const NOISE = [
        'digital', 'webrip', 'scan', 'c2c', 'f', 'fiction', 'repack', 'noads',
        'covers only', 'covers', 'dcp', 'minutemen', 'zone-empire', 'empire',
        'the last kryptonian-dcp', 'darkness-empire', 'phd', 'son of ultron',
    ];

    public function parse(string $filename): ?FilenameGuess
    {
        $stem = pathinfo(str_replace('\\', '/', trim($filename)), PATHINFO_FILENAME);
        if ($stem === '') {
            return null;
        }

        $year = $this->year($stem);
        $working = $this->withoutSeparators($this->withoutBracketedGroups($stem));

        [$working, $volume] = $this->extractVolume($working);
        [$working, $issueNumber] = $this->extractIssueNumber($working);

        $series = $this->series($working);
        if ($series === null) {
            return null;
        }

        return new FilenameGuess($series, $issueNumber, $volume, $year);
    }

    /** A four-digit year inside brackets, which is where releases put it. */
    private function year(string $stem): ?int
    {
        if (preg_match_all('/[(\[]\s*(\d{4})\s*[)\]]/', $stem, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $candidate) {
            $year = (int) $candidate;
            if ($year >= self::EARLIEST_YEAR && $year <= self::LATEST_YEAR) {
                return $year;
            }
        }

        return null;
    }

    private function withoutBracketedGroups(string $stem): string
    {
        return (string) preg_replace('/[(\[][^)\]]*[)\]]/', ' ', $stem);
    }

    /**
     * Separators become spaces before anything is looked for, not after.
     *
     * An underscore is a word character, so `\b` does not see a boundary in
     * `theboys_vol2_getsome` and every marker in the name goes unrecognised —
     * the whole thing ends up as the series. Doing this first is what makes
     * the underscore and dot conventions parse like the space one.
     */
    private function withoutSeparators(string $working): string
    {
        // A dot is a decimal point only between digits and with one or two
        // digits after it: `700.5` is an issue number, while the one in
        // `v01.001` is separating a zero-padded number and has to go.
        $spaced = (string) preg_replace('/_|(?<!\d)\.|\.(?!\d{1,2}(?!\d))/', ' ', $working);

        return (string) preg_replace('/\s+/', ' ', $spaced);
    }

    /** @return array{0: string, 1: int|null} */
    private function extractVolume(string $working): array
    {
        if (preg_match('/\bv(?:ol(?:ume)?)?\.?\s*(\d{1,4})\b/i', $working, $match) !== 1) {
            return [$working, null];
        }

        $volume = (int) $match[1];
        $working = (string) preg_replace('/\bv(?:ol(?:ume)?)?\.?\s*\d{1,4}\b/i', ' ', $working, 1);

        return [$working, $volume > 0 ? $volume : null];
    }

    /**
     * A hash is unambiguous. Without one, only a number that ends the name
     * counts, and only if it is short enough to be an issue rather than a date
     * or a resolution.
     *
     * @return array{0: string, 1: string|null}
     */
    private function extractIssueNumber(string $working): array
    {
        $digits = self::MAX_ISSUE_DIGITS;

        if (preg_match('/#\s*(\d{1,'.$digits.'}(?:\.\d{1,2})?)\b/', $working, $match) === 1) {
            return [(string) preg_replace('/#\s*\d{1,'.$digits.'}(?:\.\d{1,2})?\b/', ' ', $working, 1), $this->normaliseIssue($match[1])];
        }

        if (preg_match('/(?:^|[\s-])(\d{1,'.$digits.'}(?:\.\d{1,2})?)\s*$/', $working, $match) === 1) {
            return [(string) preg_replace('/(?:^|[\s-])\d{1,'.$digits.'}(?:\.\d{1,2})?\s*$/', ' ', $working, 1), $this->normaliseIssue($match[1])];
        }

        return [$working, null];
    }

    /** "007" and "7" are the same issue; "7.5" keeps its fraction. */
    private function normaliseIssue(string $raw): string
    {
        if (!str_contains($raw, '.')) {
            return (string) (int) $raw;
        }

        [$whole, $fraction] = explode('.', $raw, 2);

        return (int) $whole.'.'.$fraction;
    }

    private function series(string $working): ?string
    {
        $series = trim((string) preg_replace('/\s+/', ' ', $working));
        $series = trim($series, " -–—:;,");

        if ($series === '' || in_array(strtolower($series), self::NOISE, true)) {
            return null;
        }

        // A series that is only digits or punctuation is a parse that went wrong.
        return preg_match('/\p{L}/u', $series) === 1 ? $series : null;
    }
}
