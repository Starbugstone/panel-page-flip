<?php

declare(strict_types=1);

namespace App\Enum;

/** Where a piece of metadata came from. */
enum MetadataSource: string
{
    case User = 'user';
    case ComicInfo = 'comicinfo';
    case Provider = 'provider';
    case Filename = 'filename';
}
