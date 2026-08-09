<?php

namespace App\ComicSource\Pdf;

/**
 * Enough of a PDF to serve a comic out of one, in pure PHP.
 *
 * Comic PDFs are, overwhelmingly, a container holding one full-page image per
 * page — the same thing a CBZ is, with a different wrapper. When that is what
 * a document turns out to be, its pages can be served by handing the embedded
 * image straight to the reader: no rasterising, no subprocess, no loss of
 * quality, and it works on hosting that forbids running external programs.
 *
 * What this is not is a renderer. A page built from vector art or text has no
 * embedded image to hand over, and this class says so rather than guessing;
 * the provider then falls back to Poppler where that is available.
 */
final class PdfDocument
{
    /** Bounds on a hostile file: neither is anywhere near a real comic. */
    private const MAX_OBJECTS = 500_000;
    private const MAX_PAGES = 20_000;
    private const MAX_TREE_DEPTH = 64;

    /** Byte offset of each object, or the object stream holding it. */
    /** @var array<int, int> */
    private array $offsets = [];
    /** @var array<int, array{0: int, 1: int}> object number => [container stream number, index] */
    private array $compressed = [];
    /** @var array<int, mixed> */
    private array $resolved = [];
    /** @var array<string, mixed> */
    private array $trailer = [];
    /** @var list<array<string, mixed>>|null */
    private ?array $pages = null;

    private function __construct(private readonly string $buffer)
    {
    }

    public static function open(string $path): self
    {
        $buffer = @file_get_contents($path);
        if ($buffer === false || !str_starts_with($buffer, '%PDF-')) throw new PdfException('Not a PDF.');

        $document = new self($buffer);
        $document->loadCrossReferences();

        if (isset($document->trailer['Encrypt'])) throw new PdfException('Encrypted PDFs are not supported.');

        return $document;
    }

    public function pageCount(): int
    {
        return count($this->pages());
    }

    /**
     * The single full-page image behind logical page $page, or null when the
     * page is not built that way and only a renderer could produce it.
     */
    public function pageImage(int $page): ?PdfPageImage
    {
        $pages = $this->pages();
        if ($page < 1 || !isset($pages[$page - 1])) throw new PdfException('Page not found.');

        $resources = $this->resolve($pages[$page - 1]['Resources'] ?? null);
        if (!is_array($resources)) return null;

        $xObjects = $this->resolve($resources['XObject'] ?? null);
        if (!is_array($xObjects)) return null;

        $images = [];
        foreach ($xObjects as $candidate) {
            $stream = $this->resolve($candidate);
            if (!$stream instanceof PdfStream) continue;
            if (($stream->dictionary['Subtype'] ?? null) !== '/Image') continue;
            $images[] = $stream;
        }

        // Exactly one image is the shape we can serve faithfully. Zero means a
        // rendered page; more than one means the page is a composition, and
        // picking one of them would silently show the reader part of a page.
        if (count($images) !== 1) return null;

        return $this->imageFrom($images[0]);
    }

    private function imageFrom(PdfStream $stream): ?PdfPageImage
    {
        $filter = $this->resolve($stream->dictionary['Filter'] ?? null);
        $filters = is_array($filter) ? $filter : ($filter === null ? [] : [$filter]);
        $filters = array_map(static fn ($value): string => is_string($value) ? $value : '', $filters);

        $width = (int) $this->resolve($stream->dictionary['Width'] ?? 0);
        $height = (int) $this->resolve($stream->dictionary['Height'] ?? 0);
        if ($width < 1 || $height < 1) return null;

        // The case worth having: the stream is already a JPEG, so the reader
        // gets the author's own bytes at the author's own quality.
        if ($filters === ['/DCTDecode']) {
            $info = @getimagesizefromstring($stream->raw);
            if (!is_array($info) || ($info['mime'] ?? '') !== 'image/jpeg') return null;
            return new PdfPageImage($stream->raw, 'image/jpeg', $width, $height);
        }

        // Some producers wrap the JPEG in Flate as well; unwrap and re-check.
        if ($filters === ['/FlateDecode', '/DCTDecode']) {
            $inflated = @gzuncompress($stream->raw);
            if (!is_string($inflated)) return null;
            $info = @getimagesizefromstring($inflated);
            if (!is_array($info) || ($info['mime'] ?? '') !== 'image/jpeg') return null;
            return new PdfPageImage($inflated, 'image/jpeg', $width, $height);
        }

        // A JPXDecode page is JPEG 2000, which browsers do not display and GD
        // cannot convert. Poppler handles it; say we cannot.
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function pages(): array
    {
        if ($this->pages !== null) return $this->pages;

        $root = $this->resolve($this->trailer['Root'] ?? null);
        if (!is_array($root)) throw new PdfException('PDF has no document catalogue.');

        $tree = $this->resolve($root['Pages'] ?? null);
        if (!is_array($tree)) throw new PdfException('PDF has no page tree.');

        $pages = [];
        $this->collectPages($tree, $pages, [], 0);
        if ($pages === []) throw new PdfException('PDF has no pages.');

        return $this->pages = $pages;
    }

    /**
     * Walk the page tree in order, carrying down the attributes a page is
     * allowed to inherit from its ancestors.
     *
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $pages
     * @param array<string, mixed> $inherited
     */
    private function collectPages(array $node, array &$pages, array $inherited, int $depth, ?array $seen = null): void
    {
        if ($depth > self::MAX_TREE_DEPTH) throw new PdfException('PDF page tree is too deep.');
        if (count($pages) > self::MAX_PAGES) throw new PdfException('PDF has too many pages.');

        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $key) {
            if (isset($node[$key])) $inherited[$key] = $node[$key];
        }

        $type = $node['Type'] ?? null;
        $kids = $this->resolve($node['Kids'] ?? null);

        if ($type === '/Page' || (!is_array($kids) && $type !== '/Pages')) {
            $pages[] = $node + $inherited;
            return;
        }

        if (!is_array($kids)) throw new PdfException('PDF page tree node has no children.');

        foreach ($kids as $kid) {
            // A tree that points back at itself would otherwise loop forever.
            $key = $kid instanceof PdfReference ? $kid->key() : null;
            if ($key !== null) {
                $seen ??= [];
                if (isset($seen[$key])) throw new PdfException('PDF page tree contains a cycle.');
                $seen[$key] = true;
            }

            $child = $this->resolve($kid);
            if (is_array($child)) $this->collectPages($child, $pages, $inherited, $depth + 1, $seen);
        }
    }

