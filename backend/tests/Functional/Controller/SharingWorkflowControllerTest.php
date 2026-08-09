<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Service\ComicShareService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Service\SharingWorkflowService;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * The two convenience endpoints behind the Sharing page's **Share comics**
 * flow, and the privacy boundary they are built around.
 *
 * The boundary is the point of most of this file: reusable recipients are
 * addresses the sender previously supplied, never anything read out of the user
 * directory, and a bulk share is a convenience over the ordinary invitation
 * model rather than a second way in.
 */
final class SharingWorkflowControllerTest extends AbstractApiTestCase
{
    use MailerAssertionsTrait;


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

    public function testBothEndpointsRequireAuthentication(): void
    {
        $this->browser()->request('GET', '/api/shares/recent-recipients', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [1],
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRecentRecipientsAreCappedAtTheDocumentedLimit(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'busy-sharer@example.com']);

        $shared = SharingWorkflowService::RECENT_RECIPIENT_LIMIT + 5;
        for ($i = 0; $i < $shared; ++$i) {
            $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
            $this->persistPendingShare($comic, $owner, sprintf('friend%d@example.com', $i));
        }

        $recipients = $this->getJson('/api/shares/recent-recipients')['recipients'];

        self::assertCount(SharingWorkflowService::RECENT_RECIPIENT_LIMIT, $recipients);
        // Most recent first, so the cap keeps the addresses a sender is most
        // likely to want rather than the ones they have finished with.
        self::assertSame(sprintf('friend%d@example.com', $shared - 1), $recipients[0]['email']);
    }

