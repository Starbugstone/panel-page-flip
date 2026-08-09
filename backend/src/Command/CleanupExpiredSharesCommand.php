<?php

namespace App\Command;

use App\Entity\ShareClaimCode;
use App\Repository\ComicShareRepository;
use App\Repository\ShareClaimCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Delete invitations nobody answered before they expired, and sharing codes
 * that have been dead long enough to stop being worth looking at.
 *
 * For invitations, only pending relationships are in scope. An accepted share
 * has no expiry, and a declined or revoked one is history somebody may still be
 * looking at. An expired invitation is deleted rather than kept, because it
 * holds the email address of somebody who may never have had an account here
 * and who did not act on it.
 *
 * Sharing codes are given a month past their expiry first. A dead code cannot
 * be redeemed again the moment it dies, so keeping it is not a risk — but its
 * owner is still asking how many people took it up and which comics went with
 * it, and that question outlives the code by rather more than a day.
 *
 * Run periodically from cron:
 *
 *   php bin/console app:cleanup-expired-shares
 *   docker exec -it panel-page-flip-php-1 php bin/console app:cleanup-expired-shares
 */
#[AsCommand(
    name: 'app:cleanup-expired-shares',
    description: 'Deletes expired share invitations and long-dead sharing codes',
)]
class CleanupExpiredSharesCommand extends Command
{
    /**
     * Shares removed per flush. A cron job that has not run for a long time, or
     * a bulk invitation import, would otherwise hydrate every expired share and
     * its cascaded tokens into one unit of work — and run out of memory before
     * deleting anything at all.
     */
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicShareRepository $shareRepository,
        private readonly ShareClaimCodeRepository $claimCodeRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Removes pending share invitations whose expiry date has passed, and sharing codes that '
            . 'expired more than ' . ltrim(ShareClaimCode::RETENTION_AFTER_EXPIRY, '+') . ' ago.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cleaning up expired shares and sharing codes');

        // Resolved once so a batch cannot pick up invitations that expire while
        // the command is still working through the backlog.
        $now = new \DateTimeImmutable();

        $invitations = $this->purgeExpiredInvitations($now);
        $codes = $this->purgeDeadClaimCodes($now);

        if ($invitations === 0 && $codes === 0) {
            $io->success('Nothing to clean up.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Removed %d expired invitation(s) and %d dead sharing code(s).',
            $invitations,
            $codes
        ));

        return Command::SUCCESS;
    }

    private function purgeExpiredInvitations(\DateTimeImmutable $now): int
    {
        $count = 0;

        while (($expired = $this->shareRepository->findExpiredPendingShares($now, self::BATCH_SIZE)) !== []) {
            foreach ($expired as $share) {
                // The invitation tokens go with it: they are mapped with
                // orphanRemoval and a cascading foreign key.
                $this->entityManager->remove($share);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            $count += count($expired);
        }

        return $count;
    }

    /**
     * Delete codes that expired long enough ago to have answered every question
     * their owner had about them.
     *
     * Withdrawn codes go the same way and on the same clock. Withdrawing one
     * stops it working immediately; it does not make the record of what was
     * offered any less interesting to the person who offered it.
     *
     * Only the code rows and their join rows go. The shares a code produced are
     * ordinary relationships and outlive it entirely — that is the whole point
     * of a code being a way in rather than the access itself.
     */
    private function purgeDeadClaimCodes(\DateTimeImmutable $now): int
    {
        $count = 0;

        while (($dead = $this->claimCodeRepository->findDeletable($now, self::BATCH_SIZE)) !== []) {
            foreach ($dead as $code) {
                $this->entityManager->remove($code);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            $count += count($dead);
        }

        return $count;
    }
}
