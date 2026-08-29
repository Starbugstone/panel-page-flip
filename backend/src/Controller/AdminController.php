<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminAuditLog;
use App\Entity\Comic;
use App\Entity\User;
use App\Repository\ComicRepository;
use App\Repository\AdminAuditLogRepository;
use App\Service\AdminAuditService;
use App\Service\AppDataEncryptionService;
use App\Service\ComicCleanupService;
use App\Service\ComicFormatService;
use App\Service\ComicPageDelivery;
use App\Enum\ComicSourceType;
use App\Service\DropboxImportService;
use App\Service\MetadataProviderConfigurationService;
use App\Service\MetadataProviderRegistry;
use App\Service\Pagination\PaginationRequest;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Keep the administrative boundary structural so every action inherits it. */
#[Route('/api/admin', name: 'api_admin_')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private const SECRET_COLUMN_LENGTH = 1024;

    public function __construct(
        private readonly CacheInterface $cache,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%metron_shared_enabled%')]
        private readonly bool $metronSharedAllowedByEnvironment = false,
        #[Autowire('%comic_vine_shared_enabled%')]
        private readonly bool $comicVineSharedAllowedByEnvironment = false
    ) {
    }

    #[Route('/comic-formats', name: 'comic_formats', methods: ['GET'])]
    public function comicFormats(ComicFormatService $formats, ComicPageDelivery $delivery): JsonResponse
    {
        return $this->json(['formats' => $formats->status(), 'delivery' => $delivery->describe()]);
    }

    #[Route('/comic-formats/verify', name: 'comic_formats_verify', methods: ['POST'])]
    public function verifyComicFormats(ComicFormatService $formats, ComicPageDelivery $delivery): JsonResponse
    {
        return $this->json(['formats' => $formats->status(true), 'delivery' => $delivery->describe()]);
    }

    #[Route('/comic-formats', name: 'comic_formats_update', methods: ['PUT'])]
    public function updateComicFormats(Request $request, ComicFormatService $formats, AdminAuditService $auditService, EntityManagerInterface $entityManager): JsonResponse
    {
        $data = \App\Http\JsonRequestDecoder::decode($request);
        if (!is_array($data) || !is_array($data['enabled'] ?? null)) return $this->json(['message' => 'Enabled formats must be an array.'], Response::HTTP_BAD_REQUEST);
        try {
            $enabled = array_map(static fn (mixed $value): ComicSourceType => ComicSourceType::from((string) $value), $data['enabled']);
            $formats->save($enabled);
            $saved = $formats->enabled();
            $auditService->log($this->getAdminUser(), 'comic_formats_updated', 'configuration', 1, ['enabled' => array_map(static fn (ComicSourceType $type): string => $type->value, $saved)]);
            $entityManager->flush();
        } catch (\ValueError|\RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        return $this->json(['formats' => $formats->status()]);
    }

    #[Route('/metadata-providers', name: 'metadata_providers', methods: ['GET'])]
    public function metadataProviders(
        MetadataProviderRegistry $providers,
        MetadataProviderConfigurationService $configuration
    ): JsonResponse {
        // Whether a provider is configured, never what it was configured with.
        // The quota alongside it is what the provider last told us about the
        // account, which is the only honest source for a daily budget.
        return $this->json([
            'providers' => $providers->adminStatus($this->sharedSecrets($configuration), $configuration),
            // The environment's half of each switch, so an administrator can
            // see why a toggle they turned on is not taking effect.
            'environment' => [
                'metronSharedEnabled' => $this->metronSharedAllowedByEnvironment,
                'comicVineEnabled' => $this->comicVineSharedAllowedByEnvironment,
            ],
            // Not per-provider: it governs whether users may bring a token at
            // all, for any provider.
            'settings' => ['personalCredentialsEnabled' => $configuration->arePersonalCredentialsEnabled()],
        ]);
    }

    /**
     * Try credentials against the live services and report what each said.
     *
     * Accepts credentials in the body so they can be tested before they are
     * saved — the alternative is storing something unverified and finding out
     * later that no lookup ever worked. Anything the body omits falls back to
     * what is already stored, so testing one provider does not mean retyping
     * the other's.
     */
    #[Route('/metadata-providers/verify', name: 'metadata_providers_verify', methods: ['POST'])]
    public function verifyMetadataProviders(
        Request $request,
        MetadataProviderConfigurationService $configuration,
        MetadataProviderRegistry $providers
    ): JsonResponse {
        $submitted = \App\Http\JsonRequestDecoder::decode($request);
        if (!is_array($submitted)) {
            $submitted = [];
        }

        $typed = static function (string $field) use ($submitted): ?string {
            $value = $submitted[$field] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $stored = $this->sharedSecrets($configuration);

        return $this->json([
            'results' => $providers->verify([
                'metron' => $typed('metronToken') ?? $stored['metron'],
                'comicvine' => $typed('comicVineApiKey') ?? $stored['comicvine'],
            ]),
        ]);
    }

    #[Route('/metadata-providers', name: 'metadata_providers_update', methods: ['PUT'])]
    public function updateMetadataProviders(
        Request $request,
        MetadataProviderConfigurationService $configuration,
        MetadataProviderRegistry $providers,
        AdminAuditService $auditService,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = \App\Http\JsonRequestDecoder::decode($request);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $settings = $configuration->get();

        foreach (['metronToken', 'comicVineApiKey'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];
            if ($value !== null && !is_string($value)) {
                return $this->json(['message' => sprintf('%s must be a string or null.', $field)], Response::HTTP_BAD_REQUEST);
            }

            // The column holds ciphertext, which is longer than what went into
            // it, so the limit comes from the column. Bytes rather than
            // characters: a multibyte value passes a character count and still
            // overflows, and that lands as a database error at flush time.
            if (is_string($value) && strlen(trim($value)) > AppDataEncryptionService::maxPlaintextBytes(self::SECRET_COLUMN_LENGTH)) {
                return $this->json(['message' => sprintf('%s is longer than a credential this provider issues.', $field)], Response::HTTP_BAD_REQUEST);
            }

            $settings->{'set'.ucfirst($field)}($value);
        }

        $switches = [
            'metronSharedEnabled' => 'setMetronSharedEnabled',
            'comicVineEnabled' => 'setComicVineEnabled',
            'personalCredentialsEnabled' => 'setPersonalCredentialsEnabled',
        ];

        foreach ($switches as $field => $setter) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            if (!is_bool($data[$field])) {
                return $this->json(['message' => sprintf('%s must be true or false.', $field)], Response::HTTP_BAD_REQUEST);
            }

            $settings->{$setter}($data[$field]);
        }

        // The values are secrets, so the audit trail records that they changed
        // and never which fields held what. The switches are not secrets and
        // are recorded in full: turning shared provider access on or off is
        // exactly the kind of change somebody later needs to be able to find.
        //
        // Logged before saving on purpose. The audit entry is persisted but not
        // flushed, so save()'s flush commits the change and its record in one
        // transaction — logging afterwards left a window where the credential
        // change landed and the record it requires did not.
        $auditService->log($this->getAdminUser(), 'metadata_providers_updated', 'configuration', 1, [
            'configured' => array_column($providers->adminStatus($this->sharedSecrets($configuration), $configuration), 'configured', 'key'),
            'metronSharedEnabled' => $configuration->isMetronSharedEnabled(),
            'comicVineEnabled' => $configuration->isComicVineEnabled(),
            'personalCredentialsEnabled' => $configuration->arePersonalCredentialsEnabled(),
        ]);
        $configuration->save();

        return $this->json([
            'providers' => $providers->adminStatus($this->sharedSecrets($configuration), $configuration),
            'environment' => [
                'metronSharedEnabled' => $this->metronSharedAllowedByEnvironment,
                'comicVineEnabled' => $this->comicVineSharedAllowedByEnvironment,
            ],
            'settings' => ['personalCredentialsEnabled' => $configuration->arePersonalCredentialsEnabled()],
        ]);
    }

    /**
     * The installation's secrets, keyed by provider.
     *
     * Never returned to a client. The registry needs them only to fingerprint
     * the upstream account for quota bookkeeping and to say whether a provider
     * is configured at all.
     *
     * @return array<string, string|null>
     */
    private function sharedSecrets(MetadataProviderConfigurationService $configuration): array
    {
        return [
            'metron' => $configuration->metronToken(),
            'comicvine' => $configuration->comicVineApiKey(),
        ];
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(EntityManagerInterface $entityManager, ComicRepository $comics): JsonResponse
    {
        $stats = $this->cache->get('admin.stats.v1', function (ItemInterface $item) use ($entityManager, $comics): array {
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

            // The same definition the per-user admin figures use, so the
            // installation total and the rows beneath it are the same accounting.
            $storageUsed = $comics->getTotalStorageBytes();

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
        // Either credential counts, matching User::hasDropboxConnection() and the
        // guards on the actions offered for each row. Listing only accounts with
        // a live access token hid the ones whose token had been cleared but
        // which the refresh token can still recover — and then offered
        // force-sync and disconnect for accounts that were not in the list.
        $users = $entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('COALESCE(u.dropboxAccessToken, :empty) != :empty')
            ->orWhere('COALESCE(u.dropboxRefreshToken, :empty) != :empty')
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
    public function forceDropboxSync(
        int $id,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $admin = $this->getAdminUser();
        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser || !$targetUser->hasDropboxConnection()) {
            return $this->json(['message' => 'Dropbox user not found'], Response::HTTP_NOT_FOUND);
        }

        $process = new Process([PHP_BINARY, 'bin/console', 'app:dropbox-sync', '--user-id=' . $id, '--limit=10'], $this->projectDir);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            // The command's own output is not carried into the log: it names
            // Dropbox paths and can echo an API URL with a credential in it.
            // What matters here is that an import an administrator asked for
            // did not happen, and which account's library may now be partial.
            $securityLogger->security(
                SecurityAuditLogger::DATA_INTEGRITY_FAILURE,
                [
                    'actor_user_id' => $admin->getId(),
                    'target_user_id' => $targetUser->getId(),
                    'target_type' => 'user',
                    'operation' => 'dropbox_force_sync',
                    'exit_code' => $process->getExitCode(),
                ],
                result: SecurityAuditLogger::RESULT_FAILED
            );

            return $this->json(
                ['message' => 'Dropbox import failed. Please try again or review the server logs.'],
                Response::HTTP_BAD_GATEWAY
            );
        }

        $auditService->log($admin, 'dropbox_force_sync', 'user', $targetUser->getId(), ['email' => $targetUser->getEmail()]);
        $entityManager->flush();

        return $this->json(['message' => 'Dropbox import completed', 'output' => $process->getOutput()]);
    }

    #[Route('/dropbox-users/{id}/disconnect', name: 'dropbox_user_disconnect', methods: ['POST'])]
    public function disconnectDropboxUser(
        int $id,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $admin = $this->getAdminUser();
        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser || !$targetUser->hasDropboxConnection()) {
            return $this->json(['message' => 'Dropbox user not found'], Response::HTTP_NOT_FOUND);
        }

        $targetUser->setDropboxAccessToken(null);
        $targetUser->setDropboxRefreshToken(null);
        $auditService->log($admin, 'dropbox_disconnect', 'user', $targetUser->getId(), ['email' => $targetUser->getEmail()]);
        $entityManager->flush();

        $securityLogger->audit(SecurityAuditLogger::INTEGRATION_DISCONNECTED, [
            'actor_user_id' => $admin->getId(),
            'target_user_id' => $targetUser->getId(),
            'target_type' => 'user',
            'integration' => 'dropbox',
            'disconnected_by_admin' => true,
        ]);

        return $this->json(['message' => 'Dropbox disconnected']);
    }

    #[Route('/cleanup/dry-run', name: 'cleanup_dry_run', methods: ['POST'])]
    public function cleanupDryRun(ComicCleanupService $cleanupService): JsonResponse
    {
        return $this->json(['cleanup' => $cleanupService->scan()]);
    }

    #[Route('/cleanup/apply', name: 'cleanup_apply', methods: ['POST'])]
    public function cleanupApply(
        ComicCleanupService $cleanupService,
        AdminAuditService $auditService,
        EntityManagerInterface $entityManager,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $admin = $this->getAdminUser();
        $result = $cleanupService->apply();
        $auditService->log($admin, 'quarantine_orphan_files', 'filesystem', null, $result['quarantined'] ?? null);
        $entityManager->flush();

        $found = (int) ($result['totals']['orphanedComics'] ?? 0) + (int) ($result['totals']['orphanedCovers'] ?? 0);
        $moved = (int) ($result['quarantined']['orphanedComics'] ?? 0) + (int) ($result['quarantined']['orphanedCovers'] ?? 0);

        $securityLogger->audit(SecurityAuditLogger::STORAGE_ORPHAN_QUARANTINE, [
            'actor_user_id' => $admin->getId(),
            'target_type' => 'filesystem',
            'files_found' => $found,
            'files_quarantined' => $moved,
        ]);

        // A file the scan identified and the move could not take is the case
        // worth waking somebody for: the library and the disk now disagree, and
        // the next scan will report the same file again without saying why.
        if ($moved < $found) {
            $securityLogger->critical(
                SecurityAuditLogger::DATA_INTEGRITY_FAILURE,
                [
                    'actor_user_id' => $admin->getId(),
                    'target_type' => 'filesystem',
                    'operation' => 'orphan_quarantine',
                    'files_found' => $found,
                    'files_quarantined' => $moved,
                    'reason' => 'orphaned files could not be moved to quarantine',
                ],
                SecurityAuditLogger::RESULT_FAILED,
                'storage'
            );
        }

        return $this->json(['cleanup' => $result]);
    }

    #[Route('/audit-logs', name: 'audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
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

    /** Narrow the authenticated principal to the entity required by audit logs. */
    private function getAdminUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
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
