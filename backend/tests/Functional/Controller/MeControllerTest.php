<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

class MeControllerTest extends AbstractApiTestCase
{
    public function testReturnsAuthenticatedUser(): void
    {
        $user = UserFactory::createOne(['email' => 'me@test.local']);
        $this->loginAs($user);

        $payload = $this->getJson('/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('me@test.local', $payload['user']['email']);
        self::assertSame(['ROLE_USER'], $payload['user']['roles']);
        self::assertFalse($payload['user']['isAdmin']);
        self::assertFalse($payload['sessionRefreshed']);
    }

    public function testReturnsAdminFlagForAdminUsers(): void
    {
        $user = UserFactory::new()->admin()->create();
        $this->loginAs($user);

        $payload = $this->getJson('/api/me');

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['user']['isAdmin']);
    }

    public function testAnonymousGetReturnsAnEmptyUser(): void
    {
        $payload = $this->getJson('/api/me');

        self::assertResponseIsSuccessful();
        self::assertNull($payload['user']);
        self::assertFalse($payload['sessionRefreshed']);
    }

    public function testAnonymousPostStillRequiresAuthentication(): void
    {
        $this->postJson('/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPostMarksSessionRefreshedWithoutRotatingId(): void
    {
        $user = UserFactory::createOne();
        $this->loginAs($user);

        // First call to bind session id
        $this->browser()->request('GET', '/api/me');
        $sessionIdBefore = $this->browser()->getRequest()->getSession()->getId();

        $this->postJson('/api/me');

        $sessionIdAfter = $this->browser()->getRequest()->getSession()->getId();

        $payload = $this->json();
        self::assertResponseIsSuccessful();
        self::assertTrue($payload['sessionRefreshed']);
        self::assertSame($sessionIdBefore, $sessionIdAfter, 'Session ID must not be rotated on keep-alive');
    }
}
