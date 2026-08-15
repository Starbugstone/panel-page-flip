<?php

namespace App\Tests\Unit\Service;

use App\Entity\MetadataProviderConfiguration;
use App\Entity\User;
use App\Enum\ProviderStatus;
use App\Metadata\Provider\MetadataProviderInterface;
use App\Metadata\Provider\PersonalProviderCredentials;
use App\Metadata\Provider\ProviderAccess;
use App\Metadata\Provider\ProviderCandidate;
use App\Metadata\Provider\ProviderQuery;
use App\Metadata\Provider\ProviderSearchResult;
use App\Metadata\Provider\ProviderVerification;
use App\Metadata\Provider\SharedProviderCredentials;
use App\Service\CandidateRanker;
use App\Service\MetadataAccessResolver;
use App\Service\MetadataProviderRegistry;
use App\Service\ProviderCircuitBreaker;
use App\Service\ProviderQuotaTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * One provider per lookup, chosen deliberately.
 *
 * The failure this replaces is quiet: asking every provider spends two accounts
 * on one click, and cascading after a failure spends somebody else's allowance
 * to paper over the first one's outage.
 */
final class MetadataProviderRegistryTest extends TestCase
{
    public function testAsksOnlyOneProvider(): void
    {
        $metron = $this->provider('metron');
        $comicVine = $this->provider('comicvine');

        $this->registry([$metron, $comicVine], sharedMetron: true, sharedComicVine: true)
            ->search(new ProviderQuery('Batman'), $this->user());

        self::assertSame(1, $metron->searches);
        self::assertSame(0, $comicVine->searches);
    }

    /** A failure is reported, never smoothed over by charging another account. */
    public function testDoesNotCascadeToASecondProviderAfterAFailure(): void
    {
        $metron = $this->provider('metron', ProviderSearchResult::unavailable('metron', ProviderStatus::Unreachable, 'down'));
        $comicVine = $this->provider('comicvine');

        $lookup = $this->registry([$metron, $comicVine], sharedMetron: true, sharedComicVine: true)
            ->search(new ProviderQuery('Batman'), $this->user());

        self::assertSame(0, $comicVine->searches);
        self::assertSame('metron', $lookup->searched);
        self::assertSame([], $lookup->candidates);
    }

    /** Spending your own allowance costs the installation nothing. */
    public function testPrefersTheProviderTheUserHasTheirOwnCredentialFor(): void
    {
        $metron = $this->provider('metron');
        $comicVine = $this->provider('comicvine');

        $lookup = $this->registry(
            [$metron, $comicVine],
            sharedMetron: true,
            sharedComicVine: true,
            personalComicVineKey: 'personal-key'
        )->search(new ProviderQuery('Batman'), $this->user());

        self::assertSame('comicvine', $lookup->searched);
        self::assertSame(0, $metron->searches);
    }

    public function testAnExplicitChoiceOverridesThePreference(): void
    {
        $metron = $this->provider('metron');
        $comicVine = $this->provider('comicvine');

        $lookup = $this->registry([$metron, $comicVine], sharedMetron: true, sharedComicVine: true)
            ->search(new ProviderQuery('Batman'), $this->user(), 'comicvine');

        self::assertSame('comicvine', $lookup->searched);
        self::assertSame(1, $comicVine->searches);
    }

    /**
     * "Nothing matched" and "never asked" have to stay distinguishable
     * internally, which is the whole reason the result carries a status.
     *
     * Asserted on the object rather than its JSON: the serialised form is
     * deliberately reduced, because this detail is operator diagnostics and
     * PublicProviderStatus is what a user is shown.
     */
    public function testReportsWhyTheOtherProvidersWereNotAsked(): void
    {
        $lookup = $this->registry(
            [$this->provider('metron'), $this->provider('comicvine')],
            sharedMetron: true,
            sharedComicVine: false
        )->search(new ProviderQuery('Batman'), $this->user());

        $byKey = array_column($lookup->providers, null, 'provider');

        self::assertSame('metron', $lookup->searched);
        self::assertSame(ProviderStatus::Disabled, $byKey['comicvine']->status);
    }

