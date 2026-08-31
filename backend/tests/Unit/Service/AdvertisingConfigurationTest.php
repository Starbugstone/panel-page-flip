<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AdvertisingConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Whether this installation shows advertising.
 *
 * The property worth protecting is not "the flag is read correctly" but that
 * every unusable configuration lands on *off*. An operator who mistypes a
 * publisher id must get a log line and a working application, never a broken
 * page or a script pointed at an account that is not theirs.
 */
final class AdvertisingConfigurationTest extends TestCase
{
    private const VALID_CLIENT = 'ca-pub-1234567890123456';

    public function testAdvertisingIsOffUnlessBothSettingsAgree(): void
    {
        self::assertFalse($this->configuration(false, '')->isEnabled());
        self::assertFalse($this->configuration(false, self::VALID_CLIENT)->isEnabled());
        self::assertFalse($this->configuration(true, '')->isEnabled());
        self::assertTrue($this->configuration(true, self::VALID_CLIENT)->isEnabled());
    }

    public function testDisabledAdvertisingExposesNoPublisherId(): void
    {
        $configuration = $this->configuration(false, self::VALID_CLIENT);

        self::assertNull($configuration->client());
        self::assertSame(
            ['enabled' => false, 'client' => null],
            $configuration->publicConfiguration()
        );
    }

    public function testAValidPublisherIdCanStillBackTheConsentPlatformWhenAdsAreOff(): void
    {
        $configuration = $this->configuration(false, self::VALID_CLIENT);

        self::assertNull($configuration->client());
        self::assertSame(self::VALID_CLIENT, $configuration->consentClient());
    }

    /**
     * @dataProvider unusableClients
     */
    public function testAnUnusableClientDisablesAdvertisingAndLogsAWarning(string $client): void
    {
        $logger = new CollectingLogger();
        $configuration = $this->configuration(true, $client, logger: $logger);

        self::assertFalse($configuration->isEnabled());
        self::assertNull($configuration->client());
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('ADSENSE_CLIENT is missing or invalid', $logger->warnings[0]);
        self::assertStringContainsString('remains available', $logger->warnings[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableClients(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'placeholder from the template' => ['ca-pub-xxxxxxxxxxxxxxxx'];
        yield 'too few digits' => ['ca-pub-123456789012345'];
        yield 'too many digits' => ['ca-pub-12345678901234567'];
        yield 'missing prefix' => ['pub-1234567890123456'];
        yield 'admob application id' => ['ca-app-pub-3940256099942544'];
        yield 'a whole script tag pasted in' => ['<script src="//pagead2.googlesyndication.com"></script>'];
    }

    public function testSurroundingWhitespaceIsToleratedRatherThanFatal(): void
    {
        $configuration = $this->configuration(true, '  '.self::VALID_CLIENT."\n");

        self::assertTrue($configuration->isEnabled());
        self::assertSame(self::VALID_CLIENT, $configuration->client());
    }

    public function testAWorkableConfigurationLogsNothing(): void
    {
        $logger = new CollectingLogger();
        $this->configuration(true, self::VALID_CLIENT, logger: $logger);

        self::assertSame([], $logger->warnings);
    }

    /**
     * Two keys and no more.
     *
     * Asserted on the key list rather than only on the values, because anything
     * added here is published to every unauthenticated visitor — and a flag
     * describing the installation is exactly the kind of thing that gets added
     * without anybody deciding it should be public.
     */
    public function testTheBrowserIsToldTheOutcomeAndNothingElse(): void
    {
        $published = $this->configuration(true, self::VALID_CLIENT)->publicConfiguration();

        self::assertSame(
            ['enabled' => true, 'client' => self::VALID_CLIENT],
            $published
        );
        self::assertSame(['enabled', 'client'], array_keys($published));
    }

    private function configuration(
        bool $enabled,
        string $client,
        ?CollectingLogger $logger = null,
    ): AdvertisingConfiguration {
        return new AdvertisingConfiguration($enabled, $client, $logger ?? new CollectingLogger());
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ((string)$level === 'warning') {
            $this->warnings[] = (string)$message;
        }
    }
}