    /**
     * Twenty comics must not mean twenty messages in somebody's inbox. Each one
     * still carries its own link, because each invitation is still answered,
     * expired and revoked on its own.
     */
    public function testBulkShareSendsOneGroupedEmailCarryingEveryInvitation(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'grouped@example.com', 'name' => 'Grouped Owner']);
        $comics = [
            ComicFactory::new()->ownedBy($owner)->create(['title' => 'Batman Begins'])->object(),
            ComicFactory::new()->ownedBy($owner)->create(['title' => 'Superman Returns'])->object(),
            ComicFactory::new()->ownedBy($owner)->create(['title' => 'Wonder Woman'])->object(),
        ];

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => array_map(static fn (Comic $comic): int => (int) $comic->getId(), $comics),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(3, $payload['created']);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertSame('Grouped Owner shared 3 comics with you!', $email->getSubject());
        self::assertSame('friend@example.com', $email->getTo()[0]->getAddress());

        $body = $email->getHtmlBody();
        foreach (['Batman Begins', 'Superman Returns', 'Wonder Woman'] as $title) {
            self::assertStringContainsString($title, $body);
        }

        // One link per invitation, and three different ones: a shared link
        // would make accepting one comic accept all of them. Decoded first
        // because the template escapes hrefs as HTML attributes.
        preg_match_all('#/share/invitation/([A-Za-z0-9_-]+)#', html_entity_decode($body), $matches);
        self::assertCount(3, array_unique($matches[1]));
    }

    public function testAGroupedInvitationEmailStillNamesNoExplicitComic(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'mixed@example.com']);
        $ordinary = ComicFactory::new()->ownedBy($owner)->create(['title' => 'All Ages Adventure'])->object();
        $explicit = ComicFactory::new()->ownedBy($owner)
            ->create(['title' => 'Very Explicit Title', 'explicitContent' => true])
            ->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$ordinary->getId(), $explicit->getId()],
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);

        $body = self::getMailerMessage()->getHtmlBody();
        self::assertStringContainsString('All Ages Adventure', $body);
        // The inbox is the one surface the recipient cannot choose to open, so
        // the age gate holds here exactly as it does on the invitation page.
        self::assertStringNotContainsString('Very Explicit Title', $body);
        self::assertStringContainsString('explicit content (18+)', $body);
    }

    /**
     * One send is one claim on the allowance, so what the limiter protects —
     * how much mail one account can put in somebody's inbox — is unchanged by
     * bulk sharing.
     */
    public function testABulkShareClaimsOneAllowanceHoweverManyComicsItCarries(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'allowance@example.com']);

        // Five comics in one action. Charged per relationship this would spend
        // half the hourly allowance on its own.
        $comicIds = [];
        for ($i = 0; $i < 5; ++$i) {
            $comicIds[] = (int) ComicFactory::new()->ownedBy($owner)->create()->object()->getId();
        }

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => $comicIds,
            'email' => 'first@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        // Nine of the ten remain, so nine more sends are still accepted.
        for ($i = 0; $i < 9; ++$i) {
            $this->postJson('/api/shares/invitations/bulk', [
                'comicIds' => $comicIds,
                'email' => sprintf('guest%d@example.com', $i),
                'senderResponsibilityAccepted' => true,
            ]);
            self::assertResponseStatusCodeSame(201, sprintf('Bulk share %d should be within the allowance.', $i + 2));
        }

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => $comicIds,
            'email' => 'one-too-many@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('too many invitations', $payload['message']);

        // Refused before anything was created, so the eleventh recipient has no
        // half-made relationship waiting for them.
        $sharedByMe = $this->getJson('/api/shares/shared-by-me')['sharedByMe'];
        $recipients = [];
        foreach ($sharedByMe as $group) {
            foreach ($group['recipients'] as $recipient) {
                $recipients[] = $recipient['recipientEmail'];
            }
        }
        self::assertNotContains('one-too-many@example.com', $recipients);
    }

    public function testBulkShareRejectsMoreComicsThanOneActionMayCarry(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'greedy@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => array_fill(0, SharingWorkflowService::MAX_BULK_COMICS + 1, (int) $comic->getId()),
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('at most', $payload['message']);
        self::assertEmailCount(0);
    }

    public function testBulkShareDoesNotSilentlyRecreateAPendingInvitation(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'repeat@example.com']);
        $pending = ComicFactory::new()->ownedBy($owner)->create()->object();
        $fresh = ComicFactory::new()->ownedBy($owner)->create()->object();
        $existing = $this->persistPendingShare($pending, $owner, 'friend@example.com');
        // To the second: the column has no sub-second precision, and what
        // matters is that the clock was not restarted.
        $existingExpiry = $existing->getExpiresAt()?->format('Y-m-d H:i:s');

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$pending->getId(), $fresh->getId()],
            'email' => 'friend@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(207);
        self::assertSame(1, $payload['created']);

        $byId = array_column($payload['results'], null, 'comicId');
        self::assertSame('skipped', $byId[(int) $pending->getId()]['status']);
        self::assertSame('created', $byId[(int) $fresh->getId()]['status']);

        // The existing relationship is untouched — its clock was not restarted
        // and no second row was created beside it.
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $rows = $entityManager->getRepository(ComicShare::class)
            ->findBy(['comic' => $pending, 'recipientEmailNormalized' => 'friend@example.com']);

        self::assertCount(1, $rows);
        self::assertSame((int) $existing->getId(), (int) $rows[0]->getId());
        self::assertSame($existingExpiry, $rows[0]->getExpiresAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * The invitation endpoint must not become an account-enumeration oracle:
     * an address that belongs to a registered user and one that does not have
     * to be indistinguishable in the response.
     */
    public function testBulkShareAnswersIdenticallyForRegisteredAndUnknownRecipients(): void
    {
        UserFactory::createOne(['email' => 'registered@example.com']);
        $owner = $this->createAndLoginUser(['email' => 'prober@example.com']);
        $first = ComicFactory::new()->ownedBy($owner)->create()->object();
        $second = ComicFactory::new()->ownedBy($owner)->create()->object();

        $known = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$first->getId()],
            'email' => 'registered@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        $knownStatus = $this->browser()->getResponse()->getStatusCode();

        $unknown = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$second->getId()],
            'email' => 'nobody-here@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertSame($knownStatus, $this->browser()->getResponse()->getStatusCode());
        self::assertSame(
            array_column($known['results'], 'status'),
            array_column($unknown['results'], 'status')
        );
        self::assertSame($known['created'], $unknown['created']);
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
