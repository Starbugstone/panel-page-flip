<?php

namespace App\Command;

use App\Service\RollbackService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rollback',
    description: 'Rollback to a previous deployment',
)]
class RollbackCommand extends Command
{
    private RollbackService $rollbackService;

    public function __construct(RollbackService $rollbackService)
    {
        $this->rollbackService = $rollbackService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('commit', InputArgument::OPTIONAL, 'Commit hash to rollback to (if not provided, rolls back to previous deployment)')
            ->addOption('reason', 'r', InputOption::VALUE_REQUIRED, 'Reason for the rollback', 'Manual rollback via console')
            ->addOption('list-targets', 'l', InputOption::VALUE_NONE, 'List available rollback targets')
            ->setHelp('
This command allows you to rollback your application to a previous deployment.

Examples:
  # Rollback to previous deployment
  php bin/console app:rollback

  # Rollback to specific commit
  php bin/console app:rollback abc1234

  # Rollback with custom reason
  php bin/console app:rollback --reason="Fixing critical bug"

  # List available rollback targets
  php bin/console app:rollback --list-targets
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // List available targets if requested
            if ($input->getOption('list-targets')) {
                return $this->listRollbackTargets($io);
            }

            $commit = $input->getArgument('commit');
            $reason = $input->getOption('reason');

            $io->title('🔄 Application Rollback');

            // Confirm the rollback
            if (!$this->confirmRollback($io, $commit)) {
                $io->info('Rollback cancelled.');
                return Command::SUCCESS;
            }

            $io->section('Starting rollback process...');

            // Perform the rollback
            if ($commit) {
                $io->info("Rolling back to commit: {$commit}");
                $result = $this->rollbackService->rollbackToCommit($commit, $reason);
            } else {
                $io->info('Rolling back to previous deployment');
                $result = $this->rollbackService->rollbackToPrevious($reason);
            }

            // Display results
            $this->displayRollbackResults($io, $result);

            $io->success('🎉 Rollback completed successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('❌ Rollback failed: ' . $e->getMessage());
            
            if ($output->isVerbose()) {
                $io->section('Error Details:');
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    private function listRollbackTargets(SymfonyStyle $io): int
    {
        $io->title('📋 Available Rollback Targets');

        try {
            $targets = $this->rollbackService->getAvailableRollbackTargets();

            if (empty($targets)) {
                $io->warning('No rollback targets available.');
                return Command::SUCCESS;
            }

            $rows = [];
            foreach ($targets as $target) {
                $rows[] = [
                    $target->getShortCommitHash(),
                    $target->getCommitHash(),
                    $target->getBranch(),
                    $target->getDeployedAt()->format('Y-m-d H:i:s'),
                    $target->getDeployedBy() ?? 'Unknown'
                ];
            }

            $io->table(
                ['Short Hash', 'Full Hash', 'Branch', 'Deployed At', 'Deployed By'],
                $rows
            );

            $io->info('Use: php bin/console app:rollback <commit-hash>');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to retrieve rollback targets: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function confirmRollback(SymfonyStyle $io, ?string $commit): bool
    {
        $io->section('⚠️  Rollback Confirmation');
        
        if ($commit) {
            $io->text("You are about to rollback to commit: <fg=yellow>{$commit}</>");
        } else {
            $io->text('You are about to rollback to the <fg=yellow>previous deployment</>');
        }

        $io->warning([
            'This action will:',
            '• Reset your codebase to the target commit',
            '• Run composer install and clear caches',
            '• Create a backup of the current state',
            '• Update deployment history',
            '',
            'User uploads and .env.local files will NOT be affected.'
        ]);

        return $io->confirm('Do you want to proceed with the rollback?', false);
    }

    private function displayRollbackResults(SymfonyStyle $io, array $result): void
    {
        $io->section('📊 Rollback Results');

        $io->definitionList(
            ['Status' => $result['status']],
            ['From Commit' => $result['from_commit']],
            ['To Commit' => $result['to_commit']],
            ['Backup Created' => $result['backup_info']['path'] ?? 'N/A']
        );

        if (!empty($result['steps'])) {
            $io->section('🔧 Executed Steps');
            
            foreach ($result['steps'] as $stepName => $stepData) {
                $status = $stepData['status'] === 'success' ? '✅' : '❌';
                $io->text("{$status} {$stepName}");
                
                if ($io->isVerbose() && !empty($stepData['output'])) {
                    $io->text('   Output: ' . trim($stepData['output']));
                }
            }
        }
    }
} 