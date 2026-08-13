<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;

final class ComicInfoSourceFactory
{
    /** @param iterable<ComicInfoSourceInterface> $sources */
    public function __construct(private readonly iterable $sources)
    {
    }

    public function for(ComicSourceType $type): ?ComicInfoSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->supports($type)) {
                return $source;
            }
        }

        return null;
    }
}
