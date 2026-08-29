<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Work that has to happen, but not while the caller is waiting for a response.
 *
 * Mail goes through the transport inline here: `SendEmailMessage` is
 * deliberately not routed to the async transport, because a queued message on
 * an installation with no worker running is never delivered at all. The cost is
 * that every send becomes part of the response time of whatever triggered it —
 * two SMTP round trips on a public, unauthenticated endpoint, in the case this
 * was written for.
 *
 * Deferring to `kernel.terminate` keeps delivery in this same process, with the
 * same failure handling, but after the response has already reached the client.
 *
 * The limit is worth knowing: `kernel.terminate` fires for HTTP requests only.
 * Work deferred from a console command or a message handler would never run, so
 * only defer from a controller's request path.
 */
final class DeferredWork implements EventSubscriberInterface
{
    /** @var list<callable(): void> */
    private array $pending = [];

    public function defer(callable $work): void
    {
        $this->pending[] = $work;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $work) {
            $work();
        }
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }
}
