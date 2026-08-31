<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Entity\Comic;
use App\Metadata\FilenameGuess;

/**
 * What we know about a comic when asking a provider what it is.
 *
 * Built from the values in front of the user rather than only from the ones
 * already saved: a filename suggestion accepted into the edit form is the whole
 * reason the search is worth running, and making the user save and reopen to
 * use it is the flow break this replaces.
 */
final class ProviderQuery
{
    private const MAX_TERM_LENGTH = 200;

    public function __construct(
        public readonly string $series,
        public readonly ?string $issueNumber = null,
        public readonly ?int $year = null,
        public readonly ?int $volume = null,
    ) {
    }

    /**
     * The staged form values, with the comic and then its filename filling any
     * gap. The staged values are hints for a search — never authority to edit
     * anything, which stays tied to the comic id at the controller.
     *
     * @param array<string, mixed> $staged
     */
    public static function staged(Comic $comic, array $staged, ?FilenameGuess $guess = null): ?self
    {
        $text = static function (string $field) use ($staged): ?string {
            $value = $staged[$field] ?? null;

            return (is_string($value) || is_int($value)) && trim((string) $value) !== '' ? trim((string) $value) : null;
        };

        // Integers only. is_numeric() would accept "1.9" and "2e3" and cast them
        // to 1 and 2000, quietly searching for a different record than the one
        // the form said.
        $number = static function (string $field) use ($staged): ?int {
            $value = $staged[$field] ?? null;

            if (is_int($value)) {
                return $value;
            }

            return is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) trim($value) : null;
        };

        return self::build(
            $text('series') ?? $comic->getSeries() ?? $guess->series ?? $comic->getTitle(),
            $text('issueNumber') ?? $comic->getIssueNumber() ?? $guess?->issueNumber,
            $number('year') ?? ($comic->getPublishedAt() !== null ? (int) $comic->getPublishedAt()->format('Y') : null) ?? $guess?->year,
            $number('volume') ?? $comic->getVolume() ?? $guess?->volume,
        );
    }

    private static function build(?string $series, ?string $issueNumber, ?int $year, ?int $volume): ?self
    {
        $series = trim((string) preg_replace('/\s+/u', ' ', (string) $series));
        if ($series === '') {
            return null;
        }

        $issueNumber = $issueNumber === null ? null : trim($issueNumber);

        return new self(
            mb_substr($series, 0, self::MAX_TERM_LENGTH),
            $issueNumber === '' ? null : ($issueNumber === null ? null : mb_substr($issueNumber, 0, 20)),
            $year !== null && $year >= 1800 && $year <= 2200 ? $year : null,
            $volume !== null && $volume > 0 && $volume <= 9999 ? $volume : null,
        );
    }

    /**
     * Stable across equivalent queries, so a cache entry is reused.
     *
     * Keyed by the query alone and not by the account behind it: the answer to
     * "what issues are called this" is the same whoever asks, and sharing it is
     * how one user's lookup saves another user's allowance.
     */
    public function cacheKey(string $provider): string
    {
        return $provider.'.'.hash(
            'xxh128',
            mb_strtolower($this->series).'|'.$this->issueNumber.'|'.$this->year.'|'.$this->volume
        );
    }
}
