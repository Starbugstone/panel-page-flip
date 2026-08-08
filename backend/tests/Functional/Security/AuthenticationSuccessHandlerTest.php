<?php

namespace App\Tests\Functional\Security;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

class AuthenticationSuccessHandlerTest extends AbstractApiTestCase
{
    public function testSuccessfulLoginRecordsLastLoginDate(): void
    {
        $user = UserFactory::createOne([
            'email' => 'successful-login@test.local',
            'password' => 'Valid!Password123',
        ])->object();

        $payload = $this->postJson('/api/login', [
            'email' => $user->getEmail(),
            'password' => 'Valid!Password123',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['success']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->refresh($user);
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testRejectedUnverifiedLoginDoesNotRecordLastLoginDate(): void
    {
        $user = UserFactory::new()->unverified()->create([
            'email' => 'unverified-login@test.local',
            'password' => 'Valid!Password123',
        ])->object();

        $this->postJson('/api/login', [
            'email' => $user->getEmail(),
            'password' => 'Valid!Password123',
        ]);

        self::assertResponseStatusCodeSame(403);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->refresh($user);
        self::assertNull($user->getLastLoginAt());
    }

    /**
     * The 403 body is not the security boundary — the session is.
     *
     * json_login stores the authenticated token before the success handler is
     * consulted, so refusing an unverified account in the response alone leaves
     * a usable session cookie behind and every other endpoint lets it through.
     */
    public function testRejectedUnverifiedLoginLeavesNoUsableSession(): void
    {
        $user = UserFactory::new()->unverified()->create([
            'email' => 'unverified-session@test.local',
            'password' => 'Valid!Password123',
        ])->object();

        $this->postJson('/api/login', [
            'email' => $user->getEmail(),
            'password' => 'Valid!Password123',
        ]);

        self::assertResponseStatusCodeSame(403);

        // The account the login was refused for must not be able to read the
        // library it was refused access to.
        $this->client->request('GET', '/api/comics', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidPasswordDoesNotRecordLastLoginDate(): void
    {
        $user = UserFactory::createOne([
            'email' => 'failed-login@test.local',
            'password' => 'Valid!Password123',
        ])->object();

        $this->postJson('/api/login', [
            'email' => $user->getEmail(),
            'password' => 'Wrong!Password123',
        ]);

        self::assertResponseStatusCodeSame(401);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->refresh($user);
        self::assertNull($user->getLastLoginAt());
    }
}
