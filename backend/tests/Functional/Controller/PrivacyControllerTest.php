<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

final class PrivacyControllerTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    public function testUserCanExportPersonalDataWithoutSecrets(): void
    {
        $user = $this->createAndLoginUser([
            'email' => 'export@test.local',
            'name' => 'Export User',
            'dropboxAccessToken' => 'encrypted-access-secret',
            'dropboxRefreshToken' => 'encrypted-refresh-secret',
        ]);
        ComicFactory::new()->ownedBy($user)->explicit()->create(['title' => 'Exported Comic']);

        $payload = $this->getJson('/api/privacy/export');
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertSame('export@test.local', $payload['account']['email']);
        self::assertSame('Exported Comic', $payload['comics'][0]['title']);
        // The owner's own classification is stored, so an export of what is
        // held about them has to name it — and on the comic, not only on the
        // shares handed out, or a comic nobody was invited to would omit it.
        self::assertTrue($payload['comics'][0]['explicitContent']);
        self::assertTrue($payload['account']['dropboxConnected']);
        self::assertStringNotContainsString('password', strtolower($encoded));
        self::assertStringNotContainsString('encrypted-access-secret', $encoded);
        self::assertStringNotContainsString('encrypted-refresh-secret', $encoded);
        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAccountDeletionRequiresPasswordAndExplicitConfirmation(): void
    {
        $this->createAndLoginUser();

        $payload = $this->deleteJson('/api/privacy/account', [
            'confirmation' => 'DELETE',
            'currentPassword' => 'wrong-password',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('The current password is incorrect.', $payload['message']);
    }

    public function testUserCanDeleteOwnAccountAndAuditDataIsRedacted(): void
    {
        $user = $this->createAndLoginUser([
            'email' => 'delete-me@test.local',
            'name' => 'Delete Me',
            'password' => 'P@ssw0rd!Strong',
        ]);
        ComicFactory::new()->ownedBy($user)->create();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $audit = (new AdminAuditLog())
            ->setAdminUser($user)
            ->setAction('profile_update')
            ->setTargetType('user')
            ->setTargetId($user->getId())
            ->setPayload(['email' => $user->getEmail(), 'name' => $user->getName()]);
        $entityManager->persist($audit);
        $entityManager->flush();
        $userId = $user->getId();
        $auditId = $audit->getId();

        $payload = $this->deleteJson('/api/privacy/account', [
            'confirmation' => 'DELETE',
            'currentPassword' => 'P@ssw0rd!Strong',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('deleted', $payload['message']);

        $entityManager->clear();
        self::assertNull($entityManager->find(User::class, $userId));
        self::assertSame(0, ComicFactory::repository()->count());

        $redactedAudit = $entityManager->find(AdminAuditLog::class, $auditId);
        self::assertNotNull($redactedAudit);
        self::assertNull($redactedAudit->getAdminUser());
        self::assertNull($redactedAudit->getTargetId());
        // assertEquals, not assertSame: MySQL normalises JSON object keys by
        // length, so the payload comes back as name-then-email regardless of
        // the order it was written in.
        self::assertEquals(['email' => '[redacted]', 'name' => '[redacted]'], $redactedAudit->getPayload());
    }

    /**
     * The three deletion paths write one shared record, so the only thing that
     * tells them apart afterwards is who is named as the actor. An erasure the
     * account holder asked for must not be indistinguishable from the retention
     * sweep, which acts on nobody's behalf — that distinction is the answer to
     * "did this person ask to be forgotten, or did we decide for them".
     */
    public function testSelfServiceDeletionRecordsTheAccountHolderAsTheActor(): void
    {
        $user = $this->createAndLoginUser([
            'email' => 'self-delete@test.local',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $userId = $user->getId();

        $this->deleteJson('/api/privacy/account', [
            'confirmation' => 'DELETE',
            'currentPassword' => 'P@ssw0rd!Strong',
        ]);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::USER_ACCOUNT_DELETED);
        self::assertSame($userId, $record->context['actor_user_id']);
        self::assertSame($userId, $record->context['target_user_id']);
        self::assertTrue($record->context['self_service']);
    }

    public function testLastAdministratorCannotDeleteOwnAccount(): void
    {
        $this->createAndLoginAdmin(['password' => 'P@ssw0rd!Strong']);

        $payload = $this->deleteJson('/api/privacy/account', [
            'confirmation' => 'DELETE',
            'currentPassword' => 'P@ssw0rd!Strong',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('The last administrator account cannot be deleted.', $payload['message']);
    }
}
