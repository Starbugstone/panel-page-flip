<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Twig\Environment;

class RegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
        Environment $twig
    ): Response {
        // If user is already logged in, return an appropriate API response
        if ($this->getUser()) {
            return new JsonResponse(['message' => 'User already authenticated.'], Response::HTTP_FORBIDDEN);
        }

        // Get data from JSON request body
        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate required fields
        $constraints = new Assert\Collection([
            'email' => [
                new Assert\NotBlank(['message' => 'Email is required']),
                new Assert\Email(['message' => 'Invalid email format'])
            ],
            'password' => [
                new Assert\NotBlank(['message' => 'Password is required']),
                new Assert\Length([
                    'min' => 6,
                    'minMessage' => 'Password must be at least {{ limit }} characters long'
                ])
            ],
            'plainPassword' => new Assert\Optional(new Assert\Type('string')),
            'name' => new Assert\Optional(new Assert\Type('string'))
        ]);

        $violations = $validator->validate($data, $constraints);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $propertyPath = $violation->getPropertyPath();
                $errors[$propertyPath] = $violation->getMessage();
            }
            return new JsonResponse(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Check if user already exists
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return new JsonResponse(['message' => 'User with this email already exists'], Response::HTTP_CONFLICT);
        }

        // Create new user
        $user = new User();
        $user->setEmail($data['email']);
        
        // Set name if provided
        if (isset($data['name']) && !empty($data['name'])) {
            $user->setName($data['name']);
        }
        
        // Set default roles
        $user->setRoles(['ROLE_USER']);
        
        // Hash the password
        $password = $data['password'] ?? $data['plainPassword'] ?? null;
        if (!$password) {
            return new JsonResponse(['message' => 'Password is required'], Response::HTTP_BAD_REQUEST);
        }
        
        $user->setPassword($userPasswordHasher->hashPassword($user, $password));

        // Generate email verification token
        $verificationToken = $user->generateEmailVerificationToken();
        
        // Save to database
        $entityManager->persist($user);
        $entityManager->flush();
        
        // Send verification email
        $this->sendVerificationEmail($user, $verificationToken, $mailer, $urlGenerator, $twig);

        // Return success response
        return new JsonResponse(
            [
                'message' => 'User registered successfully. Please check your email to verify your account.',
                'requiresVerification' => true
            ],
            Response::HTTP_CREATED
        );
    }
    
    /**
     * Send verification email to the user
     */
    private function sendVerificationEmail(
        User $user,
        string $token,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
        Environment $twig
    ): void {
        // Generate the API verification URL (backend)
        $apiVerificationUrl = $urlGenerator->generate(
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
}
