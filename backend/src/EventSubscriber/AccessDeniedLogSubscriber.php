<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\SecurityAuditLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records every refused API request, whatever refused it.
 *
 * Hooked to the response and not to the access-denied exception, because this
 * application refuses in two different ways: the firewall and the voters throw,
 * while most controllers return a 403 JSON body directly. Watching the status
 * code catches both, and cannot be bypassed by a controller that decides to
 * refuse in a new way tomorrow.
 *
 * A single 403 is ordinary — a stale tab, a link followed after a share was
 * revoked, an admin page opened by somebody who used to be one. It is logged at
 * warning and left there. What escalates is repetition from one source, and
 * admin endpoints escalate on a tighter count than the rest: a non-admin
 * probing `/api/admin` is not explained by a stale tab.
 */
final class AccessDeniedLogSubscriber implements EventSubscriberInterface
{
    /** Attempts on an admin surface, before an administrator is told. */
    private const ADMIN_PROBE_THRESHOLD = 3;

    public function __construct(
        private readonly SecurityAuditLogger $auditLogger,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the CSRF subscriber's own response handling, and after
            // anything else that may still change the status.
            KernelEvents::RESPONSE => ['logDenial', -64],
        ];
    }

    public function logDenial(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if ($response->getStatusCode() !== Response::HTTP_FORBIDDEN) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // A refused login is an authentication failure and is already recorded
        // as one, with the account-enumeration care that needs.
        if ($request->getPathInfo() === '/api/login') {
            return;
        }

        $user = $this->security->getUser();
        $actorId = $user instanceof User ? $user->getId() : null;
        $isAdminSurface = $this->isAdminSurface($request);

        $context = [
            'actor_user_id' => $actorId,
            'authenticated' => $actorId !== null,
            'path' => $request->getPathInfo(),
            'admin_surface' => $isAdminSurface,
        ];

        // Scoped to the account when there is one, and to the address when there
        // is not. Counting an authenticated prober by IP would let a shared
        // office address raise an alert about the wrong person.
        $scope = $actorId !== null ? 'user:' . $actorId : 'ip:' . $this->auditLogger->clientIp();

        if ($isAdminSurface) {
            $this->auditLogger->suspicious(
                SecurityAuditLogger::ADMIN_ACCESS_DENIED,
                $scope,
                $context,
                self::ADMIN_PROBE_THRESHOLD
            );

            return;
        }

        $this->auditLogger->suspicious(SecurityAuditLogger::AUTHORIZATION_DENIED, $scope, $context);
    }

    /**
     * Matched on the path rather than the route, because a request refused
     * before routing has no route — and a probe that never resolves to a
     * controller is exactly the kind worth counting.
     *
     * A prefix for `/api/admin`, rather than an enumeration of the endpoints
     * that exist today: a pattern listing them would quietly demote each new
     * sub-route to the ordinary threshold until somebody remembered to add it.
     *
     * `/api/users` cannot be a prefix, because it is three surfaces wearing one
     * path. The collection is administrators-only — listing accounts and
     * creating one both refuse anybody else. So is every sub-route of an
     * account, such as marking one verified. The account itself is not: every
     * user may read and update their own record.
     *
     * That middle case is why the whole prefix cannot take the tighter count. A
     * 403 on `/api/users/{someoneElse}` means an ordinary user aimed at another
     * account, which a stale link or a bookmark kept after a demotion explains
     * just as well as probing does. On the admin threshold of three, that would
     * raise a high-severity "administrator probing" alert naming them. It is
     * still recorded and still counted — on the ordinary authorization
     * threshold, where one-off refusals belong.
     */
    private function isAdminSurface(Request $request): bool
    {
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/api/admin')) {
            return true;
        }

        if ($path !== '/api/users' && !str_starts_with($path, '/api/users/')) {
            return false;
        }

        // '' is the collection, 'id' is somebody's own record, and 'id/anything'
        // is an administrator's action on it.
        $remainder = trim(substr($path, strlen('/api/users')), '/');

        return $remainder === '' || str_contains($remainder, '/');
    }
}
