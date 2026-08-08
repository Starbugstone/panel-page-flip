<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Service\AdminAuditService;
use App\Service\PasswordValidator;
use App\Service\Pagination\PaginationRequest;
use App\Service\SecurityAuditLogger;
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
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user and assert its type
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated or invalid user type'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user is an admin
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $verified = $request->query->has('verified') ? $request->query->getBoolean('verified') : null;
        $pagination = PaginationRequest::fromRequest($request, UserRepository::ADMIN_SORT_FIELDS, 'createdAt');

        /** @var UserRepository $userRepository */
        $userRepository = $entityManager->getRepository(User::class);
        $page = $userRepository->findAdminPage($pagination, $verified);
        $counts = $userRepository->countOwnedContent(
            array_map(static fn (User $u): int => $u->getId(), $page->items)
        );

        $usersArray = array_map(
            fn (User $u): array => $this->serializeUser($u, $counts[$u->getId()] ?? null),
            $page->items
        );

        // `users` stays alongside `items` while any client still reads it.
        return $this->json([
            'items' => $usersArray,
            'users' => $usersArray,
            'pagination' => $page->toArray(),
        ]);
    }

    /**
     * @param array{comicCount: int, tagCount: int}|null $counts Precomputed
     *        totals; omitted counts fall back to the user's own collections.
     * @return array<string, mixed>
     */
    private function serializeUser(User $user, ?array $counts = null): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()?->format('c'),
            'lastLoginAt' => $user->getLastLoginAt()?->format('c'),
            'isEmailVerified' => $user->isEmailVerified(),
            'comicCount' => $counts['comicCount'] ?? $user->getComics()->count(),
            'tagCount' => $counts['tagCount'] ?? $user->getCreatedTags()->count(),
        ];
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user and assert its type
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated or invalid user type'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user is an admin or the requested user
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && $user->getId() !== $id) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get user by id
        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $userData = $this->serializeUser($targetUser);

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

        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // Basic validation for required fields
        if (empty($data['email']) || empty($data['password']) || empty($data['name'])) {
            return $this->json(['message' => 'Missing required fields: email, password, name'], Response::HTTP_BAD_REQUEST);
        }

        $passwordErrors = $passwordValidator->validate((string) $data['password']);
        if ($passwordErrors !== []) {
            return $this->json(['message' => 'Password does not meet policy requirements.', 'errors' => ['password' => $passwordErrors]], Response::HTTP_BAD_REQUEST);
        }

        // Check if email already exists
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json(['message' => 'Email already in use'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setName($data['name']);
        $user->setPassword($passwordHasher->hashPassword($user, $data['password']));

        // Set roles, ensuring ROLE_USER is always present
        $roles = $data['roles'] ?? ['ROLE_USER'];
        if (!in_array('ROLE_USER', $roles)) {
            $roles[] = 'ROLE_USER';
        }
        $user->setRoles(array_unique($roles)); // Ensure roles are unique
        $user->setCreatedAt(new \DateTimeImmutable()); // Set creation date
        $user->setIsEmailVerified(true);

        $violations = $validator->validate($user);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                // Only include property path if it's useful (e.g., not for general class constraints)
                $propertyPath = $violation->getPropertyPath();
                $errors[] = ($propertyPath ? $propertyPath . ': ' : '') . $violation->getMessage();
            }
            return $this->json(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
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
            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
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

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        PasswordValidator $passwordValidator,
        AdminAuditService $auditService,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        // Get the current user and assert its type
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated or invalid user type'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user is an admin or the requested user
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && $user->getId() !== $id) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get user by id
        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // Get data from request
        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $beforeRoles = $targetUser->getRoles();

        // Update user properties
        if (isset($data['name'])) {
            $targetUser->setName($data['name']);
        }

        // Only admins can update roles
        if (isset($data['roles']) && in_array('ROLE_ADMIN', $user->getRoles())) {
            // Ensure ROLE_USER is always present
            $roles = $data['roles'];
            if (!in_array('ROLE_USER', $roles)) {
                $roles[] = 'ROLE_USER';
            }

            if ($targetUser->getId() === $user->getId() && !in_array('ROLE_ADMIN', $roles, true)) {
                return $this->json(['message' => 'You cannot remove your own admin role'], Response::HTTP_FORBIDDEN);
            }

            if (in_array('ROLE_ADMIN', $targetUser->getRoles(), true) && !in_array('ROLE_ADMIN', $roles, true)) {
                $remainingAdmins = $entityManager->getRepository(User::class)->countAdminsExcluding($targetUser);
                if ($remainingAdmins === 0) {
                    // Once is somebody discovering the rule. Repeatedly is worth
                    // an administrator's attention: the last admin account is
                    // the one an attacker would most like to be rid of.
                    $securityLogger->suspicious(
                        SecurityAuditLogger::LAST_ADMIN_PROTECTED,
                        'user:' . $user->getId(),
                        [
                            'actor_user_id' => $user->getId(),
                            'target_user_id' => $targetUser->getId(),
                            'target_type' => 'user',
                        ],
                        3
                    );

                    return $this->json(['message' => 'There must be at least one admin'], Response::HTTP_CONFLICT);
                }
            }

            $targetUser->setRoles($roles);
        }

        // Update password if provided
        $passwordChanged = false;
        if (isset($data['password']) && !empty($data['password'])) {
            $passwordErrors = $passwordValidator->validate((string) $data['password']);
            if ($passwordErrors !== []) {
                return $this->json(['message' => 'Password does not meet policy requirements.', 'errors' => ['password' => $passwordErrors]], Response::HTTP_BAD_REQUEST);
            }

            $targetUser->setPassword($passwordHasher->hashPassword($targetUser, $data['password']));
            $passwordChanged = true;
        }

        // Validate user
        $violations = $validator->validate($targetUser);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }
            return $this->json(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $afterRoles = $targetUser->getRoles();

        if ($user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            if ($beforeRoles !== $afterRoles || $user->getId() !== $targetUser->getId()) {
                $auditService->log($user, 'user_update', 'user', $targetUser->getId(), [
                    'email' => $targetUser->getEmail(),
                    'rolesBefore' => $beforeRoles,
                    'rolesAfter' => $afterRoles,
                ]);
            }
        }

        // Save changes
        $entityManager->flush();

        // After the flush, so nothing is announced that a validation failure or
        // a database error could still have undone.
        if ($passwordChanged) {
            // The value and the hash are both absent by construction: neither is
            // ever put in the context, and the processor would remove them if
            // some later edit did.
            $securityLogger->audit(SecurityAuditLogger::USER_PASSWORD_CHANGED, [
                'actor_user_id' => $user->getId(),
                'target_user_id' => $targetUser->getId(),
                'target_type' => 'user',
                'changed_by_admin' => $user->getId() !== $targetUser->getId(),
            ]);
        }

        if ($beforeRoles !== $afterRoles) {
            $securityLogger->audit(SecurityAuditLogger::USER_ROLES_CHANGED, [
                'actor_user_id' => $user->getId(),
                'target_user_id' => $targetUser->getId(),
                'target_type' => 'user',
                'roles_before' => $beforeRoles,
                'roles_after' => $afterRoles,
            ]);

            $wasAdmin = in_array('ROLE_ADMIN', $beforeRoles, true);
            $isAdmin = in_array('ROLE_ADMIN', $afterRoles, true);

            // Both directions, and both immediately. A grant is somebody gaining
            // the run of the instance; a removal may be an attacker locking the
            // real administrators out of their own site.
            if ($wasAdmin !== $isAdmin) {
                $securityLogger->critical(SecurityAuditLogger::ADMIN_ROLE_CHANGED, [
                    'actor_user_id' => $user->getId(),
                    'target_user_id' => $targetUser->getId(),
                    'target_type' => 'user',
                    'change' => $isAdmin ? 'granted' : 'removed',
                    'roles_before' => $beforeRoles,
                    'roles_after' => $afterRoles,
                ], SecurityAuditLogger::RESULT_SUCCESS, 'user:' . $targetUser->getId());
            }
        }

        return $this->json([
            'message' => 'User updated successfully',
            'user' => [
                'id' => $targetUser->getId(),
                'email' => $targetUser->getEmail(),
                'name' => $targetUser->getName(),
                'roles' => $targetUser->getRoles(),
                'isEmailVerified' => $targetUser->isEmailVerified(),
            ]
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        AccountDeletionService $accountDeletion,
        SecurityAuditLogger $securityLogger,
    ): JsonResponse {
        // Get the current user and assert its type
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated or invalid user type'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user is an admin
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
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
        $targetWasAdmin = in_array('ROLE_ADMIN', $targetUser->getRoles(), true);

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

    #[Route('/{id}/verify', name: 'verify', methods: ['POST'])]
    public function verify(
        int $id,
        EntityManagerInterface $entityManager,
        AdminAuditService $auditService,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        $admin = $this->getUser();
        if (!$admin instanceof User || !in_array('ROLE_ADMIN', $admin->getRoles(), true)) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $targetUser = $entityManager->getRepository(User::class)->find($id);
        if (!$targetUser) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $targetUser->setIsEmailVerified(true);
        $targetUser->setEmailVerificationToken(null);
        $targetUser->setEmailVerificationTokenExpiresAt(null);
        $auditService->log($admin, 'user_verify', 'user', $targetUser->getId(), ['email' => $targetUser->getEmail()]);
        $entityManager->flush();

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
