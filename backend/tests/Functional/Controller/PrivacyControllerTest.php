<?php

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

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

    public function testPersonalDataExportDoesNotCallEmptyDropboxFieldsAConnection(): void
    {
        $this->createAndLoginUser([
            'dropboxAccessToken' => '',
            'dropboxRefreshToken' => '',
        ]);

        $payload = $this->getJson('/api/privacy/export');

        self::assertResponseIsSuccessful();
        self::assertFalse($payload['account']['dropboxConnected']);
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
     * Anybody can register under any display name, so a name that also occurs
     * inside other people's audit rows must not rewrite them. `a` is inside
     * almost everything; `ROLE_USER` and `Stan Lee` are whole values that mean
     * something else on a row about somebody else.
     *
     * @dataProvider namesThatOccurElsewhere
     */
    public function testDeletingAnAccountLeavesOtherPeoplesAuditRowsAlone(string $name): void
    {
        $unrelatedPayload = [
            'email' => 'unrelated@example.com',
            'rolesAfter' => ['ROLE_ADMIN', 'ROLE_USER'],
            'after' => ['author' => 'Stan Lee', 'description' => 'A saga about Stan Lee.'],
        ];
        $unrelated = (new AdminAuditLog())
            ->setAction('role_change')
            ->setTargetType('user')
            ->setTargetId(999999)
            ->setPayload($unrelatedPayload);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($unrelated);
        $entityManager->flush();
        $auditId = $unrelated->getId();

        $this->createAndLoginUser(['email' => 'leaving@example.com', 'name' => $name]);
        $this->deleteJson('/api/privacy/account', ['confirmation' => 'DELETE', 'currentPassword' => UserFactory::PASSWORD]);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        self::assertEquals($unrelatedPayload, $entityManager->find(AdminAuditLog::class, $auditId)->getPayload());
    }

    /** @return iterable<string, array{string}> */
    public static function namesThatOccurElsewhere(): iterable
    {
        yield 'a single letter' => ['a'];
        yield 'a role name' => ['ROLE_USER'];
        yield 'a comic author' => ['Stan Lee'];
    }

    public function testDeletingAnAccountRedactsWhatIdentifiesItOnAnybodysRow(): void
    {
        $user = $this->createAndLoginUser(['email' => 'Leaving.Person@example.com', 'name' => 'Sam']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $otherAdminsRow = (new AdminAuditLog())
            ->setAction('user_update')
            ->setTargetType('comic')
            ->setTargetId(7)
            ->setPayload([
                'email' => 'leaving.person@EXAMPLE.com',
                'name' => ' sam ',
                'note' => 'Requested by leaving.person@example.com. Same day as Samantha.',
                'lookalikes' => ['not-leaving.person@example.com', 'leaving.person@example.community'],
                'author' => 'Sam',
                'nested' => ['recipient' => ['email' => 'Leaving.Person@example.com']],
            ]);
        $ownRow = (new AdminAuditLog())
            ->setAdminUser($user)
            ->setAction('profile_update')
            ->setTargetType('user')
            ->setTargetId($user->getId())
            ->setPayload(['displayedAs' => 'Sam', 'summary' => 'Samantha', 'count' => 2]);
        $entityManager->persist($otherAdminsRow);
        $entityManager->persist($ownRow);
        $entityManager->flush();
        $otherId = $otherAdminsRow->getId();
        $ownId = $ownRow->getId();

        $this->deleteJson('/api/privacy/account', ['confirmation' => 'DELETE', 'currentPassword' => UserFactory::PASSWORD]);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        self::assertEquals([
            'email' => '[redacted]',
            'name' => '[redacted]',
            'note' => 'Requested by [redacted]. Same day as Samantha.',
            'lookalikes' => ['not-leaving.person@example.com', 'leaving.person@example.community'],
            // Somebody else's row, and a comic's author rather than this person.
            'author' => 'Sam',
            'nested' => ['recipient' => ['email' => '[redacted]']],
        ], $entityManager->find(AdminAuditLog::class, $otherId)->getPayload());

        $own = $entityManager->find(AdminAuditLog::class, $ownId);
        self::assertNull($own->getAdminUser());
        self::assertNull($own->getTargetId());
        self::assertEquals(['displayedAs' => '[redacted]', 'summary' => 'Samantha', 'count' => 2], $own->getPayload());
    }

    /** Batched reads must reach the rows past the first batch. */
    public function testDeletingAnAccountRedactsPastTheFirstBatchOfAuditRows(): void
    {
        $this->createAndLoginUser(['email' => 'many-rows@example.com', 'name' => 'Many Rows']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $last = null;
        foreach (range(1, 501) as $number) {
            $last = (new AdminAuditLog())
                ->setAction('row_'.$number)
                ->setTargetType('system')
                ->setPayload(['email' => 'many-rows@example.com']);
            $entityManager->persist($last);
        }
        $entityManager->flush();
        $lastId = $last->getId();

        $this->deleteJson('/api/privacy/account', ['confirmation' => 'DELETE', 'currentPassword' => UserFactory::PASSWORD]);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        self::assertSame(['email' => '[redacted]'], $entityManager->find(AdminAuditLog::class, $lastId)->getPayload());
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

    public function testDeletingAnAccountRemovesItsStagedUploadsImmediately(): void
    {
        $user = $this->createAndLoginUser();
        $accountStaging = self::getContainer()->getParameter('upload_staging_directory') . '/' . $user->getId();
        $otherStaging = self::getContainer()->getParameter('upload_staging_directory') . '/other-account';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($accountStaging . '/upload-a/chunk_0', 'part of their file');
        $filesystem->dumpFile($otherStaging . '/upload-b/chunk_0', 'somebody else');

        try {
            $this->deleteJson('/api/privacy/account', [
                'confirmation' => 'DELETE',
                'currentPassword' => UserFactory::PASSWORD,
            ]);

            self::assertResponseIsSuccessful();
            self::assertDirectoryDoesNotExist($accountStaging);
            self::assertFileExists($otherStaging . '/upload-b/chunk_0');
        } finally {
            $filesystem->remove([$accountStaging, $otherStaging]);
        }
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

    /**
     * An export is a file that leaves the server, so a provider token has no
     * business in it. Nothing about the token is the user's personal data in
     * any sense an export serves — it is a credential for somebody else's API.
     */
    public function testTheExportDoesNotCarryAMetadataProviderToken(): void
    {
        $this->loginAs(UserFactory::createOne());
        $this->putJson('/api/me/metadata-credentials', ['metronToken' => 'personal-metron-token']);
        // The export is only worth checking if there was a token to leak.
        self::assertResponseIsSuccessful();

        $this->getJson('/api/privacy/export');

        self::assertResponseIsSuccessful();
        $body = (string) $this->browser()->getResponse()->getContent();
        self::assertStringNotContainsString('personal-metron-token', $body);
        self::assertStringNotContainsString('metronToken', $body);
    }
}
