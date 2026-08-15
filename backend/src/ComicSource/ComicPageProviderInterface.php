<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;

interface ComicPageProviderInterface
{
    public function supports(ComicSourceType $type): bool;
    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo;

    /**
     * @param int|null $targetWidth roughly how wide the caller intends to serve
     *                              this page. A hint, never a promise: a
     *                              provider that hands back stored bytes ignores
     *                              it, and one that has to draw the page uses it
     *                              to avoid rasterising far more detail than
     *                              anything downstream will keep.
     */
    public function readPage(string $sourcePath, ComicSourceType $type, int $page, ?int $targetWidth = null): PageResult;
}
