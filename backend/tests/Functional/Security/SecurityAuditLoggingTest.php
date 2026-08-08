<?php

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Monolog\Level;

/**
 * Authentication, refusals, and the account-security changes that go with them.
 *
 * Two things are being pinned. The first is that these events are recorded at
 * all, on the channel meant for them, with identifiers an investigation can
 * follow. The second — the one worth more — is what is *not* recorded: no
 * submitted password, no password hash, no session cookie, and no list of the
 * addresses an attacker typed into the login form.
 */
final class SecurityAuditLoggingTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    private const PASSWORD = 'CorrectHorse!7Battery';

    public function testASuccessfulLoginIsRecordedOnTheSecurityChannel(): void
    {
        $user = $this->registeredUser('signs-in@test.local');

        $this->login('signs-in@test.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::AUTHENTICATION_SUCCEEDED);

        // Info, not warning: a login is not a problem. It is here so a burst of
        // failures can be read against the success that followed it.
        self::assertSame(Level::Info, $record->level);
        self::assertSame($user->getId(), $record->context['actor_user_id']);
        self::assertSame(SecurityAuditLogger::RESULT_SUCCESS, $record->context['result']);
        self::assertNotEmpty($record->context['request_id']);
        self::assertSame('api_login', $record->context['route']);
    }

    public function testAFailedLoginIsRecordedWithoutTheCredentialsThatWereSubmitted(): void
    {
        $user = $this->registeredUser('mistypes@test.local');

        $this->login('mistypes@test.local', 'NotTheRightPassword!1');
        self::assertResponseStatusCodeSame(401);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::AUTHENTICATION_FAILED);

        // The account is named by id, which is what an investigation needs and
        // what a leaked log file cannot be used to phish with.
        self::assertSame($user->getId(), $record->context['actor_user_id']);
        self::assertTrue($record->context['account_resolved']);

        $this->assertNothingLogged('NotTheRightPassword!1', 'A submitted password');
        $this->assertNothingLogged((string) $user->getPassword(), 'A stored password hash');
    }

    /**
     * The privacy rule from the issue, and the one most easily got wrong: a
     * login form is open to the world, so writing every submitted address into
     * the security log builds a database of addresses attackers came here with.
     */
    public function testAFailedLoginAgainstAnUnknownAccountDoesNotRecordTheSubmittedAddress(): void
    {
        $this->login('harvested-from-a-breach@example.test', 'whatever');
        self::assertResponseStatusCodeSame(401);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::AUTHENTICATION_FAILED);

        self::assertNull($record->context['actor_user_id']);
        self::assertFalse($record->context['account_resolved']);
        $this->assertNothingLogged('harvested-from-a-breach@example.test', 'An unresolved login address');
    }

    /**
     * An account that exists and typed the right password has not failed to
     * authenticate — it has failed a policy check. Counting it as a failure
     * would raise brute-force alerts about somebody who simply has not clicked
     * their verification link.
     */
    public function testAnUnverifiedAccountIsNotCountedAsAnAuthenticationFailure(): void
    {
        $this->registeredUser('unverified@test.local', verified: false);

        $this->login('unverified@test.local', self::PASSWORD);
        self::assertResponseStatusCodeSame(403);

        $this->assertLoggedSecurityEvent(SecurityAuditLogger::AUTHENTICATION_UNVERIFIED);
        $this->assertNoSecurityEvent(SecurityAuditLogger::AUTHENTICATION_FAILED);
    }

    public function testRepeatedFailedLoginsProduceOneThrottledAdminAlert(): void
    {
        $this->registeredUser('under-attack@test.local');
        // Read from the container rather than restated here, so the test proves
        // that the *configured* number is the one honoured.
        $threshold = static::getContainer()->get(SecurityAuditLogger::class)->failedLoginThreshold();

        for ($attempt = 0; $attempt < $threshold; ++$attempt) {
            $this->login('under-attack@test.local', 'wrong-' . $attempt);
        }

        // One message on crossing the threshold, carrying the count. It says
        // "three attempts" rather than arriving three times.
        $alerts = $this->alertsAbout(SecurityAuditLogger::AUTHENTICATION_FAILED);
        self::assertCount(1, $alerts);
        self::assertSame('security-alerts@example.test', $alerts[0]->getTo()[0]->getAddress());
        self::assertStringContainsString(
            sprintf('%d occurrences', $threshold),
            $alerts[0]->getHtmlBody()
        );

        // The attack continues past the threshold, and nothing more goes out
        // until the window expires. An attacker who can force one email per
        // attempt has a mail cannon pointed at the administrators.
        for ($attempt = 0; $attempt < $threshold; ++$attempt) {
            $this->login('under-attack@test.local', 'still-wrong-' . $attempt);
            self::assertSame([], $this->alertsAbout(SecurityAuditLogger::AUTHENTICATION_FAILED));
        }

        // Every attempt is still logged; only the mail is throttled.
        self::assertCount($threshold * 2, $this->securityRecords(SecurityAuditLogger::AUTHENTICATION_FAILED));
    }

    public function testANonAdminReachingAnAdminEndpointIsRecorded(): void
    {
        $this->createAndLoginUser(['email' => 'curious@test.local']);

        $this->getJson('/api/admin/stats');
        self::assertResponseStatusCodeSame(403);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::ADMIN_ACCESS_DENIED);
        self::assertTrue($record->context['admin_surface']);
        self::assertSame('/api/admin/stats', $record->context['path']);
        self::assertSame(SecurityAuditLogger::RESULT_DENIED, $record->context['result']);
    }

    /**
     * An admin surface is probed on a tighter count than an ordinary refusal: a
     * stale tab explains one 403 on a comic, it does not explain somebody
     * working through `/api/admin`.
     */
    public function testRepeatedAdminProbesProduceOneThrottledAlert(): void
    {
        $this->createAndLoginUser(['email' => 'prober@test.local']);

        foreach (['/api/admin/stats', '/api/admin/audit-logs', '/api/admin/dropbox-users'] as $path) {
            $this->getJson($path);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertCount(3, $this->securityRecords(SecurityAuditLogger::ADMIN_ACCESS_DENIED));
        self::assertCount(1, $this->alertsAbout(SecurityAuditLogger::ADMIN_ACCESS_DENIED));

        // The fourth probe is logged and stays quiet.
        $this->getJson('/api/users');
        self::assertCount(4, $this->securityRecords(SecurityAuditLogger::ADMIN_ACCESS_DENIED));
        self::assertSame([], $this->alertsAbout(SecurityAuditLogger::ADMIN_ACCESS_DENIED));
    }

    /**
     * A sub-route of an administrator surface is still that surface. Somebody
     * marking accounts verified is doing an administrator's job, and a refusal
     * there belongs on the tight count with the rest of them.
     */
    public function testAnAdminSubRouteCountsAsAnAdminSurface(): void
    {
        $this->createAndLoginUser(['email' => 'sub-prober@test.local']);
        $target = UserFactory::createOne(['email' => 'verify-target@test.local'])->object();

        $this->postJson('/api/users/' . $target->getId() . '/verify');
        self::assertResponseStatusCodeSame(403);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::ADMIN_ACCESS_DENIED);
        self::assertTrue($record->context['admin_surface']);
        $this->assertNoSecurityEvent(SecurityAuditLogger::AUTHORIZATION_DENIED);
    }

    /**
     * The other half of the rule above. Reading somebody else's account is a
     * refusal an ordinary user reaches by accident — a stale link, a bookmark
     * kept after a demotion — and the account routes are not administrators-only
     * the way their sub-routes are. Counting those three at the probing
     * threshold would mail an administrator a high-severity report accusing a
     * user who clicked a dead link three times.
     */
    public function testReadingAnotherAccountIsNotCountedAsAdminProbing(): void
    {
        $this->createAndLoginUser(['email' => 'stale-link@test.local']);
        $other = UserFactory::createOne(['email' => 'somebody-else@test.local'])->object();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->getJson('/api/users/' . $other->getId());
            self::assertResponseStatusCodeSame(403);
        }

        // Recorded in full, as an ordinary refusal.
        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::AUTHORIZATION_DENIED);
        self::assertFalse($record->context['admin_surface']);
        self::assertCount(3, $this->securityRecords(SecurityAuditLogger::AUTHORIZATION_DENIED));

        // Nothing here claims an administrator surface was probed. Both
        // thresholds happen to be three in the test environment — in production
        // the ordinary one is ten — so what separates the two paths is the event
        // that gets escalated, not the count that escalates it.
        $this->assertNoSecurityEvent(SecurityAuditLogger::ADMIN_ACCESS_DENIED);
        self::assertSame([], $this->alertsAbout(SecurityAuditLogger::ADMIN_ACCESS_DENIED));
    }

    public function testAPasswordChangeIsAuditedWithoutTheValueOrTheHash(): void
    {
        $admin = $this->createAndLoginAdmin(['email' => 'operator@test.local']);
        $target = UserFactory::createOne(['email' => 'reset-me@test.local'])->object();

        $this->putJson('/api/users/' . $target->getId(), ['password' => 'ANewPassword!9Here']);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::USER_PASSWORD_CHANGED);
        self::assertSame($admin->getId(), $record->context['actor_user_id']);
        self::assertSame($target->getId(), $record->context['target_user_id']);
        self::assertTrue($record->context['changed_by_admin']);

        $this->assertNothingLogged('ANewPassword!9Here', 'A submitted password');
    }

    public function testEveryResponseCarriesACorrelationIdThatMatchesItsLogRecords(): void
    {
        $this->registeredUser('correlated@test.local');

        $this->login('correlated@test.local', self::PASSWORD);

        $header = $this->browser()->getResponse()->headers->get('X-Request-Id');
        self::assertNotEmpty($header);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::AUTHENTICATION_SUCCEEDED);
        self::assertSame($header, $record->context['request_id']);
    }

    /* ---------------------------------------------------------------------- */

    private function registeredUser(string $email, bool $verified = true): User
    {
        return UserFactory::createOne([
            'email' => $email,
            'password' => self::PASSWORD,
            'isEmailVerified' => $verified,
        ])->object();
    }

    private function login(string $email, string $password): void
    {
        $this->browser()->request(
            'POST',
            '/api/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR)
        );
    }
}
