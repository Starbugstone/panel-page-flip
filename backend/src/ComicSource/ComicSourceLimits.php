<?php

namespace App\ComicSource;

enum ComicSourceLimits
{
    public const MAX_ENTRIES = 10_000;
    public const MAX_UNCOMPRESSED_BYTES = 2_147_483_648;
    public const MAX_PAGE_BYTES = 67_108_864;
    public const MAX_METADATA_BYTES = 2_097_152;
}
