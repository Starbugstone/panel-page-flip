<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A comic this request may not have, for whatever reason.
 *
 * Deliberately says nothing about which reason. "No such comic" and "not
 * yours" are the same answer here so that the API cannot be used to count
 * somebody else's library: an id that answers 403 while its neighbour answers
 * 404 has confirmed a comic exists to somebody with no right to know it.
 *
 * {@see \App\EventSubscriber\ApiExceptionSubscriber} renders it.
 */
final class ComicNotAccessibleException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Comic not found');
    }
}
