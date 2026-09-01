<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Builds the HTML CSP and keeps its nonce coupled to the script attributes. */
final class ContentSecurityPolicy
{
    /** @var array{directives: array<string, list<string>>, scriptSrcWithoutAdvertising: list<string>, scriptSrcWithAdvertising: list<string>}|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly AdvertisingConfiguration $advertising,
        #[Autowire('%kernel.project_dir%/config/csp.json')]
        private readonly string $manifestPath,
        private readonly ?GoogleAnalyticsConfiguration $analytics = null,
    ) {
    }

    public function googleScriptsEnabled(): bool
    {
        return $this->advertising->isEnabled() || ($this->analytics?->isEnabled() ?? false);
    }

    /** A base64url value that is safe in both an HTML attribute and CSP. */
    public function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public function header(?string $nonce = null): string
    {
        $manifest = $this->manifest();
        $directives = $manifest['directives'];
        $scriptSources = $this->googleScriptsEnabled()
            ? $manifest['scriptSrcWithAdvertising']
            : $manifest['scriptSrcWithoutAdvertising'];

        if ($this->googleScriptsEnabled()) {
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

    /** @return array{directives: array<string, list<string>>, scriptSrcWithoutAdvertising: list<string>, scriptSrcWithAdvertising: list<string>} */
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
            || !is_array($decoded['scriptSrcWithoutAdvertising'] ?? null)
            || !is_array($decoded['scriptSrcWithAdvertising'] ?? null)) {
            throw new \RuntimeException('The CSP manifest has an invalid shape.');
        }

        $directives = [];
        foreach ($decoded['directives'] as $name => $sources) {
            if (!is_string($name) || ($sourceList = $this->sourceList($sources)) === null) {
                throw new \RuntimeException('The CSP manifest has an invalid directive.');
            }
            $directives[$name] = $sourceList;
        }
        $withoutAdvertising = $this->sourceList($decoded['scriptSrcWithoutAdvertising']);
        $withAdvertising = $this->sourceList($decoded['scriptSrcWithAdvertising']);
        if ($withoutAdvertising === null || $withAdvertising === null) {
            throw new \RuntimeException('The CSP manifest has an invalid script source list.');
        }

        return $this->manifest = [
            'directives' => $directives,
            'scriptSrcWithoutAdvertising' => $withoutAdvertising,
            'scriptSrcWithAdvertising' => $withAdvertising,
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
