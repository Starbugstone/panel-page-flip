<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

/**
 * The whole answer to one lookup: the candidates, and what every provider had
 * to say about being asked — including the ones that were deliberately not.
 *
 * A user who sees no results needs to know which of "nothing matched", "you
 * have no token", "an administrator turned this off" and "we are waiting out a
 * rate limit" they are looking at, and only the second and third are things
 * they can do something about.
 */
final class ProviderLookup implements \JsonSerializable
{
    /**
     * @param list<ProviderCandidate>    $candidates ranked, best first
     * @param list<ProviderSearchResult> $providers  one entry per known provider
     */
    public function __construct(
        public readonly array $candidates,
        public readonly array $providers,
        /** The provider that was actually asked, if any was. */
        public readonly ?string $searched = null,
    ) {
    }

    public static function nothingToAsk(array $providers): self
    {
        return new self([], $providers);
    }

    /**
     * A backstop like ProviderSearchResult's: `searched` names which account
     * the installation reached for, which is operator information. Controllers
     * build the user-facing payload explicitly.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['candidates' => $this->candidates];
    }
}
