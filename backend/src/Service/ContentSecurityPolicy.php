<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the HTML CSP and keeps its nonce coupled to the script attributes.
 *
 * Two profiles, chosen per response:
 *
 *   - the Google-capable one, which is Google's own documented strict-CSP shape
 *     (`'strict-dynamic'` plus a per-response nonce, with the legacy tokens
 *     browsers that understand it ignore) because AdSense, Funding Choices and
 *     gtag.js load descendants the server cannot enumerate;
 *   - the tight one, `'self'` plus Turnstile, which is what this application's
 *     own bundle actually needs.
 *
 * The relaxed profile is not served merely because Google is switched on
 * somewhere. On the legal-policy routes it is withheld regardless — see
 * {@see FrontendRouteRegistry::isGoogleFree()}. Those pages are the ones Google
 * requires to be free of consent-requiring tags, and a route the application
 * gates but the header still permits is one provider regression away from
 * breaking that requirement. Withholding it also strips the Google origins from
 * `img-src`, `frame-src` and `connect-src`, so a tag that somehow ran there
 * could not reach Google either.
 */
final class ContentSecurityPolicy
{
    /** @var array{directives: array<string, list<string>>, googleOrigins: list<string>, scriptSrcWithoutGoogle: list<string>, scriptSrcWithGoogle: list<string>}|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly AdvertisingConfiguration $advertising,
        #[Autowire('%kernel.project_dir%/config/csp.json')]
        private readonly string $manifestPath,
        private readonly ?GoogleAnalyticsConfiguration $analytics = null,
        private readonly ?FrontendRouteRegistry $routes = null,
    ) {
    }

    /** Whether any optional Google integration is effective for this installation. */
    public function googleScriptsEnabled(): bool
    {
        return $this->advertising->isEnabled() || ($this->analytics?->isEnabled() ?? false);
    }

    /** The same question for one route, which the Google-free set can answer "no" to. */
    public function googleScriptsEnabledFor(?string $path): bool
    {
        return $this->googleScriptsEnabled() && !$this->isGoogleFree($path);
    }

    /** A base64url value that is safe in both an HTML attribute and CSP. */
    public function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /**
     * @param string|null $path the route being served, or null for the
     *                          installation-wide policy
     */
    public function header(?string $nonce = null, ?string $path = null): string
    {
        $manifest = $this->manifest();
        $googleFree = $this->isGoogleFree($path);
        $withGoogle = $this->googleScriptsEnabled() && !$googleFree;
        $directives = $googleFree
            ? $this->withoutGoogleOrigins($manifest['directives'], $manifest['googleOrigins'])
            : $manifest['directives'];
        $scriptSources = $withGoogle
            ? $manifest['scriptSrcWithGoogle']
            : $manifest['scriptSrcWithoutGoogle'];

        if ($withGoogle) {
            if ($nonce === null || $nonce === '') {
                throw new \InvalidArgumentException('A Google-script-enabled CSP requires a nonce.');
            }
            $scriptSources = array_map(
                static fn (string $source): string => str_replace('{nonce}', $nonce, $source),
                $scriptSources
            );
        }

        $directives['script-src'] = $scriptSources;

        return implode('; ', array_map(
            static fn (string $name, array $sources): string => $name.' '.implode(' ', $sources),
            array_keys($directives),
            array_values($directives)
        ));
    }

    /**
     * Apply the response nonce to every initial script, including Vite's entry.
     * Descendants created by that trusted module inherit trust through
     * `strict-dynamic`, which is how the later AdSense/CMP scripts are loaded.
     */
    public function nonceScripts(string $html, string $nonce): string
    {
        return preg_replace(
            '/<script\b(?![^>]*\bnonce=)/i',
            '<script nonce="'.$nonce.'"',
            $html
        ) ?? $html;
    }

    private function isGoogleFree(?string $path): bool
    {
        return $path !== null && ($this->routes?->isGoogleFree($path) ?? false);
    }

    /**
     * @param array<string, list<string>> $directives
     * @param list<string>                $googleOrigins
     *
     * @return array<string, list<string>>
     */
    private function withoutGoogleOrigins(array $directives, array $googleOrigins): array
    {
        // A directive emptied of everything is left out rather than emitted
        // bare: `img-src` with no source list is a syntax error some browsers
        // treat as the whole header being malformed, and `default-src 'self'`
        // already covers what would remain.
        $filtered = [];
        foreach ($directives as $name => $sources) {
            $kept = array_values(array_filter(
                $sources,
                static fn (string $source): bool => !in_array($source, $googleOrigins, true)
            ));
            if ($kept !== []) {
                $filtered[$name] = $kept;
            }
        }

        return $filtered;
    }

    /** @return array{directives: array<string, list<string>>, googleOrigins: list<string>, scriptSrcWithoutGoogle: list<string>, scriptSrcWithGoogle: list<string>} */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $contents = file_get_contents($this->manifestPath);
        if ($contents === false) {
            throw new \RuntimeException('Could not read the CSP manifest.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || !is_array($decoded['directives'] ?? null)
            || !is_array($decoded['googleOrigins'] ?? null)
            || !is_array($decoded['scriptSrcWithoutGoogle'] ?? null)
            || !is_array($decoded['scriptSrcWithGoogle'] ?? null)) {
            throw new \RuntimeException('The CSP manifest has an invalid shape.');
        }

        $directives = [];
        foreach ($decoded['directives'] as $name => $sources) {
            if (!is_string($name) || ($sourceList = $this->sourceList($sources)) === null) {
                throw new \RuntimeException('The CSP manifest has an invalid directive.');
            }
            $directives[$name] = $sourceList;
        }
        $googleOrigins = $this->sourceList($decoded['googleOrigins']);
        $withoutGoogle = $this->sourceList($decoded['scriptSrcWithoutGoogle']);
        $withGoogle = $this->sourceList($decoded['scriptSrcWithGoogle']);
        if ($googleOrigins === null || $withoutGoogle === null || $withGoogle === null) {
            throw new \RuntimeException('The CSP manifest has an invalid script source list.');
        }

        return $this->manifest = [
            'directives' => $directives,
            'googleOrigins' => $googleOrigins,
            'scriptSrcWithoutGoogle' => $withoutGoogle,
            'scriptSrcWithGoogle' => $withGoogle,
        ];
    }

    /** @return list<string>|null */
    private function sourceList(mixed $sources): ?array
    {
        if (!is_array($sources) || !array_is_list($sources)) {
            return null;
        }
        foreach ($sources as $source) {
            if (!is_string($source) || $source === '') {
                return null;
            }
        }

        return $sources;
    }
}
