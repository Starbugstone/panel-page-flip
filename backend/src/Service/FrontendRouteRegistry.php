<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FrontendRouteRegistry
{
    /** @var array<int, array{path: string, changefreq: string, priority: string}> */
    private array $indexable;

    /** @var list<string> */
    private array $noindex;

    /** @var list<string> */
    private array $noindexPatterns;

    /**
     * Routes no optional Google integration may touch, whatever the operator
     * switched on.
     *
     * Google requires the privacy-policy URL its Privacy & Messaging setup
     * points at to host neither the Funding Choices consent-message tag nor any
     * other script requiring consent. That is a property of the page, so it is
     * decided here — in the manifest every layer already reads — rather than by
     * each provider, loader and CSP profile independently remembering to check.
     *
     * @var list<string>
     */
    private array $googleFree;

    public function __construct(#[Autowire('%kernel.project_dir%/config/frontend-routes.json')] string $manifestPath)
    {
        $raw = is_readable($manifestPath) ? file_get_contents($manifestPath) : false;
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Frontend route manifest not readable: %s', $manifestPath));
        }

        try {
            $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Invalid frontend route manifest: %s', $manifestPath), 0, $e);
        }

        $this->indexable = $manifest['indexable'] ?? [];
        $this->noindex = $manifest['noindex'] ?? [];
        $this->noindexPatterns = $manifest['noindexPatterns'] ?? [];
        $this->googleFree = $manifest['googleFree'] ?? [];
    }

    public function isKnown(string $path): bool
    {
        return $this->isIndexable($path)
            || in_array($path, $this->noindex, true)
            || $this->matchesNoindexPattern($path);
    }

    /** Whether this path must be served with no AdSense, Funding Choices or GA4. */
    public function isGoogleFree(string $path): bool
    {
        return in_array($this->canonicalPath($path), $this->googleFree, true);
    }

    /** Match the legal-page aliases React Router accepts without widening other routes. */
    public function canonicalPath(string $path): string
    {
        $normalized = strtolower(rtrim($path, '/'));

        return in_array($normalized, $this->googleFree, true) ? $normalized : $path;
    }

    /** @return list<string> */
    public function googleFreeRoutes(): array
    {
        return $this->googleFree;
    }

    public function isIndexable(string $path): bool
    {
        foreach ($this->indexable as $route) {
            if ($route['path'] === $path) {
                return true;
            }
        }

        return false;
    }

    private function matchesNoindexPattern(string $path): bool
    {
        foreach ($this->noindexPatterns as $pattern) {
            if (preg_match('#'.$pattern.'#', $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
