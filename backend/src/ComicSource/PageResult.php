<?php

namespace App\ComicSource;

final class PageResult
{
    public function __construct(public readonly string $content, public readonly string $mimeType)
    {
        $size = strlen($content);
        if ($size < 1 || $size > ComicSourceLimits::MAX_PAGE_BYTES) {
            throw new \RuntimeException('Comic page exceeds the allowed size.');
        }

        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new \RuntimeException('Comic page has an unsupported image type.');
        }
    }

    public static function fromImageContent(string $content): self
    {
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!is_string($mimeType)) {
            throw new \RuntimeException('Comic page image type could not be detected.');
        }

        return new self($content, $mimeType);
    }
}
