<?php

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/** A handler whose disk is full, in the only form a PSR logger can express it. */
final class ThrowingLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        throw new \RuntimeException('Unable to write to the log directory.');
    }
}
