<?php

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ShareToken;
use App\Entity\User;
use App\Repository\ShareTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;
use App\Repository\ComicRepository;
use App\Repository\TagRepository;
use App\Entity\Tag;
use Symfony\Component\String\Slugger\SluggerInterface;
use Psr\Log\LoggerInterface; // For logging cover copy errors

#[Route('/api/share')]
class ShareController extends AbstractController
{
    private string $comicsDirectory;
    private string $frontendUrl;

    public function __construct(string $comicsDirectory, string $frontendUrl)
    {
        $this->comicsDirectory = $comicsDirectory;
        $this->frontendUrl = $frontendUrl;
    }

    #[Route('/comic/{comicId}', name: 'app_share_comic', methods: ['POST'])]
    public function shareComicAction(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        Environment $twig,
        ShareTokenRepository $shareTokenRepository,
        ValidatorInterface $validator,
        int $comicId,
        #[CurrentUser] ?User $currentUser
    ): JsonResponse {
        // Get the current logged-in user
        if (!$currentUser) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Fetch the Comic entity
        $comic = $entityManager->getRepository(Comic::class)->find($comicId);

        if (!$comic) {
            return new JsonResponse(['error' => 'Comic not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if the comic's owner is the current user
        if ($comic->getOwner() !== $currentUser) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // Get recipientEmail from the JSON request body
        $data = json_decode($request->getContent(), true);
        $recipientEmail = $data['recipientEmail'] ?? null;

        if (empty($recipientEmail)) {
            return new JsonResponse(['error' => 'Recipient email is required'], Response::HTTP_BAD_REQUEST);
        }
        
        $violations = $validator->validate($recipientEmail, new \Symfony\Component\Validator\Constraints\Email());
        if (count($violations) > 0) {
            return new JsonResponse(['error' => 'Invalid email format'], Response::HTTP_BAD_REQUEST);
        }
        
        // Rate limiting: Check if user has sent too many share invitations recently
        $recentSharesCount = $shareTokenRepository->countRecentSharesByUser(
            $currentUser, 
            new \DateTimeImmutable('-1 hour')
        );
        
        // Limit to 10 shares per hour
        if ($recentSharesCount >= 10) {
            return new JsonResponse(
                ['error' => 'Rate limit exceeded. Please try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        // Check if recipientEmail is the same as the sender's email
        if ($recipientEmail === $currentUser->getEmail()) {
            return new JsonResponse(['error' => 'You cannot share a comic with yourself.'], Response::HTTP_BAD_REQUEST);
        }

        // Check if an active ShareToken already exists
        $existingToken = $shareTokenRepository->findOneBy([
            'comic' => $comic,
            'sharedWithEmail' => $recipientEmail,
            'isUsed' => false,
        ]);

        if ($existingToken && $existingToken->getExpiresAt() > new \DateTimeImmutable()) {
            return new JsonResponse(
                ['error' => 'This comic has already been shared with this email address and the token is still active.'],
                Response::HTTP_CONFLICT
            );
        }
        
        // If an expired or used token exists, we can create a new one.
        // If existingToken is not null here, it means it's either used or expired, so it's fine to create a new one.

        try {
            // Create a new ShareToken entity
            $shareToken = new ShareToken($comic, $currentUser, $recipientEmail);

            // Persist the ShareToken entity
            $entityManager->persist($shareToken);
            $entityManager->flush();

            // Construct the share link - hardcoded for development
            $shareLink = 'http://localhost:3001/share/accept/' . $shareToken->getToken();

            // Render the email content
            // Assuming tokenExpirationDays is a parameter or can be derived
            $tokenExpirationDays = $shareToken->getExpiresAt()->diff(new \DateTimeImmutable())->days;

            $emailBody = $twig->render('emails/share_comic.html.twig', [
                'shareLink' => $shareLink,
                'sharedByUser' => $currentUser,
                'comic' => $comic,
                'recipientEmail' => $recipientEmail,
                'tokenExpirationDays' => $tokenExpirationDays,
            ]);

            // Send the email
            $systemFromAddress = $this->getParameter('mailer_from_address');
            $systemFromName = $this->getParameter('mailer_from_name');
            
            // Get user's name (fallback to email if name is not available)
            $userName = $currentUser->getName() ?: $currentUser->getEmail();
            $userEmail = $currentUser->getEmail();
            
            // Create the email with proper from and reply-to addresses
            $email = new Email();
            
            // Set the system name for the from address
            if ($systemFromName) {
                $email->from(new \Symfony\Component\Mime\Address($systemFromAddress, $systemFromName));
            } else {
                $email->from($systemFromAddress);
            }
            
            // Set the user's name for the reply-to address
            if ($userName) {
                $email->replyTo(new \Symfony\Component\Mime\Address($userEmail, $userName));
            } else {
                $email->replyTo($userEmail);
            }
            
            // Set other email properties
            $email->to($recipientEmail)
                ->subject($userName . ' shared a comic with you!')
                ->html($emailBody);

            $mailer->send($email);

            return new JsonResponse(['message' => 'Comic shared successfully.'], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            // Log the exception message if needed: $e->getMessage()
            return new JsonResponse(['error' => 'An unexpected error occurred: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/accept/{token}', name: 'app_share_accept', methods: ['GET'])]
    public function acceptShareAction(
        string $token,
        #[CurrentUser] ?User $currentUser,
        EntityManagerInterface $entityManager,
        ShareTokenRepository $shareTokenRepository,
        // ComicRepository $comicRepository, // Not directly used, original comic from token
        TagRepository $tagRepository,
        SluggerInterface $slugger,
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

        $entityManager->beginTransaction();

        try {
            $originalComic = $shareToken->getComic();
            if (!$originalComic) { // Should not happen if DB integrity is maintained
                 return new JsonResponse(['error' => 'Original comic not found for this token'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $newComic = new Comic();
            $newComic->setTitle($originalComic->getTitle());
            $newComic->setDescription($originalComic->getDescription());
            $newComic->setAuthor($originalComic->getAuthor());
            $newComic->setPublisher($originalComic->getPublisher());
            $newComic->setPageCount($originalComic->getPageCount());
            $newComic->setOwner($currentUser);
            $newComic->setCreatedAt(new \DateTimeImmutable()); // Set creation date for the new copy
            $newComic->setUpdatedAt(new \DateTimeImmutable()); // Set updated date for the new copy

            // Copy Comic File (CBZ)
            $sharerId = $shareToken->getSharedByUser()->getId();
            $originalComicRelativePath = $originalComic->getFilePath(); // e.g., "comic-file.cbz" or "subfolder/comic-file.cbz" if owner organises them
            
            // Prevent path traversal by removing any directory traversal sequences
            $sanitizedPath = str_replace(['../', '..\\', './'], '', $originalComicRelativePath);
            
            // The full path should be $this->comicsDirectory . '/' . $sharerId . '/' . $originalComic->getFilePath()
            $originalComicPath = $this->comicsDirectory . '/' . $sharerId . '/' . $sanitizedPath;


            $recipientId = $currentUser->getId();
            $recipientComicDir = $this->comicsDirectory . '/' . $recipientId;

            if (!file_exists($recipientComicDir)) {
                if (!mkdir($recipientComicDir, 0775, true)) {
                    throw new \RuntimeException('Failed to create recipient comic directory.');
                }
                // Ensure proper permissions are set
                chmod($recipientComicDir, 0775);
            }

            $originalFilenameWithoutExt = pathinfo($originalComicRelativePath, PATHINFO_FILENAME);
            $extension = pathinfo($originalComicRelativePath, PATHINFO_EXTENSION);
            
            $newSafeFilename = $slugger->slug($originalFilenameWithoutExt)->lower();
            $newComicFilename = $newSafeFilename . '-' . uniqid() . '.' . $extension;
            $newComicPath = $recipientComicDir . '/' . $newComicFilename;

            if (!copy($originalComicPath, $newComicPath)) {
                throw new \RuntimeException('Failed to copy comic file.');
            }
            $newComic->setFilePath($newComicFilename); // Store relative path to recipient's comic dir

            // Persist newComic to get its ID for cover path
            $entityManager->persist($newComic);
            $entityManager->flush(); // Flush here to get the ID

            $newComicId = $newComic->getId();

            // Copy Cover Image
            if ($originalComic->getCoverImagePath()) {
                // originalCoverImagePath is relative to sharer's comic directory, e.g., "covers/comic_id/cover.jpg"
                $originalCoverRelativePath = $originalComic->getCoverImagePath(); 
                // Prevent path traversal by removing any directory traversal sequences
                $sanitizedCoverPath = str_replace(['../', '..\\', './'], '', $originalCoverRelativePath);
                $originalCoverPath = $this->comicsDirectory . '/' . $sharerId . '/' . $sanitizedCoverPath;


                if (file_exists($originalCoverPath)) {
                    $originalCoverFilenameWithoutExt = pathinfo($originalCoverRelativePath, PATHINFO_FILENAME);
                    $newCoverExtension = pathinfo($originalCoverRelativePath, PATHINFO_EXTENSION);

                    $newCoverDir = $recipientComicDir . '/covers/' . $newComicId;
                    if (!file_exists($newCoverDir)) {
                        if (!mkdir($newCoverDir, 0775, true)) {
                            $logger->error("Failed to create cover directory for new comic ID {$newComicId}");
                            // Continue without cover image rather than failing the entire operation
                        } else {
                            chmod($newCoverDir, 0775);
                        }
                    }
                    
                    $newCoverSafeFilename = $slugger->slug($originalCoverFilenameWithoutExt)->lower();
                    $newCoverFilename = $newCoverSafeFilename . '-' . uniqid() . '.' . $newCoverExtension;
                    $newCoverFullPath = $newCoverDir . '/' . $newCoverFilename;

                    if (copy($originalCoverPath, $newCoverFullPath)) {
                        $newComic->setCoverImagePath('covers/' . $newComicId . '/' . $newCoverFilename); // Relative to recipient's comic dir
                    } else {
                        $logger->error("Failed to copy cover image for new comic ID {$newComicId} from {$originalCoverPath}");
                    }
                } else {
                     $logger->warning("Original cover image not found at {$originalCoverPath} for original comic ID {$originalComic->getId()}");
                }
            }

            // Copy Tags
            foreach ($originalComic->getTags() as $originalTag) {
                $tag = $tagRepository->findOneBy(['name' => $originalTag->getName()]);
                if (!$tag) {
                    $tag = new Tag();
                    $tag->setName($originalTag->getName());
                    $tag->setCreator($currentUser); // New tags created during sharing are by the recipient
                    $tag->setCreatedAt(new \DateTimeImmutable());
                    $tag->setUpdatedAt(new \DateTimeImmutable());
                    $entityManager->persist($tag);
                    // No flush needed here, will be flushed with newComic and shareToken
                }
                $newComic->addTag($tag);
            }

            $shareToken->setIsUsed(true);
            $entityManager->persist($shareToken);
            $entityManager->persist($newComic); // Persist again if cover image path or tags were updated
            $entityManager->commit();

            // Manually construct array for response to ensure structure
            $responseData = [
                'id' => $newComic->getId(),
                'title' => $newComic->getTitle(),
                'description' => $newComic->getDescription(),
                'author' => $newComic->getAuthor(),
                'publisher' => $newComic->getPublisher(),
                'pageCount' => $newComic->getPageCount(),
                'filePath' => $newComic->getFilePath(),
                'coverImagePath' => $newComic->getCoverImagePath(),
                'owner_id' => $newComic->getOwner() ? $newComic->getOwner()->getId() : null,
                'tags' => array_map(fn(Tag $tag) => ['id' => $tag->getId(), 'name' => $tag->getName()], $newComic->getTags()->toArray()),
                'createdAt' => $newComic->getCreatedAt() ? $newComic->getCreatedAt()->format('c') : null,
                'updatedAt' => $newComic->getUpdatedAt() ? $newComic->getUpdatedAt()->format('c') : null,
            ];

            return new JsonResponse($responseData, Response::HTTP_OK);

        } catch (\Exception $e) {
            $entityManager->rollback();
            $logger->error('Error in acceptShareAction: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An internal error occurred while accepting the share: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
