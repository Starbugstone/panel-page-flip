<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

interface MetadataProviderInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * Candidates for a comic.
     *
     * Returns a result rather than throwing: a provider being down or throttled
     * is not an error the user did anything to cause. The result says which of
     * those happened, so "found nothing" and "never asked" stay distinguishable.
     */
    public function search(ProviderQuery $query, ProviderAccess $access): ProviderSearchResult;

    /**
     * One exact record, by the id a previously accepted candidate carried.
     *
     * This is what makes a refresh cheap and honest: the comic remembers which
     * record it was matched to, so refreshing asks for that record again rather
     * than re-running a fuzzy search and hoping the same one comes back first.
     */
    public function detail(string $externalId, ProviderAccess $access): ProviderSearchResult;

    /**
     * Try a secret against the live service and say what happened.
     *
     * Takes the secret as an argument rather than reading configuration, so an
     * administrator can test what they have just typed before saving it, and so
     * a user can test their own token without it ever becoming installation
     * configuration. Never cached — a cached answer to "does this key work" is
     * worthless, since the question is only asked when something changed.
     */
    public function verify(?string $secret): ProviderVerification;
}
