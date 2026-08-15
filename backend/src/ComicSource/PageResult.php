<?php

namespace App\ComicSource;

final class PageResult
{
    /**
     * @param bool $isSourceSized whether these pixels are the page's own. False
     *                            for anything a provider drew, whose dimensions
     *                            describe the render rather than the page, and
     *                            must therefore not be recorded as its geometry.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly bool $isSourceSized = true,
    ) {
        $size = strlen($content);
        if ($size < 1 || $size > ComicSourceLimits::MAX_PAGE_BYTES) {
            throw new \RuntimeException('Comic page exceeds the allowed size.');
        }

        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new \RuntimeException('Comic page has an unsupported image type.');
        }
    }

    public static function fromImageContent(string $content, bool $isSourceSized = true): self
    {
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!is_string($mimeType)) {
            throw new \RuntimeException('Comic page image type could not be detected.');
        }

        return new self($content, $mimeType, $isSourceSized);
    }
}
