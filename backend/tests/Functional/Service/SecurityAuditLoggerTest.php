<?php

namespace App\Tests\Functional\Service;

use App\Monolog\SensitiveDataProcessor;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Monolog\Level;

/**
 * The wiring itself: two channels that stay apart, and a redaction step that
 * nothing has to remember to call.
 *
 * These go through the container rather than a hand-built logger, because the
 * thing being checked is the configuration. A processor that works perfectly
 * but is attached to the wrong handler protects nothing, and a channel split
 * that exists only in a YAML comment is not a split.
 */
final class SecurityAuditLoggerTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    public function testAuditAndSecurityEventsGoToDifferentChannels(): void
    {
        $logger = $this->logger();

        $logger->audit('audit.test.event', ['target_id' => 1]);
        $logger->security('security.test.event', ['target_id' => 2]);

        self::assertCount(1, $this->auditRecords('audit.test.event'));
        self::assertCount(1, $this->securityRecords('security.test.event'));

        // Neither leaks into the other. An audit trail read through a stream of
        // refusals is not an audit trail.
        self::assertSame([], $this->securityRecords('audit.test.event'));
        self::assertSame([], $this->auditRecords('security.test.event'));
    }

    public function testEveryRecordCarriesTheSameShape(): void
    {
        $this->logger()->audit('audit.test.event', ['actor_user_id' => 11, 'target_type' => 'comic']);

        $record = $this->assertLoggedAuditEvent('audit.test.event');

        self::assertSame('audit.test.event', $record->context['event']);
        self::assertSame(SecurityAuditLogger::RESULT_SUCCESS, $record->context['result']);
        self::assertSame(11, $record->context['actor_user_id']);
        self::assertSame('comic', $record->context['target_type']);
        self::assertNotEmpty($record->context['occurred_at']);
    }

    /**
     * The moment is the server's account of when something happened. A caller
     * that could choose it — or a request body that could reach it — would make
     * the record worthless as evidence, which is the one thing it is for.
     */
    public function testTheEventNameResultAndTimestampCannotBeOverriddenByACaller(): void
    {
        $this->logger()->security(
            'security.test.event',
            [
                'event' => 'something.else',
                'result' => 'success',
                'occurred_at' => '1999-01-01T00:00:00+00:00',
            ],
            result: SecurityAuditLogger::RESULT_DENIED
        );

        $record = $this->assertLoggedSecurityEvent('security.test.event');

        self::assertSame('security.test.event', $record->context['event']);
        self::assertSame(SecurityAuditLogger::RESULT_DENIED, $record->context['result']);
        self::assertNotSame('1999-01-01T00:00:00+00:00', $record->context['occurred_at']);
    }

    public function testRedactionHappensInThePipelineNotInTheCaller(): void
    {
        // Written the careless way on purpose: a caller that hands the logger a
        // whole payload is exactly the case the processor exists for.
        $this->logger()->security('security.test.event', [
            'submitted' => [
                'email' => 'someone@test.local',
                'password' => 'PlaintextPassword!1',
                'api_key' => 'a-third-party-key',
            ],
            'target_id' => 5,
        ]);

        $record = $this->assertLoggedSecurityEvent('security.test.event');

        self::assertSame(SensitiveDataProcessor::REDACTED, $record->context['submitted']['password']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $record->context['submitted']['api_key']);
        self::assertSame('someone@test.local', $record->context['submitted']['email']);
        self::assertSame(5, $record->context['target_id']);
    }

    public function testACriticalEventIsRecordedAtCriticalLevel(): void
    {
        $this->logger()->critical('security.test.critical', ['target_id' => 3]);

        $record = $this->assertLoggedSecurityEvent('security.test.critical');
        self::assertSame(Level::Critical, $record->level);
    }

    /**
     * Reading a comic, turning a page and fetching a cover are the highest
     * volume things this application does. None of them is a security event,
     * and logging them would build a behavioural profile with a retention
     * period attached.
     */
    public function testReadingAComicIsNotAnAuditEvent(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reader@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $this->clearSecurityLog();

        $this->getJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();
        $this->getJson('/api/comics');
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->auditRecords());
        self::assertSame([], $this->securityRecords());
    }

    private function logger(): SecurityAuditLogger
    {
        $logger = static::getContainer()->get(SecurityAuditLogger::class);
        self::assertInstanceOf(SecurityAuditLogger::class, $logger);

        return $logger;
    }
}
