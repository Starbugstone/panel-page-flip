<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Usernames: suggesting them, taking them, and turning one back into a person.
 *
 * Sharing needs a public identity, and this is the thing that hands one out and
 * looks one up. Both halves are enumeration surfaces — a username is short,
 * memorable and chosen, which is the opposite of the sixty random bits behind a
 * `U-` code — so both are rate limited, and the rate limit is the control that
 * actually does the work here rather than entropy.
 *
 * There is deliberately no search. Resolution is exact, one name at a time,
 * charged for every miss: knowing somebody's username is how you reach them,
 * and there is no way to ask this service who exists.
 */
final class UsernameService
{
    /**
     * How many misses a caller may accumulate in the limiter's window.
     *
     * Sized for somebody mistyping a friend's handle, not for somebody working
     * through a word list. Successful resolutions are free, so the person
     * sharing with the same two people every week never meets it.
     */
    public const LOOKUP_ATTEMPTS = 30;

    /**
     * How hard to try before falling back to a wider suffix.
     *
     * The four-digit space is roughly 3,600 adjective/noun pairs times ten
     * thousand, so a collision is already unlikely; five tries is for the
     * installation large enough that "unlikely" happens, and the fallback is
     * for the one after that.
     */
    private const SUGGESTION_ATTEMPTS = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UsernameGenerator $generator,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly IdentifierLookupGuard $lookupGuard,
        private readonly RateLimiterFactory $usernameLookupLimiter,
        private readonly RateLimiterFactory $usernameChangeLimiter,
    ) {
    }

    /**
     * A username nobody currently holds.
     *
     * A suggestion and nothing more: it is checked against the table because an
     * offer that is already taken wastes the person's time, not because that
     * check is what keeps names unique. The unique index does that, and it is
     * still the authority when two registrations pick the same name in the same
     * second.
     */
    public function suggest(): string
    {
        for ($attempt = 0; $attempt < self::SUGGESTION_ATTEMPTS; ++$attempt) {
            $candidate = $this->generator->generate();

            if ($this->isAvailable($candidate)) {
                return $candidate;
            }
        }

        // Every ordinary candidate was taken, which on a real installation
        // means the four-digit space is crowded rather than that anything is
        // wrong. Widening the suffix costs nothing and is still a name a person
        // would accept.
        return $this->generator->generate(8);
    }

    /** Whether this username is both legal and free. */
    public function isAvailable(string $username): bool
    {
        if (!UsernamePolicy::isValid($username)) {
            return false;
        }

        return $this->findByUsername($username) === null;
    }

    /**
     * The account holding this username, without spending anybody's allowance.
     *
     * The unauthenticated internals; {@see resolve()} is the one callers reach
     * for when a person typed the name.
     */
    public function findByUsername(string $username): ?User
    {
        $canonical = UsernamePolicy::canonicalise($username);

        return $canonical === ''
            ? null
            : $this->userRepository->findOneBy(['usernameCanonical' => $canonical]);
    }

    /**
     * Who this username belongs to, or null.
     *
     * The caller is charged for a lookup that finds nothing and not for one that
     * succeeds, so sharing with the people you know never runs into the limit
     * and working through a word list does.
     *
     * @throws ShareException when the caller has spent their allowance
     */
    public function resolve(string $username, User $caller): ?User
    {
        // Before the query, and charged whether or not the name exists. The
        // miss-only allowance below cannot come first: once it is spent, a real
        // username still resolving while an imaginary one is refused *is* the
        // oracle. See {@see IdentifierLookupGuard}.
        $this->lookupGuard->charge($caller, 'username');

        $recipient = $this->findByUsername($username);

        if ($recipient !== null) {
            return $recipient;
        }

        $this->chargeFailedLookup($caller);

        return null;
    }

    /**
     * Give a new account its username.
     *
     * Separate from {@see change()} because registration has no previous name
     * to audit against and no churn to rate limit — the account does not exist
     * yet. It does not flush: the caller is mid-registration and owns the unit
     * of work that the user row belongs to.
     *
     * @throws ShareException when the name is illegal or already held
     */
    public function assign(User $user, string $username): void
    {
        $this->assertUsable($username);

        $user->setUsername($username);
    }

    /**
     * Replace an existing username.
     *
     * Rate limited and audited, both for the same reason: a handle that other
     * people have written down is an identity, and one that can be swapped
     * freely is one that can be swapped *into* — taking a name somebody has
     * just vacated is the cheapest impersonation there is. The limit makes
     * churn slow and the record makes it visible.
     *
     * @throws ShareException
     */
    public function change(User $user, string $username): void
    {
        $this->reserveChangeAllowance($user);
        $this->assertUsable($username, $user);

        $previous = $user->getUsername();
        $user->setUsername($username);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The index refused a name that was free when it was checked.
            // Doctrine closes the manager on this, so there is nothing left to
            // retry through; the caller asks again with a different name.
            throw new ShareException('That username was taken a moment ago. Please choose another.', 409);
        }

        $this->auditLogger->audit(SecurityAuditLogger::USERNAME_CHANGED, [
            'actor_user_id' => $user->getId(),
            'target_type' => 'user',
            'target_id' => $user->getId(),
            // Both names, because this record exists precisely to answer "who
            // used to be called that?" after somebody complains. Neither is a
            // secret: they are the account's public identity by definition.
            'previous_username' => $previous,
            'username' => $user->getUsername(),
        ]);
    }

    /**
     * @param User|null $except the account already holding the name, for whom
     *                          re-taking their own name is not a collision
     *
     * @throws ShareException
     */
    private function assertUsable(string $username, ?User $except = null): void
    {
        $problem = UsernamePolicy::validate($username);
        if ($problem !== null) {
            throw new ShareException($problem, 400);
        }

        $holder = $this->findByUsername($username);
        if ($holder !== null && $holder->getId() !== $except?->getId()) {
            throw new ShareException('That username is already taken. Please choose another.', 409);
        }
    }

    /**
     * @throws ShareException
     */
    private function reserveChangeAllowance(User $user): void
    {
        $limit = $this->usernameChangeLimiter->create((string) $user->getId())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        throw ShareException::rateLimited('You have changed your username too many times recently.', $limit);
    }

    /**
     * @throws ShareException
     */
    private function chargeFailedLookup(User $caller): void
    {
        $limit = $this->usernameLookupLimiter->create((string) $caller->getId())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        // Ordinary use does not reach this. An account that exhausts the
        // allowance is guessing at handles, which is the one thing an exact
        // lookup with no directory behind it exists to be bad at.
        $this->auditLogger->suspicious(
            SecurityAuditLogger::USERNAME_ENUMERATION_ATTEMPT,
            'user:' . $caller->getId(),
            [
                'actor_user_id' => $caller->getId(),
                'target_type' => 'username',
            ],
            1
        );

        throw ShareException::rateLimited('Too many usernames have been looked up.', $limit);
    }
}
