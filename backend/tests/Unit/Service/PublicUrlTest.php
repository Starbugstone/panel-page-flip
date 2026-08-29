<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ContentReport;
use App\Service\PublicUrl;
use PHPUnit\Framework\TestCase;

final class PublicUrlTest extends TestCase
{
    /** @dataProvider sameOriginProvider */
    public function testItRecognizesOnlyTheConfiguredPublicOrigin(string $url): void
    {
        self::assertTrue((new PublicUrl('https://panel.example'))->hasSameOrigin($url));
    }

    public static function sameOriginProvider(): iterable
    {
        yield 'ordinary URL' => ['https://panel.example/read/17'];
        yield 'host is case insensitive' => ['https://PANEL.EXAMPLE/read/17'];
        yield 'explicit default port' => ['https://panel.example:443/read/17'];
        yield 'query and fragment do not change the origin' => ['https://panel.example/read/17?source=email#page-2'];
    }

    /** @dataProvider differentOriginProvider */
    public function testItRejectsURLsOutsideTheConfiguredPublicOrigin(string $url): void
    {
        self::assertFalse((new PublicUrl('https://panel.example'))->hasSameOrigin($url));
    }

    public static function differentOriginProvider(): iterable
    {
        yield 'foreign host' => ['https://foreign.example/read/17'];
        yield 'subdomain' => ['https://preview.panel.example/read/17'];
        yield 'different scheme' => ['http://panel.example/read/17'];
        yield 'different port' => ['https://panel.example:444/read/17'];
        yield 'credentials' => ['https://user:pass@panel.example/read/17'];
        yield 'relative URL' => ['/read/17'];
    }

    public function testMatchPathReturnsCaptureGroupsForOurOwnUrls(): void
    {
        $url = new PublicUrl('https://panel.example');

        self::assertSame('17', $url->matchPath('https://panel.example/read/17', ContentReport::PATH_PANEL_URL)[1] ?? null);
        self::assertSame(
            'abc123',
            $url->matchPath('https://panel.example/share/invitation/abc123', ContentReport::PATH_INVITATION_URL)[1] ?? null,
        );
    }

    /** @dataProvider unmatchedPathProvider */
    public function testMatchPathReturnsNullForForeignOriginsAndOtherRoutes(string $candidate): void
    {
        self::assertNull((new PublicUrl('https://panel.example'))->matchPath($candidate, ContentReport::PATH_PANEL_URL));
    }

    public static function unmatchedPathProvider(): iterable
    {
        yield 'another origin serving the same path' => ['https://foreign.example/read/17'];
        yield 'a different route on our origin' => ['https://panel.example/library/17'];
        yield 'a trailing segment the route does not have' => ['https://panel.example/read/17/pages'];
        yield 'a non-numeric comic id' => ['https://panel.example/read/seventeen'];
        yield 'not a URL at all' => ['C-ABC123'];
    }

    public function testItHonoursANonDefaultConfiguredPort(): void
    {
        $url = new PublicUrl('http://localhost:8080');

        self::assertTrue($url->hasSameOrigin('http://localhost:8080/read/17'));
        self::assertFalse($url->hasSameOrigin('http://localhost/read/17'));
    }
}
