<?php

namespace App\Tests\Functional;

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * Reads back what the `security` and `audit` channels were actually given.
 *
 * The records come from real in-memory handlers attached to those channels in
 * the test environment, so everything a test sees has been through the same
 * pipeline a file handler would receive it from — the redaction processor
 * included. Asserting on a logger spy instead would prove that a caller passed
 * a token to the logger and nothing about whether it reached the disk.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait SecurityLogAssertions
{
    /** @return list<LogRecord> */
    protected function securityRecords(?string $event = null): array
    {
        return $this->recordsFrom('app.monolog.test_security_handler', $event);
    }

    /** @return list<LogRecord> */
    protected function auditRecords(?string $event = null): array
    {
        return $this->recordsFrom('app.monolog.test_audit_handler', $event);
    }

    protected function assertLoggedSecurityEvent(string $event, string $message = ''): LogRecord
    {
        $records = $this->securityRecords($event);
        self::assertNotEmpty(
            $records,
            $message ?: sprintf('Expected a "%s" record on the security channel. Saw: %s', $event, $this->eventNames())
        );

        return $records[0];
    }

    protected function assertLoggedAuditEvent(string $event, string $message = ''): LogRecord
    {
        $records = $this->auditRecords($event);
        self::assertNotEmpty(
            $records,
            $message ?: sprintf('Expected an "%s" record on the audit channel. Saw: %s', $event, $this->eventNames())
        );

        return $records[0];
    }

    protected function assertNoSecurityEvent(string $event): void
    {
        self::assertSame(
            [],
            $this->securityRecords($event),
            sprintf('Did not expect a "%s" record on the security channel.', $event)
        );
    }

    protected function assertNoAuditEvent(string $event): void
    {
        self::assertSame(
            [],
            $this->auditRecords($event),
            sprintf('Did not expect an "%s" record on the audit channel.', $event)
        );
    }

    /**
     * Nothing anywhere in either channel contains this string.
     *
     * Blunt on purpose. A test that checks one field for one secret passes
     * happily while the same secret sits in a neighbouring key that nobody
     * thought about.
     */
    protected function assertNothingLogged(string $needle, string $what): void
    {
        self::assertStringNotContainsString(
            $needle,
            $this->serialisedRecords(),
            sprintf('%s must never reach the security or audit log.', $what)
        );
    }

    /**
     * The administrator alerts this event produced during the most recent
     * request.
     *
     * Only the most recent, because the mailer's message logger is reset with
     * the rest of the container between requests. That suits these tests: the
     * question is always "did *this* request send one", which is precisely what
     * separates an alert from a throttled one.
     *
     * Queued events are skipped. With Messenger configured, one send raises two
     * `MessageEvent`s — the handover to the bus and the delivery — and counting
     * both would make every alert look like two.
     *
     * @return list<Email>
     */
    protected function alertsAbout(string $event): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $mailerEvent) {
            $message = $mailerEvent->getMessage();
            if ($mailerEvent->isQueued() || !$message instanceof Email) {
                continue;
            }

            if (str_contains((string) $message->getSubject(), $event)) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    protected function clearSecurityLog(): void
    {
        $this->handler('app.monolog.test_security_handler')->clear();
        $this->handler('app.monolog.test_audit_handler')->clear();
    }

    /** @return list<LogRecord> */
    private function recordsFrom(string $service, ?string $event): array
    {
        $records = $this->handler($service)->getRecords();

        if ($event === null) {
            return array_values($records);
        }

        return array_values(array_filter(
            $records,
            static fn (LogRecord $record): bool => $record->message === $event
        ));
    }

    private function handler(string $service): TestHandler
    {
        $handler = static::getContainer()->get($service);
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }

    private function serialisedRecords(): string
    {
        $records = array_merge($this->securityRecords(), $this->auditRecords());

        return implode("\n", array_map(
            fn (LogRecord $record): string => $record->message . ' ' . $this->describe($record->context, 0),
            $records
        ));
    }

    /**
     * A flat, printable rendering of a context.
     *
     * Objects are named rather than walked. A context may legitimately hold an
     * exception, and dumping one drags in whatever its stack frames reference —
     * which is circular, enormous, and not where a leaked secret would be
     * hiding anyway: the processor rewrites the strings, and a `Throwable` is
     * the formatter's business.
     */
    private function describe(mixed $value, int $depth): string
    {
        if ($depth > 8) {
            return '...';
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key . '=' . $this->describe($item, $depth + 1);
            }

            return '[' . implode(', ', $parts) . ']';
        }

        if ($value instanceof \Throwable) {
            return $value::class . ': ' . $value->getMessage();
        }

        if (is_object($value)) {
            return $value::class;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? 'null' : (string) $value;
    }

    private function eventNames(): string
    {
        $records = array_merge($this->securityRecords(), $this->auditRecords());
        $names = array_map(static fn (LogRecord $record): string => $record->message, $records);

        return $names === [] ? '(nothing)' : implode(', ', array_unique($names));
    }
}
