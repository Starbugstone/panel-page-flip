<?php

namespace App\Controller;

use App\Service\ContentReportService;
use App\Service\ContentReportValidationException;
use App\Service\RateLimitRetry;
use App\Service\SecurityAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ContentReportController extends AbstractController
{
    public function __construct(private readonly RateLimiterFactory $contentReportLimiter)
    {
    }

    #[Route('/api/content-reports', name: 'api_content_reports_submit', methods: ['POST'])]
    public function submit(Request $request, ContentReportService $reports, SecurityAuditLogger $auditLogger): JsonResponse
    {
        $limit = $this->contentReportLimiter->create($request->getClientIp() ?: 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $auditLogger->suspicious(SecurityAuditLogger::RATE_LIMIT_TRIGGERED, 'content-report:'.($request->getClientIp() ?: 'unknown'), [
                'limiter' => 'content_report',
                'path' => $request->getPathInfo(),
            ]);
            return $this->json(['message' => 'Too many reports. Please try again later.'], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) RateLimitRetry::seconds($limit),
            ]);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $report = $reports->submit($data);
        } catch (ContentReportValidationException $exception) {
            return $this->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $response = [
            'message' => 'Your report has been received and will be reviewed.',
        ];
        if ($report !== null) {
            $response['reference'] = $report->getReference();
        }

        return $this->json($response, Response::HTTP_CREATED);
    }
}
