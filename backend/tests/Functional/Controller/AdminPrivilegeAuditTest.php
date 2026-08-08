<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administrator actions: what stays in the database trail, and what is serious
 * enough to reach an inbox.
 *
 * The division is deliberate. `AdminAuditLog` remains the durable record of
 * what an administrator did — it survives a log rotation and an administrator
 * can read it in the UI. The security channel and the alerts on top of it exist
 * for the subset that changes who holds the keys, because that is the change
 * nobody would think to go looking for.
 */
final class AdminPrivilegeAuditTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    public function testGrantingAdminRecordsTheRolesBeforeAndAfter(): void
    {
        $admin = $this->createAndLoginAdmin(['email' => 'grantor@test.local']);
        $target = UserFactory::createOne(['email' => 'promoted@test.local'])->object();

        $this->putJson('/api/users/' . $target->getId(), ['roles' => ['ROLE_ADMIN']]);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::USER_ROLES_CHANGED);
        self::assertSame($admin->getId(), $record->context['actor_user_id']);
        self::assertSame($target->getId(), $record->context['target_user_id']);
        self::assertSame(['ROLE_USER'], $record->context['roles_before']);
        self::assertContains('ROLE_ADMIN', $record->context['roles_after']);
    }

    public function testGrantingAdminAlertsTheAdministrators(): void
    {
        $this->createAndLoginAdmin(['email' => 'grantor2@test.local']);
        $target = UserFactory::createOne(['email' => 'promoted2@test.local'])->object();

        $this->putJson('/api/users/' . $target->getId(), ['roles' => ['ROLE_ADMIN']]);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::ADMIN_ROLE_CHANGED);
        self::assertSame('granted', $record->context['change']);

        // On the first occurrence, not on a threshold: one person gaining the
        // run of the instance is not something to wait for a pattern about.
        self::assertCount(1, $this->alertsAbout(SecurityAuditLogger::ADMIN_ROLE_CHANGED));
    }

    public function testRemovingAdminAlertsTheAdministrators(): void
    {
        $this->createAndLoginAdmin(['email' => 'demoter@test.local']);
        $target = UserFactory::new()->admin()->create(['email' => 'demoted@test.local'])->object();

        $this->putJson('/api/users/' . $target->getId(), ['roles' => ['ROLE_USER']]);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::ADMIN_ROLE_CHANGED);
        self::assertSame('removed', $record->context['change']);
        self::assertContains('ROLE_ADMIN', $record->context['roles_before']);
        self::assertNotContains('ROLE_ADMIN', $record->context['roles_after']);

        // Both directions alert. A removal may be somebody locking the real
        // administrators out of their own instance.
        self::assertCount(1, $this->alertsAbout(SecurityAuditLogger::ADMIN_ROLE_CHANGED));
    }

    public function testAnOrdinaryProfileEditIsNotAPrivilegeChange(): void
    {
        $this->createAndLoginAdmin(['email' => 'editor@test.local']);
        $target = UserFactory::createOne(['email' => 'renamed@test.local'])->object();

        $this->putJson('/api/users/' . $target->getId(), ['name' => 'A New Name']);
        self::assertResponseIsSuccessful();

        $this->assertNoAuditEvent(SecurityAuditLogger::USER_ROLES_CHANGED);
        $this->assertNoSecurityEvent(SecurityAuditLogger::ADMIN_ROLE_CHANGED);
        self::assertSame([], $this->alertsAbout(SecurityAuditLogger::ADMIN_ROLE_CHANGED));
    }

    public function testDeletingAnAdminAccountIsBothAuditedAndAlerted(): void
    {
        $admin = $this->createAndLoginAdmin(['email' => 'remover@test.local']);
        $target = UserFactory::new()->admin()->create(['email' => 'removed-admin@test.local'])->object();
        $targetId = $target->getId();

        $this->deleteJson('/api/users/' . $targetId);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::USER_ACCOUNT_DELETED);
        self::assertSame($admin->getId(), $record->context['actor_user_id']);
        self::assertSame($targetId, $record->context['target_user_id']);
        self::assertTrue($record->context['target_was_admin']);

        self::assertCount(1, $this->alertsAbout(SecurityAuditLogger::ADMIN_ROLE_CHANGED));
    }

    /**
     * The database trail is what an administrator reads in the UI and what
     * survives log rotation, so it has to keep working — and it has to keep not
     * containing the password that was submitted with the request.
     */
    public function testTheDatabaseAuditTrailStillRecordsUserCreationAndNeverThePassword(): void
    {
        $this->createAndLoginAdmin(['email' => 'creator@test.local']);

        $this->postJson('/api/users', [
            'email' => 'created@test.local',
            'name' => 'Created User',
            'password' => 'ASecretPassword!42',
            'roles' => ['ROLE_USER'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $entries = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(AdminAuditLog::class)
            ->findBy(['action' => 'user_create']);

        self::assertCount(1, $entries);
        self::assertStringNotContainsString(
            'ASecretPassword!42',
            json_encode($entries[0]->getPayload(), JSON_THROW_ON_ERROR)
        );

        $this->assertNothingLogged('ASecretPassword!42', 'A submitted password');
        $this->assertLoggedAuditEvent(SecurityAuditLogger::USER_REGISTERED);
    }

    public function testCreatingAnAccountThatIsAdminFromTheStartIsTreatedAsAGrant(): void
    {
        $this->createAndLoginAdmin(['email' => 'creator2@test.local']);

        $this->postJson('/api/users', [
            'email' => 'born-admin@test.local',
            'name' => 'Born Admin',
            'password' => 'AnotherSecret!42',
            'roles' => ['ROLE_ADMIN'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::ADMIN_ROLE_CHANGED);
        self::assertSame('granted_on_creation', $record->context['change']);
        self::assertCount(1, $this->alertsAbout(SecurityAuditLogger::ADMIN_ROLE_CHANGED));
    }
}
