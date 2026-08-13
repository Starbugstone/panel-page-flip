<?php

declare(strict_types=1);

namespace App\Enum;

enum ComicPageType: string
{
    case FrontCover = 'FrontCover';
    case InnerCover = 'InnerCover';
    case Roundup = 'Roundup';
    case Story = 'Story';
    case Advertisement = 'Advertisement';
    case Editorial = 'Editorial';
    case Letters = 'Letters';
    case Preview = 'Preview';
    case BackCover = 'BackCover';
    case Other = 'Other';
    case Deleted = 'Deleted';

    public static function tryFromName(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, trim($value)) === 0) {
                return $case;
            }
        }

        return null;
    }
}
