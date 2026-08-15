<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Raised when an action needs a signed-in user and there is none.
 *
 * The firewall already refuses anonymous callers on every `/api` route, so
 * reaching this means something got past it — a route outside the pattern, or a
 * token whose user no longer resolves. It is the belt to the firewall's braces,
 * and it is worth keeping precisely because it should never fire.
 *
 * Thrown rather than returned so the answer is written once, in
 * {@see \App\EventSubscriber\ApiExceptionSubscriber}, instead of at every
 * action that happens to need a user.
 */
final class UnauthenticatedException extends \RuntimeException
{
    public const MESSAGE = 'User not authenticated';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
