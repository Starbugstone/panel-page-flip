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

        self::assertSame([
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
        ], $candidate->jsonSerialize());
    }
}
