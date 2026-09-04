<?php

namespace App\Tests\Functional\Controller;

use App\Service\AdvertisingConfiguration;
use App\Service\ContentSecurityPolicy;
use App\Service\FrontendRouteRegistry;
use App\Service\GoogleAnalyticsConfiguration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FrontendControllerTest extends WebTestCase
{
    /**
     * @dataProvider knownRouteProvider
     */
    public function testKnownFrontendRoutesReturnTheSpa(string $path, bool $shouldBeIndexed): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        // The Google origins stay in the non-script directives even with both
        // integrations off, because they are what the policy would need the
        // moment an operator switches one on — except on the legal routes,
        // where the header must never name Google whatever is configured.
        self::assertResponseHeaderSame(
            'Content-Security-Policy',
            self::googleFreeRoute($path)
                ? "default-src 'self'; object-src 'none'; base-uri 'none'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; frame-src 'self' https://challenges.cloudflare.com; connect-src 'self'; frame-ancestors 'none'; script-src 'self' https://challenges.cloudflare.com"
                : "default-src 'self'; object-src 'none'; base-uri 'none'; img-src 'self' data: blob: https://pagead2.googlesyndication.com https://googleads.g.doubleclick.net https://tpc.googlesyndication.com https://www.google.com https://www.googletagmanager.com https://*.google-analytics.com; style-src 'self' 'unsafe-inline'; frame-src 'self' https://googleads.g.doubleclick.net https://tpc.googlesyndication.com https://www.google.com https://fundingchoicesmessages.google.com https://challenges.cloudflare.com; connect-src 'self' https://pagead2.googlesyndication.com https://googleads.g.doubleclick.net https://fundingchoicesmessages.google.com https://ep1.adtrafficquality.google https://ep2.adtrafficquality.google https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com; frame-ancestors 'none'; script-src 'self' https://challenges.cloudflare.com"
        );

        if ($shouldBeIndexed) {
            self::assertResponseHeaderNotSame('X-Robots-Tag', 'noindex, follow');
            $canonicalPath = $path === '/' ? '/' : $path;
            self::assertStringContainsString(
                sprintf('<link rel="canonical" href="http://localhost:8080%s" />', $canonicalPath),
                (string) $client->getResponse()->getContent()
            );
        } else {
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        }
    }

    public function testUnknownFrontendRouteReturnsAReal404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/this-page-does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        self::assertStringContainsString('<div id="root">', (string) $client->getResponse()->getContent());
    }

    public function testAdvertisingResponseCouplesItsStrictCspToEveryInitialScript(): void
    {
        $client = static::createClient();
        $advertising = new AdvertisingConfiguration(true, 'ca-pub-1234567890123456', new NullLogger());
        static::getContainer()->set(AdvertisingConfiguration::class, $advertising);
        static::getContainer()->set(
            ContentSecurityPolicy::class,
            self::policy($advertising)
        );

        $client->request('GET', '/');

        $header = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertMatchesRegularExpression("/script-src 'nonce-([A-Za-z0-9_-]+)' 'unsafe-inline' 'unsafe-eval' 'strict-dynamic' https: http:/", $header);
        preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $header, $match);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<script nonce="'.$match[1].'"', $content);
        self::assertSame(substr_count(strtolower($content), '<script'), substr_count($content, '<script nonce="'.$match[1].'"'));
    }

    /**
     * @dataProvider googleFreeRouteProvider
     */
    public function testLegalRoutesAreServedGoogleFreeEvenWithBothIntegrationsOn(string $path): void
    {
        $client = static::createClient();
        $advertising = new AdvertisingConfiguration(true, 'ca-pub-1234567890123456', new NullLogger());
        $analytics = new GoogleAnalyticsConfiguration(true, 'G-PSW1MY7HB4', new NullLogger());
        static::getContainer()->set(AdvertisingConfiguration::class, $advertising);
        static::getContainer()->set(GoogleAnalyticsConfiguration::class, $analytics);
        static::getContainer()->set(ContentSecurityPolicy::class, self::policy($advertising, $analytics));

        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $header = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $header);
        self::assertStringNotContainsString('strict-dynamic', $header);
        self::assertStringNotContainsString('nonce-', $header);
        // Not only script-src: an ad or measurement tag that somehow ran here
        // still could not reach Google over any directive.
        self::assertStringNotContainsString('google', $header);
        self::assertStringNotContainsString('doubleclick', $header);
        self::assertStringNotContainsString('adtrafficquality', $header);
        // The shell is not nonced either, because a nonce is what would let a
        // trusted module pull descendants in under strict-dynamic.
        self::assertStringNotContainsString('<script nonce=', (string) $client->getResponse()->getContent());
    }

    private static function googleFreeRoute(string $path): bool
    {
        return in_array($path, ['/privacy', '/cookies', '/terms'], true);
    }

    /** @return iterable<string, array{string}> */
    public static function googleFreeRouteProvider(): iterable
    {
        yield '/privacy' => ['/privacy'];
        yield '/cookies' => ['/cookies'];
        yield '/terms' => ['/terms'];
    }

    public function testAnOrdinaryRouteStillGetsTheGoogleCapablePolicyOnTheSameInstallation(): void
    {
        $client = static::createClient();
        $advertising = new AdvertisingConfiguration(true, 'ca-pub-1234567890123456', new NullLogger());
        $analytics = new GoogleAnalyticsConfiguration(true, 'G-PSW1MY7HB4', new NullLogger());
        static::getContainer()->set(AdvertisingConfiguration::class, $advertising);
        static::getContainer()->set(GoogleAnalyticsConfiguration::class, $analytics);
        static::getContainer()->set(ContentSecurityPolicy::class, self::policy($advertising, $analytics));

        $client->request('GET', '/');

        $header = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString('strict-dynamic', $header);
        self::assertStringContainsString('https://www.googletagmanager.com', $header);
    }

    private static function policy(
        AdvertisingConfiguration $advertising,
        ?GoogleAnalyticsConfiguration $analytics = null,
    ): ContentSecurityPolicy {
        $backend = dirname(__DIR__, 3);

        return new ContentSecurityPolicy(
            $advertising,
            $backend.'/config/csp.json',
            $analytics,
            new FrontendRouteRegistry($backend.'/config/frontend-routes.json'),
        );
    }

    public function testRegexMetacharactersInThePathAreNotExpandedIntoTheCanonical(): void
    {
        $client = static::createClient();
        // A literal $0 in the path used to be expanded as a preg_replace
        // backreference, splicing the matched <link> tag into its own href.
        $client->request('GET', '/$0');

        self::assertResponseStatusCodeSame(404);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<link rel="canonical" href="http://localhost:8080/$0" />', $content);
        self::assertStringNotContainsString('href="http://localhost:8080/<link', $content);
    }

    public function knownRouteProvider(): iterable
    {
        yield 'public landing page' => ['/', true];
        yield 'privacy policy' => ['/privacy', true];
        yield 'terms of service' => ['/terms', true];
        yield 'cookie notice' => ['/cookies', true];
        yield 'illegal content report' => ['/report-content', true];
        yield 'login' => ['/login', false];
        yield 'dashboard' => ['/dashboard', false];
        yield 'settings' => ['/settings', false];
        yield 'upload' => ['/upload', false];
        yield 'bulk upload' => ['/upload/bulk', false];
        yield 'admin' => ['/admin', false];
        yield 'admin user' => ['/admin/users/123', false];
        yield 'dropbox sync' => ['/dropbox-sync', false];
        yield 'comic reader' => ['/read/123', false];
        yield 'password-reset token' => ['/reset-password/example-token', false];
        yield 'sharing page' => ['/sharing', false];
        yield 'invitation token' => ['/share/invitation/example-token', false];
    }
}
