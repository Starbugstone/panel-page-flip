<?php

namespace App\Tests\Functional\Service;

use App\Entity\ContentReport;
use App\Service\ContentReportRetentionService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ContentReportRetentionServiceTest extends AbstractApiTestCase
{
    public function testOnlyExpiredClosedReportsWithoutLegalHoldAreRemoved(): void
    {
        $admin = UserFactory::new()->admin()->create()->object();
        $expired = $this->report()->reviewAs($admin, ContentReport::STATUS_CLOSED);
        $open = $this->report();
        $held = $this->report()->reviewAs($admin, ContentReport::STATUS_REJECTED)->setLegalHold(true);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($expired);
        $entityManager->persist($open);
        $entityManager->persist($held);
        $entityManager->flush();
        $expiredId = $expired->getId();
        $openId = $open->getId();
        $heldId = $held->getId();

        $removed = static::getContainer()->get(ContentReportRetentionService::class)
            ->cleanup(new \DateTimeImmutable('+731 days'));

        self::assertSame(1, $removed);
        self::assertNull($entityManager->find(ContentReport::class, $expiredId));
        self::assertNotNull($entityManager->find(ContentReport::class, $openId));
        self::assertNotNull($entityManager->find(ContentReport::class, $heldId));
    }

    private function report(): ContentReport
    {
        return new ContentReport(
            'Reporter',
            uniqid('reporter-', true).'@example.com',
            ContentReport::CATEGORY_OTHER_ILLEGAL,
            'Reference supplied by the reporter',
            'A substantive explanation with enough identifying context for an administrator to assess the report.'
        );
    }
}
