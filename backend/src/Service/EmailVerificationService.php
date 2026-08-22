<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final class EmailVerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerificationTokenRepository $tokens,
    ) {
    }

    public function issue(User $user): string
    {
        if ($user->getId() !== null) {
            $this->removeTokensFor($user);
        }
        $token = new EmailVerificationToken($user);
        $plainToken = $token->getPlainToken();
        if ($plainToken === null) {
            throw new \RuntimeException('Email verification token was not generated.');
        }

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $plainToken;
    }

    public function verify(string $plainToken): EmailVerificationResult
    {
        $token = $this->tokens->findValidToken($plainToken);
        if ($token === null || ($user = $token->getUser()) === null) {
            return new EmailVerificationResult(EmailVerificationResult::INVALID);
        }

        if ($user->isEmailVerified()) {
            $this->removeTokensFor($user);
            $this->entityManager->flush();

            return new EmailVerificationResult(EmailVerificationResult::ALREADY_VERIFIED, $user);
        }

        $user->setIsEmailVerified(true);
        $this->removeTokensFor($user);
        $this->entityManager->flush();

        return new EmailVerificationResult(EmailVerificationResult::VERIFIED, $user);
    }

    /**
     * Verify an account without a token, as an administrator does.
     *
     * The outstanding tokens go with it. Verification and "no tokens left" is
     * one fact, not two, and the controller that used to reach for the entity's
     * own setters to say so went on calling them after the token became a
     * collection — so every administrative verification answered 500. Keeping
     * the rule here means there is only one place left that can drift.
     */
    public function markVerified(User $user): void
    {
        $user->setIsEmailVerified(true);
        $this->removeTokensFor($user);
        $this->entityManager->flush();
    }

    private function removeTokensFor(User $user): void
    {
        foreach ($this->tokens->findBy(['user' => $user]) as $token) {
            $this->entityManager->remove($token);
        }
    }
}
