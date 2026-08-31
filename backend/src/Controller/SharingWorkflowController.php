<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComicShare;
use App\Entity\LibraryFolder;
use App\Service\LibraryFolderService;
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
        private readonly LibraryFolderService $folders,
    ) {
    }

    /**
     * What sharing this folder would hand over, before anybody is named.
     *
     * Read-only, and the reason folder sharing is one click: the client shows
     * the count and the titles it is about to offer without the sender having
     * to tick forty-two boxes. It is a preview and never the authority — the
     * share itself re-resolves the folder, so a comic added, moved or revoked in
     * between changes what is sent rather than what was previewed.
     */
    #[Route('/folders/{folderId}/comics', name: 'app_shares_folder_comics', methods: ['GET'], requirements: ['folderId' => '\d+'])]
    public function folderComics(int $folderId): JsonResponse
    {
        $user = $this->requireUser();

        $folder = $this->folders->findOwned($user, $folderId);
        // Not found rather than forbidden, the same answer the folder API gives:
        // confirming that an id exists would say something about another
        // account's library.
        if (!$folder instanceof LibraryFolder) {
            return $this->json(['message' => 'Folder not found.'], Response::HTTP_NOT_FOUND);
        }

        $contents = $this->workflow->folderShareContents($user, $folder);

        return $this->json([
            'folder' => ['id' => (int) $folder->getId(), 'name' => $folder->getName()],
            'comicIds' => array_map(static fn ($comic): int => (int) $comic->getId(), $contents['comics']),
            'comicCount' => count($contents['comics']),
            'folderCount' => $contents['folderCount'],
            'unshareableCount' => $contents['unshareableCount'],
            // Administrators may share an entire folder regardless of size.
            // Null is deliberate: it distinguishes "unlimited" from a large
            // guess that a sufficiently large library could eventually hit.
            'limit' => $user->isAdmin() ? null : SharingWorkflowService::MAX_FOLDER_COMICS,
        ]);
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
            // Including when the recipient resolves to the sender. Naming
            // yourself is refused by ComicShareService, which owns that rule
            // for every recipient form; a second copy here answered a
            // different status for a username than an address got.
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

        // Two ways to say what is going, and exactly one of them per request:
        // a list the sender assembled, or a folder they pointed at. Kept apart
        // for the same reason the three recipient forms are — a request whose
        // two halves disagree would still share something, and which something
        // would be decided here rather than by the sender.
        $namesFolder = ($data['folderId'] ?? null) !== null;
        $namesComics = ($data['comicIds'] ?? null) !== null;

        if ($namesFolder && $namesComics) {
            return $this->json([
                'message' => 'Name what you are sharing one way only: a folder, or a list of comics.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $sourceFolder = null;

        if ($namesFolder) {
            $resolved = $this->resolveShareableFolder($data['folderId'], $user);
            if ($resolved instanceof JsonResponse) {
                return $resolved;
            }

            [$sourceFolder, $comicIds] = $resolved;
        } else {
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
            ($data['markExplicit'] ?? null) === true,
            $sourceFolder
        );

        $status = $result['created'] === $result['total']
            ? Response::HTTP_CREATED
            : Response::HTTP_MULTI_STATUS;

        return $this->json($result, $status);
    }

    /**
     * The folder being shared and everything in it the sender may pass on — or
     * the response to return instead of sharing anything.
     *
     * The ids are resolved here rather than trusted from the request, which is
     * what makes the larger folder ceiling safe to offer: the sender is not
     * handing over a list of two hundred ids, they are pointing at a folder the
     * server then walks. A hand-written `folderId` reaches nothing that
     * {@see SharingWorkflowService::folderShareContents()} would not have given
     * the owner in the preview.
     *
     * @return array{0: LibraryFolder, 1: list<int>}|JsonResponse
     */
    private function resolveShareableFolder(mixed $rawFolderId, \App\Entity\User $user): array|JsonResponse
    {
        if (!is_int($rawFolderId) && !(is_string($rawFolderId) && ctype_digit($rawFolderId))) {
            return $this->json(['message' => 'A folder id must be a positive integer.'], Response::HTTP_BAD_REQUEST);
        }

        $folder = (int) $rawFolderId < 1 ? null : $this->folders->findOwned($user, (int) $rawFolderId);
        if (!$folder instanceof LibraryFolder) {
            return $this->json(['message' => 'Folder not found.'], Response::HTTP_NOT_FOUND);
        }

        $contents = $this->workflow->folderShareContents($user, $folder);
        $comics = $contents['comics'];

        if ($comics === []) {
            // Said as one sentence whether the folder is empty or holds only
            // comics somebody else shared in: the sender is looking at the
            // folder and the answer they need is the same either way.
            return $this->json([
                'message' => 'There is nothing in that folder that you can share.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$user->isAdmin() && count($comics) > SharingWorkflowService::MAX_FOLDER_COMICS) {
            return $this->json([
                'message' => sprintf(
                    'That folder holds %d comics, and a folder share carries at most %d.',
                    count($comics),
                    SharingWorkflowService::MAX_FOLDER_COMICS
                ),
            ], Response::HTTP_BAD_REQUEST);
        }

        return [$folder, array_map(static fn ($comic): int => (int) $comic->getId(), $comics)];
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
