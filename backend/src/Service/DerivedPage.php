<?php

namespace App\Service;

use App\ComicSource\PageResult;
use App\Enum\PageVariant;

/**
 * A page as it leaves the derivative pipeline: the bytes, the variant they
 * satisfy, the format they are actually in, and the source page's own geometry
 * where it is known.
 *
 * The format is not always the one the variant asked for — a server that cannot
 * encode WebP serves what the provider produced — and the caller has to vary
 * its cache validators on what happened rather than on what was requested.
 */
final class DerivedPage
{
    public function __construct(
        public readonly PageResult $page,
        public readonly PageVariant $variant,
        public readonly string $format,
        public readonly ?PageGeometry $geometry = null,
    ) {
    }
}
