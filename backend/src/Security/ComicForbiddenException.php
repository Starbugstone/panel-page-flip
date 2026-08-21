<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A comic the caller may see, but may not do this particular thing to.
 *
 * Distinct from {@see ComicNotAccessibleException} because the two protect
 * different people: that one hides a comic's existence from a stranger, this
 * one refuses an action to somebody who can already name the comic and would
 * only be confused by "no such comic".
 *
 * {@see \App\EventSubscriber\ApiExceptionSubscriber} renders it.
 */
final class ComicForbiddenException extends \RuntimeException
{
    public function __construct(string $message = 'You do not have permission to do that with this comic.')
    {
        parent::__construct($message);
    }
}
