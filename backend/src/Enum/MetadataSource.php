<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a piece of metadata came from, in order of how much it can be trusted.
 *
 * The user's own answer outranks everything: they were looking at the comic.
 * The file's own statement outranks a guess made from its name.
 */
enum MetadataSource: string
{
    case User = 'user';
    case ComicInfo = 'comicinfo';
    case Provider = 'provider';
    case Filename = 'filename';

    public function rank(): int
    {
        return match ($this) {
            self::User => 3,
            self::ComicInfo => 2,
            self::Provider => 1,
            self::Filename => 0,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }
}
