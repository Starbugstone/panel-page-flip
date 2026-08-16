<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Comic ids out of a request body, or nothing.
 *
 * Three endpoints take the same list of ids from a hand-writable JSON body and
 * all three have to refuse the same things — a float, a string that is not a
 * number, a zero, a negative. Repeating that loop is how one of the copies ends
 * up accepting `"3abc"` after a refactor.
 */
final class ComicIdList
{
    /**
     * @param array<mixed> $raw
     *
     * @return list<int>|null null when any entry is not a positive integer
     */
    public static function parse(array $raw): ?array
    {
        $ids = [];

        foreach ($raw as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $id = (int) $value;
            } else {
                return null;
            }

            if ($id <= 0) {
                return null;
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
