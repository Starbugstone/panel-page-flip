<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\User;
use App\Repository\ContentReportRepository;
use App\Service\ContentReportNotifier;
use App\Service\ContentReportLinkService;
use App\Service\ContentReportTargetResolver;
use App\Service\ContentRestrictionService;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/content-reports', name: 'api_admin_content_reports_')]
#[IsGranted('ROLE_ADMIN')]
final class AdminContentReportController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, ContentReportRepository $reports): JsonResponse
    {
        try {
            $from = $this->date($request->query->get('from'));
            $to = $this->date($request->query->get('to'));
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'reports' => array_map($this->serializeSummary(...), $reports->findForAdmin(
                $request->query->get('status'),
                $request->query->get('category'),
                $from,
                $to,
            )),
            'statuses' => ContentReport::STATUSES,
            'categories' => ContentReport::CATEGORIES,
        ]);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(ContentReport $report, Request $request, ContentReportTargetResolver $resolver): JsonResponse
    {
        return $this->json(['report' => $this->serializeDetail($report, $resolver, $request->query->get('q'))]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        ContentReport $report,
        Request $request,
        EntityManagerInterface $entityManager,
        ContentRestrictionService $restrictions,
        ContentReportLinkService $linker,
        ContentReportTargetResolver $resolver,
        ContentReportNotifier $notifier,
        SecurityAuditLogger $auditLogger,
    ): JsonResponse {
        $admin = $this->admin();
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $previousTarget = $this->targetIds($report);
            if (array_key_exists('targetType', $data) || array_key_exists('targetId', $data)) {
                if (!is_string($data['targetType'] ?? null) || !is_int($data['targetId'] ?? null)) {
                    throw new \DomainException('A target type and integer target ID are required together.');
                }
                $linker->select($report, $data['targetType'], $data['targetId'], 'admin_selection');
            } elseif (array_intersect(['linkedUserId', 'linkedComicId', 'linkedShareId'], array_keys($data)) !== []) {
                $this->applyLegacyTarget($report, $data, $entityManager, $linker);
            }
            $linker->assertCanonical($report);

            $status = (string) ($data['status'] ?? ContentReport::STATUS_UNDER_REVIEW);
            $report->reviewAs($admin, $status)->resolve(
                $this->nullableBoundedString($data['resolutionCode'] ?? null, 64, 'Resolution code'),
                $this->nullableBoundedString($data['resolutionNote'] ?? null, 10000, 'Resolution note'),
            );
            if (array_key_exists('legalHold', $data)) {
                $report->setLegalHold($data['legalHold'] === true);
            }

            $action = (string) ($data['action'] ?? 'none');
            $restrictions->apply($action, $report, $admin);
            $entityManager->flush();
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $statusEvent = match ($report->getStatus()) {
            ContentReport::STATUS_REJECTED => SecurityAuditLogger::CONTENT_REPORT_REJECTED,
            ContentReport::STATUS_CLOSED => SecurityAuditLogger::CONTENT_REPORT_CLOSED,
            default => SecurityAuditLogger::CONTENT_REPORT_REVIEW_STARTED,
        };
        $auditLogger->audit($statusEvent, [
            'actor_user_id' => $admin->getId(),
            'target_type' => 'content_report',
            'target_id' => $report->getId(),
            'report_id' => $report->getId(),
            'status' => $report->getStatus(),
        ]);
        $linkedTarget = $this->targetIds($report);
        if ($linkedTarget !== $previousTarget) {
            $auditLogger->audit(SecurityAuditLogger::CONTENT_REPORT_TARGET_LINKED, [
                'actor_user_id' => $admin->getId(),
                'target_type' => 'content_report',
                'target_id' => $report->getId(),
                'report_id' => $report->getId(),
                'previous_linked_user_id' => $previousTarget['user'],
                'linked_user_id' => $linkedTarget['user'],
                'previous_linked_comic_id' => $previousTarget['comic'],
                'linked_comic_id' => $linkedTarget['comic'],
                'previous_linked_share_id' => $previousTarget['share'],
                'linked_share_id' => $linkedTarget['share'],
                'resolution_method' => $report->getResolutionMethod(),
            ]);
        }

        $owner = $report->getLinkedComic()?->getOwner() ?? $report->getLinkedUser();
        if (($data['notifyOwner'] ?? false) === true && $owner instanceof User && $notifier->notifyOwner($report, $owner, $action)) {
            $auditLogger->audit(SecurityAuditLogger::CONTENT_REPORT_USER_NOTIFIED, [
                'actor_user_id' => $admin->getId(),
                'target_user_id' => $owner->getId(),
                'target_type' => 'content_report',
                'target_id' => $report->getId(),
                'report_id' => $report->getId(),
            ]);
        }

        return $this->json(['report' => $this->serializeDetail($report, $resolver)]);
    }

    /** @return array<string, mixed> */
    private function serializeSummary(ContentReport $report): array
    {
        return [
            'id' => $report->getId(),
            'reference' => $report->getReference(),
            'status' => $report->getStatus(),
            'category' => $report->getCategory(),
            'reporterDisplay' => $report->getReporterOrganization() ?: $report->getReporterName(),
            'createdAt' => $report->getCreatedAt()->format(DATE_ATOM),
            'reviewedAt' => $report->getReviewedAt()?->format(DATE_ATOM),
            'linkedTarget' => $this->linkedTargetSummary($report),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDetail(ContentReport $report, ContentReportTargetResolver $resolver, ?string $query = null): array
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
            'reviewedBy' => $reviewer ? [
                'id' => $reviewer->getId(),
                'name' => $reviewer->getName(),
            ] : null,
            'resolutionCode' => $report->getResolutionCode(),
            'resolutionNote' => $report->getResolutionNote(),
            'legalHold' => $report->isLegalHold(),
            'resolutionMethod' => $report->getResolutionMethod(),
            'targetSnapshot' => [
                'userId' => $report->getLinkedUserIdSnapshot(),
                'comicId' => $report->getLinkedComicIdSnapshot(),
                'shareId' => $report->getLinkedShareIdSnapshot(),
                'comicTitle' => $report->getLinkedComicTitleSnapshot(),
            ],
            'targetResolution' => $resolver->resolve($report, $query),
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

    /** @return array<string, mixed>|null */
    private function linkedTargetSummary(ContentReport $report): ?array
    {
        if ($report->getLinkedShare() !== null) {
            return ['type' => 'share', 'id' => $report->getLinkedShare()->getId(), 'label' => $report->getLinkedShare()->getComic()?->getTitle() ?? $report->getLinkedShare()->getComicTitleSnapshot()];
        }
        if ($report->getLinkedComic() !== null) {
            return ['type' => 'comic', 'id' => $report->getLinkedComic()->getId(), 'label' => $report->getLinkedComic()->getTitle()];
        }
        if ($report->getLinkedUser() !== null) {
            return ['type' => 'user', 'id' => $report->getLinkedUser()->getId(), 'label' => $report->getLinkedUser()->getName()];
        }
        if ($report->getLinkedComicIdSnapshot() !== null || $report->getLinkedUserIdSnapshot() !== null || $report->getLinkedShareIdSnapshot() !== null) {
            return ['type' => 'snapshot', 'id' => $report->getLinkedShareIdSnapshot() ?? $report->getLinkedComicIdSnapshot() ?? $report->getLinkedUserIdSnapshot(), 'label' => $report->getLinkedComicTitleSnapshot() ?: 'Deleted linked record'];
        }
        return null;
    }

    /** @return array{user: int|null, comic: int|null, share: int|null} */
    private function targetIds(ContentReport $report): array
    {
        return ['user' => $report->getLinkedUser()?->getId(), 'comic' => $report->getLinkedComic()?->getId(), 'share' => $report->getLinkedShare()?->getId()];
    }

    /** @param array<string, mixed> $data */
    private function applyLegacyTarget(ContentReport $report, array $data, EntityManagerInterface $entityManager, ContentReportLinkService $linker): void
    {
        $share = array_key_exists('linkedShareId', $data) ? $this->findNullable($entityManager, ComicShare::class, $data['linkedShareId']) : null;
        $comic = array_key_exists('linkedComicId', $data) ? $this->findNullable($entityManager, Comic::class, $data['linkedComicId']) : null;
        $user = array_key_exists('linkedUserId', $data) ? $this->findNullable($entityManager, User::class, $data['linkedUserId']) : null;

        if ($share instanceof ComicShare) {
            if ($comic instanceof Comic && $share->getComic()?->getId() !== $comic->getId()) throw new \DomainException('The selected share does not belong to the selected comic.');
            if ($user instanceof User && $share->getOwner()?->getId() !== $user->getId()) throw new \DomainException('The selected user does not own the selected share.');
            $linker->linkShare($report, $share, 'legacy_admin_selection');
        } elseif ($comic instanceof Comic) {
            if ($user instanceof User && $comic->getOwner()?->getId() !== $user->getId()) throw new \DomainException('The selected user does not own the selected comic.');
            $linker->linkComic($report, $comic, 'legacy_admin_selection');
        } elseif ($user instanceof User) {
            $linker->linkUser($report, $user, 'legacy_admin_selection');
        }
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function findNullable(EntityManagerInterface $entityManager, string $class, mixed $id): ?object
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new \DomainException('Linked record identifiers must be integers or null.');
        }
        return $entityManager->getRepository($class)->find((int) $id)
            ?? throw new \DomainException('A linked record could not be found.');
    }

    private function nullableBoundedString(mixed $value, int $max, string $label): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || mb_strlen($value) > $max) {
            throw new \DomainException(sprintf('%s must be %d characters or fewer.', $label, $max));
        }
        return trim($value) ?: null;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Date filters must use YYYY-MM-DD.');
        }
        return $date;
    }
}
