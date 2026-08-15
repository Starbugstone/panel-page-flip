<?php

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps every record so a test can assert on what was written.
 *
 * Used where the question is not "did this log" but "what did it put in the
 * log" — a provider failure whose exception message quotes the request URL, and
 * with it the credential the URL carried.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
