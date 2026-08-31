<?php

namespace App\Command;

use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Service\ExpiredShareCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The scheduled retention sweep for sharing records.
 *
 * The work itself lives in {@see ExpiredShareCleanupService}, because an
 * administrator can run the same sweep from the admin page and a deletion rule
 * that exists twice is one that will eventually disagree with itself. This is
 * the normal path; the button is the fallback for an installation whose cron is
 * not running.
 *
 * Run periodically from cron:
 *
 *   php bin/console app:cleanup-expired-shares
 *   docker exec -it panel-page-flip-php-1 php bin/console app:cleanup-expired-shares
 */
#[AsCommand(
    name: 'app:cleanup-expired-shares',
    description: 'Deletes expired share invitations, long-dead sharing codes and long-revoked shares',
)]
class CleanupExpiredSharesCommand extends Command
{
    public function __construct(private readonly ExpiredShareCleanupService $cleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Removes pending share invitations whose expiry date has passed, sharing codes that '
            . 'expired more than ' . ltrim(ShareClaimCode::RETENTION_AFTER_EXPIRY, '+') . ' ago, and shares '
            . 'revoked more than ' . ltrim(ComicShare::RETENTION_AFTER_REVOCATION, '+') . ' ago.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cleaning up expired shares and sharing codes');

        // Deliberately not audited. A cron job reporting its own quiet runs is
        // noise, and what it removed is already visible in what is no longer
        // there. The admin button audits instead, because a person deleting
        // records from other people's accounts should be answerable for it.
        $removed = $this->cleanup->run();

        if ($removed['invitations'] === 0 && $removed['claimCodes'] === 0 && $removed['revokedShares'] === 0) {
            $io->success('Nothing to clean up.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Removed %d expired invitation(s), %d dead sharing code(s) and %d long-revoked share(s).',
            $removed['invitations'],
            $removed['claimCodes'],
            $removed['revokedShares']
        ));

        return Command::SUCCESS;
    }
}
