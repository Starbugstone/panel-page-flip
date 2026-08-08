<?php

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail server that hangs for longer than the cooldown it was sent under.
 *
 * Sending happens outside the alert lock, so a transport that blocks can outlive
 * the window its caller claimed. This reproduces that without waiting: handed a
 * message, it runs whatever the test says happened meanwhile — the claim
 * expiring, another request taking a fresh one — and only then refuses, so the
 * original caller reaches its release path holding a claim somebody else has
 * since replaced.
 */
final class InterferingMailer implements MailerInterface
{
    private bool $interfered = false;

    /** @param callable(): void $meanwhile what happened while this send was blocked */
    public function __construct(private $meanwhile)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        // Once only. The interference itself sends a message, and that send must
        // not recurse back into this.
        if (!$this->interfered) {
            $this->interfered = true;
            ($this->meanwhile)();
        }

        throw new TransportException('Connection timed out.');
    }
}
