<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AdvertisingConfiguration;
use App\Service\ContentSecurityPolicy;
use App\Service\FrontendRouteRegistry;
use App\Service\GoogleAnalyticsConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ContentSecurityPolicyTest extends TestCase
{
    private string $manifest;

    private FrontendRouteRegistry $routes;

    protected function setUp(): void
    {
        $this->manifest = dirname(__DIR__, 3).'/config/csp.json';
        $this->routes = new FrontendRouteRegistry(dirname(__DIR__, 3).'/config/frontend-routes.json');
    }

    public function testAdvertisingUsesGooglesDocumentedStrictNoncePolicy(): void
    {
        $policy = $this->policy(true, 'ca-pub-1234567890123456');

        $header = $policy->header('per-response-value');

        self::assertStringContainsString("script-src 'nonce-per-response-value' 'unsafe-inline' 'unsafe-eval' 'strict-dynamic' https: http:", $header);
        self::assertStringNotContainsString('https://pagead2.googlesyndication.com https://partner.googleadservices.com', $header);
    }

    public function testAdvertisingNonceIsAddedToEveryInitialScript(): void
    {
        $policy = $this->policy(true, 'ca-pub-1234567890123456');

        $html = '<script type="module" src="/assets/app.js"></script><script nonce="existing">x</script>';

        self::assertSame(
            '<script nonce="fresh" type="module" src="/assets/app.js"></script><script nonce="existing">x</script>',
            $policy->nonceScripts($html, 'fresh')
        );
    }

    public function testNoGoogleIntegrationKeepsTheTighterSelfOnlyScriptPolicy(): void
    {
        $policy = $this->policy(false, '');

        self::assertStringContainsString("script-src 'self'", $policy->header());
        self::assertStringNotContainsString('unsafe-eval', $policy->header());
        self::assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $policy->header());
        self::assertStringContainsString('frame-src \'self\' https://googleads.g.doubleclick.net https://tpc.googlesyndication.com https://www.google.com https://fundingchoicesmessages.google.com https://challenges.cloudflare.com', $policy->header());
    }

    public function testAnalyticsAloneReceivesTheNoncePolicyNeededForTheTag(): void
    {
        $policy = $this->policy(false, '', true, 'G-PSW1MY7HB4');

        $header = $policy->header('analytics-nonce');

        self::assertTrue($policy->googleScriptsEnabled());
        self::assertStringContainsString("script-src 'nonce-analytics-nonce'", $header);
        self::assertStringContainsString('https://*.google-analytics.com', $header);
        self::assertStringContainsString('https://www.googletagmanager.com', $header);
    }

    /**
     * An unusable AdSense client cannot buy the relaxed policy for a deployment
     * with nothing else Google-shaped switched on.
     */
    public function testAnInvalidAdvertisingConfigurationCannotWeakenTheHeader(): void
    {
        $policy = $this->policy(true, 'ca-pub-nope');

        self::assertFalse($policy->googleScriptsEnabled());
        self::assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $policy->header());
    }

    /**
     * @dataProvider googleFreeRouteProvider
     */
    public function testLegalRoutesAreServedWithoutGoogleEvenWhenBothIntegrationsAreOn(string $path): void
    {
        $policy = $this->policy(true, 'ca-pub-1234567890123456', true, 'G-PSW1MY7HB4');

        self::assertTrue($policy->googleScriptsEnabled());
        self::assertFalse($policy->googleScriptsEnabledFor($path));

        // No nonce is passed, because none is minted for these routes. The
        // Google-capable branch would throw if it were still selected here.
        $header = $policy->header(null, $path);

        self::assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $header);
        self::assertStringNotContainsString('strict-dynamic', $header);
        self::assertStringNotContainsString('nonce-', $header);
        foreach ($this->googleOrigins() as $origin) {
            self::assertStringNotContainsString($origin, $header, $origin.' must not be reachable from '.$path);
        }
        self::assertStringNotContainsString('google', $header);
        // What the page genuinely needs survives the filtering.
        self::assertStringContainsString("default-src 'self'", $header);
        self::assertStringContainsString("img-src 'self' data: blob:", $header);
        self::assertStringContainsString("frame-ancestors 'none'", $header);
        self::assertStringContainsString('https://challenges.cloudflare.com', $header);
    }

    /** @return iterable<string, array{string}> */
    public static function googleFreeRouteProvider(): iterable
    {
        yield '/privacy' => ['/privacy'];
        yield '/cookies' => ['/cookies'];
        yield '/terms' => ['/terms'];
    }

    public function testOrdinaryRoutesStillReceiveTheGoogleCapablePolicy(): void
    {
        $policy = $this->policy(true, 'ca-pub-1234567890123456');

        self::assertTrue($policy->googleScriptsEnabledFor('/'));
        self::assertStringContainsString('strict-dynamic', $policy->header('a-nonce', '/'));
        self::assertTrue($policy->googleScriptsEnabledFor('/report-content'));
    }

    /**
     * Every Google origin the manifest lists anywhere must also be in
     * `googleOrigins`, or the legal routes would keep whichever one was added
     * and forgotten.
     */
    public function testTheManifestNamesEveryGoogleOriginItUses(): void
    {
        $manifest = json_decode((string) file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);
        $declared = $manifest['googleOrigins'];
        $used = [];
        foreach ($manifest['directives'] as $sources) {
            foreach ($sources as $source) {
                if (preg_match('/google|doubleclick|adtrafficquality/i', $source) === 1) {
                    $used[] = $source;
                }
            }
        }

        self::assertNotEmpty($used);
        self::assertSame([], array_values(array_diff(array_unique($used), $declared)));
    }

    public function testMalformedManifestIsRejectedAtTheBoundary(): void
    {
        $manifest = tempnam(sys_get_temp_dir(), 'csp-');
        self::assertIsString($manifest);
        file_put_contents($manifest, json_encode([
            'directives' => ['default-src' => "'self'"],
            'googleOrigins' => [],
            'scriptSrcWithoutGoogle' => ["'self'"],
            'scriptSrcWithGoogle' => ["'nonce-{nonce}'"],
        ], JSON_THROW_ON_ERROR));
        $policy = new ContentSecurityPolicy(
            new AdvertisingConfiguration(false, '', new NullLogger()),
            $manifest
        );

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('CSP manifest');
            $policy->header();
        } finally {
            unlink($manifest);
        }
    }

    /** @return list<string> */
    private function googleOrigins(): array
    {
        $manifest = json_decode((string) file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);

        return $manifest['googleOrigins'];
    }

    private function policy(
        bool $adsEnabled,
        string $client,
        bool $analyticsEnabled = false,
        string $measurementId = '',
    ): ContentSecurityPolicy {
        return new ContentSecurityPolicy(
            new AdvertisingConfiguration($adsEnabled, $client, new NullLogger()),
            $this->manifest,
            new GoogleAnalyticsConfiguration($analyticsEnabled, $measurementId, new NullLogger()),
            $this->routes,
        );
    }
}
