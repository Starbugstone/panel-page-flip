<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much a candidate looks like the comic it was found for.
 *
 * Advisory only. Nothing in the application applies a candidate on the strength
 * of its confidence — the ranking exists so the person choosing sees the likely
 * answer first, not so the choice can be skipped.
 */
enum MatchConfidence: string
{
    /** Series and issue number both agree. */
    case Exact = 'exact';

    /** The series agrees and nothing contradicts it. */
    case High = 'high';

    /** Plausibly the same series, but the name is not the same name. */
    case Ambiguous = 'ambiguous';

    /** Came back from the search and little else recommends it. */
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::Exact => 0,
            self::High => 1,
            self::Ambiguous => 2,
            self::Low => 3,
        };
    }
}
