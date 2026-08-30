<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class LogoutTest extends AbstractApiTestCase
{
    public function testLogoutInvalidatesTheServerSession(): void
    {
        $user = UserFactory::createOne([
            'email' => 'logout-session@test.local',
            'password' => 'Valid!Password123',
        ]);

        $this->postJson('/api/login', [
            'email' => $user->getEmail(),
            'password' => 'Valid!Password123',
        ]);
        self::assertResponseIsSuccessful();
        $this->getJson('/api/login_check');
        self::assertResponseIsSuccessful();

        $payload = $this->postJson('/api/logout');
        self::assertResponseIsSuccessful();
        self::assertSame('Logout successful', $payload['message']);

        $this->getJson('/api/login_check');
        self::assertResponseStatusCodeSame(401);
    }
}
