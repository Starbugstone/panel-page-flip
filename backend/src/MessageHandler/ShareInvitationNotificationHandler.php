<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ComicShare;
use App\Entity\User;
use App\Message\ShareInvitationNotification;
use App\Repository\ComicShareRepository;
use App\Service\ComicShareService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Send the one email that announces a share, after the share is already real.
 *
 * Everything this handler needs it reads back from the database, because the
 * message carries only ids. That is what lets it mint the invitation links here
 * rather than hours earlier: a notice that is retried after a transport outage
 * then carries a link that works, and no plaintext token ever sits in the queue.
 *
 * A failure is recorded on the shares and rethrown, so Messenger's retry policy
 * gets its turn and a notice that never succeeds ends up in the failure
 * transport rather than disappearing. What it must never do is take the shares
 * away — they were committed before this ran, the recipient can already see
 * them on their Sharing page, and an owner whose SMTP server was briefly down
 * should be offered a resend rather than told their share did not happen.
 */
#[AsMessageHandler]
final class ShareInvitationNotificationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareRepository $shareRepository,
        private readonly ComicShareService $shareService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ShareInvitationNotification $notification): void
    {
        $owner = $this->entityManager->getRepository(User::class)->find($notification->ownerId);
        if (!$owner instanceof User) {
            // The account went away between the share and the send. There is
            // nobody to send on behalf of and nothing to retry into.
            return;
        }

        $shares = [];
        foreach ($notification->shareIds as $shareId) {
            $share = $this->shareRepository->find($shareId);

            // Revoked, accepted from another device, or tombstoned in the
            // meantime. Announcing it now would be describing a state that no
            // longer holds, so it is dropped from the notice rather than
            // failing the whole batch.
            if ($share instanceof ComicShare && $share->isPending() && !$share->isTombstoned()) {
                $shares[] = $share;
            }
        }

        if ($shares === []) {
            return;
        }

        try {
            $this->shareService->notify($shares, $owner);
        } catch (\Throwable $exception) {
            foreach ($shares as $share) {
                $share->markNotificationFailed();
            }
            $this->entityManager->flush();

            $this->logger->error('A share invitation notice could not be delivered.', [
                'owner_user_id' => $notification->ownerId,
                'share_ids' => $notification->shareIds,
                'exception' => $exception,
            ]);

            // Rethrown so Messenger retries, and so a notice that never lands
            // is visible in the failure transport instead of being swallowed.
            throw $exception;
        }
    }
}
