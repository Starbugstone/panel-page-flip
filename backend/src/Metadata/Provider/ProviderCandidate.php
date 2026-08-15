<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

use App\Enum\MatchConfidence;
use App\Metadata\Classification;

/**
 * One record a provider thinks might be this comic. A candidate, never a fact:
 * choosing between these is the user's job.
 *
 * Confidence is not set by the provider that produced the candidate. A provider
 * only knows what its own search returned, and the ranking has to be comparable
 * across providers, so CandidateRanker attaches it afterwards.
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
        public readonly ?int $issueCount = null,
        public readonly ?string $publisher = null,
        public readonly ?string $summary = null,
        public readonly ?\DateTimeImmutable $publishedAt = null,
        public readonly array $creators = [],
        public readonly ?string $coverUrl = null,
        public readonly ?string $languageCode = null,
        public readonly ?string $ageRating = null,
        public readonly ?Classification $classification = null,
        public readonly MatchConfidence $confidence = MatchConfidence::Low,
        /** Whether this came from a detail lookup rather than a search row. */
        public readonly bool $isDetailed = false,
    ) {
    }

    public function withConfidence(MatchConfidence $confidence): self
    {
        return new self(
            $this->provider,
            $this->externalId,
            $this->series,
            $this->issueNumber,
            $this->title,
            $this->volume,
            $this->issueCount,
            $this->publisher,
            $this->summary,
            $this->publishedAt,
            $this->creators,
            $this->coverUrl,
            $this->languageCode,
            $this->ageRating,
            $this->classification,
            $confidence,
            $this->isDetailed,
        );
    }

    public function year(): ?int
    {
        return $this->publishedAt !== null ? (int) $this->publishedAt->format('Y') : null;
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
            'issueCount' => $this->issueCount,
            'publisher' => $this->publisher,
            'summary' => $this->summary,
            'publishedAt' => $this->publishedAt?->format('Y-m-d'),
            'creators' => $this->creators,
            'coverUrl' => $this->coverUrl,
            'languageCode' => $this->languageCode,
            'ageRating' => $this->ageRating,
            'classification' => $this->classification?->jsonSerialize() ?: null,
            'confidence' => $this->confidence->value,
            'detailed' => $this->isDetailed,
        ];
    }
}
