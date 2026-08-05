<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ShareToken;
use App\Entity\User;
use App\Repository\ShareTokenRepository;
use App\Service\ComicService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Environment;
use App\Repository\TagRepository;
use App\Entity\Tag;
use Psr\Log\LoggerInterface; // For logging cover copy errors

#[Route('/api/share')]
class ShareController extends AbstractController
{
    private string $publicSharesDirectory;

    public function __construct(
        private readonly string $comicsDirectory,
        private readonly string $frontendUrl,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
        private readonly LoggerInterface $logger,
        ?string $publicSharesDirectory = null
    ) {
        // If not explicitly provided, use a subdirectory of the comics directory
        $this->publicSharesDirectory = $publicSharesDirectory ?? $comicsDirectory . '/public_shares';
    }

    /**
     * Created on demand rather than in the constructor, so sharing a comic is
     * what touches the filesystem instead of every request to this controller.
     */
    private function ensurePublicSharesDirectory(): void
    {
        if (!is_dir($this->publicSharesDirectory)
            && !mkdir($this->publicSharesDirectory, 0775, true)
            && !is_dir($this->publicSharesDirectory)
        ) {
            throw new \RuntimeException('Failed to create the public shares directory.');
        }
    }

    /**
     * Resolve a path stored in the database against a user's comic directory.
     *
     * basename() is used rather than stripping "../" sequences: filtering can be
     * defeated by nested payloads such as "....//", while basename() cannot
     * produce a path outside the directory at all.
     */
    private function resolveUserFile(int $userId, string $storedPath, bool $keepCoverPrefix = false): string
    {
        $base = $this->comicsDirectory . '/' . $userId;

        if (!$keepCoverPrefix) {
            return $base . '/' . basename($storedPath);
        }

        // Cover paths are stored as "covers/{comicId}/{file}"; rebuild them from
        // their own components so no segment can escape the directory.
        $segments = array_map('basename', array_filter(explode('/', $storedPath), static fn ($s) => $s !== ''));

        return $base . '/' . implode('/', $segments);
    }

