<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonRequestDecoder;
use App\Repository\UserRepository;
use App\Service\ApiRateLimiter;
use App\Service\EmailVerificationMailer;
use App\Service\EmailVerificationResult;
use App\Service\EmailVerificationService;
use App\Service\PublicUrl;
use App\Service\SecurityAuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/email-verification')]
final class EmailVerificationController extends AbstractController
{
    private const RESEND_MESSAGE = 'If your email exists and still needs verification, a verification email has been sent.';

    public function __construct(private readonly PublicUrl $publicUrl)
    {
    }

    #[Route('/verify/{token}', name: 'app_email_verification_verify', methods: ['GET'], requirements: ['token' => '[A-Fa-f0-9]{64}'])]
    public function verify(string $token, EmailVerificationService $verification, SecurityAuditLogger $securityLogger): Response
    {
        $result = $verification->verify($token);
        if ($result->status === EmailVerificationResult::INVALID || $result->user === null) {
            $securityLogger->suspicious(
                SecurityAuditLogger::AUTHENTICATION_FAILED,
                'verify:' . $securityLogger->clientIp(),
                ['reason' => 'invalid_email_verification_token'],
                $securityLogger->failedLoginThreshold()
            );

            return $this->redirectToFrontend('verification-failed', 'Invalid or expired verification token');
        }

        if ($result->status === EmailVerificationResult::ALREADY_VERIFIED) {
            return $this->redirectToFrontend('verification-success', 'Your email has already been verified');
        }

        $securityLogger->audit(SecurityAuditLogger::USER_EMAIL_VERIFIED, [
            'actor_user_id' => $result->user->getId(),
            'target_user_id' => $result->user->getId(),
            'target_type' => 'user',
            'verified_by_admin' => false,
        ]);

        return $this->redirectToFrontend('verification-success', 'Your email has been verified successfully');
    }

    #[Route('/resend', name: 'app_email_verification_resend', methods: ['POST'])]
    public function resendVerificationEmail(
        Request $request,
        UserRepository $users,
        ApiRateLimiter $rateLimiter,
        EmailVerificationService $verification,
        EmailVerificationMailer $verificationMailer,
        SecurityAuditLogger $securityLogger
    ): JsonResponse {
        if ($rateLimitResponse = $rateLimiter->limit($request, 'verification_resend')) {
            return $rateLimitResponse;
        }

        $data = JsonRequestDecoder::decode($request);
        $email = $data['email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            return $this->json(['message' => 'Email is required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $users->findOneBy(['email' => trim($email)]);
        if ($user !== null && !$user->isEmailVerified()) {
            $plainToken = $verification->issue($user);
            $verificationMailer->send($user, $plainToken);
            $securityLogger->audit(SecurityAuditLogger::USER_VERIFICATION_RESENT, [
                'actor_user_id' => $user->getId(),
                'target_user_id' => $user->getId(),
                'target_type' => 'user',
            ]);
        }

        return $this->json(['message' => self::RESEND_MESSAGE], Response::HTTP_OK);
    }

    private function redirectToFrontend(string $status, string $message): Response
    {
        return $this->redirect(sprintf(
            '%s?status=%s&message=%s',
            $this->publicUrl->to('/email-verification'),
            urlencode($status),
            urlencode($message)
        ));
    }
}
