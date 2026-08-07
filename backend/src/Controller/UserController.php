<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Service\AdminAuditService;
use App\Service\PasswordValidator;
use App\Service\Pagination\PaginationRequest;
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
        AdminAuditService $auditService
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
            $auditService->log($admin, 'user_create', 'user', $user->getId(), ['email' => $user->getEmail(), 'roles' => $user->getRoles()]);
            $entityManager->flush();
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
        AdminAuditService $auditService
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
                    return $this->json(['message' => 'There must be at least one admin'], Response::HTTP_CONFLICT);
                }
            }

            $targetUser->setRoles($roles);
        }

        // Update password if provided
        if (isset($data['password']) && !empty($data['password'])) {
            $passwordErrors = $passwordValidator->validate((string) $data['password']);
            if ($passwordErrors !== []) {
                return $this->json(['message' => 'Password does not meet policy requirements.', 'errors' => ['password' => $passwordErrors]], Response::HTTP_BAD_REQUEST);
            }

            $targetUser->setPassword($passwordHasher->hashPassword($targetUser, $data['password']));
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

        if ($user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $afterRoles = $targetUser->getRoles();
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

        $auditService->log($user, 'user_delete', 'user', $targetUser->getId(), ['email' => $targetUser->getEmail()]);
        // Flush so AccountDeletionService can load and redact this audit row.
        $entityManager->flush();

        try {
            // Same erasure path as self-service deletion: shares, tags, audit
            // redaction, and durable file purge. Comics remain an explicit
            // admin precondition above so libraries are not deleted by surprise.
            $accountDeletion->delete($targetUser);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => 'User deleted successfully']);
    }

    #[Route('/{id}/verify', name: 'verify', methods: ['POST'])]
    public function verify(int $id, EntityManagerInterface $entityManager, AdminAuditService $auditService): JsonResponse
    {
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
