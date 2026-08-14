<?php

declare(strict_types=1);

namespace App\Metadata\Provider;

interface MetadataProviderInterface
{
    public function key(): string;

    public function label(): string;

    /** Whether an administrator has given this provider credentials. */
    public function isConfigured(): bool;

    /**
     * Candidates for a comic. Returns an empty list rather than throwing: a
     * provider being down, unconfigured or rate-limited is not an error the
     * user did anything to cause.
     *
     * @return list<ProviderCandidate>
     */
    public function search(ProviderQuery $query): array;
}
