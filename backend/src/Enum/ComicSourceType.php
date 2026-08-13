<?php

namespace App\Enum;

enum ComicSourceType: string
{
    case CBZ = 'cbz';
    case CBR = 'cbr';
    case CB7 = 'cb7';
    case CBT = 'cbt';
    case PDF = 'pdf';

    public static function fromFilename(string $filename): self
    {
        return self::tryFrom(strtolower(pathinfo($filename, PATHINFO_EXTENSION)))
            ?? throw new \RuntimeException('Unsupported comic source format.');
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::CBZ => 'application/vnd.comicbook+zip',
            self::CBR => 'application/vnd.comicbook-rar',
            self::CB7 => 'application/x-7z-compressed',
            self::CBT => 'application/x-tar',
            self::PDF => 'application/pdf',
        };
    }
}
