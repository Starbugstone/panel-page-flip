<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ApiRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $loginLimiter,
        private readonly RateLimiterFactory $registerLimiter,
        private readonly RateLimiterFactory $forgotPasswordLimiter,
        private readonly RateLimiterFactory $verificationResendLimiter,
        private readonly RateLimiterFactory $comicSearchLimiter,
        private readonly RateLimiterFactory $usernameLookupLimiter,
        private readonly SecurityAuditLogger $auditLogger
    ) {
    }

    public function limit(Request $request, string $limiterName, ?string $key = null): ?JsonResponse
    {
        $factory = match ($limiterName) {
            'login' => $this->loginLimiter,
            'register' => $this->registerLimiter,
            'forgot_password' => $this->forgotPasswordLimiter,
            'verification_resend' => $this->verificationResendLimiter,
            'comic_search' => $this->comicSearchLimiter,
            'username_lookup' => $this->usernameLookupLimiter,
            default => throw new \InvalidArgumentException(sprintf('Unknown limiter "%s".', $limiterName)),
        };

        $clientKey = $key ?? $this->getClientKey($request);
        $limit = $factory->create($clientKey)->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        $this->auditLogger->suspicious(
            SecurityAuditLogger::RATE_LIMIT_TRIGGERED,
            sprintf('%s:%s', $limiterName, $clientKey),
            ['limiter' => $limiterName, 'path' => $request->getPathInfo()]
        );

        $retryAfter = RateLimitRetry::seconds($limit);
        $response = new JsonResponse([
            'message' => 'Too many requests. Please try again later.',
            'retryAfter' => $retryAfter,
        ], Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    private function getClientKey(Request $request): string
    {
        return $request->getClientIp() ?: 'unknown';
    }
}
