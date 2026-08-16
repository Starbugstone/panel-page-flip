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
use App\Service\ShareException;
use App\Service\UsernameService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        SecurityAuditLogger $securityLogger,
        UsernameService $usernames
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
            // Optional on the wire, required on the account. The form always
            // sends one because it is shown a suggestion, but a client that
            // omits it gets a generated name rather than an error: an account
            // with no username cannot be shared with, so there is no state in
            // which leaving it out is better than filling it in.
            'username' => new Assert\Optional(new Assert\Type('string')),
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

        $requestedUsername = isset($data['username']) && is_string($data['username'])
            ? ltrim(trim($data['username']), '@')
            : '';

        try {
            $usernames->assign(
                $user,
                $requestedUsername !== '' ? $requestedUsername : $usernames->suggest()
            );
        } catch (ShareException $exception) {
            // A name the caller chose and cannot have. Reported under
            // `username` so the form can put the message next to the field and
            // offer another suggestion, rather than as a bare 409 the user has
            // to interpret.
            return new JsonResponse([
                'message' => 'Validation failed',
                'errors' => ['username' => $exception->getMessage()],
                'suggestion' => $usernames->suggest(),
            ], $exception->getStatusCode());
        }

        // The `U-` code is filled in by UserIdentityListener on persist, along
        // with a username for any caller that did not send one. Issued at
        // creation rather than on first use because a code is how other people
        // reach this account, and one that only exists after its owner next
        // visits the Sharing page cannot be shared with until then.
        $entityManager->persist($user);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two registrations picked the same username between the check and
            // this write. The index is the authority and has just said so;
            // Doctrine closes the manager on it, so the useful answer is a
            // fresh suggestion rather than a retry this request cannot make.
            return new JsonResponse([
                'message' => 'Validation failed',
                'errors' => ['username' => 'That username was taken a moment ago. Please choose another.'],
            ], Response::HTTP_CONFLICT);
        }

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
