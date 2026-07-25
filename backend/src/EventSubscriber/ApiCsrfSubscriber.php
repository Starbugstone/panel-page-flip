<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class ApiCsrfSubscriber implements EventSubscriberInterface
{
    private const TOKEN_ID = 'api';
    private const COOKIE_NAME = 'XSRF-TOKEN';
    private const HEADER_NAME = 'X-XSRF-TOKEN';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Security $security
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['validateToken', -10],
            KernelEvents::RESPONSE => ['setTokenCookie', 0],
        ];
    }

    public function validateToken(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->shouldValidate($request)) {
            return;
        }

        $submittedToken = (string) $request->headers->get(self::HEADER_NAME, '');
        if ($submittedToken === '' || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $submittedToken))) {
            $event->setResponse(new JsonResponse(['message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN));
        }
    }

    public function setTokenCookie(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api') || !$this->security->getUser()) {
            return;
        }

        $token = (string) $this->csrfTokenManager->getToken(self::TOKEN_ID);
        $event->getResponse()->headers->setCookie(Cookie::create(self::COOKIE_NAME)
            ->withValue($token)
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_LAX)
        );
    }

    private function shouldValidate(Request $request): bool
    {
        if (!str_starts_with($request->getPathInfo(), '/api') || $request->isMethodSafe(false)) {
            return false;
        }

        if (!$this->security->getUser()) {
            return false;
        }

        return !preg_match('#^/api/(login|register|forgot-password|reset-password|email-verification/resend)#', $request->getPathInfo());
    }
}
