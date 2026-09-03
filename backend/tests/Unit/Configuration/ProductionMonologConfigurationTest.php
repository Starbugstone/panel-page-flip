<?php

declare(strict_types=1);

namespace App\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ProductionMonologConfigurationTest extends TestCase
{
    public function testWarningsTriggerTheProductionMainHandler(): void
    {
        $configuration = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/monolog.yaml');

        self::assertIsArray($configuration);
        self::assertSame(
            'warning',
            $configuration['when@prod']['monolog']['handlers']['main']['action_level'] ?? null
        );
    }
}
