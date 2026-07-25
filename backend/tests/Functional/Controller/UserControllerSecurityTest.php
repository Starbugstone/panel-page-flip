<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

class UserControllerSecurityTest extends AbstractApiTestCase
{
    public function testRegularUserCannotListUsers(): void
    {
        $this->createAndLoginUser();
        $this->getJson('/api/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanListUsersWithoutPasswordOrTokensLeaking(): void
    {
        $this->createAndLoginAdmin(['email' => 'admin-list@test.local']);
        UserFactory::createOne([
            'email' => 'listed-user@test.local',
            'dropboxAccessToken' => 'sensitive-access-token',
            'dropboxRefreshToken' => 'sensitive-refresh-token',
        ]);

        $payload = $this->getJson('/api/users');
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $payload['users']);
        self::assertStringNotContainsString('password', strtolower($encoded));
        self::assertStringNotContainsString('sensitive-access-token', $encoded);
        self::assertStringNotContainsString('sensitive-refresh-token', $encoded);
    }

    public function testRegularUserCannotCreateAnotherUser(): void
    {
        $this->createAndLoginUser();
        $this->postJson('/api/users', [
            'email' => 'forbidden@test.local',
            'name' => 'Forbidden',
            'password' => 'Valid!Password123',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatedUserIsVerifiedAndAudited(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->postJson('/api/users', [
            'email' => 'created-by-admin@test.local',
            'name' => 'Created User',
            'password' => 'Valid!Password123',
            'roles' => ['ROLE_ADMIN'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($payload['user']['isEmailVerified']);
        self::assertContains('ROLE_USER', $payload['user']['roles']);
        self::assertContains('ROLE_ADMIN', $payload['user']['roles']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $audit = $entityManager->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'user_create']);
        self::assertNotNull($audit);
        self::assertSame('created-by-admin@test.local', $audit->getPayload()['email']);
    }

    public function testAdminCannotCreateUserWithWeakPassword(): void
    {
        $this->createAndLoginAdmin();
        $payload = $this->postJson('/api/users', [
            'email' => 'weak@test.local',
            'name' => 'Weak Password',
            'password' => 'qwerty',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(4, $payload['errors']['password']);
    }

    public function testAdminCannotDemoteSelf(): void
    {
        $admin = $this->createAndLoginAdmin();
        $payload = $this->patchJson('/api/users/' . $admin->getId(), ['roles' => ['ROLE_USER']]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('You cannot remove your own admin role', $payload['message']);
    }

    public function testAdminCannotDeleteSelf(): void
    {
        $admin = $this->createAndLoginAdmin();
        $payload = $this->deleteJson('/api/users/' . $admin->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Cannot delete your own account', $payload['message']);
    }

    public function testAdminCannotCascadeDeleteAUsersComics(): void
    {
        $this->createAndLoginAdmin();
        $owner = UserFactory::createOne()->object();
        ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->deleteJson('/api/users/' . $owner->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('still owns comics', $payload['message']);
        self::assertSame(1, ComicFactory::repository()->count());
    }

    public function testAuditHistorySurvivesAdministratorDeletion(): void
    {
        $this->createAndLoginAdmin();
        $formerAdmin = UserFactory::new()->admin()->create()->object();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $audit = (new AdminAuditLog())
            ->setAdminUser($formerAdmin)
            ->setAction('historical_action')
            ->setTargetType('system');
        $entityManager->persist($audit);
        $entityManager->flush();
        $auditId = $audit->getId();

        $entityManager->remove($formerAdmin);
        $entityManager->flush();
        $entityManager->clear();

        $preservedAudit = $entityManager->find(AdminAuditLog::class, $auditId);
        self::assertNotNull($preservedAudit);
        self::assertNull($preservedAudit->getAdminUser());
    }

    public function testRegularUserCannotReadAnotherUserProfile(): void
    {
        $currentUser = $this->createAndLoginUser();
        $otherUser = UserFactory::createOne()->object();
        self::assertNotSame($currentUser->getId(), $otherUser->getId());

        $this->getJson('/api/users/' . $otherUser->getId());
        self::assertResponseStatusCodeSame(403);
    }
}
