<?php

namespace App\Controller;

use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Entity\User;
use App\Repository\ShareClaimCodeRepository;
use App\Service\ExpiredShareCleanupService;
use App\Service\Pagination\PaginationRequest;
use App\Service\ShareClaimCodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Operational tooling for the sharing codes an instance has issued.
 *
 * Content codes are capabilities that leave the building — pasted into chats,
 * forwarded, posted by mistake — so somebody has to be able to see what is
 * outstanding and stop one without going to the database. This is that surface,
 * and it is the whole of it: everything here reads metadata or calls a lifecycle
 * method that already exists.
 *
 * Three things it deliberately cannot do. It cannot show a code: the encrypted
 * copy an instance keeps is the *owner's* record of what they handed out, and
 * support acting on a report needs to stop a code rather than hold one. It
 * cannot revoke a share,
 * because withdrawing a code stops the way in and never the access already
 * granted — taking a comic back is moderation, which is a different decision on
 * a different screen. And it cannot delete a live record, because the cleanup
 * it runs is the retention sweep and nothing else.
 *
 * User codes are not managed here. Their lifecycle is rotation, which belongs
 * on the admin user page beside the account it identifies.
 */
#[Route('/api/admin/sharing-codes')]
#[IsGranted('ROLE_ADMIN')]
final class AdminShareCodeController extends AbstractController
{
    public function __construct(
        private readonly ShareClaimCodeRepository $contentCodes,
        private readonly ShareClaimCodeService $contentCodeService,
        private readonly ExpiredShareCleanupService $cleanup,
    ) {
    }

    #[Route('', name: 'app_admin_sharing_codes_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = PaginationRequest::fromRequest(
            $request,
            ShareClaimCodeRepository::ADMIN_SORT_FIELDS,
            'createdAt'
        );

        $status = $request->query->get('status');
        if (!in_array($status, ['active', 'expired', 'withdrawn', 'exhausted', 'comics_removed'], true)) {
            $status = null;
        }

        $page = $this->contentCodes->findAdminPage($pagination, [
            'status' => $status,
            'ownerId' => $request->query->has('ownerId') ? $request->query->getInt('ownerId') : null,
            'createdFrom' => $this->date($request->query->get('createdFrom')),
            'createdTo' => $this->date($request->query->get('createdTo'), endOfDay: true),
            'expiresFrom' => $this->date($request->query->get('expiresFrom')),
            'expiresTo' => $this->date($request->query->get('expiresTo'), endOfDay: true),
        ]);

        return $this->json([
            'items' => array_map(
                static fn (ShareClaimCode $code): array => $code->toAdminPayload(),
                $page->items
            ),
            'pagination' => $page->toArray(),
            'retentionAfterExpiry' => ltrim(ShareClaimCode::RETENTION_AFTER_EXPIRY, '+'),
            // Two windows on two clocks. The sweep dialog describes both, so it
            // reads them from here rather than assuming they stay equal.
            'retentionAfterRevocation' => ltrim(ComicShare::RETENTION_AFTER_REVOCATION, '+'),
        ]);
    }

    #[Route('/{id}/revoke', name: 'app_admin_sharing_codes_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revoke(int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $code = $this->contentCodeService->revokeAsAdministrator($id, $admin);

        return $this->json([
            'message' => 'Sharing code withdrawn. Comics already claimed through it are unaffected.',
            'contentCode' => $code->toAdminPayload(),
        ]);
    }

    /**
     * Run the retention sweep by hand.
     *
     * The scheduled command remains the normal path; this is for an
     * installation whose cron is not running, or the first minutes after a
     * deployment. Both call the same service, so an administrator pressing this
     * cannot remove anything the nightly job would have left alone.
     */
    #[Route('/cleanup', name: 'app_admin_sharing_codes_cleanup', methods: ['POST'])]
    public function runCleanup(): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $removed = $this->cleanup->runForAdministrator($admin);

        return $this->json([
            'message' => sprintf(
                '%d expired invitation(s), %d dead sharing code(s) and %d long-revoked share(s) removed.',
                $removed['invitations'],
                $removed['claimCodes'],
                $removed['revokedShares']
            ),
            'invitationsRemoved' => $removed['invitations'],
            'contentCodesRemoved' => $removed['claimCodes'],
            'revokedSharesRemoved' => $removed['revokedShares'],
        ]);
    }

    /**
     * A date filter from the query string, or null.
     *
     * Unparseable input is dropped rather than rejected: a filter the operator
     * cannot see is not worth a 400, and showing the unfiltered table is the
     * safer failure — it shows more than they asked for rather than silently
     * less.
     */
    private function date(mixed $value, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }

        // A day given as a bare date means the whole of it, so "to 3 August"
        // includes everything that happened on the 3rd.
        return $endOfDay && !str_contains($value, ':') ? $date->setTime(23, 59, 59) : $date;
    }
}
