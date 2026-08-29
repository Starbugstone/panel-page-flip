<?php

namespace App\EventSubscriber;

use App\Service\ApiRateLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LoginRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApiRateLimiter $rateLimiter,
        private readonly bool $enabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['limitLogin', 20],
        ];
    }

    public function limitLogin(RequestEvent $event): void
    {
        // Local development repeatedly signs the same test accounts in from one
        // Docker IP, so this control only blocks the developer it is supposed
        // to help — which is why there is a switch at all. It is its own
        // setting rather than a reading of APP_ENV: every deployment that is
        // not a developer's laptop is reachable from the internet whatever it
        // calls its environment, and a staging host with brute-force protection
        // silently off is the case this must not produce.
        if (!$this->enabled) {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== '/api/login' || !$request->isMethod('POST')) {
            return;
        }

        $response = $this->rateLimiter->limit($request, 'login');
        if ($response) {
            $event->setResponse($response);
        }
    }
}
