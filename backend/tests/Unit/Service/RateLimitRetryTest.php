<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RateLimitRetry;
use App\Service\ShareException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimit;

class RateLimitRetryTest extends TestCase
{
    public function testSecondsCountForwardFromNow(): void
    {
        self::assertSame(90, RateLimitRetry::seconds($this->refusedIn(90)));
    }

    /**
     * Rounded up, because sending somebody back at the sixty-second mark of a
     * ninety-second wait just buys them a second refusal.
     */
    public function testMinutesRoundUp(): void
    {
        self::assertSame(2, RateLimitRetry::minutes($this->refusedIn(90)));
        self::assertSame(1, RateLimitRetry::minutes($this->refusedIn(60)));
        self::assertSame(1, RateLimitRetry::minutes($this->refusedIn(1)));
    }

    /**
     * A limiter whose window has just elapsed reports a moment already past.
     * "Try again in 0 minutes" reads as a bug; "1" reads as an instruction.
     */
    public function testAnElapsedWindowStillAsksForOneOfEachUnit(): void
    {
        $elapsed = $this->refusedIn(-30);

        self::assertSame(1, RateLimitRetry::seconds($elapsed));
        self::assertSame(1, RateLimitRetry::minutes($elapsed));
    }

    /**
     * The half of the sentence no caller writes: every allowance refuses with
     * the same closing instruction, and it is appended rather than repeated.
     */
    public function testARateLimitedShareExceptionSaysWhenToComeBack(): void
    {
        $exception = ShareException::rateLimited('You have sent too many invitations recently.', $this->refusedIn(90));

        self::assertSame(
            'You have sent too many invitations recently. Please try again in 2 minute(s).',
            $exception->getMessage()
        );
        self::assertSame(429, $exception->getStatusCode());
        self::assertNull($exception->getErrorCode());
    }

    private function refusedIn(int $seconds): RateLimit
    {
        return new RateLimit(0, new \DateTimeImmutable(sprintf('@%d', time() + $seconds)), false, 10);
    }
}
