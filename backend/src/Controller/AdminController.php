<?php

namespace App\Controller;

use App\Entity\AdminAuditLog;
use App\Entity\Comic;
use App\Entity\User;
use App\Repository\AdminAuditLogRepository;
use App\Service\AdminAuditService;
use App\Service\ComicCleanupService;
use App\Service\DropboxImportService;
use App\Service\Pagination\PaginationRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/admin', name: 'api_admin_')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly CacheInterface $cache,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $stats = $this->cache->get('admin.stats.v1', function (ItemInterface $item) use ($entityManager): array {
            $item->expiresAfter(60);

            $totalUsers = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(u.id)')
                ->from(User::class, 'u')
                ->getQuery()
                ->getSingleScalarResult();

            $verifiedUsers = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(u.id)')
                ->from(User::class, 'u')
                ->where('u.isEmailVerified = :verified')
                ->setParameter('verified', true)
                ->getQuery()
                ->getSingleScalarResult();

            $totalComics = (int) $entityManager->createQueryBuilder()
                ->select('COUNT(c.id)')
                ->from(Comic::class, 'c')
                ->getQuery()
                ->getSingleScalarResult();

            $storageUsed = (int) $entityManager->createQueryBuilder()
                ->select('COALESCE(SUM(c.fileSize), 0)')
                ->from(Comic::class, 'c')
                ->getQuery()
                ->getSingleScalarResult();

            $signups = $entityManager->getRepository(User::class)->findBy([], ['createdAt' => 'DESC'], 10);

            return [
                'totalUsers' => $totalUsers,
                'verifiedUsers' => $verifiedUsers,
                'totalComics' => $totalComics,
                'storageUsed' => $storageUsed,
                'recentSignups' => array_map(fn (User $user): array => $this->serializeUser($user), $signups),
            ];
        });

        return $this->json(['stats' => $stats]);
    }

    #[Route('/dropbox-users', name: 'dropbox_users', methods: ['GET'])]
    public function dropboxUsers(EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $users = $entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.dropboxAccessToken IS NOT NULL')
            ->andWhere('u.dropboxAccessToken != :empty')
            ->setParameter('empty', '')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();

        // One grouped count rather than walking every user's whole comic
        // collection in PHP. The old loop lazy-loaded each connected user's
        // entire library — every row, every column — to compare one string, so
        // opening this page hydrated the comic table once per Dropbox user.
        $comicCounts = $entityManager->getRepository(Comic::class)->countByOwnerWithDescription(
            array_map(static fn (User $user): int => $user->getId(), $users),
            DropboxImportService::IMPORT_DESCRIPTION
        );

        return $this->json([
            'users' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'lastSyncedAt' => $user->getDropboxLastSyncedAt()?->format('c'),
                'dropboxComicCount' => $comicCounts[$user->getId()] ?? 0,
            ], $users),
        ]);
    }

    #[Route('/dropbox-users/{id}/sync', name: 'dropbox_user_sync', methods: ['POST'])]
    public function forceDropboxSync(int $id, EntityManagerInterface $entityManager, AdminAuditService $auditService): JsonResponse
    {
        $admin = $this->getAdminUser();
        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser || !$targetUser->getDropboxAccessToken()) {
            return $this->json(['message' => 'Dropbox user not found'], Response::HTTP_NOT_FOUND);
        }

        $process = new Process([PHP_BINARY, 'bin/console', 'app:dropbox-sync', '--user-id=' . $id, '--limit=10'], $this->projectDir);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->json(['message' => 'Dropbox sync failed', 'output' => $process->getErrorOutput()], Response::HTTP_BAD_GATEWAY);
        }

        $auditService->log($admin, 'dropbox_force_sync', 'user', $targetUser->getId(), ['email' => $targetUser->getEmail()]);
        $entityManager->flush();

        return $this->json(['message' => 'Dropbox sync completed', 'output' => $process->getOutput()]);
    }

    #[Route('/dropbox-users/{id}/disconnect', name: 'dropbox_user_disconnect', methods: ['POST'])]
    public function disconnectDropboxUser(int $id, EntityManagerInterface $entityManager, AdminAuditService $auditService): JsonResponse
    {
        $admin = $this->getAdminUser();
        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser || !$targetUser->getDropboxAccessToken()) {
            return $this->json(['message' => 'Dropbox user not found'], Response::HTTP_NOT_FOUND);
        }

        $targetUser->setDropboxAccessToken(null);
        $targetUser->setDropboxRefreshToken(null);
        $auditService->log($admin, 'dropbox_disconnect', 'user', $targetUser->getId(), ['email' => $targetUser->getEmail()]);
        $entityManager->flush();

        return $this->json(['message' => 'Dropbox disconnected']);
    }

    #[Route('/cleanup/dry-run', name: 'cleanup_dry_run', methods: ['POST'])]
    public function cleanupDryRun(ComicCleanupService $cleanupService): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->json(['cleanup' => $cleanupService->scan()]);
    }

    #[Route('/cleanup/apply', name: 'cleanup_apply', methods: ['POST'])]
    public function cleanupApply(ComicCleanupService $cleanupService, AdminAuditService $auditService, EntityManagerInterface $entityManager): JsonResponse
    {
        $admin = $this->getAdminUser();
        $result = $cleanupService->apply();
        $auditService->log($admin, 'quarantine_orphan_files', 'filesystem', null, $result['quarantined'] ?? null);
        $entityManager->flush();

        return $this->json(['cleanup' => $result]);
    }

    #[Route('/audit-logs', name: 'audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $pagination = PaginationRequest::fromRequest($request, AdminAuditLogRepository::ADMIN_SORT_FIELDS, 'createdAt');

        /** @var AdminAuditLogRepository $repository */
        $repository = $entityManager->getRepository(AdminAuditLog::class);
        $page = $repository->findAdminPage(
            $pagination,
            $request->query->get('action'),
            $request->query->get('targetType'),
        );

        $logs = array_map(fn (AdminAuditLog $log): array => [
            'id' => $log->getId(),
            'admin' => $this->serializeUser($log->getAdminUser()),
            'action' => $log->getAction(),
            'targetType' => $log->getTargetType(),
            'targetId' => $log->getTargetId(),
            'payload' => $log->getPayload(),
            'createdAt' => $log->getCreatedAt()->format('c'),
        ], $page->items);

        return $this->json([
            'items' => $logs,
            'logs' => $logs,
            'pagination' => $page->toArray(),
            'filters' => $repository->findFilterOptions(),
        ]);
    }

    private function getAdminUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function serializeUser(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'createdAt' => $user->getCreatedAt()?->format('c'),
            'isEmailVerified' => $user->isEmailVerified(),
        ];
    }
}
