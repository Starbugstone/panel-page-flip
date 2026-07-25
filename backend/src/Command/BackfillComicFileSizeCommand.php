<?php

namespace App\Command;

use App\Entity\Comic;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-comic-file-size',
    description: 'Backfills missing Comic.fileSize values from files on disk.',
)]
class BackfillComicFileSizeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $comicsDirectory
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $comics = $this->entityManager->getRepository(Comic::class)->findBy(['fileSize' => null]);
        $updated = 0;
        $missing = 0;

        foreach ($comics as $comic) {
            $owner = $comic->getOwner();
            if (!$owner || !$comic->getFilePath()) {
                $missing++;
                continue;
            }

            $path = $this->comicsDirectory . '/' . $owner->getId() . '/' . basename($comic->getFilePath());
            if (!is_file($path)) {
                $missing++;
                continue;
            }

            $comic->setFileSize((int) filesize($path));
            $updated++;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Backfilled %d comic(s). %d file(s) were missing.', $updated, $missing));

        return Command::SUCCESS;
    }
}
