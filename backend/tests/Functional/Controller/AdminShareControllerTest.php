<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ComicShare;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The share grants themselves, as opposed to the codes some of them came from.
 *
 * The sharing-codes table can stop a code being redeemed again; it cannot see a
 * share made by emailed invitation, and it cannot take back access already
 * granted. Both are what somebody acting on a report about one comic reaching
 * one person actually needs.
 */
final class AdminShareControllerTest extends AbstractApiTestCase
{
    private function share(array $comicAttributes = [], string $status = ComicShare::STATUS_ACCEPTED): ComicShare
    {
        $owner = UserFactory::createOne()->object();
        $recipient = UserFactory::createOne(['email' => uniqid('recipient', true) . '@example.com'])->object();
        $comic = ComicFactory::createOne(['owner' => $owner] + $comicAttributes)->object();

        $share = new ComicShare($comic, $owner, (string) $recipient->getEmail());
        $share->linkRecipientUser($recipient);
        if ($status === ComicShare::STATUS_ACCEPTED) {
            $share->markAccepted($recipient);
        }

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($share);
        $entityManager->flush();

        return $share;
    }

    public function testTheListNamesBothPartiesAndTheComic(): void
    {
        $share = $this->share(['title' => 'Preacher #1']);

        $this->createAndLoginAdmin();
        $items = $this->getJson('/api/admin/shares')['items'];
        $row = array_column($items, null, 'id')[$share->getId()];

        self::assertResponseIsSuccessful();
        self::assertSame('Preacher #1', $row['comic']['title']);
        self::assertSame($share->getOwner()?->getId(), $row['owner']['id']);
        self::assertSame($share->getRecipientUser()?->getId(), $row['recipient']['id']);
        self::assertTrue($row['canRevoke']);
    }

    /**
     * The redaction the owner's view applies is for a recipient's benefit. An
     * administrator checking what adult material is moving between accounts is
     * exactly the person who has to see the title and the flag.
     */
    public function testExplicitComicsAreLabelledRatherThanRedacted(): void
    {
        $share = $this->share(['title' => 'Adult Title', 'explicitContent' => true]);

        $this->createAndLoginAdmin();
        $row = array_column($this->getJson('/api/admin/shares')['items'], null, 'id')[$share->getId()];

        self::assertSame('Adult Title', $row['comic']['title']);
        self::assertTrue($row['comic']['explicitContent']);
    }

    public function testTheListCanBeNarrowedToExplicitComics(): void
    {
        $explicit = $this->share(['title' => 'Adult Title', 'explicitContent' => true]);
        $ordinary = $this->share(['title' => 'All Ages']);

        $this->createAndLoginAdmin();
        $ids = array_column($this->getJson('/api/admin/shares?explicitOnly=true')['items'], 'id');

        self::assertContains($explicit->getId(), $ids);
        self::assertNotContains($ordinary->getId(), $ids);
    }

    public function testRevokingTakesAccessAwayWithoutTouchingTheComic(): void
    {
        $share = $this->share(['title' => 'Sandman #1']);
        $comicId = $share->getComic()?->getId();

        $this->createAndLoginAdmin();
        $payload = $this->postJson(sprintf('/api/admin/shares/%d/revoke', $share->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(ComicShare::STATUS_REVOKED, $payload['share']['status']);
        self::assertFalse($payload['share']['canRevoke']);

        // The comic is untouched: revoking is not moderation of the content.
        $comics = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(\App\Entity\Comic::class);
        self::assertNotNull($comics->find($comicId));
    }

    /** A double click, or a stale table, is the state the caller wanted. */
    public function testRevokingTwiceIsNotAnError(): void
    {
        $share = $this->share();

        $this->createAndLoginAdmin();
        $this->postJson(sprintf('/api/admin/shares/%d/revoke', $share->getId()));
        self::assertResponseIsSuccessful();

        $payload = $this->postJson(sprintf('/api/admin/shares/%d/revoke', $share->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(ComicShare::STATUS_REVOKED, $payload['share']['status']);
    }

    public function testAShareThatDoesNotExistIsReportedAsMissing(): void
    {
        $this->createAndLoginAdmin();
        $this->postJson('/api/admin/shares/999999/revoke');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAdministratorsMayReachIt(): void
    {
        $share = $this->share();

        $this->createAndLoginUser(['email' => 'ordinary@example.com']);

        $this->getJson('/api/admin/shares');
        self::assertResponseStatusCodeSame(403);

        $this->postJson(sprintf('/api/admin/shares/%d/revoke', $share->getId()));
        self::assertResponseStatusCodeSame(403);
    }
}
