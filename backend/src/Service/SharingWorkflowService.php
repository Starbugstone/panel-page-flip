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
 * only a coordinator around ComicShareService::invite().
 */
final class SharingWorkflowService
{
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
     * @return list<array{email: string}>
     */
    public function recentRecipients(User $owner, int $limit = self::RECENT_RECIPIENT_LIMIT): array
    {
        $safeLimit = max(1, min($limit, self::RECENT_RECIPIENT_LIMIT));

        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.recipientEmailNormalized AS email')
            ->addSelect('MAX(s.id) AS HIDDEN lastShareId')
            ->from(ComicShare::class, 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.recipientEmailNormalized <> :empty')
            ->setParameter('owner', $owner)
            ->setParameter('empty', '')
            ->groupBy('s.recipientEmailNormalized')
            ->orderBy('lastShareId', 'DESC')
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['email' => (string) $row['email']],
            $rows
        );
    }

    /**
     * Share several owned comics with one exact email address.
     *
     * Every item goes through the normal invitation service. That intentionally
     * means the existing rate limiter is consumed once per relationship and a
     * bulk request cannot become an invitation-spam bypass.
     *
     * The first version also keeps the existing one-email-per-comic delivery.
     * Grouped mail can be added later without changing the durable ComicShare
     * model or weakening the explicit-content email redaction.
     *
     * @param list<int> $comicIds
     * @return array{created: int, total: int, results: list<array<string, mixed>>}
     */
    public function inviteMany(
        array $comicIds,
        User $owner,
        string $recipientEmail,
        bool $senderResponsibilityAccepted
    ): array {
        $ids = array_values(array_unique($comicIds));
        $results = [];
        $created = 0;

        foreach ($ids as $comicId) {
            $comic = $this->entityManager->getRepository(Comic::class)->find($comicId);

            // Do not distinguish a missing comic from somebody else's comic.
            // The picker only sends owned ids, but a hand-written request must
            // not turn this endpoint into a comic-id discovery oracle.
            if (!$comic instanceof Comic || !$this->authorizationChecker->isGranted(ComicVoter::SHARE, $comic)) {
                $results[] = [
                    'comicId' => $comicId,
                    'status' => 'not_available',
                    'message' => 'This comic is not available to share.',
                ];
                continue;
            }

            try {
                $invitation = $this->shareService->invite(
                    $comic,
                    $owner,
                    $recipientEmail,
                    $senderResponsibilityAccepted
                );

                ++$created;
                $results[] = [
                    'comicId' => $comicId,
                    'status' => 'created',
                    'shareId' => $invitation->share->getId(),
                ];
            } catch (ShareException $exception) {
                $status = match ($exception->getStatusCode()) {
                    409 => 'skipped',
                    429 => 'rate_limited',
                    default => 'failed',
                };

                $results[] = [
                    'comicId' => $comicId,
                    'status' => $status,
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCodeName(),
                ];
            }
        }

        return [
            'created' => $created,
            'total' => count($ids),
            'results' => $results,
        ];
    }
}
