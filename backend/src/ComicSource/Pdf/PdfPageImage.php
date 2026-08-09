<?php

namespace App\ComicSource\Pdf;

/**
 * A page's own embedded image, ready to serve as-is.
 */
final class PdfPageImage
{
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
