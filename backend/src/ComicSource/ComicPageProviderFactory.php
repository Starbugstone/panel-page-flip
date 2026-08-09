<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;

final class ComicPageProviderFactory
{
    /** @param iterable<ComicPageProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    public function for(ComicSourceType $type): ComicPageProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($type)) {
                return $provider;
            }
        }

        throw new \RuntimeException(sprintf('No page provider is configured for %s.', $type->value));
    }
}