    #[Route('/pending', name: 'app_share_pending', methods: ['GET'])]
    public function getPendingShares(
        ShareTokenRepository $shareTokenRepository,
        #[CurrentUser] ?User $currentUser
    ): JsonResponse {

        if (!$currentUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        
        // Find pending shares for the current user's email
        $pendingShares = $shareTokenRepository->findPendingSharesByEmail($currentUser->getEmail());
        
        // Format the response data
        $formattedShares = [];
        foreach ($pendingShares as $share) {
            $comic = $share->getComic();
            $sharedBy = $share->getSharedByUser();
            
            $formattedShares[] = [
                'id' => $share->getId(),
                'token' => $share->getToken(),
                'comic' => [
                    'id' => $comic->getId(),
                    'title' => $comic->getTitle(),
                    'author' => $comic->getAuthor(),
                    'coverImagePath' => $comic->getCoverImagePath(),
                ],
                'sharedBy' => [
                    'id' => $sharedBy->getId(),
                    'name' => $sharedBy->getName(),
                    'email' => $sharedBy->getEmail(),
                ],
                'createdAt' => $share->getCreatedAt()->format('c'),
                'expiresAt' => $share->getExpiresAt()->format('c'),
            ];
        }
        
        return new JsonResponse(['pendingShares' => $formattedShares]);
    }
    
    #[Route('/refuse/{token}', name: 'app_share_refuse', methods: ['POST'])]
    public function refuseShareAction(
        string $token,
        #[CurrentUser] ?User $currentUser,
        EntityManagerInterface $entityManager,
        ShareTokenRepository $shareTokenRepository,
        LoggerInterface $logger
    ): JsonResponse {
        if (!$currentUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        
        $shareToken = $shareTokenRepository->findOneBy(['token' => $token, 'isUsed' => false]);
        
        if (!$shareToken) {
            return new JsonResponse(['error' => 'Share link not found or already used'], Response::HTTP_NOT_FOUND);
        }
        
        if ($shareToken->getExpiresAt() < new \DateTimeImmutable()) {
            return new JsonResponse(['error' => 'Share link expired'], Response::HTTP_GONE);
        }
        
        if ($shareToken->getSharedWithEmail() !== $currentUser->getEmail()) {
            return new JsonResponse(['error' => 'Share link not intended for this account'], Response::HTTP_FORBIDDEN);
        }
        
        try {
            // Mark the share as used
            $shareToken->setIsUsed(true);
            $entityManager->persist($shareToken);
            
            $entityManager->flush();
            
            return new JsonResponse(['message' => 'Share refused successfully'], Response::HTTP_OK);
        } catch (\Exception $e) {
            $logger->error('Error refusing share: ' . $e->getMessage());
            return new JsonResponse(['error' => 'An error occurred while refusing the share'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/comic/{comicId}', name: 'app_share_comic', methods: ['POST'])]
    public function shareComicAction(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        Environment $twig,
        ShareTokenRepository $shareTokenRepository,
        int $comicId,
        #[CurrentUser] ?User $currentUser
    ): JsonResponse {
        if (!$currentUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Get the comic
        $comic = $entityManager->getRepository(Comic::class)->find($comicId);
        if (!$comic) {
            return new JsonResponse(['error' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if the user owns the comic
        if ($comic->getOwner()->getId() !== $currentUser->getId()) {
            return new JsonResponse(['error' => 'You can only share comics you own'], Response::HTTP_FORBIDDEN);
        }

        // Get the recipient email from the request
        $data = json_decode($request->getContent(), true);
        $recipientEmail = $data['email'] ?? null;

        if (!$recipientEmail) {
            return new JsonResponse(['error' => 'Recipient email is required'], Response::HTTP_BAD_REQUEST);
        }

        // Validate the email
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Invalid email address'], Response::HTTP_BAD_REQUEST);
        }

        // Rate limiting: Check if the user has sent too many share invitations recently
        $recentSharesCount = $shareTokenRepository->countRecentSharesByUser(
            $currentUser,
            (new \DateTimeImmutable())->modify('-1 hour')
        );

        $maxSharesPerHour = 10; // Adjust as needed
        if ($recentSharesCount >= $maxSharesPerHour) {
            return new JsonResponse(
                ['error' => 'You have sent too many share invitations recently. Please try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        try {
            // Create a new ShareToken entity
            $shareToken = new ShareToken($comic, $currentUser, $recipientEmail);
            
            // Copy the comic cover to the public shares directory if it exists
            if ($comic->getCoverImagePath()) {
                $coverPath = $this->resolveUserFile($currentUser->getId(), $comic->getCoverImagePath(), true);

                if (file_exists($coverPath)) {
                    $this->ensurePublicSharesDirectory();

                    // Create a unique filename for the shared cover
                    $sharedCoverFilename = 'share_' . $shareToken->getToken() . '_' . basename($comic->getCoverImagePath());
                    $sharedCoverPath = $this->publicSharesDirectory . '/' . $sharedCoverFilename;

                    // Copy the cover to the public shares directory
                    copy($coverPath, $sharedCoverPath);

                    // Store the public path in the token
                    $shareToken->setPublicCoverPath('shared/' . $sharedCoverFilename);
                }
            }

            // Persist the ShareToken entity
            $entityManager->persist($shareToken);
            $entityManager->flush();

            // Generate the share link
            $shareLink = $this->frontendUrl . '/share/accept/' . $shareToken->getToken();

            // Get the user's name and email for the email template
            $userName = $currentUser->getName();
            $userEmail = $currentUser->getEmail();

            // Render the email template
            $emailBody = $twig->render('emails/share_comic.html.twig', [
                'comic' => $comic,
                'userName' => $userName,
                'shareLink' => $shareLink,
                'expiresAt' => $shareToken->getExpiresAt(),
            ]);

            // Send the email from the configured application address, with the
            // sharing user reachable via reply-to.
            $email = (new Email())
                ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                ->replyTo($userEmail)
                ->to($recipientEmail)
                ->subject($userName . ' shared a comic with you!')
                ->html($emailBody);

            $mailer->send($email);

            return new JsonResponse([
                'message' => 'Comic shared successfully',
                'shareToken' => $shareToken->getToken(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to share comic.', ['comic_id' => $comicId, 'exception' => $e]);
            return new JsonResponse(['error' => 'Failed to share comic.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/accept/{token}', name: 'app_share_accept', methods: ['POST'])]
    public function acceptShareAction(
        string $token,
        #[CurrentUser] ?User $currentUser,
        EntityManagerInterface $entityManager,
        ShareTokenRepository $shareTokenRepository,
        TagRepository $tagRepository,
        ComicService $comicService,
        LoggerInterface $logger // For logging non-critical errors
    ): JsonResponse {
        if (!$currentUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $shareToken = $shareTokenRepository->findOneBy(['token' => $token]);

        if (!$shareToken) {
            return new JsonResponse(['error' => 'Share link not found'], Response::HTTP_NOT_FOUND);
        }

        if ($shareToken->isIsUsed()) {
            return new JsonResponse(['error' => 'Share link already used'], Response::HTTP_GONE);
        }

        if ($shareToken->getExpiresAt() < new \DateTimeImmutable()) {
            $shareToken->setIsUsed(true);
            $entityManager->persist($shareToken);
            $entityManager->flush();
            return new JsonResponse(['error' => 'Share link expired'], Response::HTTP_GONE);
        }

        if ($shareToken->getSharedWithEmail() !== $currentUser->getEmail()) {
            return new JsonResponse(['error' => 'Share link not intended for this account'], Response::HTTP_FORBIDDEN);
        }

        $originalComic = $shareToken->getComic();
        if (!$originalComic) { // Should not happen if DB integrity is maintained
            return new JsonResponse(['error' => 'Original comic not found for this token'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $sharerId = $shareToken->getSharedByUser()->getId();
        $originalComicPath = $this->resolveUserFile($sharerId, (string) $originalComic->getFilePath());

        if (!is_file($originalComicPath)) {
            return new JsonResponse(['error' => 'The shared comic file is no longer available'], Response::HTTP_GONE);
        }

        // An accepted share is a full copy on disk, so it has to be charged
        // against the recipient's quota just like an upload would be.
        $incomingSize = (int) (filesize($originalComicPath) ?: 0);
        if ($comicService->wouldExceedQuota($currentUser, $incomingSize)) {
            return new JsonResponse(
                ['error' => 'Accepting this comic would exceed your storage quota'],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        }

        $entityManager->beginTransaction();

        try {
            $newComic = new Comic();
            $newComic->setTitle($originalComic->getTitle());
            $newComic->setDescription($originalComic->getDescription());
            $newComic->setAuthor($originalComic->getAuthor());
            $newComic->setPublisher($originalComic->getPublisher());
            $newComic->setPageCount($originalComic->getPageCount());
            $newComic->setOwner($currentUser);
            // Set upload date (creation date) for the new copy
            $newComic->setUploadedAt(new \DateTimeImmutable());
            $newComic->setUpdatedAt(new \DateTimeImmutable());

            // Copy Comic File (CBZ)
            $recipientComicDir = $this->comicsDirectory . '/' . $currentUser->getId();

            if (!is_dir($recipientComicDir) && !mkdir($recipientComicDir, 0775, true) && !is_dir($recipientComicDir)) {
                throw new \RuntimeException('Failed to create recipient comic directory.');
            }

            // Get the file extension from the original comic
            $extension = pathinfo((string) $originalComic->getFilePath(), PATHINFO_EXTENSION);

            // Use a UUID for shared comics to distinguish them from original uploads
            $uuid = bin2hex(random_bytes(16)); // Generate a UUID
            $newComicFilename = $uuid . '.' . $extension;
            $newComicPath = $recipientComicDir . '/' . $newComicFilename;

            if (!copy($originalComicPath, $newComicPath)) {
                throw new \RuntimeException('Failed to copy comic file.');
            }
            $newComic->setFilePath($newComicFilename); // Store relative path to recipient's comic dir
            // Without this the copy is invisible to quota accounting, which sums fileSize.
            $newComic->setFileSize((int) (filesize($newComicPath) ?: $incomingSize));

            // Persist newComic to get its ID for cover path
            $entityManager->persist($newComic);
            $entityManager->flush(); // Flush here to get the ID

            $newComicId = $newComic->getId();

            // Copy Cover Image
            if ($originalComic->getCoverImagePath()) {
                // Cover paths are relative to the sharer's comic directory, e.g. "covers/{comicId}/cover.jpg"
                $originalCoverRelativePath = $originalComic->getCoverImagePath();
                $originalCoverPath = $this->resolveUserFile($sharerId, $originalCoverRelativePath, true);

                if (file_exists($originalCoverPath)) {
                    $newCoverExtension = pathinfo($originalCoverRelativePath, PATHINFO_EXTENSION);
                    $newCoverDir = $recipientComicDir . '/covers/' . $newComicId;

                    if (is_dir($newCoverDir) || mkdir($newCoverDir, 0775, true) || is_dir($newCoverDir)) {
                        // Use the same UUID as the comic file for consistency
                        $newCoverFilename = $uuid . '.' . $newCoverExtension;
                        $newCoverPath = $newCoverDir . '/' . $newCoverFilename;

                        if (copy($originalCoverPath, $newCoverPath)) {
                            // Store the relative path from the user's comic directory
                            $newComic->setCoverImagePath('covers/' . $newComicId . '/' . $newCoverFilename);
                        } else {
                            // Continue without a cover rather than failing the whole acceptance
                            $logger->error("Failed to copy cover image from {$originalCoverPath} to {$newCoverPath}");
                        }
                    } else {
                        $logger->error("Failed to create cover directory for new comic ID {$newComicId}");
                    }
                }
            }

            // Copy Tags. Everything here stays inside the surrounding transaction:
            // flushing inside a try/catch per tag would close the EntityManager on
            // the first constraint violation and break every later operation.
            $existingTags = [];
            foreach ($tagRepository->findBy(['creator' => $currentUser]) as $tag) {
                $existingTags[mb_strtolower($tag->getName())] = $tag;
            }

            foreach ($originalComic->getTags() as $originalTag) {
                $tagName = $originalTag->getName();
                $tagKey = mb_strtolower($tagName);

                if (!isset($existingTags[$tagKey])) {
                    $newTag = new Tag();
                    $newTag->setName($tagName);
                    $newTag->setCreator($currentUser);
                    $entityManager->persist($newTag);
                    $existingTags[$tagKey] = $newTag;
                }

                $newComic->addTag($existingTags[$tagKey]);
            }

            // Mark the share token as used
            $shareToken->setIsUsed(true);
            $entityManager->persist($shareToken);
            
            // Clean up the public cover image if it exists
            if ($shareToken->getPublicCoverPath()) {
                $publicCoverPath = $this->publicSharesDirectory . '/' . basename($shareToken->getPublicCoverPath());
                if (file_exists($publicCoverPath)) {
                    @unlink($publicCoverPath);
                }
            }
            
            // Save all changes
            $entityManager->persist($newComic);
            $entityManager->flush();
            $entityManager->commit();
            
            return new JsonResponse([
                'message' => 'Comic accepted successfully',
                'comic' => [
                    'id' => $newComic->getId(),
                    'title' => $newComic->getTitle(),
                    'author' => $newComic->getAuthor(),
                    'coverImagePath' => $newComic->getCoverImagePath(),
                ]
            ]);
        } catch (\Throwable $e) {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }
            $logger->error('Error accepting shared comic.', ['token' => $token, 'exception' => $e]);
            return new JsonResponse(['error' => 'Failed to accept shared comic.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
