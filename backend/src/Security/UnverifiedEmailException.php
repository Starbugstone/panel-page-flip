<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Raised when credentials are correct but the address behind them was never
 * confirmed.
 *
 * An account status exception rather than a check inside the success handler,
 * because the handler runs *after* the authenticated token has been stored:
 * refusing there produces a 403 body on top of a perfectly usable session. This
 * makes authentication itself fail, so no token is ever created.
 *
 * Carries the address so the failure handler can offer to resend the
 * verification email without asking for it again.
 */
class UnverifiedEmailException extends CustomUserMessageAccountStatusException
{
    public function __construct(private readonly string $email)
    {
        parent::__construct('Your email address is not verified. Please check your inbox for the verification email.');
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /** @return array{0: string} */
    public function __serialize(): array
    {
        return [$this->email, parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [$this->email, $parentData] = $data;
        parent::__unserialize($parentData);
    }
}
