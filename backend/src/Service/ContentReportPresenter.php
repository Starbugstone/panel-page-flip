<?php

namespace App\Service;

use App\Entity\ContentReport;

/** Builds the admin API representation of content reports. */
final class ContentReportPresenter
{
    public function __construct(
        private readonly ContentReportTargetResolver $resolver,
        private readonly ContentReportTargetPresenter $targets,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(ContentReport $report): array
    {
        return [
            'id' => $report->getId(),
            'reference' => $report->getReference(),
            'status' => $report->getStatus(),
            'category' => $report->getCategory(),
            'reporterDisplay' => $report->getReporterOrganization() ?: $report->getReporterName(),
            'createdAt' => $report->getCreatedAt()->format(DATE_ATOM),
            'reviewedAt' => $report->getReviewedAt()?->format(DATE_ATOM),
            'linkedTarget' => $this->targets->linked($report),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(ContentReport $report, ?string $query = null, bool $resolveCandidates = true): array
    {
        $comic = $report->getLinkedComic();
        $share = $report->getLinkedShare();
        $user = $report->getLinkedUser();
        $reviewer = $report->getReviewedByAdmin();

        return [
            'id' => $report->getId(),
            'reference' => $report->getReference(),
            'status' => $report->getStatus(),
            'category' => $report->getCategory(),
            'reporterName' => $report->getReporterName(),
            'reporterOrganization' => $report->getReporterOrganization(),
            'reporterRole' => $report->getReporterRole(),
            'reporterEmail' => $report->getReporterEmail(),
            'referenceType' => $report->getReferenceType(),
            'reportedReference' => $report->getReportedReference(),
            'reportedContentTitle' => $report->getReportedContentTitle(),
            'reportedAccountReference' => $report->getReportedAccountReference(),
            'sourceContext' => $report->getSourceContext(),
            'explanation' => $report->getExplanation(),
            'goodFaithAcknowledgedAt' => $report->getGoodFaithAcknowledgedAt()->format(DATE_ATOM),
            'createdAt' => $report->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $report->getUpdatedAt()->format(DATE_ATOM),
            'reviewedAt' => $report->getReviewedAt()?->format(DATE_ATOM),
            'reviewedBy' => $reviewer ? ['id' => $reviewer->getId(), 'name' => $reviewer->getName()] : null,
            'resolutionCode' => $report->getResolutionCode(),
            'resolutionNote' => $report->getResolutionNote(),
            'legalHold' => $report->isLegalHold(),
            'resolutionMethod' => $report->getResolutionMethod(),
            'linkedTarget' => $this->targets->linked($report),
            'targetSnapshot' => [
                'userId' => $report->getLinkedUserIdSnapshot(),
                'comicId' => $report->getLinkedComicIdSnapshot(),
                'shareId' => $report->getLinkedShareIdSnapshot(),
                'comicTitle' => $report->getLinkedComicTitleSnapshot(),
            ],
            'targetResolution' => $resolveCandidates
                ? $this->resolver->resolve($report, $query)
                : $this->targets->settledResolution($report),
            'linkedUser' => $user ? [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'sharingRestricted' => $user->isSharingRestricted(),
            ] : null,
            'linkedComic' => $comic ? [
                'id' => $comic->getId(),
                'title' => $comic->getTitle(),
                'owner' => $comic->getOwner() ? ['id' => $comic->getOwner()->getId(), 'name' => $comic->getOwner()->getName()] : null,
                'sharingRestricted' => $comic->isSharingRestricted(),
                'quarantined' => $comic->isQuarantined(),
            ] : null,
            'linkedShare' => $share ? [
                'id' => $share->getId(),
                'status' => $share->getStatus(),
                'title' => $share->getComic()?->getTitle() ?? $share->getComicTitleSnapshot(),
            ] : null,
        ];
    }
}