    public function resolve(mixed $value): mixed
    {
        $hops = 0;
        while ($value instanceof PdfReference) {
            if (++$hops > 32) throw new PdfException('PDF reference chain is too long.');
            $value = $this->object($value->number, $value->generation);
        }

        return $value;
    }

    private function object(int $number, int $generation): mixed
    {
        $key = $number;
        if (array_key_exists($key, $this->resolved)) return $this->resolved[$key];

        // Marked before parsing so a self-referential object cannot recurse.
        $this->resolved[$key] = null;

        if (isset($this->offsets[$number])) {
            // An offset past the end of the file is a broken table, not a
            // reason to fail: the caller decides whether to rebuild.
            if ($this->offsets[$number] >= strlen($this->buffer)) return null;

            $parser = new PdfParser($this->buffer);
            $parser->seek($this->offsets[$number]);
            return $this->resolved[$key] = $parser->parseIndirectObject();
        }

        if (isset($this->compressed[$number])) {
            [$container, $index] = $this->compressed[$number];
            return $this->resolved[$key] = $this->objectFromStream($container, $index);
        }

        return null;
    }

    /**
     * Pull one object out of an object stream, where PDF 1.5+ writers put most
     * of a document's small objects, Flate-compressed together.
     */
    private function objectFromStream(int $container, int $index): mixed
    {
        $stream = $this->resolve(new PdfReference($container, 0));
        if (!$stream instanceof PdfStream) return null;

        $data = $this->inflate($stream);
        if ($data === null) return null;

        $count = (int) $this->resolve($stream->dictionary['N'] ?? 0);
        $first = (int) $this->resolve($stream->dictionary['First'] ?? 0);
        if ($index >= $count) return null;

        // The header is N pairs of "object number, offset from /First".
        $header = substr($data, 0, $first);
        if (preg_match_all('/(\d+)\s+(\d+)/', $header, $pairs, PREG_SET_ORDER) === false) return null;
        if (!isset($pairs[$index])) return null;

        $parser = new PdfParser($data);
        $parser->seek($first + (int) $pairs[$index][2]);

        return $parser->parseValue();
    }

    private function inflate(PdfStream $stream): ?string
    {
        $filter = $this->resolve($stream->dictionary['Filter'] ?? null);
        $filters = is_array($filter) ? $filter : ($filter === null ? [] : [$filter]);
        if ($filters === []) return $stream->raw;
        if ($filters !== ['/FlateDecode']) return null;

        $data = @gzuncompress($stream->raw);
        if (!is_string($data)) $data = @gzinflate($stream->raw);
        if (!is_string($data)) return null;

        $parms = $this->resolve($stream->dictionary['DecodeParms'] ?? null);
        if (is_array($parms) && isset($parms['Predictor']) && (int) $this->resolve($parms['Predictor']) > 1) {
            $data = $this->undoPngPredictor(
                $data,
                (int) $this->resolve($parms['Columns'] ?? 1),
                (int) $this->resolve($parms['Colors'] ?? 1),
                (int) $this->resolve($parms['BitsPerComponent'] ?? 8),
            );
        }

        return $data;
    }

