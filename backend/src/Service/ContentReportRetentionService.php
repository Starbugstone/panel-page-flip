<?php

namespace App\Service;

use App\Repository\ContentReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContentReportRetentionService
{
    public function __construct(
        private readonly ContentReportRepository $reports,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityAuditLogger $auditLogger,
        #[Autowire('%content_report_retention_days%')] private readonly int $retentionDays,
    ) {
    }

    public function cleanup(?\DateTimeImmutable $now = null, int $limit = 100): int
    {
        $now ??= new \DateTimeImmutable();
        $before = $now->modify(sprintf('-%d days', max(1, $this->retentionDays)));
        $expired = $this->reports->findExpiredClosed($before, $limit);

        foreach ($expired as $report) {
            $this->entityManager->remove($report);
        }
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::RETENTION_CLEANUP, [
            'target_type' => 'content_report',
            'records_removed' => count($expired),
            'retention_days' => max(1, $this->retentionDays),
        ]);

        return count($expired);
    }
}
