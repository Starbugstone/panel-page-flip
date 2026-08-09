<?php

namespace App\ComicSource;

final class ComicSourceInfo
{
    public function __construct(public readonly int $pageCount)
    {
        if ($pageCount < 1 || $pageCount > 10000) {
            throw new \RuntimeException('Comic source has an invalid page count.');
        }
    }
}
