<?php

namespace App\ComicSource;

final class ComicSourceLimits
{
    public const MAX_ENTRIES = 10_000;
    public const MAX_UNCOMPRESSED_BYTES = 2_147_483_648;
    public const MAX_PAGE_BYTES = 67_108_864;

    private function __construct()
    {
    }
}
