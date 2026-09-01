<?php

namespace App\Service\Pagination;

use Doctrine\ORM\QueryBuilder;

/**
 * The handling every admin table's per-column filter shares.
 *
 * Each column filter is a box an operator types into, so the value arrives as
 * an untrimmed string that may be absent, blank, or nonsense. Deciding what
 * that means once — blank is no filter, text is an escaped substring match, a
 * date range is inclusive of both calendar days — keeps eight tables agreeing
 * about it.
 */
final class ColumnFilter
{
    /** The escaped `LIKE` argument for a typed-in value, or null when nothing was typed. */
    public static function pattern(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === '' ? null : LikePattern::contains($value);
    }

    public static function text(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * An inclusive non-negative integer range encoded as `minimum..maximum`.
     * A legacy single integer remains an exact-value range.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function integerRange(mixed $value): ?array
    {
        $value = self::text($value);
        if (ctype_digit($value)) {
            return [(int) $value, (int) $value];
        }
        if (!preg_match('/^(\d+)\.\.(\d+)$/', $value, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /** A strict calendar day, or null when the box is blank or incomplete. */
    public static function day(mixed $value, mixed $timezone = null): ?\DateTimeImmutable
    {
        $value = self::text($value);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::timezone($timezone));

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * An inclusive calendar range encoded as `from..to`.
     *
     * Either edge may be open. A single legacy `Y-m-d` value is treated as an
     * exact-day range so old links keep their original meaning.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}|null
     */
    public static function dateRange(mixed $value, mixed $timezone = null): ?array
    {
        $value = self::text($value);
        if ($value === '') {
            return null;
        }

        if (!str_contains($value, '..')) {
            $day = self::day($value, $timezone);

            return $day === null ? null : [$day, $day];
        }

        $parts = explode('..', $value);
        if (count($parts) !== 2 || ($parts[0] === '' && $parts[1] === '')) {
            return null;
        }

        $from = $parts[0] === '' ? null : self::day($parts[0], $timezone);
        $to = $parts[1] === '' ? null : self::day($parts[1], $timezone);
        if (($parts[0] !== '' && $from === null) || ($parts[1] !== '' && $to === null)) {
            return null;
        }
        if ($from !== null && $to !== null && $from > $to) {
            return null;
        }

        return [$from, $to];
    }

    /**
     * Narrow `$field` to the inclusive calendar range `$value` names.
     *
     * Calendar days rather than instants: the column shows dates, so both range
     * edges include every row stamped anywhere within the selected day.
     * Anything malformed is ignored rather than rejected.
     */
    public static function applyDay(
        QueryBuilder $qb,
        string $field,
        string $parameter,
        mixed $value,
        mixed $timezone = null,
    ): void
    {
        $range = self::dateRange($value, $timezone);
        if ($range === null) {
            return;
        }

        [$from, $to] = $range;
        $utc = new \DateTimeZone('UTC');
        if ($from !== null) {
            $qb->andWhere(sprintf('%s >= :%sFrom', $field, $parameter))
                ->setParameter($parameter . 'From', $from->setTimezone($utc));
        }
        if ($to !== null) {
            $qb->andWhere(sprintf('%s < :%sTo', $field, $parameter))
                ->setParameter($parameter . 'To', $to->modify('+1 day')->setTimezone($utc));
        }
    }

    /** Invalid or unavailable browser zones fall back to the storage zone. */
    private static function timezone(mixed $value): \DateTimeZone
    {
        $value = self::text($value);
        if ($value !== '') {
            try {
                return new \DateTimeZone($value);
            } catch (\Exception) {
                // A forged query parameter must not turn a filter into a 500.
            }
        }

        return new \DateTimeZone('UTC');
    }

    /**
     * The stored values whose labels contain what was typed.
     *
     * Null when nothing was typed. When something was typed and no label
     * matched, `1 = 0` is added to `$qb` before null comes back: a filter for
     * "xyz" has to exclude every row, where dropping it would answer with the
     * whole unfiltered table as though every row had matched.
     *
     * More than one label can contain a short term. Returning every match is
     * what makes "any part of a label" true for inputs such as "e", rather
     * than quietly selecting whichever matching label happened to be first.
     *
     * @param array<string, string> $labelsByValue Stored value => the label the table shows.
     * @return list<string>|null
     */
    public static function matchLabels(QueryBuilder $qb, mixed $value, array $labelsByValue): ?array
    {
        $typed = mb_strtolower(self::text($value));
        if ($typed === '') {
            return null;
        }

        $matches = [];
        foreach ($labelsByValue as $candidate => $label) {
            if (str_contains(mb_strtolower($label), $typed)) {
                $matches[] = (string) $candidate;
            }
        }

        if ($matches !== []) {
            return $matches;
        }

        $qb->andWhere('1 = 0');

        return null;
    }
}
