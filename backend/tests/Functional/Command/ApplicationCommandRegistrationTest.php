<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApplicationCommandRegistrationTest extends KernelTestCase
{
    public function testWeakPasswordSampleGeneratorIsNotExposed(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        self::assertFalse($application->has('app:generate-sample-data'));
    }
}
