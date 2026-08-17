<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonRequestDecoder;
use App\Service\UsernamePolicy;
use App\Service\UsernameService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Picking a username, and turning one back into a person.
 *
 * Two of these are open to anybody, because registration needs them before an
 * account exists: a suggestion, and a check on whether a name is free. Neither
 * reveals anything an attempted registration would not — "taken" is the answer
 * signing up with that name would give a second later — and both are bounded by
 * the registration limiter that fronts the endpoint they exist to serve.
 *
 * Resolution is different and needs an account, because it is the one that
 * turns a guess into a person. It is exact, one name per request, and charged
 * for every miss.
 */
final class UsernameController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(
        private readonly UsernameService $usernames,
        private readonly \App\Service\ApiRateLimiter $rateLimiter,
    ) {
    }

    /**
     * A username nobody holds, for the registration form to offer.
     *
     * A suggestion, not a reservation: nothing is held for the caller, and the
     * unique index is still the authority when two people accept the same
     * suggestion in the same second.
     */
    #[Route('/api/users/username-suggestion', name: 'api_username_suggestion', methods: ['GET'])]
    public function suggestion(Request $request): JsonResponse
    {
        if ($limited = $this->rateLimiter->limit($request, 'username_lookup')) {
            return $limited;
        }

        return $this->json(['username' => $this->usernames->suggest()]);
    }

    /**
     * Whether a username can be taken, and why not when it cannot.
     *
     * The message matters as much as the boolean: "between 3 and 32 characters"
     * is a fixable answer where "unavailable" is a guess-again.
     *
     * Open to anybody, because the registration form needs it before an account
     * exists, and bounded per IP because it is the one place an unauthenticated
     * caller can ask whether a name is in use. It reveals nothing an attempted
     * registration would not — "taken" is the answer signing up would give a
     * second later — but a caller who can ask it ten thousand times an hour has
     * a directory, and a caller who can ask it thirty times does not.
     */
    #[Route('/api/users/username-available', name: 'api_username_available', methods: ['GET'])]
    public function available(Request $request): JsonResponse
    {
        if ($limited = $this->rateLimiter->limit($request, 'username_lookup')) {
            return $limited;
        }

        $username = (string) $request->query->get('username', '');
        $problem = UsernamePolicy::validate($username);

        if ($problem !== null) {
            return $this->json(['available' => false, 'message' => $problem]);
        }

        $free = $this->usernames->isAvailable($username);

        return $this->json([
            'available' => $free,
            'message' => $free ? null : 'That username is already taken.',
        ]);
    }

    /**
     * Who holds this username, so a sender can check before sharing.
     *
     * A POST because it is rate limited and writes to that allowance. It
     * answers with the public identity of the account and nothing else — never
     * an address, an id, or whether the account is verified.
     */
    #[Route('/api/users/resolve-username', name: 'api_username_resolve', methods: ['POST'])]
    public function resolve(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $username = JsonRequestDecoder::decode($request)['username'] ?? null;

        if (!is_string($username)) {
            return $this->json(['message' => 'A username is required.'], Response::HTTP_BAD_REQUEST);
        }

        $recipient = $this->usernames->resolve(UsernamePolicy::stripPrefix($username), $user);

        if ($recipient === null) {
            return $this->json(['message' => 'No account has that username.'], Response::HTTP_NOT_FOUND);
        }

        if ($recipient->getId() === $user->getId()) {
            return $this->json(['message' => 'That is your own username.'], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'recipient' => [
                'username' => $recipient->getUsername(),
                'name' => $recipient->getName() ?: '',
                'label' => UsernamePolicy::describe($recipient->getUsername(), $recipient->getName()),
            ],
        ]);
    }

    /**
     * Change your own username.
     *
     * Rate limited and audited by the service, for the same reason: a handle
     * other people have written down is an identity, and one that can be
     * swapped freely can be swapped *into*.
     */
    #[Route('/api/users/username', name: 'api_username_change', methods: ['PUT'])]
    public function change(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        $username = JsonRequestDecoder::decode($request)['username'] ?? null;

        if (!is_string($username)) {
            return $this->json(['message' => 'A username is required.'], Response::HTTP_BAD_REQUEST);
        }

        $this->usernames->change($user, UsernamePolicy::stripPrefix($username));

        return $this->json([
            'message' => 'Your username has been changed.',
            'username' => $user->getUsername(),
        ]);
    }
}
