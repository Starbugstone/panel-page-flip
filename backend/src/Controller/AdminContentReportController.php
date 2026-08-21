<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\User;
use App\Repository\ContentReportRepository;
use App\Service\ContentReportNotifier;
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
            'reports' => array_map($this->serialize(...), $reports->findForAdmin(
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
    public function detail(ContentReport $report): JsonResponse
    {
        return $this->json(['report' => $this->serialize($report)]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        ContentReport $report,
        Request $request,
        EntityManagerInterface $entityManager,
        ContentRestrictionService $restrictions,
        ContentReportNotifier $notifier,
        SecurityAuditLogger $auditLogger,
    ): JsonResponse {
        $admin = $this->admin();
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if (array_key_exists('linkedUserId', $data)) {
                $report->linkUser($this->findNullable($entityManager, User::class, $data['linkedUserId']));
            }
            if (array_key_exists('linkedComicId', $data)) {
                $comic = $this->findNullable($entityManager, Comic::class, $data['linkedComicId']);
                $report->linkComic($comic);
                if ($comic instanceof Comic && $report->getLinkedUser() === null) {
                    $report->linkUser($comic->getOwner());
                }
            }
            if (array_key_exists('linkedShareId', $data)) {
                $share = $this->findNullable($entityManager, ComicShare::class, $data['linkedShareId']);
                $report->linkShare($share);
                if ($share instanceof ComicShare) {
                    $report->linkComic($report->getLinkedComic() ?? $share->getComic());
                    $report->linkUser($report->getLinkedUser() ?? $share->getOwner());
                }
            }

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

        return $this->json(['report' => $this->serialize($report)]);
    }

    /** @return array<string, mixed> */
    private function serialize(ContentReport $report): array
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
            'reportedReference' => $report->getReportedReference(),
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
            'linkedUser' => $user ? [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'sharingRestricted' => $user->isSharingRestricted(),
            ] : null,
            'linkedComic' => $comic ? [
                'id' => $comic->getId(),
                'title' => $comic->getTitle(),
                'sharingRestricted' => $comic->isSharingRestricted(),
                'quarantined' => $comic->isQuarantined(),
            ] : null,
            'linkedShare' => $share ? [
                'id' => $share->getId(),
                'status' => $share->getStatus(),
            ] : null,
        ];
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
