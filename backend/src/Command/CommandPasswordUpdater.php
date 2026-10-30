<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\ResetPasswordTokenRepository;
use App\Service\PasswordValidator;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Applies the shared password policy and hash in account-management commands. */
final readonly class CommandPasswordUpdater
{
    public function __construct(
        private PasswordValidator $validator,
        private UserPasswordHasherInterface $hasher,
        private ResetPasswordTokenRepository $resetTokens,
    ) {
    }

    public function update(User $user, string $plainPassword, SymfonyStyle $io): bool
    {
        $errors = $this->validator->validate($plainPassword);
        if ($errors !== []) {
            $io->error(array_merge(['Password does not meet policy requirements:'], $errors));

            return false;
        }

        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        // Takes effect in the caller's flush, along with the password itself.
        $this->resetTokens->markOutstandingTokensUsed($user);

        return true;
    }
}
