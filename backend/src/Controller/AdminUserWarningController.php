<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Entity\UserWarning;
use App\Http\JsonRequestDecoder;
use App\Repository\ComicShareRepository;
use App\Repository\UserWarningRepository;
use App\Service\Pagination\PaginationRequest;
use App\Service\UserWarningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Telling one account that something needs to change.
 *
 * The step between noticing a problem and acting on it. Without it an
 * administrator's only options are to leave a mis-tagged comic alone or take it
 * away, and in both cases the account never finds out what was wrong — so the
 * same thing happens next week.
 *
 * One endpoint for all three places it is offered from, because a warning about
 * an account, a comic and a share differ only in what they reference. The
 * recipient is always a person: warning "a comic" means warning whoever owns
 * it, and this resolves that rather than making three callers agree about it.
 */
#[Route('/api/admin/warnings')]
#[IsGranted('ROLE_ADMIN')]
final class AdminUserWarningController extends AbstractController
{
    public function __construct(
        private readonly UserWarningService $warnings,
        private readonly UserWarningRepository $warningRepository,
        private readonly ComicShareRepository $shares,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_warnings_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = PaginationRequest::fromRequest(
            $request,
            UserWarningRepository::ADMIN_SORT_FIELDS,
            'createdAt'
        );

        $page = $this->warningRepository->findAdminPage($pagination, [
            'recipientId' => $request->query->has('recipientId')
                ? $request->query->getInt('recipientId')
                : null,
            'openOnly' => $request->query->getBoolean('openOnly'),
        ]);

        return $this->json([
            'items' => array_map(
                static fn (UserWarning $warning): array => $warning->toAdminPayload(),
                $page->items
            ),
            'pagination' => $page->toArray(),
        ]);
    }

    /**
     * Warn somebody.
     *
     * The body names exactly one target — a user, a comic or a share — and the
     * recipient follows from it. Naming a comic warns its owner; naming a share
     * warns the account that made it, which is the one being asked to change
     * what they hand out.
     */
    #[Route('', name: 'app_admin_warnings_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        // The decoder answers with an array for anything, including an empty
        // body, so there is nothing to check here — the fields are validated
        // one by one below.
        $data = JsonRequestDecoder::decode($request);

        [$message, $problem] = UserWarningService::normaliseMessage($data['message'] ?? null);
        if ($message === null) {
            return $this->json(['message' => $problem], Response::HTTP_BAD_REQUEST);
        }

        $target = $this->resolveTarget($data);
        if (is_string($target)) {
            return $this->json(['message' => $target], Response::HTTP_BAD_REQUEST);
        }

        [$recipient, $subject, $comic, $share] = $target;

        // Not a rule about who deserves one — an administrator warning another
        // administrator is legitimate — but warning yourself is a mistake every
        // time, and the notice would be dismissed by the person who wrote it.
        if ($recipient->getId() === $admin->getId()) {
            return $this->json(['message' => 'You cannot warn yourself.'], Response::HTTP_BAD_REQUEST);
        }

        $warning = $this->warnings->issue(
            $recipient,
            $admin,
            $message,
            $subject,
            $comic,
            $share,
            // Strictly true. A missing key is the absence of a request to email
            // anybody, never a request to email everybody.
            ($data['sendEmail'] ?? null) === true,
        );

        return $this->json([
            'message' => $warning->getEmailState() === UserWarning::EMAIL_FAILED
                ? 'Warning sent. It is waiting for them in the application, but the email copy could not be delivered.'
                : 'Warning sent. They will see it the next time they sign in.',
            'warning' => $warning->toAdminPayload(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Work out who is being warned and about what.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: User, 1: string, 2: Comic|null, 3: ComicShare|null}|string The target, or why there is none.
     */
    private function resolveTarget(array $data): array|string
    {
        $named = array_filter(
            ['userId', 'comicId', 'shareId'],
            static fn (string $key): bool => ($data[$key] ?? null) !== null
        );

        // Exactly one, so a body carrying both a comic and a share cannot be
        // silently resolved in whichever order this method happens to check.
        if (count($named) !== 1) {
            return 'Name exactly one of a user, a comic or a share to warn about.';
        }

        if (($data['shareId'] ?? null) !== null) {
            $share = $this->shares->find((int) $data['shareId']);
            $owner = $share?->getOwner();

            if ($share === null || $owner === null) {
                return 'That share was not found.';
            }

            return [$owner, UserWarning::SUBJECT_SHARE, $share->getComic(), $share];
        }

        if (($data['comicId'] ?? null) !== null) {
            $comic = $this->entityManager->getRepository(Comic::class)->find((int) $data['comicId']);
            $owner = $comic?->getOwner();

            if ($comic === null || $owner === null) {
                return 'That comic was not found.';
            }

            return [$owner, UserWarning::SUBJECT_COMIC, $comic, null];
        }

        $user = $this->entityManager->getRepository(User::class)->find((int) $data['userId']);

        if ($user === null) {
            return 'That user was not found.';
        }

        return [$user, UserWarning::SUBJECT_ACCOUNT, null, null];
    }
}
