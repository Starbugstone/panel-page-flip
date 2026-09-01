<?php

namespace App\Service\Pagination;

use Doctrine\ORM\QueryBuilder;

/**
 * The handling every admin table's per-column filter shares.
 *
 * Each column filter is a box an operator types into, so the value arrives as
 * an untrimmed string that may be absent, blank, or nonsense. Deciding what
 * that means once — blank is no filter, text is an escaped substring match, a
 * date is the whole of that day — keeps eight tables agreeing about it.
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
     * Narrow `$field` to the calendar day `$value` names, if it names one.
     *
     * A day rather than an instant: the column shows a date, so typing the date
     * back in has to match every row stamped anywhere within it. Anything that
     * is not a `Y-m-d` date is ignored rather than rejected — a half-typed date
     * should show the unfiltered list, not an error.
     */
    public static function applyDay(QueryBuilder $qb, string $field, string $parameter, mixed $value): void
    {
        $value = self::text($value);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            return;
        }

        $qb->andWhere(sprintf('%s >= :%sFrom', $field, $parameter))
            ->andWhere(sprintf('%s < :%sTo', $field, $parameter))
            ->setParameter($parameter . 'From', $day)
            ->setParameter($parameter . 'To', $day->modify('+1 day'));
    }

    /**
     * The stored value whose label contains what was typed.
     *
     * Null when nothing was typed. When something was typed and no label
     * matched, `1 = 0` is added to `$qb` before null comes back: a filter for
     * "xyz" has to exclude every row, where dropping it would answer with the
     * whole unfiltered table as though every row had matched.
     *
     * @param array<string, string> $labelsByValue Stored value => the label the table shows.
     */
    public static function matchLabel(QueryBuilder $qb, mixed $value, array $labelsByValue): ?string
    {
        $typed = mb_strtolower(self::text($value));
        if ($typed === '') {
            return null;
        }

        foreach ($labelsByValue as $candidate => $label) {
            if (str_contains(mb_strtolower($label), $typed)) {
                return (string) $candidate;
            }
        }

        $qb->andWhere('1 = 0');

        return null;
    }
}
