<?php

namespace App\Controller;

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

    public function __construct(
        LoggerInterface $deploymentLogger,
        MailerInterface $mailer
    ) {
        $this->logger = $deploymentLogger;
        $this->mailer = $mailer;
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
            'cache_clear' => [
                'php', 'bin/console', 
                'cache:clear', '--env=prod', '--no-debug'
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
                    'output' => $process->getOutput(),
                    'duration' => $process->getLastRunTime()
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
} 