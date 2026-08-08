<?php

namespace App\Tests\Support;

use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * An in-memory cache pool that survives the container reset between requests.
 *
 * Alert thresholds count occurrences *across* requests — that is the whole
 * mechanism. A stock ArrayAdapter is reset with the rest of the container after
 * every request the functional client makes, so the counter would restart at
 * one each time and no threshold could ever be reached.
 *
 * In production the pool is filesystem-backed and each request is a fresh
 * process, so nothing resets it there either; this is the faithful double, not
 * a convenience.
 */
final class PersistentArrayCache extends ArrayAdapter
{
    public function reset(): void
    {
        // Deliberately empty; see the class comment.
    }
}
