<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The one way anything in this application writes a security or audit record.
 *
 * Two channels, kept apart on purpose. `app_audit` is the successful state
 * changes somebody may need to reconstruct later — a role granted, a comic
 * deleted, an acknowledgement made. `app_security` is the things that went wrong
 * or look like an attack. Mixing them means the second is read through the
 * first, and the second is the one somebody is paging through at two in the
 * morning.
 *
 * Neither is Symfony's own `security` channel, which carries the framework's
 * authenticator tracing — including the address somebody typed into the login
 * form. See config/packages/monolog.yaml.
 *
 * What does *not* go in either: reads. No comic view, no page turn, no cover
 * fetch. An audit log that records what people looked at is a behavioural
 * profile with a retention period, and this application has no use for one.
 *
 * Callers pass identifiers. Titles, filenames, email addresses and tokens stay
 * out — partly because {@see \App\Monolog\SensitiveDataProcessor} cannot know
 * which free text hides a secret, and partly because an explicit comic's title
 * is itself the thing the 18+ gate exists to withhold.
 */
class SecurityAuditLogger
{
    // Authentication and account security.
    public const AUTHENTICATION_SUCCEEDED = 'security.authentication.succeeded';
    public const AUTHENTICATION_FAILED = 'security.authentication.failed';
    public const AUTHENTICATION_UNVERIFIED = 'security.authentication.unverified';
    public const OAUTH_CALLBACK_REJECTED = 'security.oauth.callback_rejected';
    public const OAUTH_PROVIDER_LINK_REFUSED = 'security.oauth.provider_link_refused';
    public const AUTHORIZATION_DENIED = 'security.authorization.denied';
    public const ADMIN_ACCESS_DENIED = 'security.authorization.admin_denied';
    public const RATE_LIMIT_TRIGGERED = 'security.rate_limit.triggered';
    public const ADMIN_ROLE_CHANGED = 'security.admin_role.changed';
    public const LAST_ADMIN_PROTECTED = 'security.admin_role.last_admin_protected';
    public const INTEGRATION_TOKEN_REJECTED = 'security.integration.token.rejected';
    public const ADULT_GATE_BYPASS_ATTEMPT = 'security.share.adult_gate_bypass_attempt';
    public const SHARE_WRONG_RECIPIENT = 'security.share.wrong_recipient';
    // Sharing codes are the only surface that turns an identifier somebody
    // typed into a person, so they are the only place worth watching for
    // somebody working through the keyspace rather than pasting a code.
    public const SHARING_CODE_ENUMERATION_ATTEMPT = 'security.share.sharing_code_enumeration_attempt';
    public const SHARE_CLAIM_CODE_REJECTED = 'security.share.claim_code_rejected';
    // The other identifier a person types at another person. Watched separately
    // from sharing codes because the two say different things: a run of failed
    // code lookups is somebody guessing at the keyspace, while a run of failed
    // username lookups is somebody guessing at *people*.
    public const USERNAME_ENUMERATION_ATTEMPT = 'security.share.username_enumeration_attempt';
    public const DATA_INTEGRITY_FAILURE = 'security.data_integrity.failure';
    // The alarm raised by a sweep large enough to look like a compromised
    // account. Its own name rather than the audit event below reused: the two
    // records describe one deletion from two sides, and sharing a name would
    // make the same sweep appear twice to anything counting by event.
    public const COMIC_BULK_DELETE_UNUSUAL = 'security.comic.bulk_delete_unusual';

