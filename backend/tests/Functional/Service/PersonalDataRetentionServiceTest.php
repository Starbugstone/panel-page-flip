<?php

namespace App\Tests\Functional\Service;

use App\Entity\AdminAuditLog;
use App\Entity\ComicShare;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordToken;
use App\Service\AccountDeletionService;
use App\Service\LogRetentionService;
use App\Service\PersonalDataRetentionService;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

final class PersonalDataRetentionServiceTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    public function testCleanupAppliesConfiguredRetentionPeriods(): void
    {
        $now = new \DateTimeImmutable('2026-08-05 12:00:00+00:00');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tokenOwner = UserFactory::createOne();
        $staleUser = UserFactory::new()->unverified()->create([
            'createdAt' => $now->modify('-31 days'),
        ]);
        UserFactory::new()->unverified()->create([
            'createdAt' => $now->modify('-29 days'),
        ]);

        $oldAudit = (new AdminAuditLog())
            ->setAction('old')
            ->setTargetType('system')
            ->setCreatedAt($now->modify('-12 months -1 day'));
        $recentAudit = (new AdminAuditLog())
            ->setAction('recent')
            ->setTargetType('system')
            ->setCreatedAt($now->modify('-11 months'));
        $verificationToken = (new EmailVerificationToken($tokenOwner))
            ->setExpiresAt($now->modify('-1 minute'));
        $resetToken = (new ResetPasswordToken())
            ->setToken('expired-reset-token')
            ->setUser($tokenOwner)
            ->setExpiresAt($now->modify('-1 minute'));

        foreach ([$oldAudit, $recentAudit, $verificationToken, $resetToken] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $staleUserId = $staleUser->getId();

        $counts = static::getContainer()->get(PersonalDataRetentionService::class)->clean($now);

        self::assertSame(1, $counts['auditLogs']);
        self::assertSame(1, $counts['verificationTokens']);
        self::assertSame(1, $counts['resetTokens']);
        self::assertSame(1, $counts['unverifiedAccounts']);
        self::assertSame(0, $counts['filesRemaining']);
        self::assertSame(0, $counts['errors']);

        $entityManager->clear();
        self::assertNull($entityManager->find(\App\Entity\User::class, $staleUserId));
        self::assertCount(1, $entityManager->getRepository(AdminAuditLog::class)->findAll());
    }

    /**
     * The sweep's list is old news by the time it reaches an account, and the
     * entity it holds may be older still. Each case changes the row behind that
     * entity's back, the way another request would have.
     *
     * @dataProvider accountsThatStoppedQualifying
     */
    public function testDeletionRechecksTheAccountOnFreshState(string $change): void
    {
        $now = new \DateTimeImmutable('2026-08-05 12:00:00+00:00');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = UserFactory::new()->unverified()->create(['createdAt' => $now->modify('-31 days')]);
        $userId = $user->getId();

        // The entity stays loaded and still reads as stale and unverified.
        $stale = $entityManager->find(\App\Entity\User::class, $userId);
        self::assertFalse($stale->isEmailVerified());
        $entityManager->getConnection()->executeStatement($change, ['id' => $userId]);

        $deleted = static::getContainer()->get(AccountDeletionService::class)
            ->deleteIfStillUnverified($stale, $now->modify('-30 days'));

        self::assertFalse($deleted);
        $entityManager->clear();
        self::assertNotNull($entityManager->find(\App\Entity\User::class, $userId));
    }

    /** @return iterable<string, array{string}> */
    public static function accountsThatStoppedQualifying(): iterable
    {
        yield 'verified since' => ['UPDATE `user` SET is_email_verified = 1 WHERE id = :id'];
        yield 'made an administrator since' => ['UPDATE `user` SET roles = \'["ROLE_ADMIN"]\' WHERE id = :id'];
        yield 'no longer old enough' => ['UPDATE `user` SET created_at = \'2026-08-01 00:00:00\' WHERE id = :id'];
    }

    public function testDeletionStillRemovesAnAccountThatQualifies(): void
    {
        $now = new \DateTimeImmutable('2026-08-05 12:00:00+00:00');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = UserFactory::new()->unverified()->create(['createdAt' => $now->modify('-31 days')]);
        $userId = $user->getId();

        self::assertTrue(
            static::getContainer()->get(AccountDeletionService::class)
                ->deleteIfStillUnverified($entityManager->find(\App\Entity\User::class, $userId), $now->modify('-30 days'))
        );

        $entityManager->clear();
        self::assertNull($entityManager->find(\App\Entity\User::class, $userId));
    }

    public function testAnAccountRemovedSinceIsLeftForTheSweepToForget(): void
    {
        $now = new \DateTimeImmutable('2026-08-05 12:00:00+00:00');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = UserFactory::new()->unverified()->create(['createdAt' => $now->modify('-31 days')]);
        $userId = $user->getId();
        $stale = $entityManager->find(\App\Entity\User::class, $userId);
        $entityManager->getConnection()->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        self::assertFalse(
            static::getContainer()->get(AccountDeletionService::class)
                ->deleteIfStillUnverified($stale, $now->modify('-30 days'))
        );
    }

    /**
     * The daily job is what makes both of these true, and it is the one a
     * production instance is already required to have scheduled.
     */
    public function testTheCleanupEnforcesQuarantineRetentionAndExpiresAbandonedUploads(): void
    {
        $now = new \DateTimeImmutable();
        $container = static::getContainer();
        $filesystem = new Filesystem();

        $quarantine = (string) $container->getParameter('quarantine_directory');
        $batch = $now->modify('-30 days')->format('Y-m-d');
        $quarantined = $quarantine . '/' . $batch . '/retention-test/7/comic.cbz';
        $filesystem->dumpFile($quarantined, 'deleted long ago');

        $user = UserFactory::createOne();
        $staging = $container->getParameter('upload_staging_directory') . '/' . $user->getId();
        $abandoned = $staging . '/upload-abandoned';
        $active = $staging . '/upload-active';
        $idleSeconds = (int) $container->getParameter('upload_staging_idle_seconds');
        $filesystem->dumpFile($abandoned . '/metadata.json', '{}');
        touch($abandoned . '/metadata.json', $now->getTimestamp() - $idleSeconds - 60);
        touch($abandoned, $now->getTimestamp() - $idleSeconds - 60);
        $filesystem->dumpFile($active . '/metadata.json', '{}');

        try {
            $counts = $container->get(PersonalDataRetentionService::class)->clean($now);

            self::assertFileDoesNotExist($quarantined);
            self::assertDirectoryDoesNotExist($quarantine . '/' . $batch);
            self::assertGreaterThanOrEqual(1, $counts['quarantineFilesDeleted']);
            self::assertSame(0, $counts['quarantineFilesRemaining']);
            self::assertDirectoryDoesNotExist($abandoned);
            self::assertDirectoryExists($active);
            self::assertGreaterThanOrEqual(1, $counts['stagedUploadsExpired']);
            self::assertSame(0, $counts['stagedUploadsRemaining']);
            self::assertSame(0, $counts['errors']);
        } finally {
            $filesystem->remove([$quarantine . '/' . $batch, $staging]);
        }
    }

    /**
     * The sweep deletes accounts nobody asked it to, so it has to say what it
     * did. Without this, an account vanishing has no explanation anywhere.
     */
    public function testTheCleanupLeavesAnAuditTrailOfItsOwn(): void
    {
        $now = new \DateTimeImmutable('2026-08-05 12:00:00+00:00');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $staleUser = UserFactory::new()->unverified()->create(['createdAt' => $now->modify('-31 days')]);
        $staleUserId = $staleUser->getId();

        $tokenOwner = UserFactory::createOne();
        $entityManager->persist((new EmailVerificationToken($tokenOwner))->setExpiresAt($now->modify('-1 minute')));
        $entityManager->persist(
            (new ResetPasswordToken())
                ->setToken('another-expired-reset-token')
                ->setUser($tokenOwner)
                ->setExpiresAt($now->modify('-1 minute'))
        );
        $entityManager->flush();

        static::getContainer()->get(PersonalDataRetentionService::class)->clean($now);

        $summary = $this->assertLoggedAuditEvent(SecurityAuditLogger::RETENTION_CLEANUP);
        self::assertSame(1, $summary->context['unverified_accounts_deleted']);
        self::assertSame(0, $summary->context['errors']);

        // Counts, not credentials. These keys name a token and hold a number,
        // and the redaction processor has to be able to tell the difference —
        // otherwise the record that proves the retention promise was kept says
        // "[redacted]" exactly where the proof would be.
        self::assertSame(1, $summary->context['verification_tokens_deleted']);
        self::assertSame(1, $summary->context['reset_tokens_deleted']);

        // And the account itself is named, once, by id — which is all that is
        // left of it and all that should be.
        $deletions = $this->auditRecords(SecurityAuditLogger::USER_ACCOUNT_DELETED);
        self::assertCount(1, $deletions);
        self::assertSame($staleUserId, $deletions[0]->context['target_user_id']);
        // Nobody asked for this one; the policy did.
        self::assertNull($deletions[0]->context['actor_user_id']);
        $this->assertNothingLogged((string) $staleUser->getEmail(), "A deleted account's address");
    }

    /**
     * Log retention and the 18+ acknowledgements are different things kept in
     * different places, and the reason those timestamps are the canonical
     * evidence is that no log policy can reach them.
     */
    public function testClearingExpiredLogsCannotTouchTheAcknowledgementTimestamps(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'ack-owner@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();

        $recipient = UserFactory::createOne(['email' => 'ack-recipient@test.local']);
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => $recipient->getEmail(),
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $share = $entityManager->getRepository(ComicShare::class)->findOneBy(['comic' => $comic->getId()]);
        self::assertNotNull($share->getSenderResponsibilityAcceptedAt());

        static::getContainer()->get(LogRetentionService::class)->clean();

        $entityManager->clear();
        $reloaded = $entityManager->getRepository(ComicShare::class)->find($share->getId());
        self::assertNotNull($reloaded->getSenderResponsibilityAcceptedAt());
    }
}
