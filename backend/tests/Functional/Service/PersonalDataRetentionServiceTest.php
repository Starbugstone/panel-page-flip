<?php

namespace App\Tests\Functional\Service;

use App\Entity\AdminAuditLog;
use App\Entity\ComicShare;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordToken;
use App\Service\LogRetentionService;
use App\Service\PersonalDataRetentionService;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

final class PersonalDataRetentionServiceTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    public function testCleanupAppliesConfiguredRetentionPeriods(): void
    {
        $now = new \DateTimeImmutable('2026-08-05 12:00:00+00:00');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tokenOwner = UserFactory::createOne()->object();
        $staleUser = UserFactory::new()->unverified()->create([
            'createdAt' => $now->modify('-31 days'),
        ])->object();
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
     * The sweep deletes accounts nobody asked it to, so it has to say what it
     * did. Without this, an account vanishing has no explanation anywhere.
     */
    public function testTheCleanupLeavesAnAuditTrailOfItsOwn(): void
    {
        $now = new \DateTimeImmutable('2026-08-05 12:00:00+00:00');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $staleUser = UserFactory::new()->unverified()->create(['createdAt' => $now->modify('-31 days')])->object();
        $staleUserId = $staleUser->getId();

        $tokenOwner = UserFactory::createOne()->object();
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
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();

        $recipient = UserFactory::createOne(['email' => 'ack-recipient@test.local'])->object();
        $this->postJson('/api/shares/comics/' . $comic->getId() . '/invitations', [
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
