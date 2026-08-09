<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The permanent code somebody hands out so that others can share with them.
 *
 * This is the one place that turns a code back into a person, and it is
 * deliberately narrow about what that means. Resolving a code answers exactly
 * one question — "who should this invitation be addressed to?" — and hands back
 * only the display name that person publishes along with the code itself. It is
 * not a login, not a lookup by email, and not a way to ask whether an address
 * has an account behind it.
 *
 * It is also the only enumeration surface the sharing feature has, so it is
 * built to be a bad one: sixty bits of entropy, a single generic answer for
 * every code that does not resolve, and an hourly ceiling on how many a caller
 * may try.
 */
final class SharingCodeService
{
    /**
     * How many failed lookups an account may make in the limiter's window.
     *
     * Sized for somebody mistyping a code a few times, not for somebody working
     * through the keyspace. At sixty bits, an attacker allowed this many
     * guesses an hour is still not finishing before the heat death of anything.
     */
    public const LOOKUP_ATTEMPTS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly RateLimiterFactory $sharingCodeLookupLimiter,
    ) {
    }

    /**
     * This account's code, issuing one the first time it is asked for.
     *
     * Lazy rather than backfilled by the migration: filling in every existing
     * account at once means generating a unique value per row inside a
     * migration that runs against a live installation, and there is no need —
     * a code that nobody has asked for is a code nobody is holding.
     *
     * Whatever it returns is permanent from that moment. Nothing here, and
     * nothing anywhere else, replaces an existing one.
     */
    public function codeFor(User $user): string
    {
        $existing = $user->getSharingCode();
        if ($existing !== null) {
            return $existing;
        }

        // Candidates are checked against the index before one is kept, and the
        // search for a free one is where the retrying belongs. The write itself
        // is attempted once: Doctrine closes the EntityManager when a flush
        // raises a constraint violation, so there is no manager left to retry
        // with — refreshing or re-reading through it would only fail again with
        // a less useful error. At sixty bits, losing the race between the check
        // and the write is not something worth building a recovery path for;
        // the caller retries by asking again on the next request.
        $candidate = null;
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $generated = SharingCodeFormat::generate();

            if ($this->userRepository->findOneBy(['sharingCode' => $generated]) === null) {
                $candidate = $generated;
                break;
            }
        }

        if ($candidate === null) {
            throw new ShareException('A sharing code could not be issued. Please try again.', 500);
        }

        $user->assignSharingCode($candidate);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new ShareException('A sharing code could not be issued. Please try again.', 500);
        }

        return $candidate;
    }

    /**
     * Who a code belongs to, or null.
     *
     * The caller is charged for a lookup that finds nothing and not for one that
     * succeeds, so ordinary use never runs into the limit and grinding through
     * candidates does.
     *
     * @throws ShareException when the caller has spent their allowance
     */
    public function resolve(string $input, User $caller): ?User
    {
        $normalised = SharingCodeFormat::normalise($input);

        $recipient = $normalised === ''
            ? null
            : $this->userRepository->findOneBy(['sharingCode' => $normalised]);

        if ($recipient !== null) {
            return $recipient;
        }

        $this->chargeFailedLookup($caller);

        return null;
    }

    /**
     * What a sender is shown once a code resolves.
     *
     * The display name and the code they already typed, and nothing else — not
     * the address, not the id, not whether the account is verified, active or
     * an administrator. Somebody holding a code is entitled to know they have
     * reached the right person, which is what a name answers; everything past
     * that is the account's business.
     *
     * @return array{name: string, sharingCode: string}
     */
    public function describe(User $recipient): array
    {
        return [
            'name' => $recipient->getName() ?: 'A Panel Page Flip reader',
            'sharingCode' => SharingCodeFormat::forDisplay((string) $recipient->getSharingCode()),
        ];
    }

    /**
     * @throws ShareException
     */
    private function chargeFailedLookup(User $caller): void
    {
        $limit = $this->sharingCodeLookupLimiter->create((string) $caller->getId())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        // Ordinary use does not reach this: a code is copied and pasted, and
        // the few that are mistyped are corrected on the next try. An account
        // that exhausts the allowance is working through candidates, which is
        // the one thing this surface exists to be bad at.
        $this->auditLogger->suspicious(
            SecurityAuditLogger::SHARING_CODE_ENUMERATION_ATTEMPT,
            'user:' . $caller->getId(),
            [
                'actor_user_id' => $caller->getId(),
                'target_type' => 'sharing_code',
            ],
            1
        );

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new ShareException(
            sprintf(
                'Too many sharing codes have been tried. Please try again in %d minute(s).',
                (int) ceil($retryAfter / 60)
            ),
            429
        );
    }
}
