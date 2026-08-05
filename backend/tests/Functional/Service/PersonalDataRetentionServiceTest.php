<?php

namespace App\Tests\Functional\Service;

use App\Entity\AdminAuditLog;
use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordToken;
use App\Service\PersonalDataRetentionService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PersonalDataRetentionServiceTest extends AbstractApiTestCase
{
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
        self::assertSame(0, $counts['errors']);

        $entityManager->clear();
        self::assertNull($entityManager->find(\App\Entity\User::class, $staleUserId));
        self::assertCount(1, $entityManager->getRepository(AdminAuditLog::class)->findAll());
    }
}
