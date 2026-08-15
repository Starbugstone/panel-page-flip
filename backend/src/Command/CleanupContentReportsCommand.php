<?php

namespace App\Command;

use App\Service\ContentReportRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:cleanup-content-reports',
    description: 'Remove closed content reports after the configured retention period, except legal holds.',
)]
final class CleanupContentReportsCommand extends Command
{
    public function __construct(private readonly ContentReportRetentionService $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->retention->cleanup();
        $output->writeln(sprintf('Removed %d expired content report(s).', $removed));
        return Command::SUCCESS;
    }
}
