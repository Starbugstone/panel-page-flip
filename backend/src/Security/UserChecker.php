<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The one place that decides an account is not allowed to hold a session.
 *
 * Runs inside authentication, so a refusal here means no token is created and
 * no session cookie is issued — unlike a check in the success handler, which
 * can only decorate the response of a login that has already succeeded.
 */
final class UserChecker implements UserCheckerInterface
{
    /**
     * Deliberately empty. Whether an address has been confirmed is checked in
     * {@see checkPostAuth()}, after the password has been verified, so an
     * unauthenticated caller cannot use the login endpoint to learn which
     * addresses have an unverified account behind them.
     */
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isEmailVerified()) {
            throw new UnverifiedEmailException((string) $user->getUserIdentifier());
        }
    }
}
