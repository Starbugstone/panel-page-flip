<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\ShareCodeType;
use App\Repository\ShareClaimCodeRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The permanent `U-` code somebody hands out so that others can share with them.
 *
 * This is the one place that turns a code back into a person, and it is
 * deliberately narrow about what that means. Resolving a code answers exactly
 * one question — "who should this share be addressed to?" — and hands back only
 * the public identity that person already publishes: their username, and the
 * display name beside it. It is not a login, not a lookup by email, and not a
 * way to ask whether an address has an account behind it.
 *
 * It is also one of the two enumeration surfaces sharing has, so it is built to
 * be a bad one: sixty bits of entropy, a single generic answer for every code
 * that does not resolve, and an hourly ceiling on how many a caller may try.
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
        private readonly ShareClaimCodeRepository $contentCodeRepository,
        private readonly SecurityAuditLogger $auditLogger,
        private readonly IdentifierLookupGuard $lookupGuard,
        private readonly RateLimiterFactory $sharingCodeLookupLimiter,
        private readonly RateLimiterFactory $sharingCodeRotationLimiter,
    ) {
    }

    /**
     * This account's code, issuing one if it somehow has none.
     *
     * Every account is given one when it is created and every account that
     * predates them was given one by the backfill, so the issuing path here is
     * a safety net rather than the normal route — a `U-` code is something
     * other people are expected to be able to ask for, and an account that
     * only acquires one on its owner's first visit cannot be shared with until
     * then.
     *
     * Whatever it returns is permanent from that moment. Nothing here, and
     * nothing anywhere else, replaces an existing one.
     */
    public function codeFor(User $user): string
    {
        $existing = $user->getUserCode();
        if ($existing !== '') {
            return $existing;
        }

        $candidate = $this->allocateUniqueCode();
        $user->assignUserCode($candidate);

        return $this->persistCode($user, $candidate);
    }

    /**
     * Retire this account's code and issue a new one.
     *
     * The reason user codes are safe to hand out at all is that they grant
     * nothing — but a code lives in chats, forums and group threads, which is
     * exactly where things escape from, and an identifier its owner cannot
     * retire is one they are stuck with. This is that escape hatch.
     *
     * Only the identifier changes. Every share already made through the old
     * code is a relationship, not an address, and is left exactly as it was:
     * pending invitations stay pending, accepted ones stay accepted, and nobody
     * loses a comic because the way they were first reached has been replaced.
     *
     * @param User|null $actor the administrator acting on somebody's behalf, or
     *                         null when the owner rotates their own
     *
     * @throws ShareException
     */
    public function rotateCode(User $user, ?User $actor = null): string
    {
        $this->reserveRotationAllowance($actor ?? $user);

        $hadCode = $user->getUserCode() !== '';
        $candidate = $this->allocateUniqueCode();
        $user->replaceUserCode($candidate);

        $code = $this->persistCode($user, $candidate);

        // Ids and facts only. Neither the old code nor the new one goes in the
        // record: the log would then be the one place both live in plaintext,
        // and an identifier somebody rotated *because* it leaked is the last
        // thing to write down.
        $this->auditLogger->audit(SecurityAuditLogger::SHARING_CODE_ROTATED, [
            'actor_user_id' => ($actor ?? $user)->getId(),
            'target_type' => 'user',
            'target_id' => $user->getId(),
            'code_type' => ShareCodeType::USER->value,
            'by_admin' => $actor !== null && $actor->getId() !== $user->getId(),
            'replaced_existing' => $hadCode,
        ]);

        return $code;
    }

    /**
     * A token no user code and no content code is using.
     *
     * The single place any sharing token is generated. The prefix already tells
     * the three kinds apart, so this is not what makes them unambiguous — it is
     * what keeps one visible token from meaning two things at once, which is
     * the property that makes a code safe to read aloud.
     *
     * At sixty bits a first candidate is essentially always free; the loop is
     * for the case that cannot be reasoned away rather than one worth planning
     * around.
     *
     * @throws ShareException when no free code turns up
     */
    public function allocateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $candidate = SharingCodeFormat::generate();

            $taken = $this->userRepository->findOneBy(['userCode' => $candidate]) !== null
                || $this->contentCodeRepository->existsForToken($candidate);

            if (!$taken) {
                return $candidate;
            }
        }

        throw new ShareException('A sharing code could not be issued. Please try again.', 500);
    }

    /**
     * Write the candidate, once.
     *
     * Doctrine closes the EntityManager when a flush raises a constraint
     * violation, so there is no manager left to retry through — refreshing or
     * re-reading would only fail again with a less useful error. Losing the
     * race between the check above and this write is not worth a recovery path
     * at sixty bits; the caller retries by asking again on the next request.
     *
     * @throws ShareException
     */
    private function persistCode(User $user, string $candidate): string
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new ShareException('A sharing code could not be issued. Please try again.', 500);
        }

        return $candidate;
    }

    /**
     * Who a `U-` code belongs to, or null.
     *
     * A code of the wrong class never reaches the repository: it is not a
     * failed guess at a user code, it is a comic or group code in the wrong
     * box, and the caller is told so rather than charged for it.
     *
     * The caller is charged for a lookup that finds nothing and not for one
     * that succeeds, so ordinary use never runs into the limit and grinding
     * through candidates does.
     *
     * @throws ShareException when the input is a code of the wrong kind, or the
     *                        caller has spent their allowance
     */
    public function resolve(string $input, User $caller): ?User
    {
        $parsed = SharingCodeFormat::parse($input);

        if ($parsed !== null && !$parsed->is(ShareCodeType::USER)) {
            throw new ShareException($parsed->type->misuseGuidance(), 400, ShareException::CODE_WRONG_CODE_TYPE);
        }

        // Before the query, and charged whether or not the code resolves — the
        // miss-only allowance below is the second layer, not the first. See
        // {@see IdentifierLookupGuard}.
        $this->lookupGuard->charge($caller, 'user_code');

        $recipient = $parsed === null
            ? null
            : $this->userRepository->findOneBy(['userCode' => $parsed->token]);

        if ($recipient !== null) {
            return $recipient;
        }

        $this->chargeFailedLookup($caller);

        return null;
    }

    /**
     * The public identity of an account, for whoever is about to share with it.
     *
     * Username, display name and the `U-` code they published — and nothing
     * else. Not the address, not the id, not whether the account is verified,
     * active or an administrator. Somebody about to hand over a comic is
     * entitled to know they have reached the right person, which is what a
     * unique username answers; everything past that is the account's business.
     *
     * @return array{username: string, name: string, label: string, userCode: string}
     */
    public function describe(User $recipient): array
    {
        $username = $recipient->getUsername();
        $name = $recipient->getName() ?: '';

        return [
            'username' => $username,
            'name' => $name,
            // What to print when there is room for one string. A display name
            // is not unique, so it never appears without the username beside
            // it — the whole reason usernames exist is that "Matthew" is not an
            // answer to "am I sharing with the right person?".
            'label' => $name === ''
                ? UsernamePolicy::forDisplay($username)
                : sprintf('%s (%s)', $name, UsernamePolicy::forDisplay($username)),
            'userCode' => SharingCodeFormat::forDisplay(ShareCodeType::USER, $recipient->getUserCode()),
        ];
    }

    /**
     * Bound how often a code can be replaced.
     *
     * Rotating is cheap for the account doing it and expensive for everybody
     * holding the old code, so this is not about load — it is about a script or
     * a stuck retry quietly making somebody uncontactable.
     *
     * @throws ShareException
     */
    private function reserveRotationAllowance(User $actor): void
    {
        $limit = $this->sharingCodeRotationLimiter->create((string) $actor->getId())->consume();

        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new ShareException(
            sprintf(
                'You have changed your sharing code too many times recently. Please try again in %d minute(s).',
                (int) ceil($retryAfter / 60)
            ),
            429
        );
    }

    /**
     * @throws ShareException
     */
    public function chargeFailedLookup(User $caller): void
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
