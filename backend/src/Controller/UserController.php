<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\ConstraintViolationErrors;
use App\Repository\UserMetadataCredentialRepository;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Service\AdminAuditService;
use App\Service\EmailVerificationService;
use App\Service\PasswordValidator;
use App\Service\Pagination\PaginationRequest;
use App\Service\SecurityAuditLogger;
use App\Service\SharingCodeService;
use App\Service\StorageQuotaService;
use App\Service\UserUpdateRejected;
use App\Service\UserUpdateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/users', name: 'api_users_')]
class UserController extends AbstractController
{
    use RequiresAuthenticatedUser;

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, EntityManagerInterface $entityManager, UserMetadataCredentialRepository $credentialRepository, StorageQuotaService $storageQuota): JsonResponse
    {
        $user = $this->requireUser();

        // Check if user is an admin
        if (!$user->isAdmin()) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $verified = $request->query->has('verified') ? $request->query->getBoolean('verified') : null;
        $pagination = PaginationRequest::fromRequest($request, UserRepository::ADMIN_SORT_FIELDS, 'createdAt');

        /** @var UserRepository $userRepository */
        $userRepository = $entityManager->getRepository(User::class);
        $page = $userRepository->findAdminPage($pagination, $verified, [
            'identity' => $request->query->get('filterIdentity'),
            'role' => $request->query->get('filterRole'),
            'verified' => $request->query->get('filterVerified'),
            'createdAt' => $request->query->get('filterCreatedAt'),
            'lastLoginAt' => $request->query->get('filterLastLoginAt'),
            'comicCount' => $request->query->get('filterComicCount'),
            'storage' => $request->query->get('filterStorage'),
            'timezone' => $request->query->get('filterTimezone'),
        ]);
        $stats = $userRepository->getOwnedContentStats(
            array_values(array_map(static fn (User $u): int => $u->getId() ?? throw new \LogicException('Persisted user has no identifier.'), $page->items))
        );

        // One query for the whole page rather than one per row: the personal
        // credential is not an association on User, precisely so that loading a
        // user never drags it along.
        $withCredential = $credentialRepository->findUserIdsWithCredential(array_values(array_map(
            static fn (User $u): int => $u->getId() ?? throw new \LogicException('Persisted user has no identifier.'),
            $page->items
        )));

        $usersArray = array_map(
            fn (User $u): array => $this->serializeUser(
                $u,
                $stats[$u->getId()] ?? null,
                isset($withCredential[$u->getId()]),
                $storageQuota
            ),
            $page->items
        );

        // `users` stays alongside `items` while any client still reads it.
        return $this->json([
            'items' => $usersArray,
            'users' => $usersArray,
            'comicCountMax' => $userRepository->getMaximumOwnedComicCount(),
            'storageMaxBytes' => $userRepository->getMaximumOwnedStorageBytes(),
            'pagination' => $page->toArray(),
        ]);
    }

    /**
     * @param array{comicCount: int, tagCount: int, storageUsedBytes: int, unmeasuredComicCount: int}|null $stats
     *        Precomputed totals; omitted counts fall back to the user's own collections.
     * @return array<string, mixed>
     */
    private function serializeUser(User $user, ?array $stats, bool $hasPersonalMetadataCredential, StorageQuotaService $storageQuota): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()->format('c'),
            'lastLoginAt' => $user->getLastLoginAt()?->format('c'),
            'isEmailVerified' => $user->isEmailVerified(),
            'comicCount' => $stats['comicCount'] ?? $user->getComics()->count(),
            'tagCount' => $stats['tagCount'] ?? $user->getCreatedTags()->count(),
            // Raw bytes, never a percentage or a formatted string: the client
            // needs the real values to say 112% when the data says 112%.
            'storageUsedBytes' => $stats['storageUsedBytes'] ?? 0,
            'storageQuotaBytes' => $storageQuota->getQuotaBytes($user),
            'storageQuotaOverrideBytes' => $user->getStorageQuotaOverrideBytes(),
            'storageDefaultQuotaBytes' => $storageQuota->getDefaultQuotaBytes(),
            'unmeasuredComicCount' => $stats['unmeasuredComicCount'] ?? 0,
            'metadataApiEnabled' => $user->isMetadataApiEnabled(),
            // Whether they brought their own provider token, never which one.
            'hasPersonalMetadataCredential' => $hasPersonalMetadataCredential,
        ];
    }

    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function get(int $id, EntityManagerInterface $entityManager, UserMetadataCredentialRepository $credentialRepository, StorageQuotaService $storageQuota): JsonResponse
    {
        $user = $this->requireUser();

        // Check if user is an admin or the requested user
        if (!$user->isAdmin() && $user->getId() !== $id) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get user by id
        /** @var UserRepository $userRepository */
        $userRepository = $entityManager->getRepository(User::class);
        $targetUser = $userRepository->find($id);
        if (!$targetUser) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // The same grouped query the list uses, for a page of one. Counting the
        // user's collections here instead would give this endpoint a second
        // definition of comic count and no storage figure at all.
        $targetUserId = $targetUser->getId() ?? throw new \LogicException('Persisted user has no identifier.');
        $stats = $userRepository->getOwnedContentStats([$targetUserId]);

        $userData = $this->serializeUser(
            $targetUser,
            $stats[$targetUserId] ?? null,
            $credentialRepository->findForUser($targetUser) !== null,
            $storageQuota
        );

        // The admin user page needs enough to explain why an account can or
        // cannot be deleted, and whether Dropbox is still attached.
        if ($this->isGranted('ROLE_ADMIN')) {
            $userData['dropboxConnected'] = $targetUser->getDropboxAccessToken() !== null
                && $targetUser->getDropboxAccessToken() !== '';
            $userData['dropboxLastSyncedAt'] = $targetUser->getDropboxLastSyncedAt()?->format('c');
        }

        return $this->json(['user' => $userData]);
    }

    // Method to create a new user (Admin only)
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        PasswordValidator $passwordValidator,
        AdminAuditService $auditService,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = \App\Http\JsonRequestDecoder::decode($request);

        $requiredErrors = [];
        foreach (['email', 'password', 'name'] as $field) {
            if (empty($data[$field])) {
                $requiredErrors[$field] = ['This field is required.'];
            }
        }
        if ($requiredErrors !== []) {
            return $this->json([
                'message' => 'Missing required fields: email, password, name',
                'errors' => $requiredErrors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $passwordErrors = $passwordValidator->validate((string) $data['password']);
        if ($passwordErrors !== []) {
            return $this->json(['message' => 'Password does not meet policy requirements.', 'errors' => ['password' => $passwordErrors]], Response::HTTP_BAD_REQUEST);
        }

        // Check if email already exists
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json([
                'message' => 'Email already in use',
                'errors' => ['email' => ['Email already in use.']],
            ], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setName($data['name']);
        $user->setPassword($passwordHasher->hashPassword($user, $data['password']));

        // Set roles, ensuring ROLE_USER is always present
        $roles = $data['roles'] ?? ['ROLE_USER'];
        if (!is_array($roles) || !array_is_list($roles) || array_filter($roles, static fn (mixed $role): bool => !is_string($role)) !== []) {
            return $this->json(['message' => 'Roles must be an array of role names.'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        $user->setRoles(array_values(array_unique($roles)));
        $user->setCreatedAt(new \DateTimeImmutable()); // Set creation date
        $user->setIsEmailVerified(true);

        $violations = $validator->validate($user);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed',
                'errors' => ConstraintViolationErrors::from($violations),
            ], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $admin = $this->getUser();
        if ($admin instanceof User) {
            // The address stays: an administrator reading the audit page needs to
            // recognise the account, and it is already theirs to see. What the
            // payload must never gain is the submitted password.
            $auditService->log($admin, 'user_create', 'user', $user->getId(), ['email' => $user->getEmail(), 'roles' => $user->getRoles()]);
            $entityManager->flush();

            $securityLogger->audit(SecurityAuditLogger::USER_REGISTERED, [
                'actor_user_id' => $admin->getId(),
                'target_user_id' => $user->getId(),
                'target_type' => 'user',
                'created_by_admin' => true,
                'roles_after' => $user->getRoles(),
            ]);

            // An account that is an administrator from the moment it exists is a
            // privilege grant, and reads the same to anybody investigating later
            // as promoting an existing one.
            if ($user->isAdmin()) {
                $securityLogger->critical(SecurityAuditLogger::ADMIN_ROLE_CHANGED, [
                    'actor_user_id' => $admin->getId(),
                    'target_user_id' => $user->getId(),
                    'target_type' => 'user',
                    'change' => 'granted_on_creation',
                    'roles_before' => [],
                    'roles_after' => $user->getRoles(),
                ], SecurityAuditLogger::RESULT_SUCCESS, 'user:' . $user->getId());
            }
        }

        return $this->json([
            'message' => 'User created successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'isEmailVerified' => $user->isEmailVerified(),
                'createdAt' => $user->getCreatedAt()->format('c'),
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\\d+'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        UserUpdateService $updater,
    ): JsonResponse {
        $user = $this->requireUser();
        if (!$user->isAdmin() && $user->getId() !== $id) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser instanceof User) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $updater->update($user, $targetUser, \App\Http\JsonRequestDecoder::decode($request));
        } catch (UserUpdateRejected $exception) {
            return $this->json($exception->payload(), $exception->statusCode());
        }

        return $this->json([
            'message' => 'User updated successfully',
            'user' => [
                'id' => $targetUser->getId(),
                'email' => $targetUser->getEmail(),
                'name' => $targetUser->getName(),
                'roles' => $targetUser->getRoles(),
                'isEmailVerified' => $targetUser->isEmailVerified(),
            ],
        ]);
    }

    /**
     * Set this account's explicit canonical-source allowance, or clear it back
     * to the installation default. Zero is deliberately unlimited; null is
     * inheritance, so the two must never be collapsed during validation.
     */
    #[Route('/{id}/storage-quota', name: 'update_storage_quota', methods: ['PATCH'], requirements: ['id' => '\\d+'])]
    public function updateStorageQuota(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        StorageQuotaService $storageQuota
    ): JsonResponse {
        $admin = $this->requireUser();
        if (!$admin->isAdmin()) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser instanceof User) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $data = \App\Http\JsonRequestDecoder::decode($request);
        if (!array_key_exists('storageQuotaOverrideBytes', $data)) {
            $message = 'storageQuotaOverrideBytes is required and must be null or a non-negative integer.';

            return $this->json([
                'message' => $message,
                'errors' => ['storageQuotaOverrideBytes' => [$message]],
            ], Response::HTTP_BAD_REQUEST);
        }

        $override = $data['storageQuotaOverrideBytes'];
        if ($override !== null && (!is_int($override) || $override < 0 || $override > StorageQuotaService::MAX_QUOTA_BYTES)) {
            $message = sprintf(
                'Storage quota must be null, 0 for unlimited, or an integer no greater than %d bytes.',
                StorageQuotaService::MAX_QUOTA_BYTES
            );

            return $this->json([
                'message' => $message,
                'errors' => ['storageQuotaOverrideBytes' => [$message]],
            ], Response::HTTP_BAD_REQUEST);
        }

        $before = $targetUser->getStorageQuotaOverrideBytes();
        if ($before !== $override) {
            $targetUser->setStorageQuotaOverrideBytes($override);
            $auditService->log($admin, 'user_storage_quota_updated', 'user', $targetUser->getId(), [
                'target_user_id' => $targetUser->getId(),
                'override_before_bytes' => $before,
                'override_after_bytes' => $override,
                'effective_after_bytes' => $storageQuota->getQuotaBytes($targetUser),
            ]);
            $entityManager->flush();
        }

        return $this->json([
            'message' => $override === null
                ? 'Storage quota restored to the server default.'
                : ($override === 0 ? 'Storage quota set to unlimited.' : 'Storage quota updated.'),
            'user' => [
                'id' => $targetUser->getId(),
                'storageQuotaBytes' => $storageQuota->getQuotaBytes($targetUser),
                'storageQuotaOverrideBytes' => $targetUser->getStorageQuotaOverrideBytes(),
                'storageDefaultQuotaBytes' => $storageQuota->getDefaultQuotaBytes(),
            ],
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\\d+'])]
    public function delete(
        int $id,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        AccountDeletionService $accountDeletion,
        SecurityAuditLogger $securityLogger,
    ): JsonResponse {
        $user = $this->requireUser();

        // Check if user is an admin
        if (!$user->isAdmin()) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get user by id
        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // Prevent deleting your own account
        if ($targetUser->getId() === $user->getId()) {
            return $this->json(['message' => 'Cannot delete your own account'], Response::HTTP_FORBIDDEN);
        }

        if (!$targetUser->getComics()->isEmpty()) {
            return $this->json([
                'message' => 'This user still owns comics. Reassign or delete those comics explicitly before deleting the account.',
            ], Response::HTTP_CONFLICT);
        }

        $targetUserId = $targetUser->getId();
        $targetWasAdmin = $targetUser->isAdmin();

        $auditService->log($user, 'user_delete', 'user', $targetUserId, ['email' => $targetUser->getEmail()]);
        // Flush so AccountDeletionService can load and redact this audit row.
        $entityManager->flush();

        try {
            // Same erasure path as self-service deletion: shares, tags, audit
            // redaction, and durable file purge. Comics remain an explicit
            // admin precondition above so libraries are not deleted by surprise.
            $accountDeletion->delete($targetUser, $user);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        // Losing an administrator is a privilege change, and one that cannot be
        // undone by putting the role back.
        if ($targetWasAdmin) {
            $securityLogger->critical(SecurityAuditLogger::ADMIN_ROLE_CHANGED, [
                'actor_user_id' => $user->getId(),
                'target_user_id' => $targetUserId,
                'target_type' => 'user',
                'change' => 'admin_account_deleted',
            ], SecurityAuditLogger::RESULT_SUCCESS, 'user:' . $targetUserId);
        }

        return $this->json(['message' => 'User deleted successfully']);
    }

    /**
     * Replace a user's `U-` code on their behalf.
     *
     * Support's version of the button the user has on their own Sharing page,
     * for when they report that the code has been posted somewhere they cannot
     * take it back from. Nothing about the account changes but the identifier,
     * and the shares made through the old code are untouched.
     *
     * The new code is deliberately not returned. An administrator has no reason
     * to hold somebody's contact handle, and the user can read it off their own
     * Sharing page the moment they look — which is also the only place it can
     * reach them without passing through a support channel on the way.
     */
    #[Route('/{id}/user-code/rotate', name: 'rotate_user_code', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function rotateSharingCode(
        int $id,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        SharingCodeService $sharingCodes
    ): JsonResponse {
        $admin = $this->getUser();
        if (!$admin instanceof User || !$admin->isAdmin()) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // The service owns generation, uniqueness and the security record,
        // so this path and the user's own cannot drift apart.
        $sharingCodes->rotateCode($targetUser, $admin);

        // Ids only in the administrative trail as well — neither the old code
        // nor the new one is written down anywhere.
        $auditService->log($admin, 'user_code_rotate', 'user', $targetUser->getId());

        return $this->json([
            'message' => 'User code replaced. The user can see the new one on their Sharing page.',
        ]);
    }

    #[Route('/{id}/verify', name: 'verify', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function verify(
        int $id,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        SecurityAuditLogger $securityLogger,
        EmailVerificationService $emailVerification
    ): JsonResponse {
        $admin = $this->getUser();
        if (!$admin instanceof User || !$admin->isAdmin()) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // Logged before the verification is written so that both land in the
        // one flush below: an audit trail that can be missing the entry for the
        // change it exists to record is worse than no audit trail at all.
        $auditService->log($admin, 'user_verify', 'user', $targetUser->getId(), ['email' => $targetUser->getEmail()]);
        $emailVerification->markVerified($targetUser);

        $securityLogger->audit(SecurityAuditLogger::USER_EMAIL_VERIFIED, [
            'actor_user_id' => $admin->getId(),
            'target_user_id' => $targetUser->getId(),
            'target_type' => 'user',
            'verified_by_admin' => true,
        ]);

        return $this->json([
            'message' => 'User marked as verified',
            'user' => [
                'id' => $targetUser->getId(),
                'email' => $targetUser->getEmail(),
                'name' => $targetUser->getName(),
                'roles' => $targetUser->getRoles(),
                'isEmailVerified' => $targetUser->isEmailVerified(),
            ],
        ]);
    }

}
