<?php

namespace App\Controller;

use App\Service\ResetPasswordService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly ResetPasswordService $resetPasswordService,
        private readonly ValidatorInterface $validator
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $email = $data['email'] ?? '';
            
            // Log the request (in development only)
            if ($this->getParameter('kernel.environment') === 'dev') {
                error_log("Forgot password request received for email: {$email}");
            }

            // Validate email
            $emailConstraint = new Assert\Email();
            $errors = $this->validator->validate($email, $emailConstraint);

            if (count($errors) > 0) {
                return $this->json(['message' => 'Invalid email format'], Response::HTTP_BAD_REQUEST);
            }

            // Process the password reset request
            $result = $this->resetPasswordService->sendPasswordResetEmail($email);
            
            // Log the result (in development only)
            if ($this->getParameter('kernel.environment') === 'dev') {
                error_log("Password reset email sent result: " . ($result ? 'success' : 'failure'));
            }

            // Always return success for security reasons, even if email doesn't exist
            return $this->json(['message' => 'If an account exists with that email, you will receive password reset instructions.']);
        } catch (\Exception $e) {
            // Log the error (in development only)
            if ($this->getParameter('kernel.environment') === 'dev') {
                error_log("Error in forgot password: " . $e->getMessage());
            }
            
            return $this->json(['message' => 'An error occurred processing your request.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/reset-password/validate/{token}', name: 'app_reset_password_validate', methods: ['GET'])]
    public function validateToken(string $token): JsonResponse
    {
        $isValid = $this->resetPasswordService->validateToken($token);

        if (!$isValid) {
            return $this->json(['message' => 'Invalid or expired token'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'Token is valid']);
    }

    #[Route('/reset-password/reset/{token}', name: 'app_reset_password_reset', methods: ['POST'])]
    public function resetPassword(Request $request, string $token): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $password = $data['password'] ?? '';

        // Validate password
        $passwordConstraint = new Assert\NotBlank();
        $errors = $this->validator->validate($password, $passwordConstraint);

        if (count($errors) > 0) {
            return $this->json(['message' => 'Password cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        // Reset the password
        $success = $this->resetPasswordService->resetPassword($token, $password);

        if (!$success) {
            return $this->json(['message' => 'Invalid or expired token'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'Password has been reset successfully']);
    }
}
