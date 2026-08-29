<?php

namespace App\Tests\Functional\Service;

use App\Repository\UserRepository;
use App\Service\PublicUrl;
use App\Service\SecurityAlertService;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\FailingMailer;
use App\Tests\Support\InterferingMailer;
use App\Tests\Support\RecordingMailer;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The decision to send, and everything that has to be true before one goes out.
 *
 * The service is built by hand here rather than pulled from the container,
 * because the interesting cases are the configurations a running instance is
 * not in: alerts turned off for a maintenance window, no recipients configured,
 * a mail server that is refusing connections. Each of those has to fail in a
 * specific way, and none of them may take the operation that reported the event
 * down with it.
 */
final class SecurityAlertServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /** @see \App\Tests\Unit\Monolog\SensitiveDataProcessorTest::FAKE_CREDENTIAL */
    private const NOT_A_REAL_SECRET = 'EXAMPLE-NOT-A-REAL-CREDENTIAL';

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->logHandler = new TestHandler();
    }

    public function testSendsToEveryConfiguredRecipientSeparately(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer, recipients: 'first@ops.test, second@ops.test');

        self::assertTrue($service->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));

        self::assertCount(2, $mailer->messages);
        // One message each. A shared To: header would publish the list of
        // people who administer the instance to all of them, and a security
        // notification is the wrong place to do that.
        self::assertSame('first@ops.test', $mailer->messages[0]->getTo()[0]->getAddress());
        self::assertCount(1, $mailer->messages[0]->getTo());
        self::assertSame('second@ops.test', $mailer->messages[1]->getTo()[0]->getAddress());
    }

    /**
     * The fallback exists so an instance that forgot to configure recipients is
     * not silently unreachable. It is a fallback and not the default: an
     * administrator account may be the very thing that has been taken over.
     */
    public function testFallsBackToVerifiedAdministratorAccountsWhenNoneAreConfigured(): void
    {
        UserFactory::new()->admin()->create(['email' => 'reachable-admin@test.local', 'isEmailVerified' => true]);
        UserFactory::new()->admin()->create(['email' => 'unverified-admin@test.local', 'isEmailVerified' => false]);
        UserFactory::createOne(['email' => 'ordinary@test.local']);

        $mailer = new RecordingMailer();
        $service = $this->service($mailer, recipients: '');

        $service->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL);

        self::assertCount(1, $mailer->messages);
        self::assertSame('reachable-admin@test.local', $mailer->messages[0]->getTo()[0]->getAddress());
    }

    public function testTheDisableSwitchSuppressesMailWithoutSuppressingTheRecord(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer, enabled: false);

        self::assertFalse($service->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertSame([], $mailer->messages);

        // Not silence: "why did nobody get told" has a findable answer, and the
        // event itself is on the security channel regardless.
        self::assertTrue($this->logHandler->hasInfoThatContains('alerts are disabled'));
    }

    public function testTheSameEventIsSentOncePerWindow(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        self::assertTrue($service->alert('security.share.adult_gate_bypass_attempt', 'high', [], 'user:7'));
        self::assertFalse($service->alert('security.share.adult_gate_bypass_attempt', 'high', [], 'user:7'));
        self::assertFalse($service->alert('security.share.adult_gate_bypass_attempt', 'high', [], 'user:7'));

        self::assertCount(1, $mailer->messages);
    }

    /**
     * The cooldown is per source, not global. One noisy client must not be able
     * to hide a second one behind its own cooldown.
     */
    public function testADifferentSourceIsNotThrottledByAnotherOne(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->alert('security.authorization.denied', 'high', [], 'user:1');
        $service->alert('security.authorization.denied', 'high', [], 'user:2');

        self::assertCount(2, $mailer->messages);
    }

    public function testAThresholdCountsOccurrencesAndReportsTheTotal(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        for ($occurrence = 1; $occurrence <= 4; ++$occurrence) {
            $sent = $service->alertOnThreshold('security.authentication.failed', 'ip:203.0.113.5', 3, 'high');
            self::assertSame($occurrence === 3, $sent, sprintf('Occurrence %d', $occurrence));
        }

        self::assertCount(1, $mailer->messages);
        self::assertStringContainsString('3 occurrences', $mailer->messages[0]->getHtmlBody());
    }

    public function testTheAlertBodyCarriesIdentifiersAndNoSecrets(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer);

        $service->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL, [
            'actor_user_id' => 3,
            'target_user_id' => 9,
            'roles_after' => ['ROLE_ADMIN', 'ROLE_USER'],
            // Everything below is the sort of thing a caller might attach to
            // the log record. None of it belongs in an inbox. The values are
            // readable phrases rather than convincing-looking tokens: a test
            // suite that trips a secret scanner on every run teaches people to
            // dismiss its findings, which is how a real leak gets waved through.
            'password' => self::NOT_A_REAL_SECRET,
            'access_token' => 'sl.' . self::NOT_A_REAL_SECRET,
            'comic_title' => 'An Explicit Title',
            'invitation_token' => self::NOT_A_REAL_SECRET,
        ]);

        $body = $mailer->messages[0]->getHtmlBody();

        self::assertStringContainsString('security.admin_role.changed', $body);
        self::assertStringContainsString('ROLE_ADMIN', $body);
        self::assertStringNotContainsString(self::NOT_A_REAL_SECRET, $body);
        self::assertStringNotContainsString('An Explicit Title', $body);
    }

    public function testAlertExplainsHowToFindTheSecurityRecordAndLinksSeparateAuditHistory(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer, siteName: 'Configured Reader Name');

        $service->alert('security.authorization.denied', SecurityAlertService::SEVERITY_HIGH, [
            'request_id' => 'request-example-123',
        ]);

        $body = $mailer->messages[0]->getHtmlBody();
        self::assertStringContainsString('Configured Reader Name', $body);
        self::assertStringNotContainsString('Comic Reader', $body);
        self::assertStringContainsString('request-example-123', $body);
        self::assertStringContainsString('search the security log', $body);
        self::assertStringContainsString('https://example.test/admin?tab=audit', $body);
        self::assertStringContainsString('Separately, administrator audit history', $body);
    }

    /**
     * The password really was changed, the role really was granted. A mail
     * server being down is not a reason to pretend otherwise, and an exception
     * escaping here would roll back an operation that had already succeeded.
     */
    public function testAFailedSendIsLoggedAndNotThrown(): void
    {
        $service = $this->service(new FailingMailer());

        self::assertFalse($service->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertTrue($this->logHandler->hasErrorThatContains('Failed to send a security alert email'));
    }

    /**
     * One stale address in the recipient list must not silence the alert for
     * everybody after it. The cooldown is claimed by the time sending starts, so
     * an abandoned message is not retried inside the window — the others have to
     * go out on this attempt or not at all.
     */
    public function testOneUndeliverableRecipientDoesNotSuppressTheRest(): void
    {
        $mailer = new FailingMailer(['broken@ops.test']);
        $service = $this->service($mailer, recipients: 'broken@ops.test, working@ops.test');

        // True, because at least one message left the building.
        self::assertTrue($service->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        // The second recipient was still attempted after the first threw.
        self::assertCount(2, $mailer->attempted);
        self::assertTrue($this->logHandler->hasErrorThatContains('Failed to send a security alert email'));
    }

    /**
     * A claimed cooldown that nothing was delivered under is the worst of both
     * worlds: nobody was told, and nobody will be told for the rest of the
     * window either. A mail server down for thirty seconds must not silence an
     * attack in progress for fifteen minutes.
     */
    public function testAFailedSendGivesTheCooldownBackSoTheNextOccurrenceRetries(): void
    {
        $mailer = new RecordingMailer();
        $failing = new FailingMailer();

        // One service, one cache: the first attempt fails, the second uses a
        // working mailer and has to get through.
        $cache = new ArrayAdapter();
        $broken = $this->service($failing, cache: $cache);
        $working = $this->service($mailer, cache: $cache);

        self::assertFalse($broken->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertTrue($working->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertCount(1, $mailer->messages);

        // And the successful one does claim the cooldown, so the throttle still
        // works once something actually got out.
        self::assertFalse($working->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertCount(1, $mailer->messages);
    }

    /**
     * Giving the cooldown back must only ever give back your own.
     *
     * Sending happens outside the lock, so a transport that hangs can outlive
     * the window it claimed. By the time it fails, another request may hold a
     * fresh claim and have already sent under it. Deleting blindly would take
     * that claim with it and let the very next occurrence send a duplicate —
     * which is the failure the cooldown exists to prevent.
     */
    public function testAStaleFailedSendDoesNotReleaseSomebodyElsesCooldown(): void
    {
        $cache = new ArrayAdapter();
        $recording = new RecordingMailer();
        $second = $this->service($recording, cache: $cache);

        // While the first send is blocked: its window elapses (the cache loses
        // the claim) and a second request claims a fresh one and gets a message
        // out under it.
        $stalled = $this->service(
            new InterferingMailer(function () use ($cache, $second): void {
                $cache->clear();
                self::assertTrue(
                    $second->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL)
                );
            }),
            cache: $cache
        );

        self::assertFalse($stalled->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertCount(1, $recording->messages);

        // The second request's cooldown survived the first one's release, so
        // this is still throttled rather than sending a duplicate.
        self::assertFalse($second->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertCount(1, $recording->messages);
    }

    public function testNoConfiguredRecipientsAndNoAdministratorsIsReportedRatherThanIgnored(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->service($mailer, recipients: '');

        self::assertFalse($service->alert('security.admin_role.changed', SecurityAlertService::SEVERITY_CRITICAL));
        self::assertSame([], $mailer->messages);
        self::assertTrue($this->logHandler->hasWarningThatContains('no administrator recipients'));
    }

    /* ---------------------------------------------------------------------- */

    private function service(
        MailerInterface $mailer,
        bool $enabled = true,
        string $recipients = 'ops@example.test',
        ?ArrayAdapter $cache = null,
        string $siteName = 'Panel Page Flip',
    ): SecurityAlertService {
        $container = static::getContainer();

        return new SecurityAlertService(
            $mailer,
            $container->get(Environment::class),
            $cache ?? new ArrayAdapter(),
            $container->get(LockFactory::class),
            $container->get(UserRepository::class),
            new Logger('test', [$this->logHandler]),
            'noreply@example.test',
            $siteName,
            new PublicUrl('https://example.test'),
            $enabled,
            $recipients,
            15,
        );
    }
}
