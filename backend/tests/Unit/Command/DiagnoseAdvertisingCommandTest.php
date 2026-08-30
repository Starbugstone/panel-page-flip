<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\DiagnoseAdvertisingCommand;
use App\Service\AdvertisingConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class DiagnoseAdvertisingCommandTest extends TestCase
{
    public function testItReportsEffectiveConfigurationWithoutSecrets(): void
    {
        $command = new DiagnoseAdvertisingCommand(
            new AdvertisingConfiguration(true, 'ca-pub-1234567890123456', new NullLogger()),
            dirname(__DIR__, 3)
        );
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Effective advertising', $display);
        self::assertStringContainsString('Publisher id valid', $display);
        self::assertStringContainsString('Google AdSense Offerwall', $display);
        self::assertStringContainsString('/upload/bulk', $display);
        self::assertStringNotContainsString('ca-pub-1234567890123456', $display);
    }

    public function testCompiledDotenvTakesPrecedenceInTheDiagnostic(): void
    {
        $directory = sys_get_temp_dir().'/panel-page-flip-csp-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            touch($directory.'/.env.local');
            self::assertSame('dotenv (.env.local)', DiagnoseAdvertisingCommand::runtimeConfigMode($directory));

            touch($directory.'/.env.local.php');
            self::assertSame('compiled dotenv (.env.local.php)', DiagnoseAdvertisingCommand::runtimeConfigMode($directory));
        } finally {
            @unlink($directory.'/.env.local.php');
            @unlink($directory.'/.env.local');
            @rmdir($directory);
        }
    }
}
