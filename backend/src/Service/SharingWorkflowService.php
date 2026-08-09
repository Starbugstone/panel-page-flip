<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
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
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Addresses this owner has already shared with, newest relationship first.
     *
     * No User join is allowed here: the caller learns only information they
     * previously entered, never whether an address belongs to a registered
     * account or anything about that account.
     *
     * Recipients reached by receiver code are listed too, but by their code and
     * name — never by the address the sender was deliberately not given.
     *
     * @return list<array{email: string|null, sharingCode: string|null, name: string|null, label: string}>
     */
    public function recentRecipients(User $owner, int $limit = self::RECENT_RECIPIENT_LIMIT): array
    {
        $safeLimit = max(1, min($limit, self::RECENT_RECIPIENT_LIMIT));

        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.recipientEmailNormalized AS email')
            ->addSelect('s.recipientSharingCode AS sharingCode')
            ->addSelect('s.recipientAliasName AS name')
            ->addSelect('MAX(s.id) AS HIDDEN lastShareId')
            ->from(ComicShare::class, 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.recipientEmailNormalized <> :empty')
            ->setParameter('owner', $owner)
            ->setParameter('empty', '')
            // Grouped by all three so a person the owner has reached both ways
            // appears once per way of reaching them, rather than one entry
            // silently carrying the other's label.
            ->groupBy('s.recipientEmailNormalized')
            ->addGroupBy('s.recipientSharingCode')
            ->addGroupBy('s.recipientAliasName')
            ->orderBy('lastShareId', 'DESC')
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static function (array $row): array {
                $sharingCode = $row['sharingCode'] === null ? null : (string) $row['sharingCode'];
                $name = $row['name'] === null ? null : (string) $row['name'];

                return [
                    // Withheld for a code recipient, so the picker cannot put
                    // back on screen the address the sender never learned.
                    'email' => $sharingCode === null ? (string) $row['email'] : null,
                    'sharingCode' => $sharingCode === null
                        ? null
                        : SharingCodeFormat::forDisplay($sharingCode),
                    'name' => $name,
                    'label' => $sharingCode === null
                        ? (string) $row['email']
                        : ($name ?: SharingCodeFormat::forDisplay($sharingCode)),
                ];
            },
            $rows
        );
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
        ?SharingCodeRecipient $viaSharingCode = null
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
                $results[$comicId] = [
                    'status' => 'not_available',
                    'message' => 'This comic is not available to share.',
                ];
                continue;
            }

            $shareable[$comicId] = $comic;
        }

        if ($shareable !== []) {
            $results += $this->shareService->inviteMany(
                array_values($shareable),
                $owner,
                $recipientEmail,
                $senderResponsibilityAccepted,
                $viaSharingCode
            );
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
