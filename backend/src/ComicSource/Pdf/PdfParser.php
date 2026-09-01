<?php

namespace App\ComicSource\Pdf;

/**
 * Reads PDF objects out of a byte string.
 *
 * Deliberately a reader and nothing else: it never evaluates anything, never
 * follows a reference, and never looks outside the buffer it was handed. A
 * comic PDF is untrusted input that arrived over an upload form, so the only
 * safe posture is to treat the file as bytes to be described, not obeyed.
 *
 * Values map onto PHP as: dictionaries to string-keyed arrays (keys without
 * the leading slash), arrays to lists, names to strings prefixed with `/`,
 * references to PdfReference, streams to PdfStream, and numbers, booleans and
 * null to their PHP equivalents.
 */
final class PdfParser
{
    /** Guards against a hostile file nesting containers until we run out of stack. */
    private const MAX_DEPTH = 32;

    private int $position = 0;

    public function __construct(private readonly string $buffer)
    {
    }

    public function seek(int $offset): void
    {
        $this->position = $offset;
    }

    /**
     * Parse the object at the current position, including the `N G obj` header
     * when one is present.
     */
    public function parseIndirectObject(): mixed
    {
        $this->skipWhitespace();
        // "12 0 obj" — consume the header if it is there, tolerate its absence.
        $mark = $this->position;
        if (preg_match('/\G(\d+)\s+(\d+)\s+obj\b/', $this->buffer, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
        } else {
            $this->position = $mark;
        }

        return $this->parseValue();
    }

    public function parseValue(int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) throw new PdfException('PDF structure is nested too deeply.');

        $this->skipWhitespace();
        if ($this->position >= strlen($this->buffer)) throw new PdfException('Unexpected end of PDF data.');

        $char = $this->buffer[$this->position];

        if ($char === '<') {
            if (($this->buffer[$this->position + 1] ?? '') === '<') return $this->parseDictionaryOrStream($depth);
            return $this->parseHexString();
        }
        if ($char === '[') return $this->parseArray($depth);
        if ($char === '(') return $this->parseLiteralString();
        if ($char === '/') return $this->parseName();
        if ($char === ']' || $char === '>' || $char === ')') throw new PdfException('Unbalanced PDF container.');

        return $this->parseKeywordOrNumber();
    }

    /** @return array<string, mixed>|PdfStream */
    private function parseDictionaryOrStream(int $depth): array|PdfStream
    {
        $this->position += 2;
        $dictionary = [];

        while (true) {
            $this->skipWhitespace();
            if ($this->position >= strlen($this->buffer)) throw new PdfException('Unterminated PDF dictionary.');

            if (str_starts_with(substr($this->buffer, $this->position, 2), '>>')) {
                $this->position += 2;
                break;
            }

            if ($this->buffer[$this->position] !== '/') throw new PdfException('Malformed PDF dictionary key.');
            $key = substr($this->parseName(), 1);
            $dictionary[$key] = $this->parseValue($depth + 1);
        }

        // A stream keyword immediately after the dictionary makes this a stream.
        $save = $this->position;
        $this->skipWhitespace();
        if (!str_starts_with(substr($this->buffer, $this->position, 6), 'stream')) {
            $this->position = $save;
            return $dictionary;
        }

        $this->position += 6;
        // The spec allows CRLF or LF after the keyword, never CR alone.
        if (str_starts_with(substr($this->buffer, $this->position, 2), "\r\n")) $this->position += 2;
        elseif (($this->buffer[$this->position] ?? '') === "\n") ++$this->position;

        $start = $this->position;
        $length = $dictionary['Length'] ?? null;

        // An indirect /Length cannot be resolved from here, and a wrong one is a
        // known way to hide data, so the endstream keyword is authoritative
        // whenever the declared length does not land on it.
        $raw = null;
        if (is_int($length) && $length >= 0 && $start + $length <= strlen($this->buffer)) {
            $after = substr($this->buffer, $start + $length, 20);
            if (preg_match('/^\s*endstream/', $after) === 1) $raw = substr($this->buffer, $start, $length);
        }

        if ($raw === null) {
            $end = strpos($this->buffer, 'endstream', $start);
            if ($end === false) throw new PdfException('Unterminated PDF stream.');
            $raw = substr($this->buffer, $start, $end - $start);
            // Trim the single EOL the writer added before the keyword.
            $raw = preg_replace('/(\r\n|\r|\n)$/', '', $raw) ?? $raw;
        }

        // Assigned through a local: this property is typed int, and in coercive
        // mode a false from strpos() lands in it as 0, so the "not found" test
        // below would never fire and the parser would resume from offset 9.
        $endstream = strpos($this->buffer, 'endstream', $start + strlen($raw));
        $this->position = $endstream === false ? strlen($this->buffer) : $endstream + 9;

        return new PdfStream($dictionary, $raw);
    }

