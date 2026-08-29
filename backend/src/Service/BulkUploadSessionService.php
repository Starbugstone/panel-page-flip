<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;

/**
 * One batch's worth of bulk-upload access, recorded on the server.
 *
 * Bulk upload exists whether or not this installation shows advertising, and
 * nothing here can refuse it — {@see open()} always succeeds. What the server
 * owns is the *scope*: a session covers one batch and then expires, so the next
 * batch meets the rewarded-ad offer again rather than inheriting a permanent
 * unlock.
 *
 * That is why it lives here rather than in `localStorage`. A browser flag
 * answers "may I skip the offer" with whatever the browser feels like saying,
 * and it survives for as long as the user leaves it there. The honest limit of
 * this design is stated plainly: AdSense's Offerwall reveals the page itself and
 * exposes no completion callback, so `$rewarded` is what the browser reported,
 * not something the server verified. It is an audit note, never a permission —
 * see docs/advertising.md.
 *
 * Cache-backed rather than a table: a session is worth nothing after it expires,
 * nothing after a deploy, and nothing to anybody but the account it belongs to.
 */
final class BulkUploadSessionService
{
    /**
     * How long one batch may take.
     *
     * Long enough that fifty large comics over a domestic upstream finish inside
     * it, and that a failed file retried twenty minutes later does not demand
     * another advertisement. Short enough to still be one batch rather than an
     * afternoon's access.
     */
    public const LIFETIME_SECONDS = 7200;

    public function __construct(
        private readonly AdvertisingConfiguration $advertising,
        private readonly CacheItemPoolInterface $bulkUploadSessionCache,
        private readonly SecurityAuditLogger $auditLogger,
    ) {
    }

    /**
     * Whether entering bulk upload should offer the rewarded advertisement.
     *
     * False whenever advertising is off, unconfigured or unusable — the gate is
     * an enhancement on top of a feature that works without it, so every
     * uncertainty resolves towards opening the uploader.
     */
    public function isGateRequired(): bool
    {
        return $this->advertising->isEnabled();
    }

    /**
     * @return array{active: bool, gateRequired: bool, expiresAt: string|null, rewarded: bool}
     */
    public function describe(User $user, ?\DateTimeImmutable $now = null): array
    {
        $session = $this->activeSession($user, $now);

        return [
            'active' => $session !== null,
            'gateRequired' => $this->isGateRequired(),
            'expiresAt' => $session['expiresAt'] ?? null,
            'rewarded' => $session['rewarded'] ?? false,
        ];
    }

    /**
     * Start a batch. Never refuses: see the class comment.
     *
     * @return array{active: bool, gateRequired: bool, expiresAt: string|null, rewarded: bool}
     */
    public function open(User $user, bool $rewarded, ?\DateTimeImmutable $now = null): array
    {
        $expiresAt = ($now ?? new \DateTimeImmutable())
            ->modify(sprintf('+%d seconds', self::LIFETIME_SECONDS));

        $item = $this->bulkUploadSessionCache->getItem($this->cacheKey($user));
        $item->set(['expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM), 'rewarded' => $rewarded]);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->bulkUploadSessionCache->save($item);

        // Written to the audit log, not just echoed back on the session: the
        // class comment calls $rewarded an audit note, and a value that expires
        // with the cache entry an hour later is not one.
        $this->auditLogger->audit(SecurityAuditLogger::BULK_UPLOAD_SESSION_OPENED, [
            'actor_user_id' => $user->getId(),
            'target_type' => 'user',
            'target_id' => $user->getId(),
            'rewarded' => $rewarded,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);

        return $this->describe($user, $now);
    }

    /** The batch is finished; the next one asks again. */
    public function close(User $user): void
    {
        $this->bulkUploadSessionCache->deleteItem($this->cacheKey($user));
    }

    /**
     * @return array{expiresAt: string, rewarded: bool}|null
     */
    private function activeSession(User $user, ?\DateTimeImmutable $now = null): ?array
    {
        $item = $this->bulkUploadSessionCache->getItem($this->cacheKey($user));
        if (!$item->isHit()) {
            return null;
        }

        $stored = $item->get();
        if (!is_array($stored) || !is_string($stored['expiresAt'] ?? null)) {
            return null;
        }

        $expiresAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $stored['expiresAt']);
        // The pool's own expiry is the usual eviction. This second check exists
        // because a filesystem pool hands back whatever is on disk after a clock
        // change, and a session that outlives its stated expiry is exactly the
        // permanent unlock the design is trying not to have.
        if ($expiresAt === false || $expiresAt <= ($now ?? new \DateTimeImmutable())) {
            return null;
        }

        return ['expiresAt' => $stored['expiresAt'], 'rewarded' => (bool)($stored['rewarded'] ?? false)];
    }

    private function cacheKey(User $user): string
    {
        return 'bulk_upload_session.'.$user->getId();
    }
}
