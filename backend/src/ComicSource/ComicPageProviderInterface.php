<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;

interface ComicPageProviderInterface
{
    public function supports(ComicSourceType $type): bool;
    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo;
    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult;
}
