<?php

declare(strict_types=1);

namespace App\Command;

use App\DataFixtures\AppFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:load-demo-fixtures',
    description: 'Adds the repeatable multi-user demo data to a local database without purging existing records.',
)]
final class LoadDemoFixturesCommand extends Command
{
    public function __construct(
        private readonly AppFixtures $fixtures,
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!in_array($this->kernel->getEnvironment(), ['dev', 'test'], true)) {
            $io->error('Demo fixtures may only be loaded in the dev or test environment.');

            return Command::FAILURE;
        }

        try {
            $created = $this->entityManager->wrapInTransaction(
                fn (): bool => $this->fixtures->load($this->entityManager)
            );
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (!$created) {
            $io->note('The demo fixtures are already loaded; no data was changed.');

            return Command::SUCCESS;
        }

        $io->success(
            'Loaded 6 demo users, 18 demo comics, and their shares, folders, progress, codes, and moderation history.'
        );

        return Command::SUCCESS;
    }
}
