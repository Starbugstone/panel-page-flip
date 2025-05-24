<?php

namespace App\Command;

use App\Repository\ShareTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

/**
 * Command to clean up expired share tokens and their public cover images
 * 
 * This command should be run periodically via a cron job to clean up expired share tokens
 * and their associated public cover images to free up disk space.
 * 
 * Usage:
 * 
 * Local installation:
 * php bin/console app:cleanup-expired-shares
 * 
 * Docker:
 * docker exec -it panel-page-flip-php-1 php bin/console app:cleanup-expired-shares
 */
#[AsCommand(
    name: 'app:cleanup-expired-shares',
    description: 'Cleans up expired share tokens and their public cover images',
)]
class CleanupExpiredSharesCommand extends Command
{
    private string $publicSharesDirectory;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShareTokenRepository $shareTokenRepository,
        private LoggerInterface $logger,
        string $publicSharesDirectory = null
    ) {
        parent::__construct();
        // If not explicitly provided, use a default path
        $this->publicSharesDirectory = $publicSharesDirectory ?? dirname(__DIR__, 2) . '/public/shared';
    }

    protected function configure(): void
    {
        $this->setHelp('This command finds expired share tokens and removes their public cover images to free up disk space.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cleaning up expired share tokens');

        // Find expired share tokens that haven't been used yet
        $now = new \DateTimeImmutable();
        $expiredTokens = $this->shareTokenRepository->findExpiredTokens($now);

        $count = count($expiredTokens);
        $io->info(sprintf('Found %d expired share tokens to clean up', $count));

        if ($count === 0) {
            $io->success('No expired tokens to clean up.');
            return Command::SUCCESS;
        }

        $removedCovers = 0;
        $errors = 0;

        foreach ($expiredTokens as $token) {
            try {
                // Clean up the public cover image if it exists
                if ($token->getPublicCoverPath()) {
                    $publicCoverPath = $this->publicSharesDirectory . '/' . basename($token->getPublicCoverPath());
                    if (file_exists($publicCoverPath)) {
                        if (@unlink($publicCoverPath)) {
                            $removedCovers++;
                            $io->writeln(sprintf('Removed public cover image: %s', basename($publicCoverPath)));
                        } else {
                            $this->logger->warning(sprintf('Failed to remove public cover image: %s', $publicCoverPath));
                            $errors++;
                        }
                    }
                }

                // Mark the token as used so it won't be processed again
                $token->setIsUsed(true);
                $this->entityManager->persist($token);
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Error cleaning up token %s: %s', $token->getToken(), $e->getMessage()));
                $errors++;
            }
        }

        // Flush all changes
        $this->entityManager->flush();

        $io->success(sprintf(
            'Cleanup completed. Processed %d tokens, removed %d cover images, encountered %d errors.',
            $count,
            $removedCovers,
            $errors
        ));

        return Command::SUCCESS;
    }
}
