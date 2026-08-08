<?php

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** Keeps what it was asked to send, and sends nothing. */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->messages[] = $message;
        }
    }
}
