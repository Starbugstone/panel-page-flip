<?php

namespace App\Service;

use App\Entity\DeploymentHistory;
use App\Repository\DeploymentHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Twig\Environment;

class RollbackService
{
    private EntityManagerInterface $entityManager;
    private DeploymentHistoryRepository $deploymentRepository;
    private LoggerInterface $logger;
    private string $projectDir;
    private MailerInterface $mailer;
    private Environment $twig;

    public function __construct(
        EntityManagerInterface $entityManager,
        DeploymentHistoryRepository $deploymentRepository,
        LoggerInterface $deploymentLogger,
        string $projectDir,
        MailerInterface $mailer,
        Environment $twig
    ) {
        $this->entityManager = $entityManager;
        $this->deploymentRepository = $deploymentRepository;
        $this->logger = $deploymentLogger;
        $this->projectDir = $projectDir;
        $this->mailer = $mailer;
        $this->twig = $twig;
    }

    /**
     * Rollback to a specific commit
     */
    public function rollbackToCommit(string $commitHash, string $reason = 'Manual rollback'): array
    {
        $this->logger->info('Starting rollback process', [
            'target_commit' => $commitHash,
            'reason' => $reason
        ]);

        // Validate commit exists
        if (!$this->validateCommit($commitHash)) {
            throw new \InvalidArgumentException("Commit {$commitHash} does not exist or is not accessible");
        }

        // Get current deployment
        $currentDeployment = $this->deploymentRepository->getCurrentDeployment();
        if (!$currentDeployment) {
            throw new \RuntimeException('No current deployment found to rollback from');
        }

        // Check if we're trying to rollback to the same commit
        if ($currentDeployment->getCommitHash() === $commitHash) {
            throw new \InvalidArgumentException('Cannot rollback to the same commit that is currently deployed');
        }

        // Create backup of current state
        $backupInfo = $this->createBackup();

        try {
            // Perform git rollback
            $rollbackSteps = $this->performGitRollback($commitHash);

            // Run post-rollback deployment steps
            $deploymentSteps = $this->runPostRollbackSteps();

            // Mark current deployment as rolled back
            $currentDeployment->setStatus('rolled_back');
            $currentDeployment->setRolledBackAt(new \DateTime());
            $currentDeployment->setRolledBackToCommit($commitHash);
            $currentDeployment->setRollbackReason($reason);

            // Create new deployment record for the rollback
            $rollbackDeployment = new DeploymentHistory();
            $rollbackDeployment->setCommitHash($commitHash);
            $rollbackDeployment->setBranch('rollback');
            $rollbackDeployment->setDeployedAt(new \DateTime());
            $rollbackDeployment->setStatus('success');
            $rollbackDeployment->setDeployedBy('rollback-system');
            $rollbackDeployment->setDeploymentStepsArray(array_merge($rollbackSteps, $deploymentSteps));

            $this->entityManager->persist($rollbackDeployment);
            $this->entityManager->flush();

            $this->logger->info('Rollback completed successfully', [
                'from_commit' => $currentDeployment->getCommitHash(),
                'to_commit' => $commitHash,
                'backup_created' => $backupInfo['path']
            ]);

            $result = [
                'status' => 'success',
                'from_commit' => $currentDeployment->getCommitHash(),
                'to_commit' => $commitHash,
                'backup_info' => $backupInfo,
                'steps' => array_merge($rollbackSteps, $deploymentSteps)
            ];

            // Send rollback notification
            $this->sendRollbackNotification($result, $reason);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Rollback failed, attempting to restore backup', [
                'error' => $e->getMessage(),
                'backup_path' => $backupInfo['path']
            ]);

            // Attempt to restore from backup
            $this->restoreFromBackup($backupInfo);

