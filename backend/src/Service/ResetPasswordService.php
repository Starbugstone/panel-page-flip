<?php

namespace App\Service;

use App\Entity\ResetPasswordToken;
use App\Entity\User;
use App\Repository\ResetPasswordTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly string $mailerFromName,
        private readonly LoggerInterface $logger,
        private readonly SecurityAuditLogger $auditLogger
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
            // Recorded, but without the address that was typed. A reset form is
            // open to anybody, and writing every submitted address into the
            // security log would build exactly the list of addresses an attacker
            // came here with.
            $this->auditLogger->audit(SecurityAuditLogger::USER_PASSWORD_RESET_REQUESTED, [
                'actor_user_id' => null,
                'account_resolved' => false,
            ]);

            return true;
        }

        // Invalidate any existing tokens for this user
        $this->tokenRepository->invalidateAllTokensForUser($user);

        // Create a new token. Only the plaintext is wanted here — the entity is
        // already persisted, and holding a reference to it invites somebody to
        // pass the stored hash somewhere it should not go.
        [, $plainToken] = $this->createToken($user);

        // Send the email
        $this->sendEmail($user, $plainToken);

        // The token itself never appears here, and the processor would strip it
        // if a later edit put it in: the record is that a reset was asked for,
        // not what would let somebody complete it.
        $this->auditLogger->audit(SecurityAuditLogger::USER_PASSWORD_RESET_REQUESTED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
            'account_resolved' => true,
        ]);

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
            // Repeatedly presenting reset links that are not valid is somebody
            // guessing at them. Scoped to the address because a reset link is
            // used before anybody is signed in, so there is no account to
            // attribute it to.
            $this->auditLogger->suspicious(
                SecurityAuditLogger::AUTHENTICATION_FAILED,
                'reset:' . $this->auditLogger->clientIp(),
                ['reason' => 'invalid_or_expired_reset_token'],
                $this->auditLogger->failedLoginThreshold()
            );

            return false;
        }

        $user = $resetToken->getUser();
        
        // Hash the new password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        
        // Mark the token as used
        $resetToken->setIsUsed(true);
        
        $this->entityManager->flush();

        $this->auditLogger->audit(SecurityAuditLogger::USER_PASSWORD_RESET_COMPLETED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
        ]);

        $this->auditLogger->audit(SecurityAuditLogger::USER_PASSWORD_CHANGED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
            'via' => 'password_reset',
        ]);

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
            $this->logger->warning('Error sending password changed notification.', ['exception' => $e]);
        }
    }
    
    /**
     * Create a new reset token for a user
     */
    /**
     * @return array{0: ResetPasswordToken, 1: string}
     */
    private function createToken(User $user): array
    {
        // Generate a random token
        $tokenString = bin2hex(random_bytes(32));
        
        // Create a new ResetPasswordToken entity
        $token = new ResetPasswordToken();
        $token->setUser($user);
        $token->setToken(hash('sha256', $tokenString));
        $token->setExpiresAt(new \DateTimeImmutable('+' . self::TOKEN_EXPIRY_HOURS . ' hours'));
        $token->setIsUsed(false);
        
        // Save the token
        $this->entityManager->persist($token);
        $this->entityManager->flush();
        
        return [$token, $tokenString];
    }
    
    /**
     * Send the password reset email
     */
    private function sendEmail(User $user, string $plainToken): void
    {
        try {
            // Generate the base URL (scheme + host + port)
            $baseUrl = sprintf(
                '%s://%s',
                $_SERVER['APP_SCHEME'] ?? 'http',
                $_SERVER['HTTP_HOST'] ?? 'localhost:8080'
            );
            
            // Create the frontend reset URL with the token
            $resetUrl = $baseUrl . '/reset-password/' . $plainToken;
            

            
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
            
            // Send the email
            $this->mailer->send($email);

        } catch (\Exception $e) {
            // Log the error (in development only)
            $this->logger->error('Error sending password reset email.', ['exception' => $e]);
            
            // Re-throw the exception to be handled by the caller
            throw $e;
        }
    }
}
