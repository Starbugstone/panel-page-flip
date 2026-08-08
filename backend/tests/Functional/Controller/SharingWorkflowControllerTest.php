<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Service\ComicShareService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class SharingWorkflowControllerTest extends AbstractApiTestCase
{
    public function testRecentRecipientsOnlyReturnsAddressesThisOwnerPreviouslyUsed(): void
    {
        $owner = UserFactory::createOne(['email' => 'owner@example.com'])->object();
        $other = UserFactory::createOne(['email' => 'other@example.com'])->object();

        $first = ComicFactory::new()->ownedBy($owner)->create()->object();
        $second = ComicFactory::new()->ownedBy($owner)->create()->object();
        $third = ComicFactory::new()->ownedBy($owner)->create()->object();
        $incoming = ComicFactory::new()->ownedBy($other)->create()->object();
        $otherComic = ComicFactory::new()->ownedBy($other)->create()->object();

        $this->persistPendingShare($first, $owner, 'jane@example.com');
        $this->persistPendingShare($second, $owner, 'bob@example.com');
        // Reusing Jane on a newer comic must collapse to one recipient and put
        // her first without looking her up in User.
        $this->persistPendingShare($third, $owner, 'jane@example.com');

        // Neither direction of somebody else's relationship belongs in the
        // owner's reusable address history.
        $this->persistPendingShare($incoming, $other, 'owner@example.com');
        $this->persistPendingShare($otherComic, $other, 'private@example.com');

        $this->loginAs($owner);
        $payload = $this->getJson('/api/shares/recent-recipients');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['jane@example.com', 'bob@example.com'],
            array_column($payload['recipients'], 'email')
        );
    }

    public function testBulkShareCreatesIndependentNormalShareRelationships(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'bulk-owner@example.com']);
        $first = ComicFactory::new()->ownedBy($owner)->create(['title' => 'First'])->object();
        $second = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Second'])->object();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$first->getId(), $second->getId()],
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $payload['created']);
        self::assertSame(2, $payload['total']);
        self::assertSame(['created', 'created'], array_column($payload['results'], 'status'));

        $sharedByMe = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        self::assertEqualsCanonicalizing(
            [$first->getId(), $second->getId()],
            array_column($sharedByMe, 'comicId')
        );
        foreach ($sharedByMe as $group) {
            self::assertSame('friend@example.com', $group['recipients'][0]['recipientEmail']);
            self::assertSame(ComicShare::STATUS_PENDING, $group['recipients'][0]['status']);
        }
    }

    public function testBulkShareCannotReshareAComicReceivedFromSomeoneElse(): void
    {
        $originalOwner = UserFactory::createOne(['email' => 'original@example.com'])->object();
        $recipient = UserFactory::createOne(['email' => 'recipient@example.com'])->object();
        $receivedComic = ComicFactory::new()->ownedBy($originalOwner)->create()->object();
        $ownComic = ComicFactory::new()->ownedBy($recipient)->create()->object();

        $share = $this->persistPendingShare($receivedComic, $originalOwner, 'recipient@example.com');
        $share->markAccepted($recipient);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginAs($recipient);
        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$receivedComic->getId(), $ownComic->getId()],
            'email' => 'third@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(207);
        self::assertSame(1, $payload['created']);
        self::assertSame(2, $payload['total']);

        $byId = [];
        foreach ($payload['results'] as $result) {
            $byId[(int) $result['comicId']] = $result;
        }

        self::assertSame('not_available', $byId[(int) $receivedComic->getId()]['status']);
        self::assertSame('created', $byId[(int) $ownComic->getId()]['status']);
        self::assertSame(
            'This comic is not available to share.',
            $byId[(int) $receivedComic->getId()]['message']
        );
    }

    public function testBulkShareStillRequiresTheSenderResponsibilityAcknowledgement(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'responsible@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => false,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('acknowledge responsibility', $payload['message']);
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
    }

    private function persistPendingShare(Comic $comic, User $owner, string $recipientEmail): ComicShare
    {
        $share = new ComicShare($comic, $owner, $recipientEmail);
        $share->markPending(new \DateTimeImmutable(ComicShareService::INVITATION_TTL));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($share);
        $entityManager->flush();

        return $share;
    }
}
