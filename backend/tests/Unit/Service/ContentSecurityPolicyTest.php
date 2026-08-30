<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AdvertisingConfiguration;
use App\Service\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ContentSecurityPolicyTest extends TestCase
{
    private string $manifest;

    protected function setUp(): void
    {
        $this->manifest = dirname(__DIR__, 3).'/config/csp.json';
    }

    public function testAdvertisingUsesGooglesDocumentedStrictNoncePolicy(): void
    {
        $policy = new ContentSecurityPolicy(
            new AdvertisingConfiguration(true, 'ca-pub-1234567890123456', new NullLogger()),
            $this->manifest
        );

        $header = $policy->header('per-response-value');

        self::assertStringContainsString("script-src 'nonce-per-response-value' 'unsafe-inline' 'unsafe-eval' 'strict-dynamic' https: http:", $header);
        self::assertStringNotContainsString('https://pagead2.googlesyndication.com https://partner.googleadservices.com', $header);
    }

    public function testAdvertisingNonceIsAddedToEveryInitialScript(): void
    {
        $policy = new ContentSecurityPolicy(
            new AdvertisingConfiguration(true, 'ca-pub-1234567890123456', new NullLogger()),
            $this->manifest
        );

        $html = '<script type="module" src="/assets/app.js"></script><script nonce="existing">x</script>';

        self::assertSame(
            '<script nonce="fresh" type="module" src="/assets/app.js"></script><script nonce="existing">x</script>',
            $policy->nonceScripts($html, 'fresh')
        );
    }

    public function testAdvertisingOffKeepsTheTighterSelfOnlyScriptPolicy(): void
    {
        $policy = new ContentSecurityPolicy(
            new AdvertisingConfiguration(false, '', new NullLogger()),
            $this->manifest
        );

        self::assertStringContainsString("script-src 'self'", $policy->header());
        self::assertStringNotContainsString('unsafe-eval', $policy->header());
    }
}
