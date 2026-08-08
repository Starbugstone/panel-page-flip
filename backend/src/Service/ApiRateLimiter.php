<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class ApiRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactory $loginLimiter,
        private readonly RateLimiterFactory $registerLimiter,
        private readonly RateLimiterFactory $forgotPasswordLimiter,
        private readonly RateLimiterFactory $verificationResendLimiter,
        private readonly SecurityAuditLogger $auditLogger
    ) {
    }

    public function limit(Request $request, string $limiterName): ?JsonResponse
    {
        $factory = match ($limiterName) {
            'login' => $this->loginLimiter,
            'register' => $this->registerLimiter,
            'forgot_password' => $this->forgotPasswordLimiter,
            'verification_resend' => $this->verificationResendLimiter,
            default => throw new \InvalidArgumentException(sprintf('Unknown limiter "%s".', $limiterName)),
        };

        $limit = $factory->create($this->getClientKey($request))->consume();
        if ($limit->isAccepted()) {
            return null;
        }

        // Every limiter reports through here, so one place covers login,
        // registration, password reset and verification resends. A limiter that
        // fires once is the limiter working; one that keeps firing from the same
        // address is somebody grinding at it, which is what the threshold and
        // the alert behind it are for.
        $this->auditLogger->suspicious(
            SecurityAuditLogger::RATE_LIMIT_TRIGGERED,
            sprintf('%s:%s', $limiterName, $this->getClientKey($request)),
            [
                'limiter' => $limiterName,
                'path' => $request->getPathInfo(),
            ]
        );

        $retryAfter = $limit->getRetryAfter();
        $response = new JsonResponse([
            'message' => 'Too many requests. Please try again later.',
            'retryAfter' => max(1, $retryAfter->getTimestamp() - time()),
        ], Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) max(1, $retryAfter->getTimestamp() - time()));

        return $response;
    }

    private function getClientKey(Request $request): string
    {
        return $request->getClientIp() ?: 'unknown';
    }
}
