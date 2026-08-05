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
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $showAll = $request->query->getBoolean('all');
        $isAdminContext = $request->query->getBoolean('adminContext');
        /** @var TagRepository $tagRepository */
        $tagRepository = $entityManager->getRepository(Tag::class);

        // Only show all tags if explicitly in admin context and user is an admin
        $isAdminListing = $showAll && $isAdminContext && $this->isGranted('ROLE_ADMIN');
        if ($isAdminListing) {
            // Admin with all=true and in admin context: Get all tags
            $tags = $tagRepository->findAll();
        } else {
            // Regular user, or admin in personal dashboard: global tags plus their own
            $tags = $tagRepository->findAvailableForUser($user);
        }

        // Usage counts are only reported for tags the caller owns. Outside the
        // admin table, how many comics across the whole install carry a global
        // tag is not the caller's to see — and skipping it saves a count query
        // per global tag on every dashboard load.
        $tagsArray = [];
        foreach ($tags as $tag) {
            $tagsArray[] = $this->serializeTag($tag, $isAdminListing || !$tag->isGlobal());
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

        $data = $this->decodePayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $tagName = $this->readTagName($data);
        if ($tagName instanceof JsonResponse) {
            return $tagName;
        }

        $isGlobal = ($data['isGlobal'] ?? false) === true;
        $hideFromLibrary = ($data['hideFromLibrary'] ?? false) === true;
        if (($isGlobal || $hideFromLibrary) && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['message' => 'Only administrators can manage global tag behavior'], Response::HTTP_FORBIDDEN);
        }
        if ($hideFromLibrary && !$isGlobal) {
            return $this->json(['message' => 'Only global tags can hide comics from the default library'], Response::HTTP_BAD_REQUEST);
        }

        $conflict = $this->conflictResponse($entityManager, $tagName, $isGlobal, $user, null, 'Tag already exists');
        if ($conflict) {
            return $conflict;
        }

        // Create new tag. Global tags belong to no one, so they get no creator.
        $tag = new Tag();
        $tag->setName($tagName);
        $tag->setIsGlobal($isGlobal);
        $tag->setHideFromLibrary($hideFromLibrary);
        if (!$isGlobal) {
            $tag->setCreator($user);
        }

        $violations = $this->validationErrorResponse($validator, $tag);
        if ($violations) {
            return $violations;
        }

        // Save tag
        $entityManager->persist($tag);
        $entityManager->flush();

        return $this->json([
            'message' => 'Tag created successfully',
            'tag' => $this->serializeTag($tag),
        ], Response::HTTP_CREATED);
    }

    /**
     * Decode the JSON request body.
     *
     * @return array<string, mixed>|JsonResponse The payload, or the error response to return.
     */
    private function decodePayload(Request $request): array|JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        return $data;
    }

    /**
     * Read and validate the tag name from a decoded request body.
     *
     * @param array<string, mixed> $data
     * @return string|JsonResponse The trimmed name, or the error response to return.
     */
    private function readTagName(array $data): string|JsonResponse
    {
        // Only a string name is acceptable. Casting instead would turn numbers,
        // booleans and arrays into nonsense tag names such as "1" or "Array".
        if (!is_string($data['name'] ?? null)) {
            return $this->json(['message' => 'Tag name is required'], Response::HTTP_BAD_REQUEST);
        }

        $tagName = trim($data['name']);
        if ($tagName === '') {
            return $this->json(['message' => 'Tag name is required'], Response::HTTP_BAD_REQUEST);
        }

        return $tagName;
    }

    /**
     * Tag names are unique within the scope the tag lives in: global tags across
     * the whole install, personal tags across everything their owner can see —
     * which includes global tags, so a personal tag can never shadow one.
     *
     * Returns the conflict response when a different tag already claims the
     * name, otherwise null.
     *
     * @param User|null $creator Owner of a personal tag; unused for global tags.
     */
    private function conflictResponse(
        EntityManagerInterface $entityManager,
        string $tagName,
        bool $isGlobal,
        ?User $creator,
        ?Tag $ignoredTag,
        string $message
    ): ?JsonResponse {
        /** @var TagRepository $tagRepository */
        $tagRepository = $entityManager->getRepository(Tag::class);

        if ($isGlobal) {
            // Globals must not share a name with another global or any personal
            // tag; the availability lookup alone would miss personal collisions.
            $existingTag = $tagRepository->findGlobalByName($tagName)
                ?? $tagRepository->findPersonalByName($tagName);
        } elseif ($creator) {
            $existingTag = $tagRepository->findAvailableByName($tagName, $creator);
        } else {
            // An ownerless personal tag cannot collide with anything scoped.
            $existingTag = null;
        }

        if (!$existingTag || ($ignoredTag && $existingTag->getId() === $ignoredTag->getId())) {
            return null;
        }

        return $this->json([
            'message' => $message,
            'tag' => $this->serializeTagSummary($existingTag),
        ], Response::HTTP_CONFLICT);
    }

    private function validationErrorResponse(ValidatorInterface $validator, Tag $tag): ?JsonResponse
    {
        $violations = $validator->validate($tag);
        if (count($violations) === 0) {
            return null;
        }

        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = $violation->getMessage();
        }

        return $this->json(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
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
        if (!$user instanceof User) {
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

        $data = $this->decodePayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $tagName = $this->readTagName($data);
        if ($tagName instanceof JsonResponse) {
            return $tagName;
        }

        // Hiding from the library is a property of global tags only. Checked
        // before anything is mutated so a rejected request changes nothing.
        $changesHideFromLibrary = array_key_exists('hideFromLibrary', $data);
        if ($changesHideFromLibrary && (!$tag->isGlobal() || !$this->isGranted('ROLE_ADMIN'))) {
            return $this->json(
                ['message' => 'Only administrators can change this option, and only on global tags'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Uniqueness is scoped to the tag being edited, not to the caller: an
        // admin renaming someone else's personal tag is checked against that
        // owner's tags.
        $conflict = $this->conflictResponse(
            $entityManager,
            $tagName,
            $tag->isGlobal(),
            $tag->getCreator(),
            $tag,
            'Tag name already exists'
        );
        if ($conflict) {
            return $conflict;
        }

        // Update tag
        $tag->setName($tagName);
        if ($changesHideFromLibrary) {
            $tag->setHideFromLibrary($data['hideFromLibrary'] === true);
        }

        $violations = $this->validationErrorResponse($validator, $tag);
        if ($violations) {
            return $violations;
        }

        // Save changes
        $entityManager->flush();

        return $this->json([
            'message' => 'Tag updated successfully',
            'tag' => $this->serializeTag($tag),
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        // Get the current user
        $user = $this->getUser();
        if (!$user instanceof User) {
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
        if (!$user instanceof User) {
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
            $tagsArray[] = $this->serializeTagSummary($tag);
        }

        return $this->json(['tags' => $tagsArray]);
    }

    /**
     * The full tag record, as the tag management tables render it.
     *
     * @param bool $includeUsage Add the comic count. Costs a query per tag, and
     *                           for a global tag it aggregates comics the caller
     *                           does not own — so callers opt in deliberately.
     * @return array<string, mixed>
     */
    private function serializeTag(Tag $tag, bool $includeUsage = true): array
    {
        $creator = $tag->getCreator();

        $data = $this->serializeTagSummary($tag) + [
            'createdAt' => $tag->getCreatedAt()?->format('c'),
            // Null for global tags: they belong to the install, not to a user.
            'creator' => $creator ? [
                'id' => $creator->getId(),
                'name' => $creator->getName() ?: $creator->getEmail(),
                'email' => $creator->getEmail(),
            ] : null,
        ];

        if ($includeUsage) {
            $data['comicCount'] = $tag->getComics()->count();
        }

        return $data;
    }

    /**
     * Just enough to identify and badge a tag, for search results and conflicts.
     *
     * @return array{id: ?int, name: ?string, isGlobal: bool, hideFromLibrary: bool}
     */
    private function serializeTagSummary(Tag $tag): array
    {
        return [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'isGlobal' => $tag->isGlobal(),
            'hideFromLibrary' => $tag->hidesFromLibrary(),
        ];
    }
}
