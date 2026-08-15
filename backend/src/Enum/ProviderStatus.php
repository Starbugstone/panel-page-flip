<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a provider lookup produced what it did.
 *
 * An empty candidate list is not one answer but six, and they call for
 * different things from whoever is looking: a genuine miss means try a
 * different search, an unconfigured provider means go and configure it, and a
 * paused one means come back later. Collapsing them into an empty array is
 * safe but tells the user nothing.
 */
enum ProviderStatus: string
{
    /** The provider answered. There may or may not be candidates. */
    case Ok = 'ok';

    /** Nobody has given this provider credentials. */
    case Unconfigured = 'unconfigured';

    /** Switched off, by the environment or by an administrator. */
    case Disabled = 'disabled';

    /** This user may not spend external metadata allowance. */
    case Forbidden = 'forbidden';

    /** The credentials were refused. */
    case Unauthorized = 'unauthorized';

    /** The provider asked us to slow down, or our own ceiling was reached. */
    case RateLimited = 'rate_limited';

    /** Temporarily held off after repeated failures. */
    case Paused = 'paused';

    /** The service could not be reached. */
    case Unreachable = 'unreachable';

    /** It answered, but with something we could not use. */
    case Failed = 'failed';
}
