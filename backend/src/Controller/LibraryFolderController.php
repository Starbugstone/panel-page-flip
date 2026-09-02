<?php

namespace App\Controller;

use App\Entity\LibraryFolder;
use App\Entity\User;
use App\Service\FolderDeletionConfirmationRequired;
use App\Service\LibraryFolderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/library/folders', name: 'api_library_folders_')]
class LibraryFolderController extends AbstractController
{
    public function __construct(private readonly LibraryFolderService $folders)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->user();

        return $this->json([
            'folders' => array_map($this->serialize(...), $this->folders->list($user)),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        if (!array_key_exists('name', $data) || !is_string($data['name'])) {
            return $this->json(['message' => 'A folder name is required.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $parentId = $this->nullableId($data, 'parentId');
            $folder = $this->folders->create($this->user(), $data['name'], $parentId);

            return $this->json(['folder' => $this->serialize($folder)], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/move-comics', name: 'move_comics', methods: ['POST'])]
    public function moveComics(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $comicIds = $data['comicIds'] ?? null;
        if (!is_array($comicIds) || !array_is_list($comicIds) || array_filter($comicIds, static fn (mixed $id): bool => !is_int($id)) !== []) {
            return $this->json(['message' => 'comicIds must be an array of integer identifiers.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $folderId = $this->nullableId($data, 'folderId');
            $comics = $this->folders->moveComics($this->user(), $comicIds, $folderId);

            return $this->json([
                'movedComicIds' => array_map(static fn ($comic): int => (int) $comic->getId(), $comics),
                'folderId' => $folderId,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/{id<\\d+>}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->user();
        $folder = $this->folders->findOwned($user, $id);
        if ($folder === null) {
            return $this->json(['message' => 'Folder not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->payload($request);
        $name = array_key_exists('name', $data) && is_string($data['name']) ? $data['name'] : null;
        $changeParent = array_key_exists('parentId', $data);
        if ($name === null && !$changeParent) {
            return $this->json(['message' => 'Nothing to update.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $parentId = $changeParent ? $this->nullableId($data, 'parentId') : null;
            $folder = $this->folders->update($user, $folder, $name, $changeParent, $parentId);

            return $this->json(['folder' => $this->serialize($folder)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/{id<\\d+>}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->user();
        $folder = $this->folders->findOwned($user, $id);
        if ($folder === null) {
            return $this->json(['message' => 'Folder not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->payload($request);
        try {
            $summary = $this->folders->delete($user, $folder, ($data['confirm'] ?? false) === true);

            return $this->json(['deleted' => true, 'summary' => $summary]);
        } catch (FolderDeletionConfirmationRequired $exception) {
            return $this->json([
                'message' => $exception->getMessage(),
                'code' => 'folder_deletion_confirmation_required',
                'summary' => $exception->summary(),
            ], Response::HTTP_CONFLICT);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function nullableId(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }
        if (!is_int($data[$key]) && !(is_string($data[$key]) && ctype_digit($data[$key]))) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer or null.', $key));
        }

        $id = (int) $data[$key];
        if ($id < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer or null.', $key));
        }

        return $id;
    }

    /** @return array{id:int, name:string, parentId:?int, createdAt:string, updatedAt:string} */
    private function serialize(LibraryFolder $folder): array
    {
        return [
            'id' => (int) $folder->getId(),
            'name' => $folder->getName(),
            'parentId' => $folder->getParent()?->getId(),
            'createdAt' => $folder->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $folder->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
