<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ComicShare;
use App\Message\ShareInvitationNotification;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\InvitationLinkAssertions;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\SwitchableMailer;
use App\Tests\Support\SwitchableMessageBus;

/**
 * The share is what is true; the email is an announcement of it.
 *
 * SMTP is not a participant in a database transaction, so the previous
 * arrangement — send inside the transaction, roll back on failure — bought "no
 * invitation nobody was told about" at the price of losing a perfectly good
 * share every time a mail server was briefly busy. These tests hold the new
 * bargain: the relationships commit, the notice is queued, and a notice that
 * fails is something the owner is told about rather than something that
 * destroys their work.
 */
final class ShareNotificationTest extends AbstractApiTestCase
{
    use InvitationLinkAssertions;

    /** Stand in for an SMTP server having a bad afternoon. */
    private function breakTheMailer(): void
    {
        SwitchableMailer::failEverything();
    }

    public function testShareLinksExistOnlyInTheEmailAndAreMintedAsItIsWritten(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'notifier@example.com']);
        $comics = [
            (int) ComicFactory::new()->ownedBy($owner)->create(['title' => 'First'])->object()->getId(),
            (int) ComicFactory::new()->ownedBy($owner)->create(['title' => 'Second'])->object()->getId(),
            (int) ComicFactory::new()->ownedBy($owner)->create(['title' => 'Third'])->object()->getId(),
        ];

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => $comics,
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(3, $payload['created']);

        // Three comics, one message: the recipient gets a notice, not an inbox
        // full of them. Each comic still carries its own link, because each
        // invitation is still answered on its own.
        self::assertEmailCount(1);
        self::assertCount(3, $this->invitationUrlsFromEmail());

        // Nothing in the response carries a link. Only the hash is stored, and
        // the plaintext existed for exactly as long as it took to render.
        self::assertStringNotContainsString(
            '/share/invitation/',
            (string) $this->browser()->getResponse()->getContent()
        );
    }

    public function testInvitationEmailUsesPublicIdentityAndProductName(): void
    {
        $owner = $this->createAndLoginUser([
            'email' => 'private-owner@example.com',
            'name' => null,
            'username' => 'PublicOwner1234',
        ]);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'recipient@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = (string) self::getMailerMessage()->getHtmlBody();
        self::assertStringContainsString('Test Sender', $body);
        self::assertStringContainsString('@PublicOwner1234', $body);
        self::assertMatchesRegularExpression('/asked\s+Test Sender\s+to share this comic with you/', $body);
        self::assertStringNotContainsString('Comic Share Platform', $body);
        self::assertStringNotContainsString('private-owner@example.com', $body);
        self::assertStringNotContainsString('entered your address', $body);
    }

    public function testTheQueuedNoticeCarriesIdsAndNoSecrets(): void
    {
        $notification = new ShareInvitationNotification(7, [11, 12, 13]);

        // What actually lands in the message store. A prepared Email would put
        // plaintext bearer tokens in a database table, retry them through it,
        // and leave them in the failure transport for an operator to read.
        $serialised = serialize($notification);

        self::assertStringNotContainsString('/share/invitation/', $serialised);
        self::assertStringNotContainsString('@', $serialised);
        self::assertSame([11, 12, 13], $notification->shareIds);
        self::assertSame(7, $notification->ownerId);
    }

    /**
     * The point of the whole rearrangement: a mail server having a bad
     * afternoon must not destroy a share the owner meant to make.
     */
    public function testAFailedNoticeLeavesTheShareStandingAndSaysSo(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'unlucky@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Still Shared'])->object();

        $this->breakTheMailer();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'unreachable@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        // The relationship exists, and the owner can see both that and the fact
        // that nobody was told about it.
        $recipient = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame(ComicShare::STATUS_PENDING, $recipient['status']);
        self::assertSame(ComicShare::NOTIFICATION_FAILED, $recipient['notificationState']);
        self::assertNull($recipient['notifiedAt']);
        self::assertTrue($recipient['canResend'], 'A failed notice has to be retryable.');
    }

    public function testASuccessfulNoticeIsRecordedAsSent(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'lucky@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'reachable@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        $recipient = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame(ComicShare::NOTIFICATION_SENT, $recipient['notificationState']);
        self::assertNotNull($recipient['notifiedAt']);
    }

    /**
     * Resending is the manual way back from a failed notice, and it reports its
     * own outcome — somebody standing in front of the screen is asking whether
     * it went this time.
     */
    public function testResendingRecoversFromAFailedNotice(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'retrier@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->breakTheMailer();
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'eventually@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        $shareId = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0]['id'];

        // The mail server comes back, and the owner presses resend.
        SwitchableMailer::reset();
        $resent = $this->postJson('/api/shares/' . $shareId . '/resend');
        self::assertResponseIsSuccessful();
        // The link went to the recipient's inbox and nowhere else. Resend is no
        // exception to that, however convenient handing it back would be.
        self::assertArrayNotHasKey('invitationUrl', $resent);
        self::assertStringContainsString('/share/invitation/', $this->invitationUrlFromEmail());

        $recipient = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame(ComicShare::NOTIFICATION_SENT, $recipient['notificationState']);
    }

    public function testAFailedResendReportsItselfWithoutTouchingTheShare(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'persistent@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'gone-away@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        $shareId = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0]['id'];

        $this->breakTheMailer();
        $payload = $this->postJson('/api/shares/' . $shareId . '/resend');

        self::assertResponseStatusCodeSame(502);
        self::assertStringContainsString('The share is unaffected', $payload['message']);
        self::assertStringContainsString('Sharing page', $payload['message']);
        self::assertStringNotContainsString('copy the link', $payload['message']);

        $recipient = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame(ComicShare::STATUS_PENDING, $recipient['status']);
        self::assertSame(ComicShare::NOTIFICATION_FAILED, $recipient['notificationState']);
    }

    /**
     * A notice about a relationship that has moved on is not sent. The worker
     * runs after the request, so the state it reads is whatever is true then.
     */
    public function testANoticeForARevokedShareIsQuietlyDropped(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'changed-mind@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'never-mind@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        $shareId = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0]['id'];
        $this->postJson('/api/shares/' . $shareId . '/revoke', []);

        $handler = static::getContainer()->get(\App\MessageHandler\ShareInvitationNotificationHandler::class);
        $handler(new ShareInvitationNotification((int) $owner->getId(), [$shareId]));

        // Nothing sent, and nothing thrown: there is no notice to give about an
        // invitation that has been withdrawn.
        self::assertEmailCount(0);
    }

    public function testANoticeForAnOwnerWhoHasGoneIsDroppedRatherThanRetriedForEver(): void
    {
        $handler = static::getContainer()->get(\App\MessageHandler\ShareInvitationNotificationHandler::class);

        $handler(new ShareInvitationNotification(999999, [1, 2]));

        self::assertEmailCount(0);
    }

    /**
     * A share arrives with its notice still to go, so nothing can read the
     * initial state as "we tried and it worked".
     */
    public function testANewShareStartsWithItsNoticeOutstanding(): void
    {
        $owner = UserFactory::createOne(['email' => 'about-to-share@example.com'])->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $share = new ComicShare($comic, $owner, 'pending-notice@example.com');
        $share->markPending(new \DateTimeImmutable('+1 day'))->awaitNotification();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($share);
        $entityManager->flush();

        self::assertSame(ComicShare::NOTIFICATION_PENDING, $share->getNotificationState());
        self::assertNull($share->getNotifiedAt());
    }

    /**
     * A queue that is down costs the notice, never the share.
     *
     * Committing before dispatching moved this failure rather than removing it.
     * An exception escaping the dispatch would reach the owner as a 500 for a
     * share that exists — so they would be told it failed, retry, and meet
     * their own duplicates. That is the half-success the whole commit-then-
     * announce design exists to prevent, arriving one step later than before.
     */
    public function testAQueueThatIsDownStillReportsTheShareAsCreated(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'unqueued@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        SwitchableMessageBus::failEverything();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'never-told@example.com',
            'senderResponsibilityAccepted' => true,
        ]);

        // The truthful answer: the share exists, and the notice did not go.
        self::assertResponseIsSuccessful();
        self::assertSame(1, $payload['created']);
        self::assertSame(
            ComicShare::NOTIFICATION_FAILED,
            $payload['results'][0]['notificationState']
        );

        SwitchableMessageBus::reset();

        // And the state survived the request, so the owner is told about it on
        // the Sharing page rather than only in the response they have closed.
        $recipient = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame(ComicShare::NOTIFICATION_FAILED, $recipient['notificationState']);

        // Recoverable by hand, which is what the failed state is for.
        $this->postJson('/api/shares/' . $recipient['id'] . '/resend');
        self::assertResponseIsSuccessful();

        $afterResend = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0]['recipients'][0];
        self::assertSame(ComicShare::NOTIFICATION_SENT, $afterResend['notificationState']);
    }
}
