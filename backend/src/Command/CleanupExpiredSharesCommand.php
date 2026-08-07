<?php

namespace App\Command;

use App\Repository\ComicShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Delete invitations nobody answered before they expired.
 *
 * Only pending relationships are in scope. An accepted share has no expiry, and
 * a declined or revoked one is history somebody may still be looking at.
 *
 * An expired invitation is deleted rather than kept, because it holds the email
 * address of somebody who may never have had an account here and who did not
 * act on it.
 *
 * Run periodically from cron:
 *
 *   php bin/console app:cleanup-expired-shares
 *   docker exec -it panel-page-flip-php-1 php bin/console app:cleanup-expired-shares
 */
#[AsCommand(
    name: 'app:cleanup-expired-shares',
    description: 'Deletes share invitations that expired without being answered',
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Removes pending share invitations whose expiry date has passed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cleaning up expired share invitations');

        // Resolved once so a batch cannot pick up invitations that expire while
        // the command is still working through the backlog.
        $now = new \DateTimeImmutable();
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

        if ($count === 0) {
            $io->success('No expired invitations to clean up.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Removed %d expired invitation(s).', $count));

        return Command::SUCCESS;
    }
}
