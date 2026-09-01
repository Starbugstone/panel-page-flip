<?php

namespace App\ComicSource\Pdf;

/**
 * Wraps raw image samples in a PNG container.
 *
 * A PDF image stream that is not already a JPEG is, once inflated, exactly the
 * pixel rows a PNG carries — same order, same packing. Encoding it is therefore
 * a header, a filter byte per row and one deflate, which is why this exists
 * instead of a GD round trip: building a full-page image pixel by pixel through
 * `imagesetpixel` is millions of calls per page, where this is a memcpy and a
 * compress. GD is still the right tool for converting between formats; it is
 * the wrong one for repacking bytes that already have the layout we want.
 */
final class PngWriter
{
    public const COLOR_GRAY = 0;
    public const COLOR_RGB = 2;
    public const COLOR_PALETTE = 3;

    /**
     * @param string      $samples raw rows, packed to a byte boundary per row
     * @param string|null $palette RGB triples, required for COLOR_PALETTE
     */
    public static function encode(
        int $width,
        int $height,
        int $bitDepth,
        int $colorType,
        string $samples,
        ?string $palette = null,
    ): string {
        $rowBytes = self::rowBytes($width, $bitDepth, $colorType);
        if ($rowBytes < 1 || $height < 1) throw new PdfException('Image has no rows.');

        // Each PNG scanline is prefixed with its filter type; 0 means "stored
        // as-is", which is what PDF samples already are.
        $filtered = '';
        for ($row = 0; $row < $height; ++$row) {
            $offset = $row * $rowBytes;
            if ($offset >= strlen($samples)) throw new PdfException('Image data is shorter than its dimensions.');
            $filtered .= "\0".str_pad(substr($samples, $offset, $rowBytes), $rowBytes, "\0");
        }

        $header = pack('NNCCCCC', $width, $height, $bitDepth, $colorType, 0, 0, 0);

        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::chunk('IHDR', $header);
        if ($colorType === self::COLOR_PALETTE) {
            if ($palette === null || $palette === '') throw new PdfException('Palette image has no palette.');
            $png .= self::chunk('PLTE', $palette);
        }
        $compressed = gzcompress($filtered, 6);
        if ($compressed === false) throw new PdfException('Image data could not be compressed.');
        $png .= self::chunk('IDAT', $compressed);
        $png .= self::chunk('IEND', '');

        return $png;
    }

    public static function rowBytes(int $width, int $bitDepth, int $colorType): int
    {
        $components = match ($colorType) {
            self::COLOR_RGB => 3,
            default => 1,
        };

        return (int) ceil($width * $components * $bitDepth / 8);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
