<?php

namespace App\Command;

use App\Entity\User;
use App\Service\AppDataEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-dropbox-tokens',
    description: 'Re-saves Dropbox-connected users so plaintext tokens are encrypted at rest.',
)]
class MigrateDropboxTokensCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppDataEncryptionService $encryption
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $users = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.dropboxAccessToken IS NOT NULL')
            ->andWhere('u.dropboxAccessToken != :empty')
            ->setParameter('empty', '')
            ->getQuery()
            ->getResult();

        $updated = 0;
        $connection = $this->entityManager->getConnection();

        foreach ($users as $user) {
            if (!$user instanceof User || !$user->getId()) {
                continue;
            }

            $connection->update('user', [
                'dropbox_access_token' => $this->encryption->encrypt($user->getDropboxAccessToken()),
                'dropbox_refresh_token' => $this->encryption->encrypt($user->getDropboxRefreshToken()),
            ], ['id' => $user->getId()]);
            $updated++;
        }

        $io->success(sprintf('Encrypted Dropbox tokens for %d user(s).', $updated));

        return Command::SUCCESS;
    }
}
