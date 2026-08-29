<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ContentReport;
use App\Entity\User;

final class ContentRestrictionService
{
    /**
     * Every administrative action, its label, and the target it needs.
     *
     * Served to the queue alongside the statuses and categories, so the admin
     * screen renders the options rather than holding its own copy of them. A
     * requirement stated in two places eventually offers an action that always
     * fails, or hides one that would have worked.
     *
     * @var list<array{value: string, label: string, requires: string|null}>
     */
    public const ACTIONS = [
        ['value' => 'none', 'label' => 'No content action', 'requires' => null],
        ['value' => 'restrict_sharing', 'label' => 'Restrict sharing for comic', 'requires' => 'comic'],
        ['value' => 'lift_sharing_restriction', 'label' => 'Lift comic sharing restriction', 'requires' => 'comic'],
        ['value' => 'revoke_all_shares', 'label' => 'Revoke all shares', 'requires' => 'comic'],
        ['value' => 'quarantine_content', 'label' => 'Quarantine comic', 'requires' => 'comic'],
        ['value' => 'lift_quarantine', 'label' => 'Lift comic quarantine', 'requires' => 'comic'],
        ['value' => 'restrict_user_sharing', 'label' => 'Restrict account sharing', 'requires' => 'user'],
        ['value' => 'lift_user_sharing_restriction', 'label' => 'Lift account sharing restriction', 'requires' => 'user'],
    ];

    public function __construct(
        private readonly ComicShareService $shares,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    public function apply(string $action, ContentReport $report, User $admin): void
    {
        // Target consistency is ContentReportLinkService::assertCanonical()'s
        // rule, enforced on the way in. Re-checking it here gave the same
        // invariant a second implementation and a second error message.
        $comic = $report->getLinkedComic();
        $user = $report->getLinkedUser();

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
