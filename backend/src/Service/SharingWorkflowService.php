<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Enum\ShareCodeType;
use App\Security\Voter\ComicVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Convenience workflows built on top of the existing one-comic sharing model.
 *
 * This service deliberately does not search User. Reusable recipients come only
 * from addresses this owner previously supplied themselves, and bulk sharing is
 * only the permission gate in front of ComicShareService::inviteMany().
 */
final class SharingWorkflowService
{
    /**
     * How many comics one bulk share may carry.
     *
     * A ceiling on the size of one invitation email rather than on how much
     * mail can be sent — that is the `share_invitation` limiter's job, and a
     * bulk share claims one allowance from it however many comics it carries.
     */
    public const MAX_BULK_COMICS = 20;

    public const RECENT_RECIPIENT_LIMIT = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareService $shareService,
        private readonly ExplicitContentPromoter $explicitContent,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * People this owner has already shared with, newest relationship first.
     *
     * No directory and no search: the rows are restricted to people this owner
     * already has a sharing relationship with, so nothing is learned that
     * sharing with them did not already establish. It is an address book of
     * known correspondents, not a lookup of who exists.
     *
     * Registered recipients are offered by username, because that is their
     * public identity and it is the one that survives them changing anything
     * else. Recipients the owner reached without seeing their address are never
     * listed by address — that is the one thing being withheld.
     *
     * @return list<array{email: string|null, username: string|null, userCode: string|null,
     *                    name: string|null, label: string}>
     */
    public function recentRecipients(User $owner, int $limit = self::RECENT_RECIPIENT_LIMIT): array
    {
        $safeLimit = max(1, min($limit, self::RECENT_RECIPIENT_LIMIT));

        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.recipientEmailNormalized AS email')
            // Whether the owner was kept from this address, not which code did
            // it. The stored one is a note about how the relationship began and
            // goes stale the moment the recipient rotates; offering it back
            // would put a retired code straight into the picker.
            ->addSelect('s.recipientUserCode AS historicalCode')
            ->addSelect('ru.username AS username')
            ->addSelect('ru.userCode AS currentCode')
            ->addSelect('ru.name AS currentName')
            ->addSelect('MAX(s.id) AS HIDDEN lastShareId')
            ->from(ComicShare::class, 's')
            ->leftJoin('s.recipientUser', 'ru')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.recipientEmailNormalized <> :empty')
            ->setParameter('owner', $owner)
            ->setParameter('empty', '')
            // Grouped so a person the owner has reached both ways appears once
            // per way of reaching them, rather than one entry silently carrying
            // the other's label.
            ->groupBy('s.recipientEmailNormalized')
            ->addGroupBy('s.recipientUserCode')
            ->addGroupBy('ru.username')
            ->addGroupBy('ru.userCode')
            ->addGroupBy('ru.name')
            ->orderBy('lastShareId', 'DESC')
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getArrayResult();

        $recipients = [];
        foreach ($rows as $row) {
            $hidden = $row['historicalCode'] !== null;
            $username = ($row['username'] ?? null) === null ? null : (string) $row['username'];
            $currentCode = ($row['currentCode'] ?? null) === null ? null : (string) $row['currentCode'];
            $name = $row['currentName'] === null ? null : (string) $row['currentName'];

            if ($username !== null) {
                $recipients[] = [
                    // Withheld for a recipient the owner was never shown, and
                    // kept for one whose address they typed themselves — where
                    // hiding it now would withhold something they already have.
                    'email' => $hidden ? null : (string) $row['email'],
                    'username' => $username,
                    'userCode' => $currentCode === null
                        ? null
                        : SharingCodeFormat::forDisplay(ShareCodeType::USER, $currentCode),
                    'name' => $name,
                    // The same rule the shared-by-me list uses, so the label a
                    // sender picks from and the label they read afterwards
                    // cannot describe one person two ways.
                    'label' => UsernamePolicy::describe($username, $name),
                ];
                continue;
            }

            // No account behind this row. A hidden recipient whose account has
            // gone is simply not offered: the alternative — falling back to the
            // address — would hand over the one thing that was being withheld.
            if ($hidden) {
                continue;
            }

            $recipients[] = [
                'email' => (string) $row['email'],
                'username' => null,
                'userCode' => null,
                'name' => null,
                'label' => (string) $row['email'],
            ];
        }

        return $recipients;
    }

    /**
     * Share several owned comics with one exact email address.
     *
     * This layer decides only what the caller is allowed to touch. Everything
     * that follows — the duplicate rules, the acknowledgement, the tokens, the
     * grouped email, the rate limiting and the audit records — belongs to
     * {@see ComicShareService::inviteMany()}, so a bulk share cannot drift away
     * from what a single invitation does.
     *
     * @param list<int> $comicIds
     *
     * @return array{created: int, total: int, results: list<array<string, mixed>>}
     *
     * @throws ShareException when the whole request is refused
     */
    public function inviteMany(
        array $comicIds,
        User $owner,
        string $recipientEmail,
        bool $senderResponsibilityAccepted,
        ?SharingCodeRecipient $viaSharingCode = null,
        bool $markExplicit = false
    ): array {
        $ids = array_values(array_unique(array_map('intval', $comicIds)));
        $shareable = [];
        $results = [];

        foreach ($ids as $comicId) {
            $comic = $this->entityManager->getRepository(Comic::class)->find($comicId);

            // Do not distinguish a missing comic from somebody else's comic.
            // The picker only sends owned ids, but a hand-written request must
            // not turn this endpoint into a comic-id discovery oracle.
            if (!$comic instanceof Comic || !$this->authorizationChecker->isGranted(ComicVoter::SHARE, $comic)) {
                // The whole batch fails, and nothing is created. Sharing five
                // of six comics and reporting the sixth in a per-comic list is
                // a sender told "5 shared" while they meant 6, and the one that
                // did not go is the one they will not notice. Refused before
                // any mutation, so there is nothing to undo.
                throw new ShareException(
                    'One or more of those comics is not available to share, so nothing was shared.',
                    403
                );
            }

            $shareable[$comicId] = $comic;
        }

        if ($shareable === []) {
            throw new ShareException('Select at least one comic to share.', 400);
        }

        // Before the shares, and inside the same unit of work, so an ordinary
        // share can never be created because the reclassification failed. A
        // throw here leaves nothing behind. The audit records wait for the
        // commit below.
        $promotions = $markExplicit
            ? $this->explicitContent->promote(array_values($shareable), $owner)
            : [];

        $results += $this->shareService->inviteMany(
            array_values($shareable),
            $owner,
            $recipientEmail,
            $senderResponsibilityAccepted,
            $viaSharingCode
        );

        // A reclassification is a change to the owner's own library and does
        // not depend on a share being created from it. `inviteMany` returns
        // without committing when every relationship it was asked for already
        // exists, so this cannot ride on that flush — an all-duplicate batch
        // would otherwise leave the promotion in memory and drop it.
        if ($promotions !== []) {
            $this->entityManager->flush();
            $this->explicitContent->recordPromotions($promotions);
        }

        // Reported in the order the caller asked, so the response lines up with
        // the selection the sender is looking at.
        $ordered = [];
        $created = 0;
        foreach ($ids as $comicId) {
            $result = $results[$comicId] ?? [
                'status' => 'failed',
                'message' => 'This comic could not be shared.',
            ];

            if ($result['status'] === 'created') {
                ++$created;
            }

            $ordered[] = ['comicId' => $comicId] + $result;
        }

        return [
            'created' => $created,
            'total' => count($ids),
            'results' => $ordered,
        ];
    }
}
