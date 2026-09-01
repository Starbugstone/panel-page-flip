<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Entity\ComicShare;
use App\Entity\User;
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
        $lastLoginAt = new \DateTimeImmutable('2026-07-25 14:30:00+00:00');
        UserFactory::createOne([
            'email' => 'listed-user@test.local',
            'dropboxAccessToken' => 'sensitive-access-token',
            'dropboxRefreshToken' => 'sensitive-refresh-token',
            'lastLoginAt' => $lastLoginAt,
        ]);

        $payload = $this->getJson('/api/users');
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $listedUser = array_values(array_filter(
            $payload['users'],
            static fn (array $user): bool => $user['email'] === 'listed-user@test.local'
        ))[0];

        self::assertResponseIsSuccessful();
        self::assertCount(2, $payload['users']);
        self::assertSame($lastLoginAt->format('c'), $listedUser['lastLoginAt']);
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

    public function testAdminCannotCreateUserWithMalformedRoles(): void
    {
        $this->createAndLoginAdmin();

        $payload = $this->postJson('/api/users', [
            'email' => 'invalid-roles@test.local',
            'name' => 'Invalid Roles',
            'password' => 'Valid!Password123',
            'roles' => 'ROLE_ADMIN',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Roles must be an array of role names.', $payload['message']);
    }

    public function testAdminUserCreationErrorsAreKeyedByField(): void
    {
        $this->createAndLoginAdmin();

        $missing = $this->postJson('/api/users', []);
        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('email', $missing['errors']);
        self::assertArrayHasKey('password', $missing['errors']);
        self::assertArrayHasKey('name', $missing['errors']);

        $invalid = $this->postJson('/api/users', [
            'email' => 'not-an-email',
            'name' => 'Invalid Email',
            'password' => 'Valid!Password123',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('email', $invalid['errors']);
        self::assertArrayNotHasKey(0, $invalid['errors']);
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
        $owner = UserFactory::createOne();
        ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->deleteJson('/api/users/' . $owner->getId());

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('still owns comics', $payload['message']);
        self::assertSame(1, ComicFactory::repository()->count());
    }

    public function testAdminDeleteErasesSharesAndRedactsAuditPayload(): void
    {
        $this->createAndLoginAdmin();
        $target = UserFactory::createOne([
            'email' => 'admin-delete-me@test.local',
            'name' => 'Admin Delete Me',
        ]);
        $comicOwner = UserFactory::createOne();
        $comic = ComicFactory::new()->ownedBy($comicOwner)->create();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $share = (new ComicShare($comic, $comicOwner, $target->getEmail()))
            ->setExpiresAt(new \DateTimeImmutable('+1 day'));
        $entityManager->persist($share);
        $entityManager->flush();
        $targetId = $target->getId();
        $shareId = $share->getId();

        $payload = $this->deleteJson('/api/users/' . $targetId);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('deleted', $payload['message']);

        $entityManager->clear();
        self::assertNull($entityManager->find(User::class, $targetId));
        // Addressed to the deleted account, so it holds their email address and
        // goes with them rather than lingering as history.
        self::assertNull($entityManager->find(ComicShare::class, $shareId));

        $auditLogs = $entityManager->getRepository(AdminAuditLog::class)->findBy(['action' => 'user_delete']);
        self::assertNotEmpty($auditLogs);
        $latest = end($auditLogs);
        self::assertNull($latest->getTargetId());
        self::assertSame('[redacted]', $latest->getPayload()['email'] ?? null);
    }

    public function testAdminCanDeleteAnotherAdminWhenMoreThanOneRemains(): void
    {
        // Regression coverage for the admin-lock/last-admin-count branch that
        // AccountDeletionService now runs for admin targets: no prior test
        // deleted an actual ROLE_ADMIN user through this endpoint, so that
        // branch (added alongside routing admin delete through the shared
        // erasure path) was never exercised outside of self-service deletion.
        $this->createAndLoginAdmin();
        $targetAdmin = UserFactory::new()->admin()->create([
            'email' => 'second-admin@test.local',
        ]);
        $targetId = $targetAdmin->getId();

        $payload = $this->deleteJson('/api/users/' . $targetId);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('deleted', $payload['message']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(User::class, $targetId));
    }

    public function testAuditHistorySurvivesAdministratorDeletion(): void
    {
        $this->createAndLoginAdmin();
        $formerAdmin = UserFactory::new()->admin()->create();
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
        $otherUser = UserFactory::createOne();
        self::assertNotSame($currentUser->getId(), $otherUser->getId());

        $this->getJson('/api/users/' . $otherUser->getId());
        self::assertResponseStatusCodeSame(403);
    }
}
