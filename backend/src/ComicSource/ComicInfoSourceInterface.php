<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;

/**
 * Reads a comic's embedded ComicInfo.xml, for the source formats that can carry
 * one. PDF cannot, so it does not implement this.
 */
interface ComicInfoSourceInterface
{
    public function supports(ComicSourceType $type): bool;

    /** Raw XML, or null when the source carries no ComicInfo.xml. */
    public function readComicInfoXml(string $sourcePath, ComicSourceType $type): ?string;
}
