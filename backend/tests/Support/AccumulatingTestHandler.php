<?php

namespace App\Tests\Support;

use Monolog\Handler\TestHandler;

/**
 * A {@see TestHandler} that survives the container reset between requests.
 *
 * The functional client is booted once and reboots are disabled, but it still
 * runs the services resetter after every request — which resets the loggers,
 * which clears their handlers. A plain TestHandler therefore only ever holds
 * the records of the most recent request, and a test that makes ten login
 * attempts can only see the tenth.
 *
 * The tests that matter here are exactly the ones spanning several requests:
 * thresholds, throttling, "logged once and not once per attempt". So this
 * handler keeps everything until a test clears it deliberately.
 */
final class AccumulatingTestHandler extends TestHandler
{
    public function reset(): void
    {
        // Deliberately empty. Clearing is a test's decision, not a side effect
        // of the request that happened to come next.
    }
}
