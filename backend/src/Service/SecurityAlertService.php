<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Decides which security events are worth an administrator's attention, and
 * mails them — at most once per event per window.
 *
 * Nothing here is wired to a Monolog handler. Mailing every warning is how an
 * alert channel becomes something people filter out, and worse, it hands an
 * attacker a mail cannon: anything that can be repeated cheaply from outside
 * would otherwise generate one message per attempt. So an alert is an explicit
 * decision by a caller, thresholds are counted server-side, and a fired alert
 * puts its own event class on cooldown.
 *
 * Delivery never affects the operation that reported the event. A password
 * really was changed and an admin role really was granted whether or not the
 * notification about it left the building, so a send failure is logged as an
 * error and swallowed.
 */
class SecurityAlertService
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';

    /**
     * Context keys that are useful in an investigation and safe in an inbox.
     * Anything else a caller attached stays in the log: an email is copied,
     * forwarded and archived outside this application's control, so it gets the
     * identifiers and not the record.
     *
     * @var list<string>
     */
    private const REPORTABLE_KEYS = [
        // First, because it is the one field that turns "something happened" into
        // a line somebody can find. The message names the day and the log file;
        // without this the reader is left grepping the day for the event.
        'request_id',
        'actor_user_id',
        'target_user_id',
        'target_type',
        'target_id',
        'result',
        'reason',
        'route',
        'method',
        'ip',
        'user_agent',
        'roles_before',
        'roles_after',
        'count',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly CacheItemPoolInterface $securityAlertCache,
        private readonly LockFactory $lockFactory,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
        #[Autowire('%mailer_from_address%')]
        private readonly string $mailerFromAddress,
        #[Autowire('%mailer_from_name%')]
        private readonly string $mailerFromName,
        private readonly PublicUrl $publicUrl,
        #[Autowire('%env(bool:SECURITY_ALERTS_ENABLED)%')]
        private readonly bool $enabled,
        #[Autowire('%env(string:SECURITY_ALERT_EMAILS)%')]
        private readonly string $configuredRecipients,
        #[Autowire('%env(int:SECURITY_ALERT_WINDOW_MINUTES)%')]
        private readonly int $windowMinutes,
    ) {
    }

    /**
     * Alert now, once per cooldown window.
     *
     * For events that are serious on their first occurrence — a role change, a
     * destructive operation that half-failed. The dedupe key still applies, so a
     * script that can trigger one of these repeatedly produces one message and
     * not a mailbox full of them.
     *
     * @param array<string, mixed> $context
     *
     * @return bool whether a message was actually sent
     */
    public function alert(string $event, string $severity, array $context = [], ?string $scope = null, int $count = 1): bool
    {
        if (!$this->enabled) {
            // Logged, not silent: "why did nobody get told" has a findable
            // answer, and the event itself is already in the security log.
            $this->logger->info('Security alert suppressed: alerts are disabled.', [
                'event' => $event,
                'severity' => $severity,
            ]);

            return false;
        }

        $claimed = $this->serialised($event, $scope ?? 'global', fn (): bool => $this->claimCooldown($event, $scope));

        if (!$claimed) {
            return false;
        }

        return $this->deliver($event, $severity, $context, $count, $scope);
    }

    /**
     * Count one occurrence, and alert when the threshold is reached.
     *
     * The counter is per event and per source, so one noisy client cannot hide
     * a second one behind it, and a legitimate user's occasional 403 does not
     * accumulate towards somebody else's brute force. Crossing the threshold
     * resets the count and starts a cooldown, so a sustained attack produces one
     * message per window carrying the number of attempts in it.
     *
     * @param array<string, mixed> $context
     *
     * @return bool whether a message was actually sent
     */
    public function alertOnThreshold(string $event, string $scope, int $threshold, string $severity, array $context = []): bool
    {
        $threshold = max(1, $threshold);

        // Count, decide and claim the cooldown as one step. Read-modify-write on
        // a cache pool is not atomic, and this is the mechanism standing between
        // a scripted attack and a mailbox full of identical messages: two
        // requests that both read the same count would both cross the threshold
        // and both send.
        $decision = $this->serialised($event, $scope, function () use ($event, $scope, $threshold): array {
            $count = $this->increment($event, $scope);

            if ($count < $threshold) {
                return ['crossed' => false, 'count' => $count, 'claimed' => false];
            }

            $this->resetCount($event, $scope);

            return [
                'crossed' => true,
                'count' => $count,
                'claimed' => $this->enabled && $this->claimCooldown($event, $scope),
            ];
        });

        if (!$decision['crossed']) {
            return false;
        }

        if (!$this->enabled) {
            $this->logger->info('Security alert suppressed: alerts are disabled.', [
                'event' => $event,
                'severity' => $severity,
                'count' => $decision['count'],
            ]);

            return false;
        }

        if (!$decision['claimed']) {
            return false;
        }

        return $this->deliver($event, $severity, $context, $decision['count'], $scope);
    }

    /**
     * Send, and give the cooldown back if nothing left the building.
     *
     * The cooldown is claimed before sending, because the claim is what stops
     * two concurrent requests from both sending. But a claim that survives a
     * failed send is the worst of both worlds: nobody was told, and nobody will
     * be told for the rest of the window either. A mail server that is down for
     * thirty seconds would otherwise silence an attack in progress for fifteen
     * minutes.
     *
     * Releasing it lets the next occurrence try again. For a thresholded alert
     * that means the next crossing rather than the next event, since the count
     * was reset on the way in — which is the right amount of back-pressure
     * against a mail server that is genuinely gone.
     *
     * @param array<string, mixed> $context
     */
    private function deliver(string $event, string $severity, array $context, int $count, ?string $scope): bool
    {
        $sent = $this->send($event, $severity, $context, $count);

        if (!$sent) {
            $this->releaseCooldown($event, $scope);
        }

        return $sent;
    }

    /**
     * Run the decision under a lock keyed on this event and source.
     *
     * Scoped rather than global, so an alert about one address never waits on an
     * alert about another. If the lock store itself is unavailable the work
     * still runs: a duplicate message is a far better failure than an alert
     * nobody receives.
     *
     * @template T
     * @param callable(): T $decide
     * @return T
     */
    private function serialised(string $event, string $scope, callable $decide): mixed
    {
        $lock = null;

        try {
            $lock = $this->lockFactory->createLock($this->key('lock', $event, $scope), 10.0);
            $lock->acquire(true);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not lock the security-alert counter; proceeding unserialised.', [
                'event' => $event,
                'exception' => $exception,
            ]);
            $lock = null;
        }

        try {
            return $decide();
        } finally {
            $lock?->release();
        }
    }

    /**
     * Take the cooldown for this event class, or report that somebody already
     * has it.
     */
    private function claimCooldown(string $event, ?string $scope): bool
    {
        $item = $this->securityAlertCache->getItem($this->key('cooldown', $event, $scope ?? 'global'));

        if ($item->isHit()) {
            return false;
        }

        $item->set((new \DateTimeImmutable())->format(DATE_ATOM))->expiresAfter($this->windowSeconds());
        $this->securityAlertCache->save($item);

        return true;
    }

    /**
     * One more occurrence of this event from this source, within a window that
     * slides forward on every attempt.
     *
     * Sliding rather than fixed, so a steady trickle designed to reset a fixed
     * window just under the threshold still accumulates. The count is cleared
     * when an alert fires, so the next message counts the next burst.
     *
     * Read-modify-write on a cache pool is not atomic. For a threshold whose
     * only job is "roughly this many, recently" that is acceptable: a lost
     * increment under concurrency delays an alert by one attempt, and by
     * definition the attempts are still coming.
     */
    private function increment(string $event, string $scope): int
    {
        $item = $this->securityAlertCache->getItem($this->key('count', $event, $scope));
        $count = $item->isHit() ? ((int) $item->get()) + 1 : 1;

        $item->set($count)->expiresAfter($this->windowSeconds());
        $this->securityAlertCache->save($item);

        return $count;
    }

    private function releaseCooldown(string $event, ?string $scope): void
    {
        $this->securityAlertCache->deleteItem($this->key('cooldown', $event, $scope ?? 'global'));
    }

    private function resetCount(string $event, string $scope): void
    {
        $this->securityAlertCache->deleteItem($this->key('count', $event, $scope));
    }

    private function key(string $kind, string $event, string $scope): string
    {
        // Hashed, because a scope is an IP, an account id or a share id, and
        // cache keys reject some of the characters those arrive with.
        return sprintf('security-alert.%s.%s', $kind, hash('xxh128', $event . '|' . $scope));
    }

    private function windowSeconds(): int
    {
        return max(60, $this->windowMinutes * 60);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(string $event, string $severity, array $context, int $count): bool
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            $this->logger->warning('Security alert not sent: no administrator recipients are configured.', [
                'event' => $event,
            ]);

            return false;
        }

        try {
            $body = $this->twig->render('emails/security_alert.html.twig', [
                'event' => $event,
                'severity' => $severity,
                'count' => $count,
                'occurredAt' => new \DateTimeImmutable(),
                'windowMinutes' => $this->windowMinutes,
                'details' => $this->reportableDetails($context),
                // The admin dashboard, not a dedicated audit page: the audit
                // list is a section of it. A path the frontend route manifest
                // does not know is served as a 404, so an invented deep link
                // would send an administrator chasing an incident to an error
                // page.
                'auditUrl' => $this->publicUrl->to('/admin'),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to render a security alert email.', [
                'event' => $event,
                'severity' => $severity,
                'exception' => $exception,
            ]);

            return false;
        }

        $sent = false;

        foreach ($recipients as $recipient) {
            // One message each rather than a shared To: or Bcc:. The list of
            // people who administer an instance is not something a security
            // notification should publish to all of them, and a Bcc: header is a
            // promise about a mail server's behaviour, not a guarantee.
            //
            // Each send is isolated. A single stale address or one refused
            // connection must not stop the rest of the list from being told —
            // the cooldown is already claimed by this point, so a message
            // abandoned here is not retried inside the window.
            try {
                $this->mailer->send(
                    (new Email())
                        ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                        ->to($recipient)
                        ->subject(sprintf('[%s] Security alert: %s', strtoupper($severity), $event))
                        ->html($body)
                );
                $sent = true;
            } catch (\Throwable $exception) {
                // The caller's operation already succeeded, or already failed on
                // its own terms. Either way it is not this method's to undo.
                $this->logger->error('Failed to send a security alert email.', [
                    'event' => $event,
                    'severity' => $severity,
                    'exception' => $exception,
                ]);
            }
        }

        return $sent;
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $configured = array_values(array_filter(
            array_map('trim', explode(',', $this->configuredRecipients)),
            static fn (string $address): bool => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));

        if ($configured !== []) {
            return $configured;
        }

        // Fallback, not the preference. An operational address survives an
        // administrator account being disabled, deleted or taken over — which
        // are exactly the situations these messages are about.
        return array_values(array_filter(array_map(
            static fn (User $admin): ?string => $admin->isEmailVerified() ? $admin->getEmail() : null,
            $this->userRepository->findAdmins()
        )));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function reportableDetails(array $context): array
    {
        $details = [];

        foreach (self::REPORTABLE_KEYS as $key) {
            if (!array_key_exists($key, $context) || $context[$key] === null) {
                continue;
            }

            $value = $context[$key];
            $details[$key] = is_array($value)
                ? implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value))
                : (string) $value;
        }

        return $details;
    }
}
