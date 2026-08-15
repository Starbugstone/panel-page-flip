<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Provider;

use App\Metadata\Provider\ProviderCandidate;
use PHPUnit\Framework\TestCase;

final class ProviderCandidateTest extends TestCase
{
    public function testJsonSerializeOmitsTimeFromThePublishedDate(): void
    {
        $candidate = new ProviderCandidate(
            provider: 'comicvine',
            externalId: '4000-1',
            series: 'Batman',
            issueNumber: '1',
            title: 'The Beginning',
            volume: 1,
            publisher: 'DC',
            summary: 'A start.',
            publishedAt: new \DateTimeImmutable('1939-05-01T12:00:00+00:00'),
            creators: ['writer' => ['Kane']],
            coverUrl: 'https://example.test/cover.jpg',
        );

        $payload = $candidate->jsonSerialize();

        self::assertSame('1939-05-01', $payload['publishedAt']);

        // Compared by intersection rather than against the whole payload: the
        // subject here is what the constructor round-trips, and a candidate
        // gains fields as providers learn to report more of them.
        $expected = [
            'provider' => 'comicvine',
            'externalId' => '4000-1',
            'series' => 'Batman',
            'issueNumber' => '1',
            'title' => 'The Beginning',
            'volume' => 1,
            'publisher' => 'DC',
            'summary' => 'A start.',
            'publishedAt' => '1939-05-01',
            'creators' => ['writer' => ['Kane']],
            'coverUrl' => 'https://example.test/cover.jpg',
        ];
        self::assertSame($expected, array_intersect_key($payload, $expected));
    }

    public function testJsonSerializeLeavesAMissingPublishedDateNull(): void
    {
        $candidate = new ProviderCandidate(provider: 'comicvine', externalId: '4000-1', series: 'Batman');

        self::assertNull($candidate->jsonSerialize()['publishedAt']);
    }
}
