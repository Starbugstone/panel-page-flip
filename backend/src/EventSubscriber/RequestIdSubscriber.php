<?php

namespace App\EventSubscriber;

use App\Service\SecurityAuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives every request an id, so the records it produced can be found together.
 *
 * A security event on its own says what happened; the same id on the audit
 * entry, the application error and the response the client got says what
 * happened around it. Returned in a header as well, so a user reporting a
 * problem can quote something that finds their request without them having to
 * describe it.
 *
 * Generated rather than taken from the client. An inbound `X-Request-Id` is
 * attacker-controlled, and correlation ids that a caller chooses can be reused
 * to make two unrelated incidents look like one.
 */
final class RequestIdSubscriber implements EventSubscriberInterface
{
    private const HEADER = 'X-Request-Id';

    public static function getSubscribedEvents(): array
    {
        return [
            // Before anything that logs, which includes the rate limiter at 20.
            KernelEvents::REQUEST => ['assignId', 512],
            KernelEvents::RESPONSE => ['exposeId', 0],
        ];
    }

    public function assignId(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(
            SecurityAuditLogger::REQUEST_ID_ATTRIBUTE,
            bin2hex(random_bytes(8))
        );
    }

    public function exposeId(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(SecurityAuditLogger::REQUEST_ID_ATTRIBUTE);
        if (is_string($requestId)) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }
}
