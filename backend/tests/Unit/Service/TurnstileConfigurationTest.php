<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TurnstileConfiguration;
use PHPUnit\Framework\TestCase;

final class TurnstileConfigurationTest extends TestCase
{
    public function testDisabledConfigurationPublishesNoKeyAndNeedsNoCredentials(): void
    {
        $configuration = new TurnstileConfiguration(false, '', '', 'https://panel.example');

        self::assertFalse($configuration->isEnabled());
        self::assertSame(['enabled' => false, 'siteKey' => null], $configuration->publicConfiguration());
    }

    public function testEnabledConfigurationDerivesTheExpectedHostnameFromTheApplicationUrl(): void
    {
        $configuration = new TurnstileConfiguration(
            true,
            '1x00000000000000000000AA',
            '1x0000000000000000000000000000000AA',
            'https://Panel.Example:8443'
        );

        self::assertTrue($configuration->isEnabled());
        self::assertSame('panel.example', $configuration->expectedHostname());
        self::assertSame('1x0000000000000000000000000000000AA', $configuration->secretKey());
        self::assertSame(
            ['enabled' => true, 'siteKey' => '1x00000000000000000000AA'],
            $configuration->publicConfiguration()
        );
    }

    /** @dataProvider incompleteConfigurationProvider */
    public function testEnabledConfigurationRejectsMissingKeysOrAnInvalidApplicationUrl(
        string $siteKey,
        string $secretKey,
        string $appUrl
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new TurnstileConfiguration(true, $siteKey, $secretKey, $appUrl);
    }

    public static function incompleteConfigurationProvider(): iterable
    {
        yield 'site key' => ['', 'secret', 'https://panel.example'];
        yield 'secret key' => ['site', '', 'https://panel.example'];
        yield 'canonical hostname' => ['site', 'secret', 'not-a-url'];
    }
}