    // Successful state changes worth reconstructing later.
    public const USER_LOGGED_OUT = 'audit.user.logged_out';
    public const USER_PASSWORD_CHANGED = 'audit.user.password_changed';
    public const USER_PASSWORD_RESET_REQUESTED = 'audit.user.password_reset_requested';
    public const USER_PASSWORD_RESET_COMPLETED = 'audit.user.password_reset_completed';
    public const USER_EMAIL_VERIFIED = 'audit.user.email_verified';
    public const USER_VERIFICATION_RESENT = 'audit.user.verification_resent';
    public const USER_REGISTERED = 'audit.user.registered';
    public const OAUTH_PROVIDER_LINKED = 'audit.oauth.provider_linked';
    public const OAUTH_PROVIDER_DISCONNECTED = 'audit.oauth.provider_disconnected';
    public const USER_ACCOUNT_DELETION_REQUESTED = 'audit.user.account_deletion_requested';
    public const USER_ACCOUNT_DELETED = 'audit.user.account_deleted';
    public const USER_ROLES_CHANGED = 'audit.user.roles_changed';
    public const COMIC_DELETED = 'audit.comic.deleted';
    public const COMICS_BULK_DELETED = 'audit.comic.bulk_deleted';
    public const COMIC_EXPLICIT_CLASSIFICATION_CHANGED = 'audit.comic.explicit_classification_changed';
    public const SHARE_CREATED = 'audit.share.created';
    public const SHARE_SENDER_RESPONSIBILITY_ACCEPTED = 'audit.share.sender_responsibility_accepted';
    public const SHARE_ADULT_CONFIRMED = 'audit.share.adult_confirmed';
    public const SHARE_ACCEPTED = 'audit.share.accepted';
    public const SHARE_DECLINED = 'audit.share.declined';
    public const SHARE_REVOKED = 'audit.share.revoked';
    // No `audit.share.tombstoned`. Tombstoning happens inside the caller's
    // uncommitted transaction, so the only honest place to record it is after
    // that commit — where the deletion events above already carry the count of
    // recipients who lost access.
    public const SHARES_CLEARED = 'audit.share.dead_records_cleared';
    public const SHARING_CODE_ROTATED = 'audit.share.sharing_code_rotated';
    // Both names go in this one, unlike the code rotation above. A username is
    // an account's public identity by definition, so neither half is a secret —
    // and the question this record exists to answer is precisely "who used to
    // be called that?" after somebody reports an impersonation.
    public const USERNAME_CHANGED = 'audit.user.username_changed';
    public const SHARE_CLAIM_CODE_CREATED = 'audit.share.claim_code_created';
    public const SHARE_CLAIM_CODE_REDEEMED = 'audit.share.claim_code_redeemed';
    public const SHARE_CLAIM_CODE_REVOKED = 'audit.share.claim_code_revoked';
    // The code itself is never in the record — only that somebody asked for it
    // back. That is the question worth answering after an account is taken
    // over: which capabilities did the intruder walk away holding?
    public const SHARE_CLAIM_CODE_REVEALED = 'audit.share.claim_code_revealed';
    // Identifiers and shape only, never the message. The administrator's words
    // to one person live in the row they were written into, which has its own
    // retention and its own audience; the log records that a notice was sent.
    public const USER_WARNING_ISSUED = 'audit.user.warning_issued';
    public const COMIC_SHARE_ADMIN_REVOKED = 'audit.share.admin_revoked';
    public const INTEGRATION_DISCONNECTED = 'audit.integration.disconnected';
    public const STORAGE_ORPHAN_QUARANTINE = 'audit.storage.orphan_quarantine';
    public const RETENTION_CLEANUP = 'audit.retention.cleanup';
    public const CONTENT_REPORT_RECEIVED = 'audit.content_report.received';
    public const CONTENT_REPORT_TARGET_LINKED = 'audit.content_report.target_linked';
    public const CONTENT_REPORT_REVIEW_STARTED = 'audit.content_report.review_started';
    public const CONTENT_REPORT_SHARING_RESTRICTED = 'audit.content_report.sharing_restricted';
    public const CONTENT_REPORT_RESTRICTION_LIFTED = 'audit.content_report.restriction_lifted';
    public const CONTENT_REPORT_CONTENT_QUARANTINED = 'audit.content_report.content_quarantined';
    public const CONTENT_REPORT_REJECTED = 'audit.content_report.rejected';
    public const CONTENT_REPORT_CLOSED = 'audit.content_report.closed';
    public const CONTENT_REPORT_USER_NOTIFIED = 'audit.content_report.user_notified';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_DENIED = 'denied';
    public const RESULT_FAILED = 'failed';

    /** The attribute {@see \App\EventSubscriber\RequestIdSubscriber} writes. */
    public const REQUEST_ID_ATTRIBUTE = '_security_request_id';

    public function __construct(
        private readonly LoggerInterface $securityLogger,
        private readonly LoggerInterface $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly SecurityAlertService $alerts,
        private readonly RequestStack $requestStack,
        #[Autowire('%env(int:SECURITY_ALERT_FAILED_LOGIN_THRESHOLD)%')]
        private readonly int $failedLoginThreshold,
        #[Autowire('%env(int:SECURITY_ALERT_AUTHZ_THRESHOLD)%')]
        private readonly int $authorizationThreshold,
    ) {
    }

    /**
     * A successful state change. Never alerts on its own — an audit record is
     * the trail, not the alarm.
     *
     * @param array<string, mixed> $context
     */
    public function audit(string $event, array $context = []): void
    {
        $this->contain(
            $event,
            fn () => $this->auditLogger->info($event, $this->enrich($event, $context, self::RESULT_SUCCESS))
        );
    }

