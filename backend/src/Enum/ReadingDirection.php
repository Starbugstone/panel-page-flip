<?php

declare(strict_types=1);

namespace App\Enum;

enum ReadingDirection: string
{
    case LeftToRight = 'ltr';
    case RightToLeft = 'rtl';

    /** ComicInfo states direction through its Manga field. */
    public static function fromManga(?string $manga): self
    {
        return strtolower(trim((string) $manga)) === 'yesandrighttoleft'
            ? self::RightToLeft
            : self::LeftToRight;
    }
}
