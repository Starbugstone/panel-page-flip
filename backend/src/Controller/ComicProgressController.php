<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Security\ComicAccess;
use App\Security\Voter\ComicVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Reading-position commands for comics the current user may view. */
#[Route('/api/comics', name: 'api_comics_')]
final class ComicProgressController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ComicShareRepository $shareRepository,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ComicAccess $comicAccess,
    ) {
    }

    #[Route('/{id}/reading-progress/reset', name: 'reset_reading_progress', methods: ['POST'])]
    public function reset(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->requireUser();
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);
        $readingProgress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);

        if ($readingProgress !== null) {
            $entityManager->remove($readingProgress);
            $entityManager->flush();
        }

        return $this->json(['message' => 'Reading progress reset successfully']);
    }

    #[Route('/{id}/progress', name: 'update_progress', methods: ['POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->requireUser();
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        // A recipient genuinely reads another user's comic; an administrator
        // merely inspecting one does not leave a position in its owner's data.
        $isRecipient = $this->shareRepository->findAccessFor($user, $comic) !== null;
        $isAdminInspection = !$isRecipient
            && $comic->getOwner()?->getId() !== $user->getId()
            && $user->isAdmin();

        $data = \App\Http\JsonRequestDecoder::decode($request);
        if (!isset($data['currentPage']) || !is_numeric($data['currentPage']) || $data['currentPage'] < 1) {
            return $this->json(['message' => 'Valid currentPage is required'], Response::HTTP_BAD_REQUEST);
        }

        $currentPage = (int) $data['currentPage'];
        $completed = isset($data['completed']) ? (bool) $data['completed'] : false;
        $revision = $this->revision($data);
        if ($revision === false) {
            return $this->json(['message' => 'Invalid revision'], Response::HTTP_BAD_REQUEST);
        }

        if ($isAdminInspection) {
            return $this->json([
                'message' => 'Admin read-only progress ignored',
                'progress' => [
                    'currentPage' => $currentPage,
                    'lastReadAt' => (new \DateTimeImmutable())->format('c'),
                    'completed' => $completed,
                    'revision' => $revision ?? 0,
                ],
            ]);
        }

        $progress = $this->save($user, $comic, $currentPage, $entityManager, $completed, $revision);

        return $this->json([
            'message' => 'Reading progress updated',
            'progress' => [
                'currentPage' => $progress->getCurrentPage(),
                'lastReadAt' => $progress->getLastReadAt()->format('c'),
                'completed' => $progress->isCompleted(),
                'revision' => $progress->getRevision(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int|false|null
     */
    private function revision(array $data): int|false|null
    {
        if (!isset($data['revision'])) {
            return null;
        }
        if (!is_numeric($data['revision']) || $data['revision'] < 1) {
            return false;
        }

        return (int) $data['revision'];
    }

    /**
     * @param bool $isRetry set once the first attempt lost the race to create
     *                      the row; stops a pathological loop
     */
    private function save(
        User $user,
        Comic $comic,
        int $currentPage,
        EntityManagerInterface $entityManager,
        bool $completed = false,
        ?int $revision = null,
        bool $isRetry = false,
    ): ComicReadingProgress {
        $progress = $entityManager->getRepository(ComicReadingProgress::class)
            ->findOneBy(['comic' => $comic, 'user' => $user]);
        $isCompleted = $completed || ($comic->getPageCount() !== null && $currentPage >= $comic->getPageCount());

        if ($progress !== null && $revision !== null) {
            $applied = (int) $entityManager->createQuery(
                'UPDATE '.ComicReadingProgress::class.' p
                 SET p.currentPage = :currentPage, p.completed = :completed, p.lastReadAt = :lastReadAt, p.revision = :revision
                 WHERE p.id = :id AND p.revision < :revision'
            )
                ->setParameter('currentPage', $currentPage)
                ->setParameter('completed', $isCompleted || $progress->isCompleted())
                ->setParameter('lastReadAt', new \DateTimeImmutable())
                ->setParameter('revision', $revision)
                ->setParameter('id', $progress->getId())
                ->execute();

            // DQL bypasses the unit of work, so refresh whichever save won.
            $entityManager->refresh($progress);
            if ($applied === 0) {
                $this->logger->debug('Ignored a superseded reading progress save.', [
                    'comic_id' => $comic->getId(),
                    'revision' => $revision,
                ]);
            }

            return $progress;
        }

        $isNew = $progress === null;
        if ($isNew) {
            $progress = (new ComicReadingProgress())
                ->setUser($user)
                ->setComic($comic);
            $entityManager->persist($progress);
        }

        $progress->setCurrentPage($currentPage);
        if ($revision !== null) {
            $progress->setRevision($revision);
        }
        if ($isCompleted) {
            $progress->setCompleted(true);
        }

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (!$isNew || $isRetry) {
                throw $exception;
            }

            // A concurrent insert closes this manager. Retry once with managed
            // copies from a fresh manager instead of failing a valid page save.
            $this->logger->debug('Reading progress row was created concurrently; applying the save to it.', [
                'comic_id' => $comic->getId(),
            ]);
            $this->managerRegistry->resetManager();
            $freshManager = $this->managerRegistry->getManager();
            $freshUser = $freshManager->find(User::class, $user->getId());
            $freshComic = $freshManager->find(Comic::class, $comic->getId());

            if (!$freshManager instanceof EntityManagerInterface
                || !$freshUser instanceof User
                || !$freshComic instanceof Comic
            ) {
                throw $exception;
            }

            return $this->save(
                $freshUser,
                $freshComic,
                $currentPage,
                $freshManager,
                $completed,
                $revision,
                true,
            );
        }

        return $progress;
    }
}
