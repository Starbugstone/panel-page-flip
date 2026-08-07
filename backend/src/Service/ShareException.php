<?php

namespace App\Service;

/**
 * A sharing operation that failed for a reason the caller should be told about,
 * carrying the HTTP status that reason maps to.
 *
 * Messages on this exception are written to be read by the person who triggered
 * the request; anything internal is logged instead and surfaces as a generic
 * failure.
 */
class ShareException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
