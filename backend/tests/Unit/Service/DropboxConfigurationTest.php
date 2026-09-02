<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DropboxConfiguration;
use PHPUnit\Framework\TestCase;

final class DropboxConfigurationTest extends TestCase
{
    /** @dataProvider incompleteCredentials */
    public function testDropboxIsUnavailableUnlessBothCredentialsArePresent(string $key, string $secret): void
    {
        self::assertFalse((new DropboxConfiguration($key, $secret))->isConfigured());
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompleteCredentials(): iterable
    {
        yield 'both missing' => ['', ''];
        yield 'key only' => ['app-key', ''];
        yield 'secret only' => ['', 'app-secret'];
        yield 'whitespace is missing' => ['  ', "\n"];
    }

    public function testDropboxIsAvailableWithBothCredentials(): void
    {
        $configuration = new DropboxConfiguration(' app-key ', ' app-secret ');

        self::assertTrue($configuration->isConfigured());
        self::assertSame('app-key', $configuration->appKey());
        self::assertSame('app-secret', $configuration->appSecret());
    }

    public function testCredentialsCannotBeReadFromAnUnavailableConfiguration(): void
    {
        $configuration = new DropboxConfiguration('', '');

        $this->expectException(\LogicException::class);
        $configuration->appKey();
    }
}
