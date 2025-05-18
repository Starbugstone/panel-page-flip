<?php

namespace App\Service;

use App\Entity\ResetPasswordToken;
use App\Entity\User;
use App\Repository\ResetPasswordTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class ResetPasswordService
{
    private const TOKEN_EXPIRY_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly ResetPasswordTokenRepository $tokenRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName
    ) {
    }

    /**
     * Create a password reset token for a user and send the reset email
     */
    public function sendPasswordResetEmail(string $email): bool
    {
        // Find the user by email
        $user = $this->userRepository->findOneBy(['email' => $email]);
        
        // If user not found, we still return true for security reasons
        // (to not reveal whether an email exists in the system)
        if (!$user) {
            return true;
        }
        
        // Invalidate any existing tokens for this user
        $this->tokenRepository->invalidateAllTokensForUser($user);
        
        // Create a new token
        $token = $this->createToken($user);
        
        // Send the email
        $this->sendEmail($user, $token);
        
        return true;
    }
    
    /**
     * Validate a reset token
     */
    public function validateToken(string $token): bool
    {
        $resetToken = $this->tokenRepository->findValidToken($token);
        return $resetToken !== null;
    }
    
    /**
     * Reset a user's password using a valid token
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $resetToken = $this->tokenRepository->findValidToken($token);
        
        if (!$resetToken) {
            return false;
        }
        
        $user = $resetToken->getUser();
        
        // Hash the new password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        
        // Mark the token as used
        $resetToken->setIsUsed(true);
        
        $this->entityManager->flush();
        
        // Send a notification email that the password was changed
        $this->sendPasswordChangedEmail($user);
        
        return true;
    }
    
    /**
     * Send a notification email that the password was changed
     */
    private function sendPasswordChangedEmail(User $user): void
    {
        try {
            // Render the email template
            $emailContent = $this->twig->render('emails/password_changed.html.twig', [
                'user' => $user,
                'changeTime' => new \DateTimeImmutable(),
            ]);
            
            // Create the email
            $email = (new Email())
                ->from(sprintf('"%s" <%s>', $this->mailerFromName, $this->mailerFromAddress))
                ->to($user->getEmail())
                ->subject('Your Password Has Been Changed - Comic Reader')
                ->html($emailContent);
            
            // Send the email
            $this->mailer->send($email);
            
        } catch (\Exception $e) {
            // Log the error but don't throw it (don't want to break the password reset process)
            error_log("Error sending password changed notification: " . $e->getMessage());
        }
    }
    
    /**
     * Create a new reset token for a user
     */
    private function createToken(User $user): ResetPasswordToken
    {
        // Generate a random token
        $tokenString = bin2hex(random_bytes(32));
        
        // Create a new ResetPasswordToken entity
        $token = new ResetPasswordToken();
        $token->setUser($user);
        $token->setToken($tokenString);
        $token->setExpiresAt(new \DateTimeImmutable('+' . self::TOKEN_EXPIRY_HOURS . ' hours'));
        $token->setIsUsed(false);
        
        // Save the token
        $this->entityManager->persist($token);
        $this->entityManager->flush();
        
        return $token;
    }
    
    /**
     * Send the password reset email
     */
    private function sendEmail(User $user, ResetPasswordToken $token): void
    {
        try {
            // Generate the base URL (scheme + host + port)
            $baseUrl = sprintf(
                '%s://%s',
                $_SERVER['APP_SCHEME'] ?? 'http',
                $_SERVER['HTTP_HOST'] ?? 'localhost:8080'
            );
            
            // Create the frontend reset URL with the token
            $resetUrl = $baseUrl . '/reset-password/' . $token->getToken();
            
            // Log the reset URL (in development only)
            error_log("Generated reset URL: {$resetUrl}");
            
            // For development: Output the reset URL to the console
            echo "\n\n=================================================================\n";
            echo "DEVELOPMENT MODE: Password Reset URL for testing\n";
            echo "=================================================================\n";
            echo $resetUrl . "\n";
            echo "=================================================================\n\n";
            
            // Render the email template
            $emailContent = $this->twig->render('emails/reset_password.html.twig', [
                'resetUrl' => $resetUrl,
                'user' => $user,
                'expiryHours' => self::TOKEN_EXPIRY_HOURS,
            ]);
            
            // Create the email
            $email = (new Email())
                ->from(sprintf('"%s" <%s>', $this->mailerFromName, $this->mailerFromAddress))
                ->to($user->getEmail())
                ->subject('Reset your password - Comic Reader')
                ->html($emailContent);
            
            // Log email details (in development only)
            error_log("Sending email to: {$user->getEmail()}");
            error_log("From address: {$this->mailerFromAddress}");
            error_log("Mailer DSN from environment: " . $_ENV['MAILER_DSN'] ?? 'not set');
            
            // Send the email
            $this->mailer->send($email);
            
            // Log success (in development only)
            error_log("Email sent successfully");
        } catch (\Exception $e) {
            // Log the error (in development only)
            error_log("Error sending password reset email: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            
            // Re-throw the exception to be handled by the caller
            throw $e;
        }
    }
}
