<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The allowance every identifier lookup spends before the database is asked.
 *
 * Sharing turns three kinds of externally supplied identifier into something —
 * a username into a person, a `U-` code into a person, a `C-`/`G-` code into
 * comics. Each of those had a miss-only allowance, charged after the lookup so
 * that reaching the people you know stayed free.
 *
 * On its own that ordering is an existence oracle. Once a caller exhausts the
 * miss allowance, a real identifier still resolves and an imaginary one is
 * refused with a 429 — and the difference between the two answers is the fact
 * the allowance existed to withhold. The lookup has to be unreachable, not
 * merely uncharged.
 *
 * So this runs first, charges whether or not the identifier would have
 * resolved, and refuses before any repository is touched. It is deliberately
 * loose: it is the ceiling that keeps an exhausted caller away from the
 * database, not the control that makes guessing hopeless. That remains entropy
 * for a code and {@see UsernameService}'s tighter miss-only allowance for a
 * username, both of which still apply behind this.
 */
final class IdentifierLookupGuard
{
    public function __construct(
        private readonly RateLimiterFactory $identifierLookupLimiter,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    /**
     * Spend one attempt, or refuse before the caller reaches the repository.
     *
     * @throws ShareException when the caller has no attempts left
     */
    public function charge(User $caller, string $surface): void
    {
        $limit = $this->identifierLookupLimiter->create((string) $caller->getId())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        // Reached only by an account making hundreds of lookups an hour, which
        // ordinary sharing does not do at any pace a person can type.
        $this->auditLogger->suspicious(
            SecurityAuditLogger::SHARING_CODE_ENUMERATION_ATTEMPT,
            'user:' . $caller->getId(),
            [
                'actor_user_id' => $caller->getId(),
                'target_type' => 'identifier_lookup',
                // Which surface was being worked through — never the identifier
                // that was tried.
                'surface' => $surface,
            ],
            1
        );

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        // The same wording whatever was being looked up and whether or not it
        // existed, so the refusal itself says nothing.
        throw new ShareException(
            sprintf(
                'Too many lookups. Please try again in %d minute(s).',
                (int) ceil($retryAfter / 60)
            ),
            429
        );
    }
}
