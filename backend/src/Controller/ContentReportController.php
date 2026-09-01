<?php

namespace App\Controller;

use App\Entity\ContentReport;
use App\Service\ContentReportService;
use App\Service\ContentReportValidationException;
use App\Service\RateLimitRetry;
use App\Service\SecurityAuditLogger;
use App\Service\TurnstileConfiguration;
use App\Service\TurnstileRejectedException;
use App\Service\TurnstileUnavailableException;
use App\Service\TurnstileVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ContentReportController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactory $contentReportLimiter,
        private readonly RateLimiterFactory $contentReportBurstLimiter,
        #[Autowire('%legal_email%')]
        private readonly string $legalEmail,
    ) {
    }

    #[Route('/api/content-reports', name: 'api_content_reports_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        ContentReportService $reports,
        SecurityAuditLogger $auditLogger,
        TurnstileConfiguration $turnstile,
        TurnstileVerifier $turnstileVerifier,
    ): JsonResponse {
        $clientIp = $request->getClientIp() ?: 'unknown';
        foreach ([
            'content_report' => $this->contentReportLimiter,
            'content_report_burst' => $this->contentReportBurstLimiter,
        ] as $name => $limiter) {
            $limit = $limiter->create($clientIp)->consume();
            if (!$limit->isAccepted()) {
                $auditLogger->suspicious(SecurityAuditLogger::RATE_LIMIT_TRIGGERED, 'content-report:'.$clientIp, [
                    'limiter' => $name,
                    'path' => $request->getPathInfo(),
                ]);

                return $this->json(['message' => 'Too many reports. Please try again later.'], Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => (string) RateLimitRetry::seconds($limit),
                ]);
            }
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        if ($this->isHoneypot($data)) {
            return $this->successResponse();
        }

        if ($turnstile->isEnabled()) {
            try {
                $turnstileVerifier->verify($data['turnstileToken'] ?? null, $clientIp);
            } catch (TurnstileRejectedException) {
                $auditLogger->security(SecurityAuditLogger::CONTENT_REPORT_TURNSTILE_REJECTED, [
                    'path' => $request->getPathInfo(),
                    'reason' => 'challenge rejected',
                ]);

                return $this->json([
                    'message' => 'Complete the anti-bot check and try again.',
                    'errors' => ['turnstile' => 'Complete the anti-bot check and try again.'],
                ], Response::HTTP_BAD_REQUEST);
            } catch (TurnstileUnavailableException) {
                $message = sprintf(
                    'Anti-bot verification is temporarily unavailable. Keep your report details and try again, or email %s.',
                    $this->legalEmail
                );
                $auditLogger->security(SecurityAuditLogger::CONTENT_REPORT_TURNSTILE_UNAVAILABLE, [
                    'path' => $request->getPathInfo(),
                    'reason' => 'verification provider unavailable',
                ], result: SecurityAuditLogger::RESULT_FAILED);

                return $this->json([
                    'message' => $message,
                    'errors' => ['turnstile' => $message],
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        try {
            $report = $reports->submit($data);
        } catch (ContentReportValidationException $exception) {
            return $this->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->successResponse($report);
    }

    /** @param array<string, mixed> $data */
    private function isHoneypot(array $data): bool
    {
        return array_key_exists('website', $data)
            && (!is_string($data['website']) || trim($data['website']) !== '');
    }

    private function successResponse(?ContentReport $report = null): JsonResponse
    {
        $response = [
            'message' => 'Your report has been received and will be reviewed.',
        ];
        if ($report !== null) {
            $response['reference'] = $report->getReference();
        }

        return $this->json($response, Response::HTTP_CREATED);
    }
}
