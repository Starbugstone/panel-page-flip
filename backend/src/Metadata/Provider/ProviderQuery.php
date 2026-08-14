<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Entity\Comic;

/** What we know about a comic when asking a provider what it is. */
final class ProviderQuery
{
    public function __construct(
        public readonly string $series,
        public readonly ?string $issueNumber = null,
        public readonly ?int $year = null,
    ) {
    }

    public static function fromComic(Comic $comic): ?self
    {
        $series = trim((string) ($comic->getSeries() ?? $comic->getTitle()));
        if ($series === '') {
            return null;
        }

        return new self(
            $series,
            $comic->getIssueNumber(),
            $comic->getPublishedAt() !== null ? (int) $comic->getPublishedAt()->format('Y') : null,
        );
    }

    /** Stable across equivalent queries, so a cache entry is reused. */
    public function cacheKey(string $provider): string
    {
        return $provider.'.'.hash('xxh128', strtolower($this->series).'|'.$this->issueNumber.'|'.$this->year);
    }
}
