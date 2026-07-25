<?php

namespace App\EventSubscriber;

use App\Service\ApiRateLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LoginRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ApiRateLimiter $rateLimiter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['limitLogin', 20],
        ];
    }

    public function limitLogin(RequestEvent $event): void
    {
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
