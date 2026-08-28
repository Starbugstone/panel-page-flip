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
        private readonly string $environment,
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
        // Local development repeatedly signs the same test accounts in from
        // one Docker IP, so a production brute-force control only blocks the
        // developer it is supposed to help. Keep the protection at the HTTP
        // boundary in production and do not consume its bucket elsewhere.
        if ('prod' !== $this->environment) {
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
