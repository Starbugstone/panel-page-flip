<?php

namespace App\Controller;

use App\Entity\ContentReport;
use App\Entity\User;
use App\Repository\ContentReportRepository;
use App\Service\ContentReportLinkService;
use App\Service\ContentReportNotifier;
use App\Service\ContentReportPresenter;
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
    public function list(Request $request, ContentReportRepository $reports, ContentReportPresenter $presenter): JsonResponse
    {
        try {
            $from = $this->date($request->query->get('from'));
            $to = $this->date($request->query->get('to'));
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'reports' => array_map($presenter->summary(...), $reports->findForAdmin(
                $request->query->get('status'),
                $request->query->get('category'),
                $from,
                $to,
            )),
            'statuses' => ContentReport::STATUSES,
            'categories' => ContentReport::CATEGORIES,
            'actions' => ContentRestrictionService::ACTIONS,
        ]);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(ContentReport $report, Request $request, ContentReportPresenter $presenter): JsonResponse
    {
        return $this->json(['report' => $presenter->detail($report, $request->query->get('q'))]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(
        ContentReport $report,
        Request $request,
        EntityManagerInterface $entityManager,
        ContentRestrictionService $restrictions,
        ContentReportLinkService $linker,
        ContentReportPresenter $presenter,
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
                if (!array_key_exists('targetType', $data) || !array_key_exists('targetId', $data)) {
                    throw new \DomainException('A target type and integer target ID are required together.');
                }
                // An explicit pair of nulls is how the queue says "this is not
                // the right record". Without it a wrong target — including one
                // linked automatically from the reference the reporter supplied
                // — could be swapped for another but never cleared.
                if ($data['targetType'] === null && $data['targetId'] === null) {
                    $linker->unlink($report);
                } elseif (!is_string($data['targetType'] ?? null) || !is_int($data['targetId'] ?? null)) {
                    throw new \DomainException('A target type and integer target ID are required together.');
                } else {
                    $linker->select($report, $data['targetType'], $data['targetId'], 'admin_selection');
                }
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
            $auditLogger->audit(
                SecurityAuditLogger::CONTENT_REPORT_TARGET_LINKED,
                ContentReportLinkService::targetLinkedPayload($report, $previousTarget) + ['actor_user_id' => $admin->getId()],
            );
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

        // No candidate search on the way out. The admin has just chosen the
        // target, so re-running it would spend six leading-wildcard scans over
        // the comic and user tables to answer a question that has been settled
        // — and would throw away the search they were looking at.
        return $this->json(['report' => $presenter->detail($report, resolveCandidates: false)]);
    }

    /** @return array{user: int|null, comic: int|null, share: int|null} */
    private function targetIds(ContentReport $report): array
    {
        return ['user' => $report->getLinkedUser()?->getId(), 'comic' => $report->getLinkedComic()?->getId(), 'share' => $report->getLinkedShare()?->getId()];
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
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
