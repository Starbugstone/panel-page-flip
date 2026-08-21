<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\ComicForbiddenException;
use App\Security\ComicNotAccessibleException;
use App\Security\UnauthenticatedException;
use App\Service\ShareException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 20]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof BadRequestHttpException) {
            $event->setResponse(new JsonResponse(['message' => $exception->getMessage()], $exception->getStatusCode()));

            return;
        }

        // The one place the API says this. Runs ahead of the security
        // listener's own 401 only because nothing reaches here unless the
        // firewall already let the request through.
        if ($exception instanceof UnauthenticatedException) {
            $event->setResponse(new JsonResponse(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNAUTHORIZED
            ));

            return;
        }

        // The single answer for a comic the caller may not have. Rendering it
        // here is what keeps "missing" and "not yours" indistinguishable: no
        // action gets the chance to be more forthcoming than its neighbours.
        if ($exception instanceof ComicNotAccessibleException) {
            $event->setResponse(new JsonResponse(
                ['message' => $exception->getMessage()],
                Response::HTTP_NOT_FOUND
            ));

            return;
        }

        // Its counterpart, for somebody who can already see the comic. Kept
        // beside it so the choice between the two stays one decision in one
        // place — see App\Security\ComicAccess.
        if ($exception instanceof ComicForbiddenException) {
            $event->setResponse(new JsonResponse(
                ['message' => $exception->getMessage()],
                Response::HTTP_FORBIDDEN
            ));

            return;
        }

        // A sharing failure already knows its own status and body — it was
        // written to be read by whoever triggered it. Rendering it here means
        // it reaches the caller intact from wherever it was raised, including
        // the services controllers call without wrapping.
        if ($exception instanceof ShareException) {
            $event->setResponse(new JsonResponse($exception->toPayload(), $exception->getStatusCode()));
        }
    }
}