    /**
     * Something refused, failed or suspicious. Logged only; escalation is the
     * caller's explicit decision via {@see suspicious()} or {@see critical()}.
     *
     * @param array<string, mixed> $context
     */
    public function security(string $event, array $context = [], string $level = LogLevel::WARNING, string $result = self::RESULT_DENIED): void
    {
        $this->contain(
            $event,
            fn () => $this->securityLogger->log($level, $event, $this->enrich($event, $context, $result))
        );
    }

    /**
     * Suspicious, and worth telling an administrator about if it keeps
     * happening.
     *
     * One expired link, one stale-UI 403 and one mistyped password are normal.
     * Ten from the same source inside the window are not, and that is the only
     * thing that reaches an inbox.
     *
     * @param array<string, mixed> $context
     */
    public function suspicious(string $event, string $scope, array $context = [], ?int $threshold = null): void
    {
        $this->security($event, $context, LogLevel::WARNING);

        $this->contain($event, fn () => $this->alerts->alertOnThreshold(
            $event,
            $scope,
            $threshold ?? $this->authorizationThreshold,
            SecurityAlertService::SEVERITY_HIGH,
            $this->enrich($event, $context, self::RESULT_DENIED)
        ));
    }

    /**
     * Serious on its first occurrence: a privilege change, a half-completed
     * destructive operation, an integration credential that stopped working.
     *
     * @param array<string, mixed> $context
     */
    public function critical(string $event, array $context = [], string $result = self::RESULT_SUCCESS, ?string $scope = null): void
    {
        $enriched = $this->enrich($event, $context, $result);

        // Two boundaries, not one. Sharing a single catch would mean an
        // unwritable log file also cancelled the email — and a role change that
        // could not be written down is exactly the moment somebody most needs
        // telling about it. The two are independent, so they fail independently.
        $this->contain($event, fn () => $this->securityLogger->critical($event, $enriched));
        $this->contain($event, fn () => $this->alerts->alert(
            $event,
            SecurityAlertService::SEVERITY_CRITICAL,
            $enriched,
            $scope
        ));
    }

    /**
     * Observing an operation must not be able to break it.
     *
     * Everything this class does happens alongside something else that has
     * usually already succeeded — a password changed, a role granted, an
     * invitation sent and committed. The ways this can fail are precisely the
     * conditions worth knowing about: a full disk, a log directory that lost its
     * permissions, an unreachable cache, a mail server that is refusing
     * connections. Letting any of those escape would turn a completed operation
     * into a 500 and invite the caller to retry something that already happened.
     *
     * The failure is reported on the ordinary application channel, which is a
     * different handler and usually a different file, so a security log that
     * cannot be written leaves a trace somewhere that can be. If even that
     * fails, there is nothing further to try and nothing worth crashing for.
     */
    private function contain(string $event, callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $exception) {
            try {
                $this->logger->error('Failed to record a security or audit event.', [
                    'event' => $event,
                    'exception' => $exception,
                ]);
            } catch (\Throwable) {
                // The fallback channel is gone too. Swallowing is the only
                // remaining option that does not take the request with it.
            }
        }
    }

    /** The threshold for authentication abuse, so callers do not restate it. */
    public function failedLoginThreshold(): int
    {
        return max(1, $this->failedLoginThreshold);
    }

    /**
     * The client address, for use as an alert scope.
     *
     * Falls back to a constant rather than to null so that requests without a
     * resolvable address are counted together instead of each starting a fresh
     * threshold of their own.
     */
    public function clientIp(): string
    {
        return $this->request()?->getClientIp() ?: 'unknown';
    }

    /**
     * The stable fields every record carries, filled in from the request when
     * there is one.
     *
     * The timestamp is generated here and never taken from a caller: the whole
     * value of an audit record is that the server decided when it happened.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function enrich(string $event, array $context, string $result): array
    {
        $request = $this->request();
        $requestContext = [];

        if ($request !== null) {
            $requestContext = [
                'request_id' => $request->attributes->get(self::REQUEST_ID_ATTRIBUTE),
                'route' => $request->attributes->get('_route'),
                'method' => $request->getMethod(),
                'ip' => $request->getClientIp(),
            ];
        }

        // Three layers, in this order. The request details come first so a
        // caller may override them — an event about an earlier request should
        // carry that request's route. The caller's own context comes next. The
        // identity of the event comes last and cannot be overridden: the event
        // name, the outcome and the moment are the server's account of what
        // happened, and an audit record whose timestamp a caller could choose
        // would not be evidence of anything.
        return array_merge($requestContext, $context, [
            'event' => $event,
            'result' => $result,
            'occurred_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    private function request(): ?Request
    {
        return $this->requestStack->getCurrentRequest();
    }
}
