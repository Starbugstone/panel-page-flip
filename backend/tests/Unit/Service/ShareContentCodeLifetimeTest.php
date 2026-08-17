<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ShareContentCodeLifetime;
use PHPUnit\Framework\TestCase;

/**
 * How long a `C-` or `G-` code lives, as the operator configured it.
 *
 * The value shipped is seven days. What matters more than the number is that
 * it is read from one place and applied at minting time, so nothing downstream
 * can invent its own idea of when a code stops working.
 */
final class ShareContentCodeLifetimeTest extends TestCase
{
    public function testTheShippedLifetimeIsAWeek(): void
    {
        self::assertSame(7, ShareContentCodeLifetime::DEFAULT_DAYS);
        self::assertSame(7, (new ShareContentCodeLifetime())->days());
    }

    public function testExpiryIsTheConfiguredNumberOfDaysAfterCreation(): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-16 09:30:00');

        self::assertSame(
            '2026-08-23 09:30:00',
            (new ShareContentCodeLifetime())->expiryFrom($createdAt)->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-08-17 09:30:00',
            (new ShareContentCodeLifetime(1))->expiryFrom($createdAt)->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-15 09:30:00',
            (new ShareContentCodeLifetime(30))->expiryFrom($createdAt)->format('Y-m-d H:i:s')
        );
    }

    /**
     * @dataProvider nonsenseValues
     */
    public function testADeploymentWithANonsenseValueFailsOnTheWayUp(int $days): void
    {
        // Thrown at construction rather than at the first code minted: a
        // container built from bad configuration is a deployment problem, and
        // the useful moment to say so is before anybody is handed an expiry in
        // the past.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SHARE_CONTENT_CODE_TTL_DAYS/');

        new ShareContentCodeLifetime($days);
    }

    /** @return iterable<string, array{int}> */
    public static function nonsenseValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-7];
        yield 'past the ceiling' => [ShareContentCodeLifetime::MAX_DAYS + 1];
    }
}
