<?php

declare(strict_types=1);

namespace App\Tests\Functional\Configuration;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineConnectionConfigurationTest extends KernelTestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDevelopmentCredentialsRemainRawDoctrineParameters(): void
    {
        self::setEnvironment('MYSQL_USER', 'reader@local');
        self::setEnvironment('MYSQL_PASSWORD', 'p@ss/word#fragment');
        self::setEnvironment('MYSQL_DATABASE', 'comic-library');

        self::bootKernel(['environment' => 'test']);
        $params = self::getContainer()->get(ManagerRegistry::class)->getConnection()->getParams();

        self::assertSame('reader@local', $params['user']);
        self::assertSame('p@ss/word#fragment', $params['password']);
        self::assertSame('comic-library_test', $params['dbname']);
    }

    private static function setEnvironment(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
