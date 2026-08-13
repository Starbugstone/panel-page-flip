<?php

namespace App\ComicSource\Pdf;

/**
 * A stream object: its dictionary, and its bytes exactly as they sit in the
 * file. Decoding is deliberately not done here — an image whose filter is
 * DCTDecode is already a JPEG, and the whole point of native extraction is to
 * hand those bytes straight to the reader without touching them.
 *
 * @phpstan-type PdfDict array<string, mixed>
 */
final class PdfStream
{
    /** @param array<string, mixed> $dictionary */
    public function __construct(
        public readonly array $dictionary,
        public readonly string $raw,
    ) {
    }
}
