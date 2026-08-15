<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ContentReport;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class ContentRestrictionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareService $shares,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    public function apply(string $action, ContentReport $report, User $admin): void
    {
        $comic = $report->getLinkedComic();
        $user = $report->getLinkedUser() ?? $comic?->getOwner();

        match ($action) {
            'restrict_sharing' => $this->restrictSharing($this->requireComic($comic), $report, $admin),
            'lift_sharing_restriction' => $this->liftSharingRestriction($this->requireComic($comic), $report, $admin),
            'revoke_all_shares' => $this->revokeAllShares($this->requireComic($comic), $report, $admin),
            'quarantine_content' => $this->quarantine($this->requireComic($comic), $report, $admin),
            'lift_quarantine' => $this->liftQuarantine($this->requireComic($comic), $report, $admin),
            'restrict_user_sharing' => $this->restrictUser($this->requireUser($user), $report, $admin),
            'lift_user_sharing_restriction' => $this->liftUserRestriction($this->requireUser($user), $report, $admin),
            'none', '' => null,
            default => throw new \DomainException('Invalid administrative content action.'),
        };
    }

    private function restrictSharing(Comic $comic, ContentReport $report, User $admin): void
    {
        $comic->restrictSharing();
        $this->audit(SecurityAuditLogger::CONTENT_REPORT_SHARING_RESTRICTED, $report, $admin, $comic);
    }

    private function liftSharingRestriction(Comic $comic, ContentReport $report, User $admin): void
    {
        $comic->liftSharingRestriction();
        $this->audit(SecurityAuditLogger::CONTENT_REPORT_RESTRICTION_LIFTED, $report, $admin, $comic);
    }

    private function revokeAllShares(Comic $comic, ContentReport $report, User $admin): void
    {
        $revoked = $this->shares->stopSharing($comic);
        $this->auditLogger->audit(SecurityAuditLogger::CONTENT_REPORT_SHARING_RESTRICTED, [
            'actor_user_id' => $admin->getId(),
            'target_type' => 'content_report',
            'target_id' => $report->getId(),
            'report_id' => $report->getId(),
            'comic_id' => $comic->getId(),
            'shares_revoked' => $revoked,
            'action' => 'revoke_all_shares',
        ]);
    }

    private function quarantine(Comic $comic, ContentReport $report, User $admin): void
    {
        $comic->quarantine();
        $this->audit(SecurityAuditLogger::CONTENT_REPORT_CONTENT_QUARANTINED, $report, $admin, $comic);
    }

    private function liftQuarantine(Comic $comic, ContentReport $report, User $admin): void
    {
        $comic->liftQuarantine();
        $this->audit(SecurityAuditLogger::CONTENT_REPORT_RESTRICTION_LIFTED, $report, $admin, $comic);
    }

    private function restrictUser(User $user, ContentReport $report, User $admin): void
    {
        $user->restrictSharing();
        $this->auditLogger->audit(SecurityAuditLogger::CONTENT_REPORT_SHARING_RESTRICTED, [
            'actor_user_id' => $admin->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'content_report',
            'target_id' => $report->getId(),
            'report_id' => $report->getId(),
            'action' => 'restrict_user_sharing',
        ]);
    }

    private function liftUserRestriction(User $user, ContentReport $report, User $admin): void
    {
        $user->liftSharingRestriction();
        $this->auditLogger->audit(SecurityAuditLogger::CONTENT_REPORT_RESTRICTION_LIFTED, [
            'actor_user_id' => $admin->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'content_report',
            'target_id' => $report->getId(),
            'report_id' => $report->getId(),
            'action' => 'lift_user_sharing_restriction',
        ]);
    }

    private function audit(string $event, ContentReport $report, User $admin, Comic $comic): void
    {
        $this->auditLogger->audit($event, [
            'actor_user_id' => $admin->getId(),
            'target_user_id' => $comic->getOwner()?->getId(),
            'target_type' => 'content_report',
            'target_id' => $report->getId(),
            'report_id' => $report->getId(),
            'comic_id' => $comic->getId(),
        ]);
    }

    private function requireComic(?Comic $comic): Comic
    {
        return $comic ?? throw new \DomainException('Link a comic before applying this action.');
    }

    private function requireUser(?User $user): User
    {
        return $user ?? throw new \DomainException('Link a user before applying this action.');
    }
}
