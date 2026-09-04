<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\DiagnoseGoogleIntegrationsCommand;
use App\Service\AdvertisingConfiguration;
use App\Service\ConsentConfiguration;
use App\Service\ContentSecurityPolicy;
use App\Service\FrontendRouteRegistry;
use App\Service\GoogleAnalyticsConfiguration;
use App\Service\PublicUrl;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class DiagnoseGoogleIntegrationsCommandTest extends TestCase
{
    private const VALID_CLIENT = 'ca-pub-1234567890123456';

    private const VALID_MEASUREMENT_ID = 'G-PSW1MY7HB4';

    public function testItReportsEffectiveConfigurationWithoutSecrets(): void
    {
        $tester = $this->tester(true, self::VALID_CLIENT, false, '');

        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Effective AdSense', $display);
        self::assertStringContainsString('AdSense client syntax', $display);
        self::assertStringContainsString('Google AdSense Offerwall', $display);
        self::assertStringContainsString('/upload/bulk', $display);
        self::assertStringNotContainsString(self::VALID_CLIENT, $display);
    }

    public function testItNamesTheConsentProviderForEachEffectiveCombination(): void
    {
        self::assertProvider('none', $this->display(false, '', false, ''));
        self::assertProvider('google', $this->display(true, self::VALID_CLIENT, false, ''));
        self::assertProvider('local', $this->display(false, '', true, self::VALID_MEASUREMENT_ID));
        self::assertProvider('google', $this->display(true, self::VALID_CLIENT, true, self::VALID_MEASUREMENT_ID));
    }

    private static function assertProvider(string $expected, string $display): void
    {
        self::assertMatchesRegularExpression('/Consent provider\s+'.preg_quote($expected, '/').'\s/', $display);
    }

    public function testAStrayPublisherIdIsCalledOutAsHavingNoEffect(): void
    {
        $display = $this->display(false, self::VALID_CLIENT, true, self::VALID_MEASUREMENT_ID);

        self::assertStringContainsString('local Analytics-only consent', $display);
        self::assertStringContainsString('has no effect', $display);
    }

    public function testEachInvalidCredentialIsBlamedOnlyForItsOwnIntegration(): void
    {
        $adsBroken = $this->display(true, 'not-a-publisher-id', true, self::VALID_MEASUREMENT_ID);
        self::assertStringContainsString('AdSense is disabled', $adsBroken);
        self::assertStringContainsString('Effective Analytics', $adsBroken);
        self::assertStringContainsString('local Analytics-only consent', $adsBroken);
        self::assertMatchesRegularExpression('/Effective Analytics\s+enabled/', $adsBroken);

        $analyticsBroken = $this->display(true, self::VALID_CLIENT, true, 'UA-123456-1');
        self::assertStringContainsString('Analytics is disabled', $analyticsBroken);
        // Advertising and its certified CMP survive Analytics being misconfigured.
        self::assertProvider('google', $analyticsBroken);
        self::assertMatchesRegularExpression('/Effective AdSense\s+enabled/', $analyticsBroken);
    }

    public function testTheLegalRoutesAreReportedAsServedWithoutGoogle(): void
    {
        $display = $this->display(true, self::VALID_CLIENT, true, self::VALID_MEASUREMENT_ID);

        self::assertStringContainsString('/privacy, /cookies, /terms', $display);
        self::assertStringContainsString('strict, no Google origins, no nonce', $display);
        self::assertStringNotContainsString('MISCONFIGURED', $display);
        self::assertStringContainsString('https://panel.example/privacy', $display);
    }

    public function testCompiledDotenvTakesPrecedenceInTheDiagnostic(): void
    {
        $directory = sys_get_temp_dir().'/panel-page-flip-csp-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            touch($directory.'/.env.local');
            self::assertSame('dotenv (.env.local)', DiagnoseGoogleIntegrationsCommand::runtimeConfigMode($directory));

            touch($directory.'/.env.local.php');
            self::assertSame('compiled dotenv (.env.local.php)', DiagnoseGoogleIntegrationsCommand::runtimeConfigMode($directory));
        } finally {
            @unlink($directory.'/.env.local.php');
            @unlink($directory.'/.env.local');
            @rmdir($directory);
        }
    }

    private function display(bool $adsEnabled, string $client, bool $analyticsEnabled, string $measurementId): string
    {
        $tester = $this->tester($adsEnabled, $client, $analyticsEnabled, $measurementId);
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function tester(bool $adsEnabled, string $client, bool $analyticsEnabled, string $measurementId): CommandTester
    {
        $projectDir = dirname(__DIR__, 3);
        $advertising = new AdvertisingConfiguration($adsEnabled, $client, new NullLogger());
        $analytics = new GoogleAnalyticsConfiguration($analyticsEnabled, $measurementId, new NullLogger());
        $routes = new FrontendRouteRegistry($projectDir.'/config/frontend-routes.json');

        return new CommandTester(new DiagnoseGoogleIntegrationsCommand(
            $advertising,
            $analytics,
            new ConsentConfiguration($advertising, $analytics),
            new ContentSecurityPolicy($advertising, $projectDir.'/config/csp.json', $analytics, $routes),
            $routes,
            new PublicUrl('https://panel.example'),
            $projectDir,
        ));
    }
}
