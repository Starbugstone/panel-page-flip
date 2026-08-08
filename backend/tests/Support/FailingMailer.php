<?php

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail server that refuses some or all of what it is given.
 *
 * With no addresses named it refuses everything — a mail server that is down.
 * Named addresses refuse only those, which is the more interesting case: one
 * stale entry in a recipient list must not silence the alert for everybody
 * after it.
 */
final class FailingMailer implements MailerInterface
{
    /** Every message it was handed, including the ones it then refused. */
    public array $attempted = [];

    /** @param list<string> $failFor addresses to refuse; empty refuses all */
    public function __construct(private readonly array $failFor = [])
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->attempted[] = $message;

        if ($this->failFor === []) {
            throw new TransportException('Connection refused.');
        }

        $recipients = $message instanceof Email
            ? array_map(static fn (object $address): string => $address->getAddress(), $message->getTo())
            : [];

        if (array_intersect($recipients, $this->failFor) !== []) {
            throw new TransportException('Mailbox unavailable.');
        }
    }
}
