<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Service\ContentReportTargetPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The detail view and the candidate list describe the same records, and the
 * queue acts on the assumption that they agree.
 *
 * These pin that: `detailed()` is the shared projection plus moderation state,
 * so a field added for one reader cannot go missing for the other.
 */
final class ContentReportTargetPresenterTest extends TestCase
{
    private ContentReportTargetPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new ContentReportTargetPresenter();
    }

    public function testDetailedComicCarriesTheSharedProjectionAndItsModerationState(): void
    {
        $owner = (new User())->setName('Owner')->setEmail('owner@example.com');
        $comic = (new Comic())->setTitle('Durable title')->setOwner($owner);

        $detailed = $this->presenter->detailed($comic);

        // Every key the candidate list publishes for this record.
        self::assertSame('comic', $detailed['type']);
        self::assertSame('Durable title', $detailed['title']);
        self::assertSame(['id' => null, 'name' => 'Owner'], $detailed['owner']);
        // Plus what only a reviewer needs.
        self::assertFalse($detailed['sharingRestricted']);
        self::assertFalse($detailed['quarantined']);

        $comic->quarantine();
        $quarantined = $this->presenter->detailed($comic);
        self::assertTrue($quarantined['quarantined']);
        self::assertTrue($quarantined['sharingRestricted']);
    }

    public function testDetailedUserCarriesTheSharedProjectionAndItsModerationState(): void
    {
        $user = (new User())->setName('Barbara Gordon')->setEmail('oracle@example.com');

        $detailed = $this->presenter->detailed($user);

        self::assertSame('user', $detailed['type']);
        self::assertSame('Barbara Gordon', $detailed['name']);
        self::assertSame('oracle@example.com', $detailed['email']);
        self::assertFalse($detailed['sharingRestricted']);
    }

    /**
     * A share has no moderation state of its own — it is restricted by acting
     * on the comic or the account behind it — so it is the shared projection
     * unchanged rather than a third, thinner spelling of a share.
     */
    public function testDetailedShareIsTheSharedProjectionUnchanged(): void
    {
        $owner = (new User())->setName('Owner')->setEmail('owner@example.com');
        $comic = (new Comic())->setTitle('Shared title')->setOwner($owner);
        $share = new ComicShare($comic, $owner, 'recipient@example.com');

        $detailed = $this->presenter->detailed($share);

        self::assertSame($this->presenter->candidate($share, 'exact'), $detailed + ['source' => 'exact']);
        self::assertSame('Shared title', $detailed['title']);
        self::assertSame(['id' => null, 'name' => 'Owner'], $detailed['owner']);
    }

    public function testNothingLinkedIsPresentedAsNothing(): void
    {
        self::assertNull($this->presenter->detailed(null));
    }
}
