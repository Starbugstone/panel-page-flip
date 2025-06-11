<?php

namespace App\Controller;

use App\Entity\DeploymentHistory;
use App\Repository\DeploymentHistoryRepository;
use App\Service\RollbackService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/deployment', name: 'api_deployment_')]
class DeploymentController extends AbstractController
{
    private const REQUIRED_ENV_VARS = [
        'DEPLOY_WEBHOOK_SECRET',
        'MAILER_FROM_ADDRESS'
    ];

    private LoggerInterface $logger;
    private MailerInterface $mailer;
    private EntityManagerInterface $entityManager;
    private DeploymentHistoryRepository $deploymentRepository;
    private RollbackService $rollbackService;

    public function __construct(
        LoggerInterface $deploymentLogger,
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
        DeploymentHistoryRepository $deploymentRepository,
        RollbackService $rollbackService
    ) {
        $this->logger = $deploymentLogger;
        $this->mailer = $mailer;
        $this->entityManager = $entityManager;
        $this->deploymentRepository = $deploymentRepository;
        $this->rollbackService = $rollbackService;
    }

    #[Route('/webhook', name: 'webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        try {
            // Validate environment configuration
            $this->validateEnvironment();
            
            // Validate webhook request
            $this->validateWebhookRequest($request);
            
            // Parse and validate payload
            $payload = $this->parsePayload($request);
            
            // Log deployment start
            $this->logger->info('Deployment webhook triggered', [
                'repository' => $payload['repository'],
                'commit' => $payload['commit'],
                'run_id' => $payload['run_id']
            ]);
            
            // Execute deployment
            $result = $this->executeDeployment($payload);
            
            // Save deployment history
            $this->saveDeploymentHistory($payload, $result, 'success');
            
            // Send notification email
            $this->sendDeploymentNotification($payload, $result);
            
