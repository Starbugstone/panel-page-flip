<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Service\ComicShareSerializer;
use App\Service\Pagination\PaginationRequest;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The share grants themselves, as opposed to the codes some of them came from.
 *
 * The sharing-codes table can stop a code from being redeemed again; it cannot
 * see a share made by emailed invitation, and it cannot take back access that
 * has already been granted. Both of those are what somebody acting on a report
 * about a specific comic reaching a specific person actually needs.
 *
 * Revoking here is the same operation the owner performs from their own Sharing
 * page, applied by an administrator: the recipient loses access, the owner
 * keeps their comic, and nothing is deleted. Removing the comic is a different
 * and heavier decision that lives on the content-report screen.
 */
#[Route('/api/admin/shares')]
#[IsGranted('ROLE_ADMIN')]
final class AdminShareController extends AbstractController
{
    public function __construct(
        private readonly ComicShareRepository $shares,
        private readonly ComicShareSerializer $serializer,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'app_admin_shares_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = PaginationRequest::fromRequest(
            $request,
            ComicShareRepository::ADMIN_SORT_FIELDS,
            'createdAt'
        );

        $page = $this->shares->findAdminPage($pagination, [
            'status' => $request->query->get('status'),
            'comicId' => $request->query->has('comicId') ? $request->query->getInt('comicId') : null,
            'ownerId' => $request->query->has('ownerId') ? $request->query->getInt('ownerId') : null,
            'explicitOnly' => $request->query->getBoolean('explicitOnly'),
        ]);

        return $this->json([
            'items' => array_map(
                fn (ComicShare $share): array => $this->serializer->toAdminPayload($share),
                $page->items
            ),
            'pagination' => $page->toArray(),
        ]);
    }

    /**
     * Take one recipient's access away.
     *
     * Idempotent: revoking an already-revoked share is the state the caller
     * wanted, so it answers the same way rather than erroring on a double
     * click or a stale table.
     */
    #[Route('/{id}/revoke', name: 'app_admin_shares_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $share = $this->shares->find($id);

        if ($share === null) {
            return $this->json(['message' => 'That share was not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($share->getStatus() !== ComicShare::STATUS_REVOKED) {
            $share->markRevoked();
            $this->entityManager->flush();

            $this->auditLogger->audit(SecurityAuditLogger::COMIC_SHARE_ADMIN_REVOKED, [
                'actor_user_id' => $admin->getId(),
                'target_type' => 'comic_share',
                'target_id' => $share->getId(),
                'comic_id' => $share->getComic()?->getId(),
                'owner_user_id' => $share->getOwner()?->getId(),
                // The recipient's id where they have an account. Never the
                // stored address: a share to somebody with no account yet is
                // identified by an email that has no business in the log.
                'recipient_user_id' => $share->getRecipientUser()?->getId(),
            ]);
        }

        return $this->json([
            'message' => 'Share revoked. The recipient no longer has access; the comic is untouched.',
            'share' => $this->serializer->toAdminPayload($share),
        ]);
    }
}
