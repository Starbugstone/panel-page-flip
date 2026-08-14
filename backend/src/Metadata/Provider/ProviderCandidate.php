<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

/**
 * One record a provider thinks might be this comic. A candidate, never a fact:
 * choosing between these is the user's job.
 */
final class ProviderCandidate implements \JsonSerializable
{
    /** @param array<string, list<string>> $creators */
    public function __construct(
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $series,
        public readonly ?string $issueNumber = null,
        public readonly ?string $title = null,
        public readonly ?int $volume = null,
        public readonly ?string $publisher = null,
        public readonly ?string $summary = null,
        public readonly ?\DateTimeImmutable $publishedAt = null,
        public readonly array $creators = [],
        public readonly ?string $coverUrl = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->provider,
            'externalId' => $this->externalId,
            'series' => $this->series,
            'issueNumber' => $this->issueNumber,
            'title' => $this->title,
            'volume' => $this->volume,
            'publisher' => $this->publisher,
            'summary' => $this->summary,
            'publishedAt' => $this->publishedAt?->format('Y-m-d'),
            'creators' => $this->creators,
            'coverUrl' => $this->coverUrl,
        ];
    }
}
