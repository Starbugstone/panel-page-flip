<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComicShare;
use App\Service\ShareException;
use App\Service\SharingCodeRecipient;
use App\Service\SharingCodeService;
use App\Service\SharingWorkflowService;
use App\Service\UsernamePolicy;
use App\Service\UsernameService;
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
        private readonly UsernameService $usernames,
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
     * This account's own `U-` code and the identity that goes with it.
     *
     * Every account is issued one when it registers, so this reads a value back
     * rather than creating one — the fallback is only for a row that somehow
     * never got one, because a code other people are expected to be able to use
     * cannot wait for its owner's first visit to exist.
     */
    #[Route('/user-code', name: 'app_shares_user_code', methods: ['GET'])]
    public function userCode(): JsonResponse
    {
        $user = $this->requireUser();

        $this->sharingCodes->codeFor($user);

        return $this->json($this->sharingCodes->describe($user));
    }

    /**
     * Retire this account's code and take a new one.
     *
     * A `U-` code lives in chats, forums and group threads, so it is exactly
     * the kind of thing that escapes further than intended. This is the way
     * back from that. It changes nothing but the identifier: every share
     * already made through the old code is a relationship, not an address, and
     * survives untouched.
     */
    #[Route('/user-code/rotate', name: 'app_shares_rotate_user_code', methods: ['POST'])]
    public function rotateUserCode(): JsonResponse
    {
        $user = $this->requireUser();

        $this->sharingCodes->rotateCode($user);

        return $this->json([
            'message' => 'Your user code has been replaced. The old one no longer works.',
        ] + $this->sharingCodes->describe($user));
    }

    /**
     * Who a `U-` code belongs to, so a sender can check they have the right
     * person before handing anything over.
     *
     * A POST because it is rate limited and writes to that allowance, and
     * because a code does not belong in a URL that ends up in logs and history.
     * It answers with the recipient's public identity and nothing else — never
     * an address, an id, or whether the account exists when the code does not
     * resolve.
     */
    #[Route('/user-code/resolve', name: 'app_shares_resolve_user_code', methods: ['POST'])]
    public function resolveUserCode(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $data = \App\Http\JsonRequestDecoder::decode($request);
        $code = is_array($data) ? ($data['userCode'] ?? null) : null;

        if (!is_string($code)) {
            return $this->json(['message' => 'A user code is required.'], Response::HTTP_BAD_REQUEST);
        }

        $recipient = $this->sharingCodes->resolve($code, $user);

        if ($recipient === null) {
            return $this->json(['message' => 'That user code is not valid.'], Response::HTTP_NOT_FOUND);
        }

        if ($recipient->getId() === $user->getId()) {
            return $this->json(['message' => 'That is your own user code.'], Response::HTTP_CONFLICT);
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
            // Carries the error code as well as the sentence, so a client can
            // reopen the acknowledgement rather than only display the failure.
            throw new ShareException(
                'You must acknowledge responsibility for the content you share.',
                Response::HTTP_BAD_REQUEST,
                ShareException::CODE_RESPONSIBILITY_REQUIRED
            );
        }

        // Three ways to name a recipient, and exactly one of them per request.
        // Two of them go through the recipient's public identity and never
        // reveal the address behind it; the third *is* the address, typed by
        // the sender, and is the only way to reach somebody with no account.
        //
        // Enforced here rather than left to a precedence order. Quietly
        // preferring the user code when a client sends a code and an address
        // means a request whose two halves disagree still shares with
        // somebody — and which somebody is decided by this function rather
        // than by the sender.
        $named = array_keys(array_filter(
            [
                'userCode' => $data['userCode'] ?? null,
                'username' => $data['username'] ?? null,
                'email' => $data['email'] ?? null,
            ],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));

        if (count($named) !== 1) {
            return $this->json([
                'message' => $named === []
                    ? 'A recipient is required.'
                    : 'Name the recipient one way only: a username, a user code, or an email address.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $recipientUser = $this->resolveRecipient($data, $user);

        if ($recipientUser instanceof JsonResponse) {
            return $recipientUser;
        }

        if ($recipientUser !== null) {
            if ($recipientUser->getId() === $user->getId()) {
                return $this->json(
                    ['message' => 'You cannot share a comic with yourself.'],
                    Response::HTTP_CONFLICT
                );
            }

            $email = ComicShare::normaliseEmail((string) $recipientUser->getEmail());
            $viaSharingCode = SharingCodeRecipient::forUser($recipientUser);
        } else {
            $viaSharingCode = null;
            $email = $data['email'] ?? null;
            if (!is_string($email)) {
                return $this->json(['message' => 'A recipient is required.'], Response::HTTP_BAD_REQUEST);
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

        $comicIds = ComicIdList::parse($rawComicIds);
        if ($comicIds === null) {
            return $this->json(['message' => 'Comic ids must be positive integers.'], Response::HTTP_BAD_REQUEST);
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
            $viaSharingCode,
            // Promotion only. An absent or false flag is the absence of a
            // claim, never a claim that the comics are fine — clearing 18+ is
            // an intentional edit on the comic itself.
            ($data['markExplicit'] ?? null) === true
        );

        $status = $result['created'] === $result['total']
            ? Response::HTTP_CREATED
            : Response::HTTP_MULTI_STATUS;

        return $this->json($result, $status);
    }

    /**
     * The account named by a `U-` code or a username, or null for an email
     * share — or a response, when what was named does not resolve.
     *
     * @param array<string, mixed> $data
     */
    private function resolveRecipient(array $data, \App\Entity\User $sender): \App\Entity\User|JsonResponse|null
    {
        $rawUserCode = $data['userCode'] ?? null;
        if (is_string($rawUserCode) && trim($rawUserCode) !== '') {
            $recipient = $this->sharingCodes->resolve($rawUserCode, $sender);

            return $recipient ?? $this->json(
                ['message' => 'That user code is not valid.'],
                Response::HTTP_NOT_FOUND
            );
        }

        $rawUsername = $data['username'] ?? null;
        if (is_string($rawUsername) && trim($rawUsername) !== '') {
            // Exact, one at a time, charged for every miss. There is no search
            // and no autocomplete behind this: knowing somebody's username is
            // how you reach them, and no request here can be turned into a list
            // of who exists.
            $recipient = $this->usernames->resolve(UsernamePolicy::stripPrefix($rawUsername), $sender);

            return $recipient ?? $this->json(
                ['message' => 'No account has that username.'],
                Response::HTTP_NOT_FOUND
            );
        }

        return null;
    }
}
