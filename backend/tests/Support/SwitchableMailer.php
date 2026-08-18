<?php

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * The real mailer, with a switch for pretending the mail server is down.
 *
 * Sharing's notification guarantees are all about what happens when a send
 * fails, and the interesting cases are the ones that go through a real HTTP
 * request — a share committing while its notice does not, a resend reporting
 * its own failure. Swapping the service from inside a test is not available
 * once the container has initialised it, so the substitution is wired in
 * permanently for the test environment and toggled instead.
 *
 * Off by default, and reset by {@see reset()} between tests, so a test that
 * forgets to turn it off cannot silently break the next one.
 */
final class SwitchableMailer implements MailerInterface
{
    private static bool $failing = false;

    public function __construct(private readonly MailerInterface $inner)
    {
    }

    /** Refuse everything from here until {@see reset()}. */
    public static function failEverything(): void
    {
        self::$failing = true;
    }

    public static function reset(): void
    {
        self::$failing = false;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (self::$failing) {
            throw new TransportException('The mail server is not answering.');
        }

        $this->inner->send($message, $envelope);
    }
}
