<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\RateLimiter\RateLimit;

/**
 * How long a refused caller has to wait, in the two units anything here says it
 * in.
 *
 * A `RateLimit` reports the moment the allowance recovers. Nothing wants that
 * moment: an HTTP `Retry-After` wants seconds from now, and a sentence shown to
 * a person wants whole minutes. Both conversions were written out at every
 * refusal, which is how one of the copies ends up rounding the other way.
 *
 * Both floors at one rather than zero. A limiter whose window has just elapsed
 * reports a moment in the past, and "try again in 0 minutes" reads as a bug
 * where "1" reads as an instruction.
 */
final class RateLimitRetry
{
    /** Seconds from now, for a `Retry-After` header or a machine-read payload. */
    public static function seconds(RateLimit $limit): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    /**
     * Whole minutes from now, for a sentence somebody reads.
     *
     * Rounded up, always: telling somebody to come back in a minute when the
     * allowance recovers in ninety seconds sends them straight into a second
     * refusal.
     */
    public static function minutes(RateLimit $limit): int
    {
        return (int) ceil(self::seconds($limit) / 60);
    }
}