    /**
     * Cross-reference streams are usually PNG-predicted, so the rows have to be
     * un-filtered before the table can be read.
     */
    private function undoPngPredictor(string $data, int $columns, int $colors, int $bits): string
    {
        $sample = max(1, (int) ceil($colors * $bits / 8));
        $rowLength = (int) ceil($columns * $colors * $bits / 8);
        if ($rowLength < 1) return $data;

        $previous = str_repeat("\0", $rowLength);
        $output = '';

        for ($offset = 0; $offset + 1 <= strlen($data); $offset += $rowLength + 1) {
            $tag = ord($data[$offset]);
            $row = substr($data, $offset + 1, $rowLength);
            if ($row === '') break;
            $row = str_pad($row, $rowLength, "\0");

            $decoded = '';
            for ($i = 0; $i < $rowLength; ++$i) {
                $raw = ord($row[$i]);
                $left = $i >= $sample ? ord($decoded[$i - $sample]) : 0;
                $up = ord($previous[$i]);
                $upLeft = $i >= $sample ? ord($previous[$i - $sample]) : 0;

                $value = match ($tag) {
                    0 => $raw,
                    1 => $raw + $left,
                    2 => $raw + $up,
                    3 => $raw + intdiv($left + $up, 2),
                    4 => $raw + self::paeth($left, $up, $upLeft),
                    default => $raw,
                };
                $decoded .= chr($value & 0xFF);
            }

            $output .= $decoded;
            $previous = $decoded;
        }

        return $output;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) return $a;
        return $pb <= $pc ? $b : $c;
    }

    /**
     * Build the object index by following the startxref chain, handling both
     * classic tables and the cross-reference streams that replaced them.
     */
    private function loadCrossReferences(): void
    {
        $tail = substr($this->buffer, -2048);
        if (preg_match_all('/startxref\s+(\d+)/', $tail, $matches) !== 1 && $matches[1] === []) {
            $this->rebuildByScanning();
            return;
        }

        $offset = (int) end($matches[1]);
        $seen = [];

        try {
            while ($offset > 0 && $offset < strlen($this->buffer) && !isset($seen[$offset])) {
                $seen[$offset] = true;
                $offset = $this->loadCrossReferenceSection($offset);
            }
        } catch (PdfException) {
            // A damaged table is common in the wild and recoverable: every
            // object header is still in the file.
            $this->rebuildByScanning();
            return;
        }

        if ($this->trailer === [] || ($this->offsets === [] && $this->compressed === [])) {
            $this->rebuildByScanning();
            return;
        }

        // A table can be perfectly well-formed and still point at the wrong
        // bytes, which is what an incremental update written by a careless
        // producer leaves behind. The catalogue is the cheapest thing to try,
        // and if it does not come back the offsets are not worth trusting.
        if (!$this->catalogueResolves()) $this->rebuildByScanning();
    }

    private function catalogueResolves(): bool
    {
        try {
            $root = $this->resolve($this->trailer['Root'] ?? null);

            return is_array($root) && isset($root['Pages']) && is_array($this->resolve($root['Pages']));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return int offset of the previous section, or 0 when there is none */
    private function loadCrossReferenceSection(int $offset): int
    {
        $parser = new PdfParser($this->buffer);
        $parser->seek($offset);

        if (preg_match('/\G\s*xref\b/', $this->buffer, $match, 0, $offset) === 1) {
            return $this->loadClassicTable($offset + strlen($match[0]));
        }

        $object = $parser->parseIndirectObject();
        if (!$object instanceof PdfStream) throw new PdfException('Unrecognised cross-reference section.');

        return $this->loadCrossReferenceStream($object);
    }

    private function loadClassicTable(int $offset): int
    {
        $position = $offset;

        while (preg_match('/\G\s*(\d+)\s+(\d+)\s*/', $this->buffer, $header, 0, $position) === 1) {
            $start = (int) $header[1];
            $count = (int) $header[2];
            $position += strlen($header[0]);

            if ($count < 0 || $count > self::MAX_OBJECTS) throw new PdfException('Cross-reference table is implausible.');

            for ($i = 0; $i < $count; ++$i) {
                if (preg_match('/\G(\d{10})\s(\d{5})\s([nf])\s{0,2}/', $this->buffer, $entry, 0, $position) !== 1) {
                    throw new PdfException('Malformed cross-reference entry.');
                }
                $position += strlen($entry[0]);

                $number = $start + $i;
                // Earlier sections win: the newest table is read first.
                if ($entry[3] === 'n' && !isset($this->offsets[$number]) && !isset($this->compressed[$number])) {
                    $this->offsets[$number] = (int) $entry[1];
                }
            }
        }

        $trailerAt = strpos($this->buffer, 'trailer', $position);
        if ($trailerAt === false) throw new PdfException('Cross-reference table has no trailer.');

        $parser = new PdfParser($this->buffer);
        $parser->seek($trailerAt + 7);
        $trailer = $parser->parseValue();
        if (!is_array($trailer)) throw new PdfException('Malformed PDF trailer.');

        $this->trailer += $trailer;

        // A hybrid file keeps a stream alongside the table for newer readers.
        if (isset($trailer['XRefStm'])) {
            try {
                $this->loadCrossReferenceSection((int) $trailer['XRefStm']);
            } catch (PdfException) {
                // The classic table we just read is sufficient on its own.
            }
        }

        return isset($trailer['Prev']) ? (int) $trailer['Prev'] : 0;
    }

    private function loadCrossReferenceStream(PdfStream $stream): int
    {
        $data = $this->inflate($stream);
        if ($data === null) throw new PdfException('Unreadable cross-reference stream.');

        $widths = $this->resolve($stream->dictionary['W'] ?? null);
        if (!is_array($widths) || count($widths) < 3) throw new PdfException('Cross-reference stream has no widths.');
        $widths = array_map(static fn ($value): int => (int) $value, $widths);

        $size = (int) $this->resolve($stream->dictionary['Size'] ?? 0);
        $index = $this->resolve($stream->dictionary['Index'] ?? null);
        $ranges = is_array($index) && $index !== [] ? array_map(static fn ($v): int => (int) $v, $index) : [0, $size];

        $rowLength = array_sum($widths);
        if ($rowLength < 1) throw new PdfException('Cross-reference stream rows are empty.');

        $position = 0;
        for ($pair = 0; $pair + 1 < count($ranges); $pair += 2) {
            $start = $ranges[$pair];
            $count = $ranges[$pair + 1];
            if ($count < 0 || $count > self::MAX_OBJECTS) throw new PdfException('Cross-reference stream is implausible.');

            for ($i = 0; $i < $count; ++$i) {
                if ($position + $rowLength > strlen($data)) break 2;

                $fields = [];
                foreach ($widths as $width) {
                    $value = 0;
                    for ($b = 0; $b < $width; ++$b) $value = ($value << 8) | ord($data[$position + $b]);
                    $fields[] = $value;
                    $position += $width;
                }

                // A zero-width type field means type 1, per the spec.
                $type = $widths[0] === 0 ? 1 : $fields[0];
                $number = $start + $i;
                if (isset($this->offsets[$number]) || isset($this->compressed[$number])) continue;

                if ($type === 1) $this->offsets[$number] = $fields[1];
                elseif ($type === 2) $this->compressed[$number] = [$fields[1], $fields[2]];
            }
        }

        $this->trailer += $stream->dictionary;

        return isset($stream->dictionary['Prev']) ? (int) $this->resolve($stream->dictionary['Prev']) : 0;
    }

    /**
     * Last resort for a file whose tables are wrong: scan for object headers.
     *
     * Plenty of real PDFs have broken cross-references and open fine in every
     * viewer, because viewers do exactly this.
     */
    private function rebuildByScanning(): void
    {
        $this->offsets = [];
        $this->compressed = [];
        $this->resolved = [];

        if (preg_match_all('/(?:^|[\r\n\s])(\d+)\s+(\d+)\s+obj\b/', $this->buffer, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new PdfException('PDF has no readable objects.');
        }

        foreach ($matches[1] as $i => [$number, $offset]) {
            if (count($this->offsets) > self::MAX_OBJECTS) break;
            // Later definitions win when a file has been incrementally updated.
            $this->offsets[(int) $number] = (int) $matches[0][$i][1] + (str_starts_with($matches[0][$i][0], (string) $number) ? 0 : 1);
        }

        if ($this->offsets === []) throw new PdfException('PDF has no readable objects.');

        if (!isset($this->trailer['Root'])) {
            if (preg_match_all('/trailer\s*(<<.*?>>)/s', $this->buffer, $trailers) === 1 || $trailers[1] !== []) {
                foreach (array_reverse($trailers[1]) as $candidate) {
                    $parser = new PdfParser($candidate);
                    $trailer = $parser->parseValue();
                    if (is_array($trailer) && isset($trailer['Root'])) { $this->trailer += $trailer; break; }
                }
            }
        }

        // Still nothing? Find the catalogue directly.
        if (!isset($this->trailer['Root'])) {
            foreach (array_keys($this->offsets) as $number) {
                $object = $this->resolve(new PdfReference($number, 0));
                if (is_array($object) && ($object['Type'] ?? null) === '/Catalog') {
                    $this->trailer['Root'] = new PdfReference($number, 0);
                    break;
                }
            }
        }

        if (!isset($this->trailer['Root'])) throw new PdfException('PDF has no document catalogue.');
    }
}