            throw new \RuntimeException('Rollback failed: ' . $e->getMessage());
        }
    }

    /**
     * Rollback to the previous successful deployment
     */
    public function rollbackToPrevious(string $reason = 'Rollback to previous deployment'): array
    {
        $deployments = $this->deploymentRepository->getLastSuccessfulDeployments(2);
        
        if (count($deployments) < 2) {
            throw new \RuntimeException('No previous deployment found to rollback to');
        }

        $previousDeployment = $deployments[1]; // Second most recent
        return $this->rollbackToCommit($previousDeployment->getCommitHash(), $reason);
    }

    /**
     * Get available rollback targets (last 10 successful deployments)
     */
    public function getAvailableRollbackTargets(): array
    {
        $deployments = $this->deploymentRepository->getLastSuccessfulDeployments(10);
        $current = $this->deploymentRepository->getCurrentDeployment();
        
        return array_filter($deployments, function($deployment) use ($current) {
            return $current && $deployment->getCommitHash() !== $current->getCommitHash();
        });
    }

    private function validateCommit(string $commitHash): bool
    {
        $process = new Process(['git', 'cat-file', '-e', $commitHash], $this->projectDir);
        $process->run();
        
        return $process->isSuccessful();
    }

    private function createBackup(): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = $this->projectDir . '/var/backups';
        $backupPath = $backupDir . '/pre-rollback-' . $timestamp;

        // Ensure backup directory exists
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Get current commit hash
        $process = new Process(['git', 'rev-parse', 'HEAD'], $this->projectDir);
        $process->mustRun();
        $currentCommit = trim($process->getOutput());

        // Create backup info
        $backupInfo = [
            'path' => $backupPath,
            'timestamp' => $timestamp,
            'commit' => $currentCommit,
            'created_at' => new \DateTime()
        ];

        // Save backup info to file
        file_put_contents($backupPath . '.json', json_encode($backupInfo, JSON_PRETTY_PRINT));

        $this->logger->info('Backup created', $backupInfo);

        return $backupInfo;
    }

    private function performGitRollback(string $commitHash): array
    {
        $steps = [];

        // Stash any uncommitted changes
        $process = new Process(['git', 'stash', 'push', '-m', 'Pre-rollback stash'], $this->projectDir);
        $process->run();
        if ($process->isSuccessful()) {
            $steps['git_stash'] = [
                'status' => 'success',
                'output' => $process->getOutput()
            ];
        }

        // Reset to target commit
        $process = new Process(['git', 'reset', '--hard', $commitHash], $this->projectDir);
        $process->setTimeout(60);
        
        try {
            $process->mustRun();
            $steps['git_reset'] = [
                'status' => 'success',
                'output' => $process->getOutput()
            ];
        } catch (ProcessFailedException $e) {
            $steps['git_reset'] = [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'output' => $process->getErrorOutput()
            ];
            throw $e;
        }

        return $steps;
    }

    private function runPostRollbackSteps(): array
    {
        $steps = [];
        $commands = [
            'composer_install' => [
                'composer', 'install', 
                '--no-dev', '--optimize-autoloader', '--no-interaction'
            ],
            'cache_cleanup' => [
                'rm', '-rf', 'var/cache/*'
            ],
            'cache_clear' => [
                'php', 'bin/console', 
                'cache:clear', '--env=prod', '--no-debug', '--no-warmup'
            ],
            'cache_warmup' => [
                'php', 'bin/console', 
                'cache:warmup', '--env=prod', '--no-debug'
            ]
        ];

        foreach ($commands as $name => $command) {
            $this->logger->info("Executing post-rollback step: {$name}");
            
            $process = new Process($command, $this->projectDir);
            $process->setTimeout(300);
            
            try {
                $process->mustRun();
                $steps[$name] = [
                    'status' => 'success',
                    'output' => $process->getOutput()
                ];
                
                $this->logger->info("Post-rollback step '{$name}' completed successfully");
                
            } catch (ProcessFailedException $e) {
                $steps[$name] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'output' => $process->getErrorOutput()
                ];
                
                $this->logger->error("Post-rollback step '{$name}' failed", [
                    'error' => $e->getMessage()
                ]);
                
                throw new \RuntimeException("Post-rollback step '{$name}' failed: " . $e->getMessage());
            }
        }

        return $steps;
    }

    private function restoreFromBackup(array $backupInfo): void
    {
        try {
            // Reset to the commit that was active before rollback
            $process = new Process(['git', 'reset', '--hard', $backupInfo['commit']], $this->projectDir);
            $process->setTimeout(60);
            $process->mustRun();

            $this->logger->info('Successfully restored from backup', $backupInfo);
        } catch (\Exception $e) {
            $this->logger->critical('Failed to restore from backup', [
                'backup_info' => $backupInfo,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Critical: Failed to restore from backup after rollback failure');
        }
    }

    private function sendRollbackNotification(array $result, string $reason): void
    {
        try {
            $email = (new Email())
                ->from($_ENV['MAILER_FROM_ADDRESS'])
                ->to($_ENV['DEPLOY_NOTIFICATION_EMAIL'] ?? $_ENV['MAILER_FROM_ADDRESS'])
                ->subject('🔄 Application Rollback Completed')
                ->html($this->twig->render('emails/rollback_success.html.twig', [
                    'result' => $result,
                    'reason' => $reason,
                    'triggered_by' => 'Rollback System',
                    'timestamp' => new \DateTime()
                ]));

            $this->mailer->send($email);

            $this->logger->info('Rollback notification email sent successfully');

        } catch (\Exception $e) {
            $this->logger->warning('Failed to send rollback notification email', [
                'error' => $e->getMessage()
            ]);
        }
    }
} 