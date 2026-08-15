<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComicShare;
use App\Service\SharingCodeRecipient;
use App\Service\SharingCodeService;
use App\Service\SharingWorkflowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/shares')]
final class SharingWorkflowController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(
        private readonly SharingWorkflowService $workflow,
        private readonly SharingCodeService $sharingCodes,
    ) {
    }

    #[Route('/recent-recipients', name: 'app_shares_recent_recipients', methods: ['GET'])]
    public function recentRecipients(): JsonResponse
    {
        $user = $this->requireUser();

        return $this->json([
            'recipients' => $this->workflow->recentRecipients($user),
        ]);
    }

    /**
     * This account's own receiver code, issued on first use.
     *
     * Reads back the same value for ever after. There is no companion endpoint
     * that changes it: everybody who was ever given it is holding the old one,
     * and an address book entry that rotates is one that stops working in every
     * conversation it was pasted into.
     */
    #[Route('/my-code', name: 'app_shares_my_code', methods: ['GET'])]
    public function myCode(): JsonResponse
    {
        $user = $this->requireUser();

        $this->sharingCodes->codeFor($user);

        return $this->json($this->sharingCodes->describe($user));
    }

    /**
     * Retire this account's code and take a new one.
     *
     * A receiver code lives in chats, forums and group threads, so it is
     * exactly the kind of thing that escapes further than intended. This is the
     * way back from that. It changes nothing but the identifier: every share
     * already made through the old code is a relationship, not an address, and
     * survives untouched.
     */
    #[Route('/my-code/rotate', name: 'app_shares_rotate_my_code', methods: ['POST'])]
    public function rotateMyCode(): JsonResponse
    {
        $user = $this->requireUser();

        $this->sharingCodes->rotateCode($user);

        return $this->json([
            'message' => 'Your sharing code has been replaced. The old one no longer works.',
        ] + $this->sharingCodes->describe($user));
    }

    /**
     * Who a receiver code belongs to, so a sender can check they have the right
     * person before handing anything over.
     *
     * A POST because it is rate limited and writes to that allowance, and
     * because a code does not belong in a URL that ends up in logs and history.
     * It answers with a display name and nothing else — never an address, an id,
     * or whether the account exists when the code does not resolve.
     */
    #[Route('/resolve-code', name: 'app_shares_resolve_code', methods: ['POST'])]
    public function resolveCode(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
        $code = is_array($data) ? ($data['sharingCode'] ?? null) : null;

        if (!is_string($code)) {
            return $this->json(['message' => 'A sharing code is required.'], Response::HTTP_BAD_REQUEST);
        }

        $recipient = $this->sharingCodes->resolve($code, $user);

        if ($recipient === null) {
            return $this->json(['message' => 'That sharing code is not valid.'], Response::HTTP_NOT_FOUND);
        }

        if ($recipient->getId() === $user->getId()) {
            return $this->json(['message' => 'That is your own sharing code.'], Response::HTTP_CONFLICT);
        }

        return $this->json(['recipient' => $this->sharingCodes->describe($recipient)]);
    }

    #[Route('/invitations/bulk', name: 'app_shares_bulk_invite', methods: ['POST'])]
    public function bulkInvite(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid request body.'], Response::HTTP_BAD_REQUEST);
        }

        if (($data['senderResponsibilityAccepted'] ?? null) !== true) {
            return $this->json([
                'message' => 'You must acknowledge responsibility for the content you share.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Two ways to name a recipient, and exactly one of them per request. A
        // sharing code resolves to an address the sender is never shown; an
        // email is the address they typed themselves.
        $rawSharingCode = $data['sharingCode'] ?? null;
        $viaSharingCode = null;

        if (is_string($rawSharingCode) && trim($rawSharingCode) !== '') {
            $recipient = $this->sharingCodes->resolve($rawSharingCode, $user);

            if ($recipient === null) {
                return $this->json([
                    'message' => 'That sharing code is not valid.',
                ], Response::HTTP_NOT_FOUND);
            }

            $email = ComicShare::normaliseEmail((string) $recipient->getEmail());
            $viaSharingCode = new SharingCodeRecipient(
                $recipient,
                (string) $recipient->getSharingCode(),
                $recipient->getName()
            );
        } else {
            $email = $data['email'] ?? null;
            if (!is_string($email)) {
                return $this->json(['message' => 'A recipient email address is required.'], Response::HTTP_BAD_REQUEST);
            }
            $email = ComicShare::normaliseEmail($email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['message' => 'A valid recipient email address is required.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $rawComicIds = $data['comicIds'] ?? null;
        if (!is_array($rawComicIds) || $rawComicIds === []) {
            return $this->json(['message' => 'Select at least one comic to share.'], Response::HTTP_BAD_REQUEST);
        }
        if (count($rawComicIds) > SharingWorkflowService::MAX_BULK_COMICS) {
            return $this->json([
                'message' => sprintf(
                    'You can share at most %d comics in one action.',
                    SharingWorkflowService::MAX_BULK_COMICS
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        $comicIds = [];
        foreach ($rawComicIds as $rawComicId) {
            if (is_int($rawComicId)) {
                $comicId = $rawComicId;
            } elseif (is_string($rawComicId) && ctype_digit($rawComicId)) {
                $comicId = (int) $rawComicId;
            } else {
                return $this->json(['message' => 'Comic ids must be positive integers.'], Response::HTTP_BAD_REQUEST);
            }

            if ($comicId <= 0) {
                return $this->json(['message' => 'Comic ids must be positive integers.'], Response::HTTP_BAD_REQUEST);
            }
            $comicIds[] = $comicId;
        }

        // The whole batch was refused before anything was created — an
        // exhausted invitation allowance, or a recipient the sender may not
        // invite. Reported as one failure with its real status rather than
        // as a per-comic result, because nothing was attempted.
        $result = $this->workflow->inviteMany(
            $comicIds,
            $user,
            $email,
            true,
            $viaSharingCode
        );

        $status = $result['created'] === $result['total']
            ? Response::HTTP_CREATED
            : Response::HTTP_MULTI_STATUS;

        return $this->json($result, $status);
    }
}
