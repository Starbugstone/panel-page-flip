<?php

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The real bus, with a switch for pretending the broker is unreachable.
 *
 * Shares are committed before the notice is queued, which buys the guarantee
 * that a mail server having a bad minute cannot cost somebody a share. It moves
 * the failure rather than removing it: between the commit and the dispatch
 * there is now a window where the rows exist and the message does not, and an
 * exception escaping from there would tell the owner the share failed while
 * they hold it. Retrying then meets its own duplicates.
 *
 * That window is only reachable by breaking the transport, so this stands in
 * for a broker that is down. Same shape as {@see SwitchableMailer}, and for the
 * same reason: a service cannot be swapped once the container has built it.
 */
final class SwitchableMessageBus implements MessageBusInterface
{
    private static bool $failing = false;

    public function __construct(private readonly MessageBusInterface $inner)
    {
    }

    /** Refuse every dispatch from here until {@see reset()}. */
    public static function failEverything(): void
    {
        self::$failing = true;
    }

    public static function reset(): void
    {
        self::$failing = false;
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if (self::$failing) {
            throw new TransportException('The queue is not answering.');
        }

        return $this->inner->dispatch($message, $stamps);
    }
}