    /** The serialised form is the safe minimum, whatever the internals hold. */
    public function testASerialisedResultCarriesNoOperatorDetail(): void
    {
        $lookup = $this->registry(
            [$this->provider('metron'), $this->provider('comicvine')],
            sharedMetron: true,
            sharedComicVine: false
        )->search(new ProviderQuery('Batman'), $this->user());

        foreach ($lookup->providers as $result) {
            self::assertSame(['provider', 'available'], array_keys($result->jsonSerialize()));
        }
    }

    public function testNothingIsAskedWhenNoProviderIsAvailable(): void
    {
        $metron = $this->provider('metron');

        $lookup = $this->registry([$metron], sharedMetron: false)->search(new ProviderQuery('Batman'), $this->user());

        self::assertSame(0, $metron->searches);
        self::assertNull($lookup->searched);
        self::assertSame([], $lookup->candidates);
    }

    /** The order the person choosing sees is the ranked one, not the API's. */
    public function testCandidatesComeBackRanked(): void
    {
        $result = ProviderSearchResult::found('metron', [
            new ProviderCandidate(provider: 'metron', externalId: '1', series: 'Herogasm', issueNumber: '1'),
            new ProviderCandidate(provider: 'metron', externalId: '2', series: 'The Boys', issueNumber: '7'),
        ]);

        $lookup = $this->registry([$this->provider('metron', $result)], sharedMetron: true)
            ->search(new ProviderQuery('The Boys', '7'), $this->user());

        self::assertSame('2', $lookup->candidates[0]->externalId);
        self::assertSame('exact', $lookup->candidates[0]->confidence->value);
    }

    /**
     * @param list<MetadataProviderInterface> $providers
     */
    private function registry(
        array $providers,
        bool $sharedMetron = false,
        bool $sharedComicVine = false,
        ?string $personalComicVineKey = null
    ): MetadataProviderRegistry {
        $settings = (new MetadataProviderConfiguration())
            ->setMetronToken('shared-metron-token')
            ->setMetronSharedEnabled($sharedMetron)
            ->setComicVineApiKey('shared-comicvine-key')
            ->setComicVineEnabled($sharedComicVine);

        $cache = $this->cache();

        $resolver = new MetadataAccessResolver(
            $this->shared($settings),
            $this->personal($personalComicVineKey),
            new ProviderCircuitBreaker($cache),
            $sharedMetron,
            $sharedComicVine || $personalComicVineKey !== null,
        );

        return new MetadataProviderRegistry(
            $providers,
            $resolver,
            new CandidateRanker(),
            new ProviderQuotaTracker($cache)
        );
    }

    private function provider(string $key, ?ProviderSearchResult $result = null): MetadataProviderInterface
    {
        return new class($key, $result) implements MetadataProviderInterface {
            public int $searches = 0;

            public function __construct(private readonly string $key, private readonly ?ProviderSearchResult $result)
            {
            }

            public function key(): string { return $this->key; }
            public function label(): string { return ucfirst($this->key); }

            public function search(ProviderQuery $query, ProviderAccess $access): ProviderSearchResult
            {
                ++$this->searches;

                return $this->result ?? ProviderSearchResult::found($this->key, [], $access->origin);
            }

            public function detail(string $externalId, ProviderAccess $access): ProviderSearchResult
            {
                return ProviderSearchResult::found($this->key, [], $access->origin);
            }

            public function verify(?string $secret): ProviderVerification
            {
                return ProviderVerification::ok();
            }
        };
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
            public function arePersonalCredentialsEnabled(): bool { return $this->settings->arePersonalCredentialsEnabled(); }
        };
    }

    private function personal(?string $comicVineKey): PersonalProviderCredentials
    {
        return new class($comicVineKey) implements PersonalProviderCredentials {
            public function __construct(private readonly ?string $comicVineKey)
            {
            }

            public function metronToken(User $user): ?string { return null; }
            public function comicVineApiKey(User $user): ?string { return $this->comicVineKey; }
        };
    }

    private function user(): User
    {
        return (new User())->setEmail('reader@example.test');
    }

    private function cache(): CacheInterface
    {
        return new class(new ArrayAdapter()) implements CacheInterface {
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