    /** @return list<mixed> */
    private function parseArray(int $depth): array
    {
        ++$this->position;
        $items = [];

        while (true) {
            $this->skipWhitespace();
            if ($this->position >= strlen($this->buffer)) throw new PdfException('Unterminated PDF array.');
            if ($this->buffer[$this->position] === ']') { ++$this->position; break; }
            $items[] = $this->parseValue($depth + 1);
        }

        return $items;
    }

    private function parseName(): string
    {
        ++$this->position;
        $name = '';

        while ($this->position < strlen($this->buffer)) {
            $char = $this->buffer[$this->position];
            if (self::isDelimiter($char) || self::isWhitespace($char)) break;

            // #-escapes, e.g. /A#20B for "A B".
            if ($char === '#' && preg_match('/^[0-9A-Fa-f]{2}/', substr($this->buffer, $this->position + 1, 2), $hex) === 1) {
                $name .= chr((int) hexdec($hex[0]));
                $this->position += 3;
                continue;
            }

            $name .= $char;
            ++$this->position;
        }

        return '/'.$name;
    }

    private function parseLiteralString(): string
    {
        ++$this->position;
        $value = '';
        $nesting = 1;

        while ($this->position < strlen($this->buffer)) {
            $char = $this->buffer[$this->position++];

            if ($char === '\\') {
                $next = $this->position < strlen($this->buffer)
                    ? $this->buffer[$this->position]
                    : '';
                ++$this->position;
                $value .= match ($next) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                    default => $next,
                };
                continue;
            }

            if ($char === '(') ++$nesting;
            if ($char === ')' && --$nesting === 0) return $value;

            $value .= $char;
        }

        throw new PdfException('Unterminated PDF string.');
    }

    private function parseHexString(): string
    {
        ++$this->position;
        $end = strpos($this->buffer, '>', $this->position);
        if ($end === false) throw new PdfException('Unterminated PDF hex string.');

        $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($this->buffer, $this->position, $end - $this->position)) ?? '';
        $this->position = $end + 1;
        if (strlen($hex) % 2 === 1) $hex .= '0';

        return (string) hex2bin($hex);
    }

    private function parseKeywordOrNumber(): mixed
    {
        // "12 0 R" has to be recognised before "12" is taken as a number.
        if (preg_match('/\G(\d+)\s+(\d+)\s+R\b/', $this->buffer, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            return new PdfReference((int) $match[1], (int) $match[2]);
        }

        if (preg_match('/\G(true|false|null)\b/', $this->buffer, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            return match ($match[1]) { 'true' => true, 'false' => false, default => null };
        }

        if (preg_match('/\G[+-]?(\d+\.?\d*|\.\d+)/', $this->buffer, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            return str_contains($match[0], '.') ? (float) $match[0] : (int) $match[0];
        }

        // An unknown keyword is not fatal for our purposes; skip it so a
        // content stream oddity cannot stop us reading a page dictionary.
        if (preg_match('/\G[A-Za-z\']+/', $this->buffer, $match, 0, $this->position) === 1) {
            $this->position += strlen($match[0]);
            return null;
        }

        throw new PdfException('Unreadable PDF token.');
    }

    private function skipWhitespace(): void
    {
        while ($this->position < strlen($this->buffer)) {
            $char = $this->buffer[$this->position];

            if (self::isWhitespace($char)) { ++$this->position; continue; }

            // Comments run to the end of the line.
            if ($char === '%') {
                $newline = strcspn($this->buffer, "\r\n", $this->position);
                $this->position += $newline;
                continue;
            }

            return;
        }
    }

    private static function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\n" || $char === "\r" || $char === "\t" || $char === "\0" || $char === "\x0C";
    }

    private static function isDelimiter(string $char): bool
    {
        return str_contains('()<>[]{}/%', $char);
    }
}
