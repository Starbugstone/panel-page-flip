<?php

namespace App\Controller;

use App\Entity\User;
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
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user is an admin
        if (!in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get all users
        $users = $entityManager->getRepository(User::class)->findAll();

        // Transform users to array
        $usersArray = [];
        foreach ($users as $u) {
            $usersArray[] = [
                'id' => $u->getId(),
                'email' => $u->getEmail(),
                'name' => $u->getName(),
                'roles' => $u->getRoles(),
                'createdAt' => $u->getCreatedAt()->format('c'),
                'comicCount' => $u->getComics()->count(),
                'tagCount' => $u->getCreatedTags()->count()
            ];
        }

        return $this->json(['users' => $usersArray]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
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

        // Transform user to array
        $userData = [
            'id' => $targetUser->getId(),
            'email' => $targetUser->getEmail(),
            'name' => $targetUser->getName(),
            'roles' => $targetUser->getRoles(),
            'createdAt' => $targetUser->getCreatedAt()->format('c'),
            'comicCount' => $targetUser->getComics()->count(),
            'tagCount' => $targetUser->getCreatedTags()->count()
        ];

        return $this->json(['user' => $userData]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
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
            $targetUser->setRoles($roles);
        }

        // Update password if provided
        if (isset($data['password']) && !empty($data['password'])) {
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

        // Save changes
        $entityManager->flush();

        return $this->json([
            'message' => 'User updated successfully',
            'user' => [
                'id' => $targetUser->getId(),
                'email' => $targetUser->getEmail(),
                'name' => $targetUser->getName(),
                'roles' => $targetUser->getRoles()
            ]
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
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

        // Delete user
        $entityManager->remove($targetUser);
        $entityManager->flush();

        return $this->json(['message' => 'User deleted successfully']);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Return user data
        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles())
            ]
        ]);
    }
}