            return $this->json([
                'status' => 'success',
                'message' => 'Deployment completed successfully',
                'timestamp' => new \DateTime(),
                'commit' => $payload['commit'],
                'steps_completed' => count($result['steps']),
                'duration' => $result['duration'] ?? null
            ]);
            
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Deployment webhook validation failed', [
                'error' => $e->getMessage(),
                'ip' => $request->getClientIp()
            ]);
            
            return $this->json([
                'status' => 'error',
                'message' => 'Invalid request'
            ], Response::HTTP_BAD_REQUEST);
            
        } catch (\Exception $e) {
            $this->logger->error('Deployment failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Save failed deployment history if we have payload
            if (isset($payload)) {
                $this->saveDeploymentHistory($payload, ['error' => $e->getMessage()], 'failed');
            }
            
            // Send error notification
            $this->sendErrorNotification($e, $request);
            
            return $this->json([
                'status' => 'error',
                'message' => 'Deployment failed',
                'error' => $this->getParameter('kernel.environment') === 'dev' ? $e->getMessage() : 'Internal error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function status(): Response
    {
        $logFile = $this->getParameter('kernel.project_dir') . '/var/log/deployment.log';
        
        if (!file_exists($logFile)) {
            return new Response('No deployment logs found.', Response::HTTP_NOT_FOUND);
        }
        
        $content = file_get_contents($logFile);
        
        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain'
        ]);
    }

    private function validateEnvironment(): void
    {
        foreach (self::REQUIRED_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? null;
            
            if (empty($value) || $value === 'your-webhook-secret-here') {
                throw new \RuntimeException(
                    "Required environment variable '{$var}' is not properly configured. " .
                    "Please set a secure value in your .env.local file."
                );
            }
        }
    }

    private function validateWebhookRequest(Request $request): void
    {
        // Validate HTTP method
        if (!$request->isMethod('POST')) {
            throw new \InvalidArgumentException('Only POST requests are allowed');
        }
        
        // Validate secret
        $expectedSecret = $_ENV['DEPLOY_WEBHOOK_SECRET'];
        $providedSecret = $request->headers->get('X-Deploy-Secret', '');
        
        if (!hash_equals($expectedSecret, $providedSecret)) {
            throw new \InvalidArgumentException('Invalid webhook secret');
        }
        
        // Validate content type
        if (!$request->headers->contains('Content-Type', 'application/json')) {
            throw new \InvalidArgumentException('Content-Type must be application/json');
        }
    }

    private function parsePayload(Request $request): array
    {
        $content = $request->getContent();
        $payload = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON payload: ' . json_last_error_msg());
        }
        
        // Validate required fields
        $requiredFields = ['event', 'repository', 'commit', 'run_id'];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) || empty($payload[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        if ($payload['event'] !== 'deployment') {
            throw new \InvalidArgumentException('Invalid event type');
        }
        
        return $payload;
    }

    private function executeDeployment(array $payload): array
    {
        $startTime = microtime(true);
        $steps = [];
        $projectDir = $this->getParameter('kernel.project_dir');
        
        $commands = [
            'composer' => [
                'composer', 'install', 
                '--no-dev', '--optimize-autoloader', '--no-interaction'
            ],
            'migrations' => [
                'php', 'bin/console', 
                'doctrine:migrations:migrate', '--no-interaction'
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
            $this->logger->info("Executing deployment step: {$name}");
            
            $process = new Process($command, $projectDir);
            $process->setTimeout(300); // 5 minute timeout
            
            try {
                $process->mustRun();
                $steps[$name] = [
                    'status' => 'success',
                    'output' => $process->getOutput()
                ];
                
                $this->logger->info("Deployment step '{$name}' completed successfully");
                
            } catch (ProcessFailedException $e) {
                $steps[$name] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'output' => $process->getOutput(),
                    'error_output' => $process->getErrorOutput()
                ];
                
                $this->logger->error("Deployment step '{$name}' failed", [
                    'error' => $e->getMessage(),
                    'output' => $process->getErrorOutput()
                ]);
                
                throw new \RuntimeException("Deployment step '{$name}' failed: " . $e->getMessage());
            }
        }
        
        return [
            'steps' => $steps,
            'duration' => round(microtime(true) - $startTime, 2) . 's'
        ];
    }

    private function sendDeploymentNotification(array $payload, array $result): void
    {
        try {
            $email = (new Email())
                ->from($_ENV['MAILER_FROM_ADDRESS'])
                ->to($_ENV['DEPLOY_NOTIFICATION_EMAIL'] ?? $_ENV['MAILER_FROM_ADDRESS'])
                ->subject('✅ Deployment Successful - ' . $payload['repository'])
                ->html($this->renderView('emails/deployment_success.html.twig', [
                    'payload' => $payload,
                    'result' => $result
                ]));
            
            $this->mailer->send($email);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send deployment notification email', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function sendErrorNotification(\Exception $exception, Request $request): void
    {
        try {
            $email = (new Email())
                ->from($_ENV['MAILER_FROM_ADDRESS'])
                ->to($_ENV['DEPLOY_NOTIFICATION_EMAIL'] ?? $_ENV['MAILER_FROM_ADDRESS'])
                ->subject('❌ Deployment Failed')
                ->html($this->renderView('emails/deployment_error.html.twig', [
                    'exception' => $exception,
                    'request_data' => [
                        'ip' => $request->getClientIp(),
                        'user_agent' => $request->headers->get('User-Agent'),
                        'timestamp' => new \DateTime()
                    ]
                ]));
            
            $this->mailer->send($email);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send error notification email', [
                'error' => $e->getMessage()
            ]);
        }
    }

    #[Route('/rollback', name: 'rollback', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rollback(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (isset($data['commit'])) {
                // Rollback to specific commit
                $result = $this->rollbackService->rollbackToCommit(
                    $data['commit'],
                    $data['reason'] ?? 'Manual rollback via API'
                );
            } else {
                // Rollback to previous deployment
                $result = $this->rollbackService->rollbackToPrevious(
                    $data['reason'] ?? 'Rollback to previous deployment'
                );
            }

            return $this->json([
                'status' => 'success',
                'message' => 'Rollback completed successfully',
                'result' => $result
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Rollback failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'status' => 'error',
                'message' => 'Rollback failed',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deploymentHistory(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

        $deployments = $this->deploymentRepository->getDeploymentHistory($page, $limit);
        $total = $this->deploymentRepository->countDeployments();

        return $this->json([
            'deployments' => array_map(function(DeploymentHistory $deployment) {
                return [
                    'id' => $deployment->getId(),
                    'commit_hash' => $deployment->getCommitHash(),
                    'short_commit' => $deployment->getShortCommitHash(),
                    'branch' => $deployment->getBranch(),
                    'repository' => $deployment->getRepository(),
                    'deployed_at' => $deployment->getDeployedAt()?->format('Y-m-d H:i:s'),
                    'status' => $deployment->getStatus(),
                    'deployed_by' => $deployment->getDeployedBy(),
                    'duration' => $deployment->getDuration(),
                    'rollback_reason' => $deployment->getRollbackReason(),
                    'rolled_back_at' => $deployment->getRolledBackAt()?->format('Y-m-d H:i:s'),
                    'rolled_back_to_commit' => $deployment->getRolledBackToCommit(),
                ];
            }, $deployments),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    #[Route('/rollback-targets', name: 'rollback_targets', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rollbackTargets(): JsonResponse
    {
        $targets = $this->rollbackService->getAvailableRollbackTargets();

        return $this->json([
            'targets' => array_map(function(DeploymentHistory $deployment) {
                return [
                    'commit_hash' => $deployment->getCommitHash(),
                    'short_commit' => $deployment->getShortCommitHash(),
                    'branch' => $deployment->getBranch(),
                    'deployed_at' => $deployment->getDeployedAt()?->format('Y-m-d H:i:s'),
                    'deployed_by' => $deployment->getDeployedBy(),
                ];
            }, $targets)
        ]);
    }

    private function saveDeploymentHistory(array $payload, array $result, string $status): void
    {
        try {
            $deployment = new DeploymentHistory();
            $deployment->setCommitHash($payload['commit']);
            $deployment->setBranch($payload['branch'] ?? 'main');
            $deployment->setRepository($payload['repository'] ?? null);
            $deployment->setGithubRunId($payload['run_id'] ?? null);
            $deployment->setDeployedAt(new \DateTime());
            $deployment->setStatus($status);
            $deployment->setDeployedBy('github-actions');
            $deployment->setDeploymentStepsArray($result);
            
            if (isset($result['duration'])) {
                $deployment->setDuration($result['duration']);
            }

            $this->entityManager->persist($deployment);
            $this->entityManager->flush();

            $this->logger->info('Deployment history saved', [
                'commit' => $payload['commit'],
                'status' => $status
            ]);

        } catch (\Exception $e) {
            $this->logger->warning('Failed to save deployment history', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
        }
    }
} 