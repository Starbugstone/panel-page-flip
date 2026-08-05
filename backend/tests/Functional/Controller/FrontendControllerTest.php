<?php

namespace App\Tests\Functional\Controller;

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

        if ($shouldBeIndexed) {
            self::assertResponseHeaderNotSame('X-Robots-Tag', 'noindex, follow');
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

    public function knownRouteProvider(): iterable
    {
        yield 'public landing page' => ['/', true];
        yield 'login' => ['/login', false];
        yield 'dashboard' => ['/dashboard', false];
        yield 'comic reader' => ['/read/123', false];
        yield 'password-reset token' => ['/reset-password/example-token', false];
        yield 'share token' => ['/share/accept/example-token', false];
    }
}
