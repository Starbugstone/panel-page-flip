<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/api/email-verification')]
class EmailVerificationController extends AbstractController
{
    #[Route('/verify/{token}', name: 'app_email_verification_verify', methods: ['GET'])]
    public function verify(
        string $token,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        Request $request
    ): Response {
        $user = $userRepository->findOneBy(['emailVerificationToken' => $token]);

        if (!$user) {
            return $this->redirectToFrontend('verification-failed', 'Invalid verification token');
        }

        if ($user->isEmailVerified()) {
            return $this->redirectToFrontend('verification-success', 'Your email has already been verified');
        }

        if ($user->isEmailVerificationTokenExpired()) {
            return $this->redirectToFrontend('verification-failed', 'Verification token has expired');
        }

        // Verify the user's email
        $user->setIsEmailVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);

        $entityManager->persist($user);
        $entityManager->flush();

        return $this->redirectToFrontend('verification-success', 'Your email has been verified successfully');
    }

    #[Route('/resend', name: 'app_email_verification_resend', methods: ['POST'])]
    public function resendVerificationEmail(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        \Symfony\Component\Mailer\MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
        \Twig\Environment $twig
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['message' => 'Email is required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $userRepository->findOneBy(['email' => $email]);

        if (!$user) {
            // Don't reveal that the user doesn't exist for security reasons
            return $this->json(['message' => 'If your email exists in our system, a verification email has been sent'], Response::HTTP_OK);
        }

        if ($user->isEmailVerified()) {
            return $this->json(['message' => 'Your email is already verified'], Response::HTTP_OK);
        }

        // Generate a new verification token
        $token = $user->generateEmailVerificationToken();
        $entityManager->persist($user);
        $entityManager->flush();

        // Send verification email
        $this->sendVerificationEmail($user, $token, $mailer, $urlGenerator, $twig);

        return $this->json(['message' => 'Verification email has been sent'], Response::HTTP_OK);
    }

    private function sendVerificationEmail(
        User $user,
        string $token,
        \Symfony\Component\Mailer\MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
        \Twig\Environment $twig
    ): void {
        // Generate the API verification URL (backend)
        $apiVerificationUrl = $this->generateUrl(
            'app_email_verification_verify',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        
        // Get the mailer configuration
        $fromEmail = $this->getParameter('mailer_from_address') ?: 'noreply@comicreader.example.com';
        $fromName = $this->getParameter('mailer_from_name') ?: 'Comic Reader';
        
        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address($fromEmail, $fromName))
            ->to($user->getEmail())
            ->subject('Verify your email address')
            ->html(
                $twig->render('emails/email_verification.html.twig', [
                    'user' => $user,
                    'verificationUrl' => $apiVerificationUrl
                ])
            );

        $mailer->send($email);
    }

    private function redirectToFrontend(string $status, string $message): Response
    {
        $frontendUrl = $this->getParameter('frontend_url');
        
        // Make sure the frontend URL doesn't have a trailing slash
        $frontendUrl = rtrim($frontendUrl, '/');
        
        $redirectUrl = sprintf('%s/email-verification?status=%s&message=%s', 
            $frontendUrl, 
            urlencode($status), 
            urlencode($message)
        );
        
        return $this->redirect($redirectUrl);
    }
}
