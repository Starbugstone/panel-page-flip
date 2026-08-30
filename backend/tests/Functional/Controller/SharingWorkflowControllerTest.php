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
        $owner = UserFactory::createOne(['email' => 'owner@example.com']);
        $other = UserFactory::createOne(['email' => 'other@example.com']);

        $first = ComicFactory::new()->ownedBy($owner)->create();
        $second = ComicFactory::new()->ownedBy($owner)->create();
        $third = ComicFactory::new()->ownedBy($owner)->create();
        $incoming = ComicFactory::new()->ownedBy($other)->create();
        $otherComic = ComicFactory::new()->ownedBy($other)->create();

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
        $first = ComicFactory::new()->ownedBy($owner)->create(['title' => 'First']);
        $second = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Second']);

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
        $originalOwner = UserFactory::createOne(['email' => 'original@example.com']);
        $recipient = UserFactory::createOne(['email' => 'recipient@example.com']);
        $receivedComic = ComicFactory::new()->ownedBy($originalOwner)->create();
        $ownComic = ComicFactory::new()->ownedBy($recipient)->create();

        $share = $this->persistPendingShare($receivedComic, $originalOwner, 'recipient@example.com');
        $share->markAccepted($recipient);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginAs($recipient);
        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$receivedComic->getId(), $ownComic->getId()],
            'email' => 'third@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        // The batch is refused whole. Sharing the one comic that *is* theirs
        // and reporting the other in a per-comic list tells a sender who asked
        // for two that they got one, in a line they have to go looking for.
        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            'One or more of those comics is not available to share, so nothing was shared.',
            $payload['message']
        );

        // And nothing was created for either of them.
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
    }

    /**
     * One rule, one status, one sentence — whichever of the three forms the
     * sender used to name themselves.
     *
     * They are asserted together because they used to disagree: a copy of the
     * rule in the controller answered 409 for a resolved username or user code
     * while the service answered 400 for an address, so the same refusal read
     * as two different failures depending on how the recipient was typed.
     *
     * @dataProvider selfRecipientProvider
     *
     * @param callable(self, User): array<string, string> $nameSelf
     */
    public function testNamingYourselfAnyWayIsRefusedIdentically(callable $nameSelf): void
    {
        $owner = $this->createAndLoginUser(['email' => 'self@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->postJson('/api/shares/invitations/bulk', array_merge([
            'comicIds' => [$comic->getId()],
            'senderResponsibilityAccepted' => true,
        ], $nameSelf($this, $owner)));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('You cannot share a comic with yourself.', $payload['message']);
    }

    /**
     * @return iterable<string, array{callable(self, User): array<string, string>}>
     */
    public static function selfRecipientProvider(): iterable
    {
        yield 'email address' => [
            static fn (self $test, User $owner): array => ['email' => (string) $owner->getEmail()],
        ];

        // Spelled differently on purpose: the address is normalised before the
        // comparison, so a shouted one must be refused just the same.
        yield 'email address in another case' => [
            static fn (self $test, User $owner): array => ['email' => strtoupper((string) $owner->getEmail())],
        ];

        yield 'username' => [
            static fn (self $test, User $owner): array => ['username' => (string) $owner->getUsername()],
        ];

        yield 'user code' => [
            static fn (self $test, User $owner): array => [
                'userCode' => $test->getJson('/api/shares/user-code')['userCode'],
            ],
        ];
    }

    public function testBulkShareStillRequiresTheSenderResponsibilityAcknowledgement(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'responsible@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

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
            $comic = ComicFactory::new()->ownedBy($owner)->create();
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
            ComicFactory::new()->ownedBy($owner)->create(['title' => 'Batman Begins']),
            ComicFactory::new()->ownedBy($owner)->create(['title' => 'Superman Returns']),
            ComicFactory::new()->ownedBy($owner)->create(['title' => 'Wonder Woman']),
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
        $ordinary = ComicFactory::new()->ownedBy($owner)->create(['title' => 'All Ages Adventure']);
        $explicit = ComicFactory::new()->ownedBy($owner)
            ->create(['title' => 'Very Explicit Title', 'explicitContent' => true])
            ;

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
            $comicIds[] = (int) ComicFactory::new()->ownedBy($owner)->create()->getId();
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
        $comic = ComicFactory::new()->ownedBy($owner)->create();

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
        $pending = ComicFactory::new()->ownedBy($owner)->create();
        $fresh = ComicFactory::new()->ownedBy($owner)->create();
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
        $first = ComicFactory::new()->ownedBy($owner)->create();
        $second = ComicFactory::new()->ownedBy($owner)->create();

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

    /**
     * Exactly one way of naming a recipient, enforced by the endpoint.
     *
     * A precedence order silently answers a contradictory request, and answers
     * it by sharing somebody's comics with somebody. Which somebody is then
     * decided by the order the server happens to check in rather than by the
     * person who pressed the button, and the sender is told it worked.
     *
     * @dataProvider conflictingRecipients
     */
    public function testNamingARecipientTwoWaysIsRefused(array $recipient): void
    {
        $owner = $this->createAndLoginUser(['email' => 'ambiguous@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->postJson('/api/shares/invitations/bulk', $recipient + [
            'comicIds' => [$comic->getId()],
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('one way only', $payload['message']);
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
    }

    public static function conflictingRecipients(): iterable
    {
        yield 'username and email' => [[
            'username' => 'SomeoneElse1234',
            'email' => 'typed@example.com',
        ]];

        yield 'user code and email' => [[
            'userCode' => 'U-7K3M-H91P-R2AX',
            'email' => 'typed@example.com',
        ]];

        yield 'username and user code' => [[
            'username' => 'SomeoneElse1234',
            'userCode' => 'U-7K3M-H91P-R2AX',
        ]];

        yield 'all three' => [[
            'username' => 'SomeoneElse1234',
            'userCode' => 'U-7K3M-H91P-R2AX',
            'email' => 'typed@example.com',
        ]];
    }

    public function testNamingNoRecipientAtAllIsRefused(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'nameless@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('recipient is required', $payload['message']);
    }

    /**
     * A comic the caller cannot share takes the whole batch with it.
     *
     * The alternative is a sender who selected six comics being told five went
     * out, in a per-comic list they have to go and read. The one that did not
     * go is the one they will not notice, and they will not go back for it.
     */
    public function testAMixedSelectionCreatesNothingAtAll(): void
    {
        $stranger = UserFactory::createOne(['email' => 'somebody-else@example.com']);
        $theirs = ComicFactory::new()->ownedBy($stranger)->create();

        $owner = $this->createAndLoginUser(['email' => 'mixed@example.com']);
        $mine = ComicFactory::new()->ownedBy($owner)->create();
        $alsoMine = ComicFactory::new()->ownedBy($owner)->create();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$mine->getId(), $theirs->getId(), $alsoMine->getId()],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(403);
        // Not even the two that were perfectly shareable.
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
    }

    /**
     * A comic that does not exist reads exactly like one that is not yours.
     *
     * Refusing the batch must not turn the endpoint into a comic-id oracle: the
     * two cases have to be one answer, or a caller can walk the id space and
     * learn which rows are real from the wording.
     */
    public function testAMissingComicAndAForeignComicGiveTheSameAnswer(): void
    {
        $stranger = UserFactory::createOne(['email' => 'not-mine@example.com']);
        $theirs = ComicFactory::new()->ownedBy($stranger)->create();

        $owner = $this->createAndLoginUser(['email' => 'prober@example.com']);
        $mine = ComicFactory::new()->ownedBy($owner)->create();

        $foreign = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$mine->getId(), $theirs->getId()],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        $foreignStatus = $this->browser()->getResponse()->getStatusCode();

        $missing = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$mine->getId(), 999_999],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertSame($foreignStatus, $this->browser()->getResponse()->getStatusCode());
        self::assertSame($foreign['message'], $missing['message']);
    }
}
