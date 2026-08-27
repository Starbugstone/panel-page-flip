<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\AdvertisingConfiguration;
use App\Service\BulkUploadSessionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * One batch's worth of bulk-upload access.
 *
 * Two properties matter. A session is scoped to a batch, so finishing one does
 * not leave the next unlocked — that is the whole reason it is not a browser
 * flag. And the service can never refuse: bulk upload exists without
 * advertising, and nothing here is allowed to become the thing that grants it.
 */
final class BulkUploadSessionServiceTest extends TestCase
{
    public function testNoSessionIsActiveBeforeOneIsOpened(): void
    {
        $service = $this->service();

        self::assertSame(
            ['active' => false, 'gateRequired' => false, 'expiresAt' => null, 'rewarded' => false],
            $service->describe($this->user(1))
        );
    }

    public function testOpeningABatchMakesItActiveUntilItsExpiry(): void
    {
        $service = $this->service();
        $now = new \DateTimeImmutable('2026-08-22 10:00:00');

        $opened = $service->open($this->user(1), rewarded: true, now: $now);

        self::assertTrue($opened['active']);
        self::assertTrue($opened['rewarded']);
        self::assertSame(
            $now->modify('+'.BulkUploadSessionService::LIFETIME_SECONDS.' seconds')->format(\DateTimeInterface::ATOM),
            $opened['expiresAt']
        );
        self::assertTrue($service->describe($this->user(1), $now)['active']);
    }

    /**
     * A batch of fifty comics that hits a failed file twenty minutes in must not
     * ask for another advertisement to retry it.
     */
    public function testASessionSurvivesLongEnoughForARealBatch(): void
    {
        $service = $this->service();
        $now = new \DateTimeImmutable('2026-08-22 10:00:00');
        $service->open($this->user(1), rewarded: true, now: $now);

        self::assertTrue($service->describe($this->user(1), $now->modify('+90 minutes'))['active']);
        self::assertFalse($service->describe($this->user(1), $now->modify('+3 hours'))['active']);
    }

    public function testFinishingABatchEndsTheSession(): void
    {
        $service = $this->service();
        $service->open($this->user(1), rewarded: true);

        $service->close($this->user(1));

        self::assertFalse($service->describe($this->user(1))['active']);
    }

    public function testOneAccountsSessionIsNotAnotherAccounts(): void
    {
        $service = $this->service();
        $service->open($this->user(1), rewarded: true);

        self::assertTrue($service->describe($this->user(1))['active']);
        self::assertFalse($service->describe($this->user(2))['active']);
    }

    public function testTheGateIsOfferedOnlyWhereAdvertisingIsEffectivelyOn(): void
    {
        self::assertFalse($this->service(advertisingEnabled: false)->isGateRequired());
        self::assertTrue($this->service(advertisingEnabled: true)->isGateRequired());
    }

    /**
     * The reward flag is a note about what the browser said, so a session opened
     * without one is still a session. Bulk upload is never actually withheld.
     */
    public function testABatchOpensWithoutARewardJustTheSame(): void
    {
        $opened = $this->service(advertisingEnabled: true)->open($this->user(1), rewarded: false);

        self::assertTrue($opened['active']);
        self::assertFalse($opened['rewarded']);
    }

    private function service(bool $advertisingEnabled = false): BulkUploadSessionService
    {
        $advertising = new AdvertisingConfiguration(
            $advertisingEnabled,
            $advertisingEnabled ? 'ca-pub-1234567890123456' : '',
            new NullLogger()
        );

        return new BulkUploadSessionService($advertising, new ArrayAdapter());
    }

    private function user(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
