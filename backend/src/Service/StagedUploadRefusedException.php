<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A chunked upload the account has no room for. The message is safe to show the
 * uploader, and the status says whether waiting could change the answer.
 */
final class StagedUploadRefusedException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
