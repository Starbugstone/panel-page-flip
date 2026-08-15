<?php

namespace App\Tests\Unit\Service;

use App\Entity\MetadataProviderConfiguration;
use App\Entity\User;
use App\Entity\UserMetadataCredential;
use App\Enum\ProviderStatus;
use App\Metadata\Provider\PersonalProviderCredentials;
use App\Metadata\Provider\SharedProviderCredentials;
use App\Service\MetadataAccessResolver;
use App\Service\ProviderCircuitBreaker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The switches that decide whether a lookup happens at all.
 *
 * Each of these is a veto, and the one that matters most is the environment's:
 * an operator who turned shared access off must not be overrulable from inside
 * the application.
 */
final class MetadataAccessResolverTest extends TestCase
{
    public function testTheEnvironmentSwitchOverrulesTheAdministrator(): void
    {
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: false
        );

        $access = $resolver->resolve('metron', $this->user());

        self::assertFalse($access->isGranted());
        self::assertSame(ProviderStatus::Disabled, $access->status);
    }

    public function testSharedAccessNeedsTheAdministratorToo(): void
    {
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(false);
            },
            metronSharedAllowedByEnvironment: true
        );

        self::assertSame(ProviderStatus::Disabled, $resolver->resolve('metron', $this->user())->status);
    }

    public function testSharedAccessNeedsAToken(): void
    {
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: true
        );

        self::assertSame(ProviderStatus::Unconfigured, $resolver->resolve('metron', $this->user())->status);
    }

    public function testSharedAccessIsGrantedWhenEveryConditionHolds(): void
    {
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: true
        );

        $access = $resolver->resolve('metron', $this->user());

        self::assertTrue($access->isGranted());
        self::assertSame('shared', $access->origin);
    }

    /**
     * Somebody who brought their own token spends their own allowance, and
     * keeps working when the installation's shared access is switched off.
     */
    public function testAPersonalTokenIsPreferredAndSurvivesTheSharedSwitch(): void
    {
        $user = $this->user();
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: false,
            personal: (new UserMetadataCredential())->setMetronToken('personal-token')
        );

        $access = $resolver->resolve('metron', $user);

        self::assertTrue($access->isGranted());
        self::assertSame('personal', $access->origin);
        self::assertSame('personal-token', $access->secret());
    }

    /** An administrator can withdraw external lookups from one account. */
    public function testAUserWithoutApiAccessIsRefusedEveryProvider(): void
    {
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: true,
            personal: (new UserMetadataCredential())->setMetronToken('personal-token')
        );

        $access = $resolver->resolve('metron', $this->user(metadataApiEnabled: false));

        self::assertFalse($access->isGranted());
        self::assertSame(ProviderStatus::Forbidden, $access->status);
    }

    /**
     * Comic Vine's terms are non-commercial only, and a user's own key does not
     * waive them — so a personal key is still gated on the installation having
     * declared the service usable.
     */
    public function testAPersonalComicVineKeyDoesNotBypassTheGlobalSwitch(): void
    {
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setComicVineEnabled(true);
            },
            comicVineSharedAllowedByEnvironment: false,
            personal: (new UserMetadataCredential())->setComicVineApiKey('personal-key')
        );

        self::assertSame(ProviderStatus::Disabled, $resolver->resolve('comicvine', $this->user())->status);
    }

    public function testComicVineIsDisableableIndependentlyOfMetron(): void
    {
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
                $c->setComicVineApiKey('shared-key')->setComicVineEnabled(false);
            },
            metronSharedAllowedByEnvironment: true,
            comicVineSharedAllowedByEnvironment: true
        );

        self::assertTrue($resolver->resolve('metron', $this->user())->isGranted());
        self::assertSame(ProviderStatus::Disabled, $resolver->resolve('comicvine', $this->user())->status);
    }

    /** A pause is temporary and belongs to the account, not to the switch. */
    public function testAPausedAccountIsHeldOffWithoutChangingTheSetting(): void
    {
        $cache = new ArrayAdapter();
        $breaker = new ProviderCircuitBreaker($this->cache($cache));
        $resolver = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: true,
            breaker: $breaker
        );

        $granted = $resolver->resolve('metron', $this->user());
        self::assertTrue($granted->isGranted());

        $breaker->recordFailure($granted->accountKey(), ProviderStatus::RateLimited, 120);

        $paused = $resolver->resolve('metron', $this->user());
        self::assertFalse($paused->isGranted());
        self::assertSame(ProviderStatus::Paused, $paused->status);
    }

    /** One user's exhausted personal token must not pause the shared one. */
    public function testAPauseOnOneAccountDoesNotAffectAnother(): void
    {
        $cache = new ArrayAdapter();
        $breaker = new ProviderCircuitBreaker($this->cache($cache));

        $personal = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: true,
            personal: (new UserMetadataCredential())->setMetronToken('personal-token'),
            breaker: $breaker
        );

        $breaker->recordFailure($personal->resolve('metron', $this->user())->accountKey(), ProviderStatus::RateLimited, 120);

        $shared = $this->resolver(
            configuration: static function (MetadataProviderConfiguration $c): void {
                $c->setMetronToken('shared-token')->setMetronSharedEnabled(true);
            },
            metronSharedAllowedByEnvironment: true,
            breaker: $breaker
        );

        self::assertTrue($shared->resolve('metron', $this->user())->isGranted());
    }

    private function resolver(
        ?callable $configuration = null,
        bool $metronSharedAllowedByEnvironment = false,
        bool $comicVineSharedAllowedByEnvironment = false,
        ?UserMetadataCredential $personal = null,
        ?ProviderCircuitBreaker $breaker = null
    ): MetadataAccessResolver {
        $settings = new MetadataProviderConfiguration();
        if ($configuration !== null) {
            $configuration($settings);
        }

        return new MetadataAccessResolver(
            $this->shared($settings),
            $this->personal($personal),
            $breaker ?? new ProviderCircuitBreaker($this->cache(new ArrayAdapter())),
            $metronSharedAllowedByEnvironment,
            $comicVineSharedAllowedByEnvironment,
        );
    }

    private function shared(MetadataProviderConfiguration $settings): SharedProviderCredentials
    {
        return new class($settings) implements SharedProviderCredentials {
            public function __construct(private readonly MetadataProviderConfiguration $settings)
            {
            }

            public function metronToken(): ?string { return $this->settings->getMetronToken(); }
            public function isMetronSharedEnabled(): bool { return $this->settings->isMetronSharedEnabled(); }
            public function comicVineApiKey(): ?string { return $this->settings->getComicVineApiKey(); }
            public function isComicVineEnabled(): bool { return $this->settings->isComicVineEnabled(); }
        };
    }

    private function personal(?UserMetadataCredential $credential): PersonalProviderCredentials
    {
        return new class($credential) implements PersonalProviderCredentials {
            public function __construct(private readonly ?UserMetadataCredential $credential)
            {
            }

            public function metronToken(User $user): ?string { return $this->credential?->getMetronToken(); }
            public function comicVineApiKey(User $user): ?string { return $this->credential?->getComicVineApiKey(); }
        };
    }

    private function user(bool $metadataApiEnabled = true): User
    {
        $user = new User();
        $user->setEmail('reader@example.test');
        $user->setMetadataApiEnabled($metadataApiEnabled);

        return $user;
    }

    private function cache(ArrayAdapter $adapter): CacheInterface
    {
        return new class($adapter) implements CacheInterface {
            public function __construct(private ArrayAdapter $adapter)
            {
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return $this->adapter->get($key, $callback, $beta, $metadata);
            }

            public function delete(string $key): bool
            {
                return $this->adapter->delete($key);
            }
        };
    }
}
