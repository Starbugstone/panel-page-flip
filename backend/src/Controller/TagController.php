<?php

namespace App\Controller;

use App\Entity\Tag;
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
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Get all tags (both user-created and system tags)
        $tags = $entityManager->getRepository(Tag::class)->findAll();

        // Transform tags to array
        $tagsArray = [];
        foreach ($tags as $tag) {
            $tagsArray[] = [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'createdAt' => $tag->getCreatedAt()->format('c'),
                'creator' => [
                    'id' => $tag->getCreator()->getId(),
                    'name' => $tag->getCreator()->getName() ?: $tag->getCreator()->getEmail(),
                ],
                'comicCount' => $tag->getComics()->count()
            ];
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
        if (!$user) {
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

        // Check if tag already exists
        $existingTag = $entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
        if ($existingTag) {
            return $this->json([
                'message' => 'Tag already exists',
                'tag' => [
                    'id' => $existingTag->getId(),
                    'name' => $existingTag->getName()
                ]
            ], Response::HTTP_CONFLICT);
        }

        // Create new tag
        $tag = new Tag();
        $tag->setName($tagName);
        $tag->setCreator($user);

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
            'tag' => [
                'id' => $tag->getId(),
                'name' => $tag->getName()
            ]
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

        // Check if user is the creator of the tag
        if ($tag->getCreator()->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $user->getRoles())) {
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

        // Check if tag name already exists (excluding current tag)
        $existingTag = $entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
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
            'tag' => [
                'id' => $tag->getId(),
                'name' => $tag->getName()
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

        // Get tag by id
        $tag = $entityManager->getRepository(Tag::class)->find($id);
        if (!$tag) {
            return $this->json(['message' => 'Tag not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if user is the creator of the tag or an admin
        if ($tag->getCreator()->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json(['message' => 'You are not authorized to delete this tag'], Response::HTTP_FORBIDDEN);
        }

        // Check if tag is used by any comics
        if ($tag->getComics()->count() > 0) {
            return $this->json([
                'message' => 'Cannot delete tag that is used by comics',
                'comicCount' => $tag->getComics()->count()
            ], Response::HTTP_CONFLICT);
        }

        // Delete tag
        $entityManager->remove($tag);
        $entityManager->flush();

        return $this->json(['message' => 'Tag deleted successfully']);
    }
}
