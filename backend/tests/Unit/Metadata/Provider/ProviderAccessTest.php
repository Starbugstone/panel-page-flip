<?php

namespace App\Tests\Unit\Metadata\Provider;

use App\Enum\ProviderStatus;
use App\Metadata\Provider\ProviderAccess;
use PHPUnit\Framework\TestCase;

/**
 * Permission to spend one provider's allowance, and the secret to do it with.
 *
 * The account key derived here is the bucket for the circuit breaker, the quota
 * record and the per-provider rate limit, so what counts as one account is
 * decided in this class and nowhere else.
 */
final class ProviderAccessTest extends TestCase
{
    public function testAGrantedAccessCarriesItsSecretAndOrigin(): void
    {
        $access = ProviderAccess::granted('metron', 'personal', 'a-token');

        self::assertTrue($access->isGranted());
        self::assertSame('personal', $access->origin);
        self::assertSame('a-token', $access->secret());
    }

    /**
     * A blank secret is not a credential. Left through it would spend a request
     * to earn a 401 — and, worse, collapse the account key to the hash of an
     * empty string, so two unrelated blank credentials would share a circuit
     * breaker, a quota record and a rate-limit bucket.
     */
    public function testABlankSecretIsNotAGrant(): void
    {
        foreach (['', '   ', "\t\n"] as $blank) {
            $access = ProviderAccess::granted('metron', 'shared', $blank);

            self::assertFalse($access->isGranted(), sprintf('%s should not grant access.', var_export($blank, true)));
            self::assertSame(ProviderStatus::Unconfigured, $access->status);
            self::assertNull($access->origin);
        }
    }

    public function testReachingForASecretThatWasNotGrantedIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        ProviderAccess::denied('metron', ProviderStatus::Disabled, 'off')->secret();
    }

    /** Surrounding whitespace is not part of a token. */
    public function testTheSecretIsTrimmed(): void
    {
        self::assertSame('a-token', ProviderAccess::granted('metron', 'shared', '  a-token  ')->secret());
    }

    /**
     * Two people who paste the same token share one upstream allowance, so they
     * have to share one account key — that is the whole basis for tracking
     * quota per account rather than per user.
     */
    public function testTheAccountKeyFollowsTheSecretRatherThanTheCaller(): void
    {
        $personal = ProviderAccess::granted('metron', 'personal', 'same-token');
        $shared = ProviderAccess::granted('metron', 'shared', 'same-token');

        self::assertSame($personal->accountKey(), $shared->accountKey());
    }

    public function testDifferentSecretsAndProvidersGetDifferentAccountKeys(): void
    {
        $one = ProviderAccess::granted('metron', 'shared', 'token-one');
        $two = ProviderAccess::granted('metron', 'shared', 'token-two');
        $elsewhere = ProviderAccess::granted('comicvine', 'shared', 'token-one');

        self::assertNotSame($one->accountKey(), $two->accountKey());
        self::assertNotSame($one->accountKey(), $elsewhere->accountKey());
    }

    /** The key is hashed, so it can be logged without carrying the credential. */
    public function testTheAccountKeyDoesNotContainTheSecret(): void
    {
        self::assertStringNotContainsString(
            'super-secret-token',
            ProviderAccess::granted('metron', 'shared', 'super-secret-token')->accountKey()
        );
    }
}
