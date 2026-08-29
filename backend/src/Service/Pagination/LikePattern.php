<?php

namespace App\Service\Pagination;

/**
 * The escaping rule shared by every `LIKE` search in the application.
 *
 * `%` and `_` are escaped so they match themselves: searching a comic library
 * for "50%" should find "50% Off", not every row in the table. The escape
 * character is the backslash MySQL applies to LIKE by default, so no ESCAPE
 * clause has to be threaded through every query that uses this.
 *
 * It lives in one place because getting it wrong turns operator-supplied search
 * text into a wildcard over the whole table.
 */
final class LikePattern
{
    /**
     * Below this, a substring search matches so much of the table that the
     * result is noise rather than an answer.
     */
    public const MIN_TERM_LENGTH = 2;

    public static function contains(string $term): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($term));

        return '%' . $escaped . '%';
    }
}
