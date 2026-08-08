<?php

namespace App\Tests\Unit\Service;

use App\Service\SecurityAlertService;
use App\Service\SecurityAuditLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\ThrowingLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Observing an operation must not be able to break it.
 *
 * Everything this logger writes happens alongside something that has usually
 * already succeeded — a password changed, a role granted, an invitation sent and
 * committed. The ways it can fail are exactly the conditions worth knowing
 * about: a full disk, a log directory that lost its permissions, an unreachable
 * cache, a mail server refusing connections. If any of those escaped, a
 * completed operation would return a 500 and invite the caller to retry
 * something that already happened.
 *
 * So every method here is given a logger that throws, and every one of them has
 * to return quietly and leave the failure on the ordinary application channel.
 */
final class SecurityAuditLoggerContainmentTest extends TestCase
{
    private TestHandler $fallbackHandler;

    protected function setUp(): void
    {
        $this->fallbackHandler = new TestHandler();
    }

    public function testAnUnwritableAuditLogDoesNotReachTheCaller(): void
    {
        $this->logger()->audit(SecurityAuditLogger::USER_PASSWORD_CHANGED, ['actor_user_id' => 1]);

        $this->assertReportedOnTheFallbackChannel(SecurityAuditLogger::USER_PASSWORD_CHANGED);
    }

    public function testAnUnwritableSecurityLogDoesNotReachTheCaller(): void
    {
        $this->logger()->security(SecurityAuditLogger::AUTHENTICATION_FAILED, ['actor_user_id' => 1]);

        $this->assertReportedOnTheFallbackChannel(SecurityAuditLogger::AUTHENTICATION_FAILED);
    }

    public function testAFailingAlertCheckDoesNotReachTheCaller(): void
    {
        $alerts = $this->createMock(SecurityAlertService::class);
        $alerts->method('alertOnThreshold')->willThrowException(new \RuntimeException('cache is gone'));

        $this->logger($alerts)->suspicious(SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT, 'user:1');

        $this->assertReportedOnTheFallbackChannel(SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT);
    }

    public function testAFailingCriticalAlertDoesNotReachTheCaller(): void
    {
        $alerts = $this->createMock(SecurityAlertService::class);
        $alerts->method('alert')->willThrowException(new \RuntimeException('mail server is gone'));

        $this->logger($alerts)->critical(SecurityAuditLogger::ADMIN_ROLE_CHANGED, ['target_user_id' => 2]);

        $this->assertReportedOnTheFallbackChannel(SecurityAuditLogger::ADMIN_ROLE_CHANGED);
    }

    /**
     * The last resort. If the fallback channel is broken too there is nothing
     * left to try, and crashing the request would be the one outcome that
     * definitely helps nobody.
     */
    public function testLosingTheFallbackChannelAsWellIsStillNotFatal(): void
    {
        $logger = new SecurityAuditLogger(
            new ThrowingLogger(),
            new ThrowingLogger(),
            new ThrowingLogger(),
            $this->createMock(SecurityAlertService::class),
            new RequestStack(),
            10,
            10,
        );

        $logger->audit(SecurityAuditLogger::USER_ACCOUNT_DELETED, ['target_user_id' => 3]);

        $this->expectNotToPerformAssertions();
    }

    private function assertReportedOnTheFallbackChannel(string $event): void
    {
        self::assertTrue(
            $this->fallbackHandler->hasErrorThatContains('Failed to record a security or audit event'),
            'The failure should be reported on the ordinary application channel.'
        );

        // The event name travels with it, so the record says what was lost.
        $records = $this->fallbackHandler->getRecords();
        self::assertSame($event, $records[0]->context['event']);
    }

    private function logger(?SecurityAlertService $alerts = null): SecurityAuditLogger
    {
        return new SecurityAuditLogger(
            new ThrowingLogger(),
            new ThrowingLogger(),
            new Logger('fallback', [$this->fallbackHandler]),
            $alerts ?? $this->createMock(SecurityAlertService::class),
            new RequestStack(),
            10,
            10,
        );
    }
}

