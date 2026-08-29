<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PublicUrl
{
    private string $baseUrl;

    public function __construct(#[Autowire('%app_url%')] string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function base(): string
    {
        return $this->baseUrl;
    }

    public function to(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * The capture groups from $pathPattern when $url is one of this
     * installation's own URLs, or null when it is not ours or does not match.
     *
     * @return list<string>|null
     */
    public function matchPath(string $url, string $pathPattern): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !$this->hasSameOrigin($url)) {
            return null;
        }

        return preg_match($pathPattern, (string) ($parts['path'] ?? ''), $matches) === 1 ? $matches : null;
    }

    public function hasSameOrigin(string $url): bool
    {
        $expected = parse_url($this->baseUrl);
        $candidate = parse_url($url);
        if (!is_array($expected) || !is_array($candidate)
            || isset($candidate['user']) || isset($candidate['pass'])) {
            return false;
        }

        $expectedScheme = mb_strtolower((string) ($expected['scheme'] ?? ''));
        $candidateScheme = mb_strtolower((string) ($candidate['scheme'] ?? ''));
        $expectedHost = mb_strtolower((string) ($expected['host'] ?? ''));
        $candidateHost = mb_strtolower((string) ($candidate['host'] ?? ''));
        if (!in_array($candidateScheme, ['http', 'https'], true)
            || $expectedScheme !== $candidateScheme
            || $expectedHost === ''
            || $expectedHost !== $candidateHost) {
            return false;
        }

        return $this->port($expected, $expectedScheme) === $this->port($candidate, $candidateScheme);
    }

    /** @param array<string, int|string> $parts */
    private function port(array $parts, string $scheme): ?int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
