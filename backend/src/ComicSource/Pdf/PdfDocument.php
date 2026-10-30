<?php

namespace App\ComicSource\Pdf;

use App\ComicSource\MemoryBudget;

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
    /** Objects needed to reach an object: a container's filter can itself be in a container. */
    private const MAX_RESOLUTION_DEPTH = 32;
    /** How many `trailer` keywords a damaged file gets to offer, newest first. */
    private const MAX_TRAILER_CANDIDATES = 32;
    /**
     * Largest source the native reader will accept into memory.
     *
     * The previous 64 MB value (introduced in the 3ef7bba Sep-2 hardening)
     * rejected every real-world comic volume this site hosts - Dragon Ball
     * volumes routinely run 110-180 MB and were the bulk of the library.
     * Raised to 500 MiB (524288000 bytes) so the native path serves the
     * full range of image-based PDFs the library actually contains. The
     * file is sized before reading so a smaller source only reserves its own
     * size and an oversized source is rejected before allocating its buffer.
     *
     * The bound is kept in place as a defence-in-depth net: a 50 GB
     * upload hitting an uncapped `file_get_contents` would exhaust memory
     * regardless of how the parser handles it. This constant is the
     * single line that bounds that worst case.
     *
     * It is a ceiling rather than a promise: a source also has to fit in what
     * memory_limit leaves, which readSource() checks.
     */
    private const MAX_DOCUMENT_BYTES = 524288000;

    /** Room a document must leave for reading a page once its bytes are in memory. */
    private const MIN_WORKING_BYTES = 16_777_216;
    /** ~600 megapixels: far past any comic page, well short of exhausting memory. */
    private const MAX_PIXELS = 600_000_000;

    /**
     * Ceiling on what one stream may inflate to.
     *
     * The compressed bytes come out of an uploaded file, so their expansion
     * ratio is chosen by whoever produced it; without a bound, a few kilobytes
     * of zeros inflate until the process dies. A real full-page scan is well
     * inside this — 2500x3500 in RGB is 26 MB of samples — and everything this
     * declines falls through to the renderer, which streams rather than
     * building the whole page in memory.
     */
    private const MAX_STREAM_BYTES = 67_108_864;

    /**
     * The predictor is undone a byte at a time in PHP, so its input is bounded
     * far more tightly than an ordinary stream: this is the cost of one loop
     * iteration times the number of bytes. Cross-reference and object streams,
     * which are what predictors are actually used on, are orders of magnitude
     * below this — MAX_OBJECTS rows of a cross-reference stream is about 5 MB.
     */
    private const MAX_PREDICTED_BYTES = 16_777_216;

    /** A palette holds 256 entries of three bytes, so no valid lookup table is larger. */
    private const MAX_LOOKUP_BYTES = 768;

    /** PNG predictors allow these depths, and no sample has more components. */
    private const PREDICTOR_DEPTHS = [1, 2, 4, 8, 16];
    private const MAX_PREDICTOR_COLORS = 32;

    /**
     * Drawing one image takes a line or two; a page much past that is doing
     * something else. Stops a compression bomb being inflated just to look at it.
     */
    private const MAX_CONTENT_BYTES = 4_096;
    private const MAX_CONTENT_STREAMS = 16;

    /** Writer-supplied states that cannot change how an opaque image looks. */
    private const NEUTRAL_STATE_KEYS = ['Type', 'OP', 'op', 'OPM', 'SA', 'AIS', 'TK', 'RI', 'FL', 'SM', 'LW', 'LC', 'LJ', 'ML', 'D'];

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
    /** @var array{number: int, data: string, offsets: list<int>}|null the last object stream read */
    private ?array $objectStream = null;
    private int $resolutionDepth = 0;
    private readonly PdfWorkBudget $budget;

    private function __construct(private readonly string $buffer)
    {
        $this->budget = new PdfWorkBudget();
    }

    public static function open(string $path): self
    {
        $buffer = self::readSource($path);
        if (!str_starts_with($buffer, '%PDF-')) throw new PdfException('Not a PDF.');

        $document = new self($buffer);
        $document->loadCrossReferences();

        if (isset($document->trailer['Encrypt'])) throw new PdfException('Encrypted PDFs are not supported.');

        return $document;
    }

    private static function readSource(string $path): string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new PdfException('Not a PDF.');

        try {
            $metadata = fstat($stream);
            $size = $metadata['size'] ?? null;
            if (!is_int($size) || $size < 0) throw new PdfException('Not a PDF.');
            if ($size > self::MAX_DOCUMENT_BYTES) throw new PdfException('PDF is too large for the native reader.');
            if (!MemoryBudget::fits($size + self::MIN_WORKING_BYTES)) throw new PdfException('PDF is too large for the native reader on this memory_limit.');

            // The open handle cannot be redirected by replacing the path. One
            // extra byte detects a concurrent append while keeping the read
            // bounded by the already-validated size.
            $buffer = @stream_get_contents($stream, $size + 1);
        } finally {
            fclose($stream);
        }

        if (!is_string($buffer)) throw new PdfException('Not a PDF.');
        if (strlen($buffer) !== $size) throw new PdfException('PDF changed while it was being read.');

        return $buffer;
    }

    public function pageCount(): int
    {
        $this->budget->restart();

        return count($this->pages());
    }

    /**
     * The single full-page image behind logical page $page, or null when the
     * page is not verifiably that image and only a renderer could produce it.
     */
    public function pageImage(int $page): ?PdfPageImage
    {
        $this->budget->restart();
        $definition = $this->page($page);

        [$name, $image] = $this->soleImageOf($definition);
        if ($name === null || $image === null || !$this->imageFillsPage($definition, $name, $image)) return null;

        return $this->imageFrom($image);
    }

    /**
     * The page's one image even if the page also holds more than that.
     *
     * A partial page, so this is only for a host with no renderer, where the
     * alternative is showing nothing at all.
     */
    public function soleImage(int $page): ?PdfPageImage
    {
        $this->budget->restart();

        $image = $this->soleImageOf($this->page($page))[1];

        return $image === null ? null : $this->imageFrom($image);
    }

    /** @return array<string, mixed> */
    private function page(int $page): array
    {
        $pages = $this->pages();
        if ($page < 1 || !isset($pages[$page - 1])) throw new PdfException('Page not found.');

        return $pages[$page - 1];
    }

    /**
     * @param array<string, mixed> $page
     * @return array{0: string|null, 1: PdfStream|null} the image's resource name and stream
     */
    private function soleImageOf(array $page): array
    {
        $resources = $this->resolve($page['Resources'] ?? null);
        if (!is_array($resources)) return [null, null];

        $xObjects = $this->resolve($resources['XObject'] ?? null);
        if (!is_array($xObjects)) return [null, null];

        $images = [];
        foreach ($xObjects as $name => $candidate) {
            $stream = $this->resolve($candidate);
            if (!$stream instanceof PdfStream) continue;
            if (($stream->dictionary['Subtype'] ?? null) !== '/Image') continue;
            $images[$name] = $stream;
        }

        // Exactly one image is the shape we can serve faithfully. Zero means a
        // rendered page; more than one means the page is a composition, and
        // picking one of them would silently show the reader part of a page.
        if (count($images) !== 1) return [null, null];

        return [(string) array_key_first($images), reset($images)];
    }

    /**
     * Whether what the reader sees on this page is that image and nothing else.
     *
     * @param array<string, mixed> $page
     */
    private function imageFillsPage(array $page, string $name, PdfStream $image): bool
    {
        // A mask decides which pixels show, and the bytes handed over carry none of it.
        if ($this->resolve($image->dictionary['SMask'] ?? null) !== null) return false;
        if ($this->resolve($image->dictionary['Mask'] ?? null) !== null) return false;

        $rotation = $this->resolve($page['Rotate'] ?? 0);
        if (!is_int($rotation) || $rotation % 360 !== 0) return false;

        // Stamps, form fields and comments draw over the page's own content.
        $annotations = $this->resolve($page['Annots'] ?? null);
        if ($annotations !== null && $annotations !== []) return false;

        $box = $this->visibleBox($page);
        $content = $this->pageContent($page);
        if ($box === null || $content === null) return false;

        return PdfPageCoverage::imageFillsBox(
            $content,
            $name,
            $box,
            $this->resolve($image->dictionary['ImageMask'] ?? false) === true,
            fn (string $state): bool => $this->graphicsStateIsNeutral($page, $state),
        );
    }

    /**
     * The page area a viewer shows: the crop box where there is one, inside the
     * media box either way.
     *
     * @param array<string, mixed> $page
     * @return array{float, float, float, float}|null x0, y0, x1, y1
     */
    private function visibleBox(array $page): ?array
    {
        $box = $this->rectangle($page['MediaBox'] ?? null);
        if ($box === null) return null;

        if (isset($page['CropBox'])) {
            $crop = $this->rectangle($page['CropBox']);
            if ($crop === null) return null;
            $box = [max($box[0], $crop[0]), max($box[1], $crop[1]), min($box[2], $crop[2]), min($box[3], $crop[3])];
        }

        return $box[0] < $box[2] && $box[1] < $box[3] ? $box : null;
    }

    /** @return array{float, float, float, float}|null */
    private function rectangle(mixed $value): ?array
    {
        $corners = $this->resolve($value);
        if (!is_array($corners) || count($corners) !== 4) return null;

        $numbers = [];
        foreach (array_values($corners) as $corner) {
            $number = $this->resolve($corner);
            if (!is_int($number) && !is_float($number)) return null;
            $numbers[] = (float) $number;
        }

        // Any two opposite corners are allowed, in either order.
        return [min($numbers[0], $numbers[2]), min($numbers[1], $numbers[3]), max($numbers[0], $numbers[2]), max($numbers[1], $numbers[3])];
    }

    /**
     * The page's content, which may be split across streams at any token.
     *
     * @param array<string, mixed> $page
     */
    private function pageContent(array $page): ?string
    {
        $contents = $this->resolve($page['Contents'] ?? null);
        $parts = is_array($contents) ? $contents : [$contents];
        if (count($parts) > self::MAX_CONTENT_STREAMS) return null;

        $content = '';
        foreach ($parts as $part) {
            $stream = $this->resolve($part);
            if (!$stream instanceof PdfStream) return null;

            $data = $this->inflate($stream, self::MAX_CONTENT_BYTES);
            if ($data === null || strlen($content) + strlen($data) > self::MAX_CONTENT_BYTES) return null;
            $content .= $data."\n";
        }

        return $content;
    }

    /** @param array<string, mixed> $page */
    private function graphicsStateIsNeutral(array $page, string $name): bool
    {
        $resources = $this->resolve($page['Resources'] ?? null);
        $states = is_array($resources) ? $this->resolve($resources['ExtGState'] ?? null) : null;
        $state = is_array($states) ? $this->resolve($states[$name] ?? null) : null;
        if (!is_array($state)) return false;

        foreach ($state as $key => $value) {
            $value = $this->resolve($value);

            $neutral = match ($key) {
                'ca', 'CA' => $value === 1 || $value === 1.0,
                'BM' => $value === '/Normal' || $value === '/Compatible',
                'SMask' => $value === '/None',
                default => in_array($key, self::NEUTRAL_STATE_KEYS, true),
            };
            if (!$neutral) return false;
        }

        return true;
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
            if (!is_array($info) || $info['mime'] !== 'image/jpeg') return null;
            return new PdfPageImage($stream->raw, 'image/jpeg', $width, $height);
        }

        // Some producers wrap the JPEG in Flate as well; unwrap and re-check.
        if ($filters === ['/FlateDecode', '/DCTDecode']) {
            $cap = $this->inflationCap(self::MAX_STREAM_BYTES);
            $inflated = $cap > 0 ? @gzuncompress($stream->raw, $cap) : false;
            if (!is_string($inflated)) return null;
            $info = @getimagesizefromstring($inflated);
            if (!is_array($info) || $info['mime'] !== 'image/jpeg') return null;
            return new PdfPageImage($inflated, 'image/jpeg', $width, $height);
        }

        // Anything else that is only compressed — or not compressed at all —
        // is raw pixel rows, which is what a PNG carries. Repacking those is
        // exact and cheap, and it covers the exports that store pages as
        // bitmaps rather than JPEGs.
        if ($filters === [] || $filters === ['/FlateDecode']) {
            return $this->pngFromSamples($stream, $width, $height);
        }

        // JPXDecode is JPEG 2000 and CCITTFaxDecode is fax-encoded bilevel.
        // Both need a real decoder; Poppler has one and we do not.
        return null;
    }

    /**
     * Repack an uncompressed image stream as a PNG.
     *
     * Returns null rather than guessing whenever the colour space or bit depth
     * is one where a wrong interpretation would show the reader a corrupted
     * page. Poppler is the fallback for those.
     */
    private function pngFromSamples(PdfStream $stream, int $width, int $height): ?PdfPageImage
    {
        // A page that would not fit in memory as pixels is not one to unpack.
        if ($width * $height > self::MAX_PIXELS) return null;

        $samples = $this->inflate($stream);
        if ($samples === null || $samples === '') return null;

        $bits = (int) $this->resolve($stream->dictionary['BitsPerComponent'] ?? 8);
        $isMask = $this->resolve($stream->dictionary['ImageMask'] ?? false) === true;
        if ($isMask) $bits = 1;

        $colourSpace = $this->colourSpace($this->resolve($stream->dictionary['ColorSpace'] ?? null));
        if ($isMask) $colourSpace = ['type' => 'gray'];
        if ($colourSpace === null) return null;

        [$colorType, $palette] = match ($colourSpace['type']) {
            'gray' => [PngWriter::COLOR_GRAY, null],
            'rgb' => [PngWriter::COLOR_RGB, null],
            'indexed' => [PngWriter::COLOR_PALETTE, $colourSpace['palette']],
            default => [null, null],
        };
        if ($colorType === null) return null;

        // PNG carries 1, 2, 4, 8 and 16 bits per sample, and RGB only at 8 or
        // 16. Anything outside that would need resampling to be honest about.
        $allowed = $colorType === PngWriter::COLOR_RGB ? [8, 16] : [1, 2, 4, 8];
        if (!in_array($bits, $allowed, true)) return null;

        // /Decode [1 0] means the samples are inverted, which is ordinary for
        // scanned bilevel pages. Flipping the bytes is exact for 1-bit gray.
        $decode = $this->resolve($stream->dictionary['Decode'] ?? null);
        if ($bits === 1 && $colorType === PngWriter::COLOR_GRAY && is_array($decode) && ($decode[0] ?? 0) == 1) {
            $this->budget->check(strlen($samples));
            $samples = ~$samples;
        }

        $needed = PngWriter::rowBytes($width, $bits, $colorType) * $height;
        if (strlen($samples) < $needed) return null;

        // Rows, their compressed form, and the two copies chunking makes of it.
        $this->budget->check(4 * $needed);

        try {
            $png = PngWriter::encode($width, $height, $bits, $colorType, $samples, $palette);
        } catch (PdfException) {
            return null;
        }

        return new PdfPageImage($png, 'image/png', $width, $height);
    }

    /**
     * Reduce a PDF colour space to the three shapes a PNG can carry directly.
     *
     * @return array{type: 'gray'|'rgb'}|array{type: 'indexed', palette: string}|null
     */
    private function colourSpace(mixed $space, int $depth = 0): ?array
    {
        if ($depth > 4) return null;
        $space = $this->resolve($space);

        if ($space === '/DeviceGray' || $space === '/CalGray' || $space === '/G') return ['type' => 'gray'];
        if ($space === '/DeviceRGB' || $space === '/CalRGB' || $space === '/RGB') return ['type' => 'rgb'];

        if (!is_array($space) || $space === []) return null;

        $family = $this->resolve($space[0] ?? null);

        // An ICC profile still describes gray or RGB underneath; /N says which.
        if ($family === '/ICCBased') {
            $profile = $this->resolve($space[1] ?? null);
            $components = $profile instanceof PdfStream ? (int) $this->resolve($profile->dictionary['N'] ?? 0) : 0;

            return match ($components) {
                1 => ['type' => 'gray'],
                3 => ['type' => 'rgb'],
                // CMYK has no PNG equivalent and converting it without the
                // profile would shift every colour on the page.
                default => null,
            };
        }

        if ($family === '/Indexed' || $family === '/I') {
            $base = $this->colourSpace($space[1] ?? null, $depth + 1);
            if ($base === null || $base['type'] === 'indexed') return null;

            $lookup = $this->resolve($space[3] ?? null);
            $table = $lookup instanceof PdfStream ? $this->inflate($lookup, self::MAX_LOOKUP_BYTES) : (is_string($lookup) ? $lookup : null);
            if ($table === null) return null;

            // Checked before the expansion below, which is three bytes out for
            // each one in. The entries check after it would come too late.
            if (strlen($table) > ($base['type'] === 'gray' ? intdiv(self::MAX_LOOKUP_BYTES, 3) : self::MAX_LOOKUP_BYTES)) return null;

            // PNG palettes are always RGB triples, so a grey base expands.
            if ($base['type'] === 'gray') {
                $expanded = '';
                for ($i = 0, $length = strlen($table); $i < $length; ++$i) $expanded .= str_repeat($table[$i], 3);
                $table = $expanded;
            }

            $entries = intdiv(strlen($table), 3);
            if ($entries < 1 || $entries > 256) return null;

            return ['type' => 'indexed', 'palette' => substr($table, 0, $entries * 3)];
        }

        if ($family === '/DeviceN' || $family === '/Separation' || $family === '/DeviceCMYK') return null;

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
     * @param array<string, true>|null $seen
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

            $parser = new PdfParser($this->buffer, $this->budget);
            $parser->seek($this->offsets[$number]);
            return $this->resolved[$key] = $parser->parseIndirectObject();
        }

        if (isset($this->compressed[$number])) {
            [$container, $index] = $this->compressed[$number];

            // Distinct objects can chain without ever repeating, so the mark
            // above does not stop this, and each link costs call frames.
            if ($this->resolutionDepth >= self::MAX_RESOLUTION_DEPTH) throw new PdfException('PDF object chain is too deep.');

            ++$this->resolutionDepth;
            try {
                return $this->resolved[$key] = $this->objectFromStream($container, $index);
            } finally {
                --$this->resolutionDepth;
            }
        }

        return null;
    }

    /**
     * Pull one object out of an object stream, where PDF 1.5+ writers put most
     * of a document's small objects, Flate-compressed together.
     */
    private function objectFromStream(int $container, int $index): mixed
    {
        $stream = $this->objectStream($container);
        if ($stream === null || !isset($stream['offsets'][$index])) return null;

        $parser = new PdfParser($stream['data'], $this->budget);
        $parser->seek($stream['offsets'][$index]);

        return $parser->parseValue();
    }

    /**
     * Kept for the next object, since neighbours are usually read in turn and
     * re-inflating a large stream for each of its objects has no upper bound.
     *
     * @return array{number: int, data: string, offsets: list<int>}|null
     */
    private function objectStream(int $container): ?array
    {
        if ($this->objectStream !== null && $this->objectStream['number'] === $container) return $this->objectStream;
        $this->objectStream = null;

        $stream = $this->resolve(new PdfReference($container, 0));
        if (!$stream instanceof PdfStream) return null;

        $data = $this->inflate($stream);
        if ($data === null) return null;

        $count = (int) $this->resolve($stream->dictionary['N'] ?? 0);
        $first = (int) $this->resolve($stream->dictionary['First'] ?? 0);
        if ($count < 1 || $count > self::MAX_OBJECTS || $first < 0 || $first > strlen($data)) return null;

        // The header is N pairs of "object number, offset from /First". Read a
        // pair at a time: a header can inflate to millions of pairs, and the
        // arrays a whole-header match builds cost far more than the text.
        $offsets = [];
        $position = 0;
        while (count($offsets) < $count && $position < $first
            && preg_match('/\G\s*\d+\s+(\d+)/', $data, $pair, 0, $position) === 1) {
            $offset = (int) $pair[1];
            if ($offset > strlen($data) - $first) break;
            $offsets[] = $first + $offset;
            $position += strlen($pair[0]);
        }

        return $this->objectStream = ['number' => $container, 'data' => $data, 'offsets' => $offsets];
    }

    private function inflate(PdfStream $stream, int $limit = self::MAX_STREAM_BYTES): ?string
    {
        $this->budget->check();

        $filter = $this->resolve($stream->dictionary['Filter'] ?? null);
        $filters = is_array($filter) ? $filter : ($filter === null ? [] : [$filter]);
        if ($filters === []) return $stream->raw;
        if ($filters !== ['/FlateDecode']) return null;

        $cap = $this->inflationCap($limit);
        if ($cap < 1) return null;

        // Both take the cap as their length limit and return false rather than
        // allocating past it, so an over-expanding stream costs nothing.
        $data = @gzuncompress($stream->raw, $cap);
        if (!is_string($data)) $data = @gzinflate($stream->raw, $cap);
        if (!is_string($data)) return null;

        $parms = $this->resolve($stream->dictionary['DecodeParms'] ?? null);
        if (is_array($parms) && isset($parms['Predictor']) && (int) $this->resolve($parms['Predictor']) > 1) {
            if (strlen($data) > self::MAX_PREDICTED_BYTES) return null;

            $data = $this->undoPngPredictor(
                $data,
                $this->resolve($parms['Columns'] ?? 1),
                $this->resolve($parms['Colors'] ?? 1),
                $this->resolve($parms['BitsPerComponent'] ?? 8),
            );
        }

        return $data;
    }

    /**
     * Largest result to let zlib produce: it builds the output and then copies
     * it once on the way out, so a stream costs twice its size at the peak. Zero
     * means no room at all, and must not reach zlib, which reads it as no limit.
     */
    private function inflationCap(int $limit): int
    {
        return min($limit, intdiv(MemoryBudget::available(), 2));
    }

    /**
     * Cross-reference streams are usually PNG-predicted, so the rows have to be
     * un-filtered before the table can be read.
     *
     * The row size comes straight from the file, so it is checked before it can
     * size anything: null means this is not a predictor stream that could exist.
     */
    private function undoPngPredictor(string $data, mixed $columns, mixed $colors, mixed $bits): ?string
    {
        if (!is_int($columns) || !is_int($colors) || !is_int($bits)) return null;
        if ($colors < 1 || $colors > self::MAX_PREDICTOR_COLORS || !in_array($bits, self::PREDICTOR_DEPTHS, true)) return null;
        if ($columns < 1 || $columns > self::MAX_PREDICTED_BYTES * 8) return null;

        $sample = max(1, intdiv($colors * $bits + 7, 8));
        $rowLength = intdiv($columns * $colors * $bits + 7, 8);

        // A short final row is tolerated below, but padding is the only way the
        // output could outgrow the input, so a row must at least fit the stream.
        if ($rowLength >= strlen($data)) return null;
        $this->budget->check(strlen($data) + 2 * $rowLength);

        $previous = str_repeat("\0", $rowLength);
        $output = '';

        for ($offset = 0; $offset + 1 <= strlen($data); $offset += $rowLength + 1) {
            $this->budget->check();

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
        $parser = new PdfParser($this->buffer, $this->budget);
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

            // Across the whole table: a limit per subsection is no limit, since
            // a file can have as many subsections as it has room for.
            if ($count < 0 || count($this->offsets) + count($this->compressed) + $count > self::MAX_OBJECTS) {
                throw new PdfException('Cross-reference table is implausible.');
            }
            $this->budget->check();

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

        $parser = new PdfParser($this->buffer, $this->budget);
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
        // No offset needs more than eight bytes, and a negative width would
        // walk backwards through the rows rather than ever running out of them.
        foreach ($widths as $width) {
            if ($width < 0 || $width > 8) throw new PdfException('Cross-reference stream widths are implausible.');
        }

        $size = (int) $this->resolve($stream->dictionary['Size'] ?? 0);
        $index = $this->resolve($stream->dictionary['Index'] ?? null);
        $ranges = is_array($index) && $index !== [] ? array_map(static fn ($v): int => (int) $v, $index) : [0, $size];

        $rowLength = array_sum($widths);
        if ($rowLength < 1) throw new PdfException('Cross-reference stream rows are empty.');

        $position = 0;
        $declared = 0;
        for ($pair = 0; $pair + 1 < count($ranges); $pair += 2) {
            $start = $ranges[$pair];
            $count = $ranges[$pair + 1];
            $declared += $count;
            if ($count < 0 || $declared + count($this->offsets) + count($this->compressed) > self::MAX_OBJECTS) {
                throw new PdfException('Cross-reference stream is implausible.');
            }
            $this->budget->check();

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
        $this->objectStream = null;

        // A match at a time: the arrays a whole-file match builds cost a hundred
        // times the text they describe, which is the difference between a small
        // hostile file being refused and it ending the process.
        $position = 0;
        for ($found = 0; $found < self::MAX_OBJECTS; ++$found) {
            if (preg_match('/(?:^|[\r\n\s])(\d+)\s+(\d+)\s+obj\b/', $this->buffer, $match, PREG_OFFSET_CAPTURE, $position) !== 1) break;
            $this->budget->check();

            [$header, $at] = $match[0];
            // Later definitions win when a file has been incrementally updated.
            $this->offsets[(int) $match[1][0]] = $at + (str_starts_with($header, $match[1][0]) ? 0 : 1);
            $position = $at + strlen($header);
        }

        if ($this->offsets === []) throw new PdfException('PDF has no readable objects.');

        if (!isset($this->trailer['Root'])) $this->recoverTrailer();

        // Still nothing? Find the catalogue directly.
        if (!isset($this->trailer['Root'])) {
            foreach (array_keys($this->offsets) as $number) {
                $object = $this->resolve(new PdfReference($number, 0));
                if (is_array($object) && ($object['Type'] ?? null) === '/Catalog') {
                    $this->trailer['Root'] = new PdfReference($number, 0);
                    break;
                }
                // Reading every object to find one is no reason to keep them.
                unset($this->resolved[$number]);
            }
        }

        if (!isset($this->trailer['Root'])) throw new PdfException('PDF has no document catalogue.');
    }

    /** The newest trailer that names a catalogue, read straight out of the file. */
    private function recoverTrailer(): void
    {
        $searchFrom = -1;
        for ($candidate = 0; $candidate < self::MAX_TRAILER_CANDIDATES; ++$candidate) {
            $at = strrpos($this->buffer, 'trailer', $searchFrom);
            if ($at === false) return;

            // One that cannot be parsed is a reason to try the next, never a
            // reason to give up on the document: the catalogue scan can still
            // recover it.
            try {
                $parser = new PdfParser($this->buffer, $this->budget);
                $parser->seek($at + 7);
                $trailer = $parser->parseValue();
            } catch (PdfException) {
                $trailer = null;
            }

            if (is_array($trailer) && isset($trailer['Root'])) {
                $this->trailer += $trailer;
                return;
            }

            if ($at === 0) return;
            $searchFrom = $at - strlen($this->buffer) - 1;
        }
    }
}
