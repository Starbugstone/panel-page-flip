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
    }

    public function isKnown(string $path): bool
    {
        return $this->isIndexable($path)
            || in_array($path, $this->noindex, true)
            || $this->matchesNoindexPattern($path);
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

    /** @return array<int, array{path: string, changefreq: string, priority: string}> */
    public function indexableRoutes(): array
    {
        return $this->indexable;
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
