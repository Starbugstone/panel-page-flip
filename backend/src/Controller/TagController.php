<?php

namespace App\Controller;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/tags', name: 'api_tags_')]
class TagController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $showAll = $request->query->getBoolean('all');
        $isAdminContext = $request->query->getBoolean('adminContext');
        $tagRepository = $entityManager->getRepository(Tag::class);

        // Only show all tags if explicitly in admin context and user is an admin
        if ($showAll && $isAdminContext && $this->isGranted('ROLE_ADMIN')) {
            // Admin with all=true and in admin context: Get all tags
            $tags = $tagRepository->findAll();
        } else {
            // Regular user, or admin in personal dashboard: Get only tags created by the user
            $tags = $tagRepository->findAvailableForUser($user);
        }

        // Transform tags to array
        $tagsArray = [];
        foreach ($tags as $tag) {
            $tagsArray[] = $this->serialiseTag($tag);
        }

        return $this->json(['tags' => $tagsArray]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request, 
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get data from request
        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // Validate tag name
        if (!isset($data['name']) || empty(trim($data['name']))) {
            return $this->json(['message' => 'Tag name is required'], Response::HTTP_BAD_REQUEST);
        }

        $tagName = trim($data['name']);

        /** @var TagRepository $tagRepository */
        $tagRepository = $entityManager->getRepository(Tag::class);
        $isGlobal = ($data['isGlobal'] ?? false) === true;
        $hideFromLibrary = ($data['hideFromLibrary'] ?? false) === true;
        if (($isGlobal || $hideFromLibrary) && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Only administrators can manage global tag behavior'], Response::HTTP_FORBIDDEN);
        }
        if ($hideFromLibrary && !$isGlobal) {
            return $this->json(['message' => 'Only global tags can hide comics from the default library'], Response::HTTP_BAD_REQUEST);
        }

        $existingTag = $isGlobal
            ? $tagRepository->findGlobalByName($tagName)
            : $tagRepository->findAvailableByName($tagName, $user);
        if ($existingTag) {
            return $this->json([
                'message' => 'Tag already exists',
                'tag' => [
                    'id' => $existingTag->getId(),
                    'name' => $existingTag->getName(),
                    'isGlobal' => $existingTag->isGlobal(),
                    'hideFromLibrary' => $existingTag->hidesFromLibrary(),
                ]
            ], Response::HTTP_CONFLICT);
        }

        // Create new tag
        $tag = new Tag();
        $tag->setName($tagName);
        $tag->setIsGlobal($isGlobal);
        $tag->setHideFromLibrary($hideFromLibrary);
        if (!$isGlobal) {
            $tag->setCreator($user);
        }

        // Validate tag
        $violations = $validator->validate($tag);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }
            return $this->json(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Save tag
        $entityManager->persist($tag);
        $entityManager->flush();

        return $this->json([
            'message' => 'Tag created successfully',
            'tag' => $this->serialiseTag($tag)
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): JsonResponse {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get tag by id
        $tag = $entityManager->getRepository(Tag::class)->find($id);
        if (!$tag) {
            return $this->json(['message' => 'Tag not found'], Response::HTTP_NOT_FOUND);
        }

        if ($tag->isGlobal() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Only administrators can update global tags'], Response::HTTP_FORBIDDEN);
        }

        // Check if user is the creator of the tag
        if ($tag->getCreator()?->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['message' => 'You are not authorized to update this tag'], Response::HTTP_FORBIDDEN);
        }

        // Get data from request
        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        // Validate tag name
        if (!isset($data['name']) || empty(trim($data['name']))) {
            return $this->json(['message' => 'Tag name is required'], Response::HTTP_BAD_REQUEST);
        }

        $tagName = trim($data['name']);

        // Check if tag name already exists for this creator (excluding current tag)
        /** @var TagRepository $tagRepository */
        $tagRepository = $entityManager->getRepository(Tag::class);
        $tagCreator = $tag->getCreator();
        $existingTag = $tag->isGlobal()
            ? $tagRepository->findGlobalByName($tagName)
            : ($tagCreator ? $tagRepository->findAvailableByName($tagName, $tagCreator) : null);
        if ($existingTag && $existingTag->getId() !== $tag->getId()) {
            return $this->json([
                'message' => 'Tag name already exists',
                'tag' => [
                    'id' => $existingTag->getId(),
                    'name' => $existingTag->getName()
                ]
            ], Response::HTTP_CONFLICT);
        }

        // Update tag
        $tag->setName($tagName);
        if (array_key_exists('hideFromLibrary', $data)) {
            if (!$tag->isGlobal() || !$this->isGranted('ROLE_ADMIN')) {
                return $this->json(['message' => 'Only administrators can change this option on global tags'], Response::HTTP_FORBIDDEN);
            }
            $tag->setHideFromLibrary($data['hideFromLibrary'] === true);
        }

        // Validate tag
        $violations = $validator->validate($tag);
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
            'message' => 'Tag updated successfully',
            'tag' => $this->serialiseTag($tag)
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

        // Get tag by id
        $tag = $entityManager->getRepository(Tag::class)->find($id);
        if (!$tag) {
            return $this->json(['message' => 'Tag not found'], Response::HTTP_NOT_FOUND);
        }

        if ($tag->isGlobal() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Only administrators can delete global tags'], Response::HTTP_FORBIDDEN);
        }

        // Check if user is the creator of the tag or an admin
        if ($tag->getCreator()?->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['message' => 'You are not authorized to delete this tag'], Response::HTTP_FORBIDDEN);
        }

        // Explicitly detach the tag so deleting an in-use tag is predictable on
        // every supported database, regardless of join-table cascade settings.
        foreach ($tag->getComics()->toArray() as $comic) {
            $comic->removeTag($tag);
        }

        // Delete tag
        $entityManager->remove($tag);
        $entityManager->flush();

        return $this->json(['message' => 'Tag deleted successfully']);
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get query parameter
        $query = $request->query->get('q', '');
        $isAdminContext = $request->query->getBoolean('adminContext');
        
        if (empty($query)) {
            return $this->json(['tags' => []]);
        }

        // Search for tags by name
        $tagRepository = $entityManager->getRepository(Tag::class);
        $tags = $tagRepository->findByNameLike($query);
        
        // Filter tags based on context
        // Only show all tags if explicitly in admin context and user is an admin
        if (!($isAdminContext && $this->isGranted('ROLE_ADMIN'))) {
            // Regular user or admin in personal dashboard: show only user's tags
            $tags = array_filter($tags, function($tag) use ($user) {
                return $tag->isGlobal() || $tag->getCreator()?->getId() === $user->getId();
            });
        }

        // Transform tags to array
        $tagsArray = [];
        foreach ($tags as $tag) {
            $tagsArray[] = [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'isGlobal' => $tag->isGlobal(),
                'hideFromLibrary' => $tag->hidesFromLibrary(),
            ];
        }

        return $this->json(['tags' => $tagsArray]);
    }

    private function serialiseTag(Tag $tag): array
    {
        $creator = $tag->getCreator();

        return [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'createdAt' => $tag->getCreatedAt()->format('c'),
            'creator' => $creator ? [
                'id' => $creator->getId(),
                'name' => $creator->getName() ?: $creator->getEmail(),
                'email' => $creator->getEmail(),
            ] : null,
            'isGlobal' => $tag->isGlobal(),
            'hideFromLibrary' => $tag->hidesFromLibrary(),
            'comicCount' => $tag->getComics()->count(),
        ];
    }
}
