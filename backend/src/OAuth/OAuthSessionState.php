<?php

declare(strict_types=1);

namespace App\OAuth;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Session-bound, short-lived state that never leaves for the browser to edit. */
final class OAuthSessionState
{
    private const FLOW_KEY = 'app.oauth.flow';
    private const PENDING_KEY = 'app.oauth.pending_registration';
    private const REAUTH_KEY = 'app.oauth.reauthenticated';
    private const FLOW_TTL = 600;
    private const PENDING_TTL = 600;
    private const REAUTH_TTL = 300;

    public function startFlow(
        SessionInterface $session,
        string $provider,
        string $mode,
        string $redirect,
        ?int $userId,
    ): void {
        $session->set(self::FLOW_KEY, [
            'provider' => $provider,
            'mode' => $mode,
            'redirect' => $redirect,
            'userId' => $userId,
            'expiresAt' => time() + self::FLOW_TTL,
        ]);
    }

    /** @return array{provider: string, mode: string, redirect: string, userId: int|null, expiresAt: int}|null */
    public function consumeFlow(SessionInterface $session, string $provider): ?array
    {
        $flow = $session->remove(self::FLOW_KEY);
        if (!is_array($flow)
            || ($flow['provider'] ?? null) !== $provider
            || !in_array($flow['mode'] ?? null, ['login', 'connect', 'reauth'], true)
            || !is_string($flow['redirect'] ?? null)
            || !is_int($flow['expiresAt'] ?? null)
            || $flow['expiresAt'] < time()
            || (!is_int($flow['userId'] ?? null) && ($flow['userId'] ?? null) !== null)) {
            return null;
        }

        /** @var array{provider: string, mode: string, redirect: string, userId: int|null, expiresAt: int} $flow */
        return $flow;
    }

    public function clearFlow(SessionInterface $session): void
    {
        $session->remove(self::FLOW_KEY);
    }

    public function storePending(SessionInterface $session, OAuthProfile $profile, string $redirect): void
    {
        $session->set(self::PENDING_KEY, [
            'provider' => $profile->provider,
            'subject' => $profile->subject,
            'email' => $profile->email,
            'name' => $profile->name,
            'emailVerified' => $profile->emailVerified,
            'emailAuthoritative' => $profile->emailAuthoritative,
            'redirect' => $redirect,
            'expiresAt' => time() + self::PENDING_TTL,
        ]);
    }

    /**
     * @return array{provider: string, subject: string, email: string, name: string|null, emailVerified: bool, emailAuthoritative: bool, redirect: string, expiresAt: int}|null
     */
    public function pending(SessionInterface $session): ?array
    {
        $pending = $session->get(self::PENDING_KEY);
        if (!is_array($pending)
            || !is_string($pending['provider'] ?? null)
            || !is_string($pending['subject'] ?? null)
            || !is_string($pending['email'] ?? null)
            || (!is_string($pending['name'] ?? null) && ($pending['name'] ?? null) !== null)
            || !is_bool($pending['emailVerified'] ?? null)
            || !is_bool($pending['emailAuthoritative'] ?? null)
            || !is_string($pending['redirect'] ?? null)
            || !is_int($pending['expiresAt'] ?? null)
            || $pending['expiresAt'] < time()) {
            $session->remove(self::PENDING_KEY);

            return null;
        }

        /** @var array{provider: string, subject: string, email: string, name: string|null, emailVerified: bool, emailAuthoritative: bool, redirect: string, expiresAt: int} $pending */
        return $pending;
    }

    public function clearPending(SessionInterface $session): void
    {
        $session->remove(self::PENDING_KEY);
    }

    public function markReauthenticated(SessionInterface $session, int $userId, string $provider): void
    {
        $session->set(self::REAUTH_KEY, [
            'userId' => $userId,
            'provider' => $provider,
            'expiresAt' => time() + self::REAUTH_TTL,
        ]);
    }

    public function consumeRecentReauthentication(SessionInterface $session, int $userId): bool
    {
        $marker = $session->remove(self::REAUTH_KEY);

        return is_array($marker)
            && ($marker['userId'] ?? null) === $userId
            && is_string($marker['provider'] ?? null)
            && is_int($marker['expiresAt'] ?? null)
            && $marker['expiresAt'] >= time();
    }
}
