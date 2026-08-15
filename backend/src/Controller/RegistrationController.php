<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\JsonRequestDecoder;
use App\Service\ApiRateLimiter;
use App\Service\EmailVerificationMailer;
use App\Service\EmailVerificationService;
use App\Service\PasswordValidator;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        PasswordValidator $passwordValidator,
        ApiRateLimiter $rateLimiter,
        EmailVerificationService $verification,
        EmailVerificationMailer $verificationMailer,
        SecurityAuditLogger $securityLogger
    ): Response {
        if ($this->getUser()) {
            return new JsonResponse(['message' => 'User already authenticated.'], Response::HTTP_FORBIDDEN);
        }

        if ($rateLimitResponse = $rateLimiter->limit($request, 'register')) {
            return $rateLimitResponse;
        }

        $data = JsonRequestDecoder::decode($request);
        $password = $data['password'] ?? $data['plainPassword'] ?? null;

        $constraints = new Assert\Collection([
            'email' => [new Assert\NotBlank(['message' => 'Email is required']), new Assert\Email(['message' => 'Invalid email format'])],
            'password' => new Assert\Optional(new Assert\Type('string')),
            'plainPassword' => new Assert\Optional(new Assert\Type('string')),
            'name' => new Assert\Optional(new Assert\Type('string')),
            'agreeTerms' => new Assert\Required([
                new Assert\NotNull(['message' => 'Terms acceptance is required']),
                new Assert\IsTrue(['message' => 'You must agree to the Terms of Service']),
            ]),
        ]);

        $violations = $validator->validate($data, $constraints);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        if (!is_string($password) || $password === '') {
            return new JsonResponse([
                'message' => 'Validation failed',
                'errors' => ['[password]' => 'Password is required'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $passwordErrors = $passwordValidator->validate($password);
        if ($passwordErrors !== []) {
            return new JsonResponse([
                'message' => 'Password does not meet policy requirements.',
                'errors' => ['password' => $passwordErrors],
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']])) {
            return new JsonResponse(['message' => 'User with this email already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail((string) $data['email']);
        if (isset($data['name']) && is_string($data['name']) && trim($data['name']) !== '') {
            $user->setName(trim($data['name']));
        }
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($userPasswordHasher->hashPassword($user, $password));

        $entityManager->persist($user);
        $plainToken = $verification->issue($user);
        $verificationMailer->send($user, $plainToken);

        $securityLogger->audit(SecurityAuditLogger::USER_REGISTERED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
            'created_by_admin' => false,
        ]);

        return new JsonResponse([
            'message' => 'User registered successfully. Please check your email to verify your account.',
            'requiresVerification' => true,
        ], Response::HTTP_CREATED);
    }
}
