<?php

namespace App\ComicSource\Pdf;

/**
 * Whether a page's content stream does nothing but draw one image over the page.
 *
 * Handing the embedded image to the reader is only faithful when that is all the
 * page is. Every other instruction is refused rather than interpreted, because a
 * wrong "no" costs one render and a wrong "yes" loses part of a page.
 */
final class PdfPageCoverage
{
    /** Writers round placement, and a point is under a pixel on a comic scan. */
    private const TOLERANCE_POINTS = 1.0;
    private const AXIS_ALIGNED = 0.001;

    /** These change later painting but paint nothing themselves. */
    private const STATE_OPERATORS = ['w', 'J', 'j', 'M', 'i', 'ri', 'BMC', 'EMC'];

    /** The fill colour is what a stencil mask is drawn in, so these matter there. */
    private const COLOUR_OPERATORS = ['g', 'G', 'rg', 'RG', 'k', 'K', 'cs', 'CS', 'sc', 'SC', 'scn', 'SCN'];

    /**
     * @param array{float, float, float, float} $box visible page area as x0, y0, x1, y1
     * @param callable(string): bool $isNeutralState whether a named graphics state changes nothing visible
     */
    public static function imageFillsBox(
        string $content,
        string $imageName,
        array $box,
        bool $imageIsStencil,
        callable $isNeutralState,
    ): bool {
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $saved = [];
        $operands = [];
        $path = null;
        $clipping = false;
        $drawn = false;

        $position = 0;
        $length = strlen($content);
        while ($position < $length) {
            $position += strspn($content, " \t\r\n\f\0", $position);
            if ($position >= $length) break;

            if ($content[$position] === '%') {
                $position += strcspn($content, "\r\n", $position);
                continue;
            }

            if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)(?=[\s\/]|$)/', $content, $token, 0, $position) === 1) {
                $operands[] = (float) $token[0];
            } elseif (preg_match('/\G\/[A-Za-z0-9_.+:-]*/', $content, $token, 0, $position) === 1) {
                $operands[] = substr($token[0], 1);
            } elseif (preg_match('/\G[A-Za-z][A-Za-z0-9*]*(?=[\s\/]|$)/', $content, $token, 0, $position) === 1) {
                switch ($token[0]) {
                    case 'q':
                        $saved[] = $matrix;
                        break;
                    case 'Q':
                        if ($saved === []) return false;
                        $matrix = array_pop($saved);
                        break;
                    case 'cm':
                        if (!self::areNumbers($operands, 6)) return false;
                        $matrix = self::multiply($operands, $matrix);
                        break;
                    case 're':
                        if (!self::areNumbers($operands, 4)) return false;
                        $path = $operands;
                        break;
                    case 'W':
                    case 'W*':
                        if ($path === null) return false;
                        $clipping = true;
                        break;
                    case 'n':
                        if ($clipping && ($path === null || !self::clipCoversBox($path, $matrix, $box))) return false;
                        $path = null;
                        $clipping = false;
                        break;
                    case 'gs':
                        if (count($operands) !== 1 || !is_string($operands[0]) || !$isNeutralState($operands[0])) return false;
                        break;
                    case 'Do':
                        if ($drawn || $operands !== [$imageName] || !self::imageCoversBox($matrix, $box)) return false;
                        $drawn = true;
                        break;
                    case 'BDC':
                        // Optional content can hide what it wraps.
                        if (($operands[0] ?? null) === 'OC') return false;
                        break;
                    default:
                        $isState = in_array($token[0], self::STATE_OPERATORS, true)
                            || (!$imageIsStencil && in_array($token[0], self::COLOUR_OPERATORS, true));
                        if (!$isState) return false;
                }

                $operands = [];
            } else {
                return false;
            }

            $position += strlen($token[0]);
        }

        return $drawn && $saved === [];
    }

    /**
     * @param list<mixed> $operands
     * @phpstan-assert-if-true list<float> $operands
     */
    private static function areNumbers(array $operands, int $count): bool
    {
        return count($operands) === $count && array_filter($operands, 'is_float') === $operands;
    }

    /**
     * @param list<float> $next
     * @param list<float> $current
     * @return list<float>
     */
    private static function multiply(array $next, array $current): array
    {
        [$a, $b, $c, $d, $e, $f] = $next;
        [$ca, $cb, $cc, $cd, $ce, $cf] = $current;

        return [
            $a * $ca + $b * $cc,
            $a * $cb + $b * $cd,
            $c * $ca + $d * $cc,
            $c * $cb + $d * $cd,
            $e * $ca + $f * $cc + $ce,
            $e * $cb + $f * $cd + $cf,
        ];
    }

    /**
     * The image occupies the unit square of the current matrix. A flip or a
     * turn would need its pixels changed, so only upright placement counts.
     *
     * @param list<float> $matrix
     * @param array{float, float, float, float} $box
     */
    private static function imageCoversBox(array $matrix, array $box): bool
    {
        [$a, $b, $c, $d, $e, $f] = $matrix;
        if (abs($b) > self::AXIS_ALIGNED || abs($c) > self::AXIS_ALIGNED || $a <= 0.0 || $d <= 0.0) return false;

        return abs($e - $box[0]) <= self::TOLERANCE_POINTS
            && abs($f - $box[1]) <= self::TOLERANCE_POINTS
            && abs($e + $a - $box[2]) <= self::TOLERANCE_POINTS
            && abs($f + $d - $box[3]) <= self::TOLERANCE_POINTS;
    }

    /**
     * @param list<float> $rectangle x, y, width, height
     * @param list<float> $matrix
     * @param array{float, float, float, float} $box
     */
    private static function clipCoversBox(array $rectangle, array $matrix, array $box): bool
    {
        [$a, $b, $c, $d, $e, $f] = $matrix;
        if (abs($b) > self::AXIS_ALIGNED || abs($c) > self::AXIS_ALIGNED) return false;

        [$x, $y, $width, $height] = $rectangle;
        $xs = [$a * $x + $e, $a * ($x + $width) + $e];
        $ys = [$d * $y + $f, $d * ($y + $height) + $f];

        return min($xs) <= $box[0] + self::TOLERANCE_POINTS
            && min($ys) <= $box[1] + self::TOLERANCE_POINTS
            && max($xs) >= $box[2] - self::TOLERANCE_POINTS
            && max($ys) >= $box[3] - self::TOLERANCE_POINTS;
    }
}
