<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ComicShare;
use App\Entity\UserWarning;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Telling one account that something needs to change.
 *
 * The step between noticing a problem and acting on it. What matters is that
 * the in-app notice is the delivery — the email is a copy — and that dismissing
 * one clears the recipient's screen without erasing the record that they were
 * told.
 */
final class UserWarningTest extends AbstractApiTestCase
{
    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /* ---------------------------------------------------------------------- */
    /* Warning about an account                                                */
    /* ---------------------------------------------------------------------- */

    public function testAnAdministratorCanWarnAnAccountAndTheAccountSeesIt(): void
    {
        $reader = UserFactory::createOne(['email' => 'warned@example.com'])->object();

        $this->createAndLoginAdmin();
        $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'Please tag adult material before you share it.',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($reader);
        $waiting = $this->getJson('/api/me/warnings')['warnings'];

        self::assertCount(1, $waiting);
        self::assertSame('Please tag adult material before you share it.', $waiting[0]['message']);
        self::assertSame(UserWarning::SUBJECT_ACCOUNT, $waiting[0]['subject']);
    }

    /**
     * A warning is from the operator of the service, not from a person the
     * recipient can go and argue with.
     */
    public function testTheRecipientIsNotToldWhichAdministratorSentIt(): void
    {
        $reader = UserFactory::createOne(['email' => 'anonymous-sender@example.com'])->object();

        $admin = $this->createAndLoginAdmin();
        $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'A word about your uploads.',
        ]);

        $this->loginAs($reader);
        $payload = json_encode($this->getJson('/api/me/warnings'), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString((string) $admin->getEmail(), $payload);
        self::assertStringNotContainsString('issuedBy', $payload);
    }

    public function testDismissingClearsItFromTheScreenAndKeepsTheRecord(): void
    {
        $reader = UserFactory::createOne(['email' => 'dismisser@example.com'])->object();

        $this->createAndLoginAdmin();
        $created = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'Please read the sharing rules.',
        ]);
        $warningId = $created['warning']['id'];

        $this->loginAs($reader);
        $this->postJson(sprintf('/api/me/warnings/%d/acknowledge', $warningId));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->getJson('/api/me/warnings')['warnings']);

        // Marked, not deleted: "were they told?" gets asked after the second
        // incident, not the first.
        $this->entityManager()->clear();
        $stored = $this->entityManager()->getRepository(UserWarning::class)->find($warningId);
        self::assertNotNull($stored);
        self::assertTrue($stored->isAcknowledged());
    }

    public function testSomebodyElsesWarningCannotBeDismissed(): void
    {
        $reader = UserFactory::createOne(['email' => 'target@example.com'])->object();

        $this->createAndLoginAdmin();
        $created = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'A notice.',
        ]);

        $this->createAndLoginUser(['email' => 'meddler@example.com']);
        $this->postJson(sprintf('/api/me/warnings/%d/acknowledge', $created['warning']['id']));

        // Missing rather than forbidden, so an id cannot be used to find out
        // whether an account has been warned.
        self::assertResponseStatusCodeSame(404);
    }

    /* ---------------------------------------------------------------------- */
    /* Warning about a comic, and about a share                                */
    /* ---------------------------------------------------------------------- */

    public function testWarningAboutAComicWarnsItsOwnerAndNamesIt(): void
    {
        $owner = UserFactory::createOne(['email' => 'comic-owner@example.com'])->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'title' => 'Questionable Comic'])->object();

        $this->createAndLoginAdmin();
        $this->postJson('/api/admin/warnings', [
            'comicId' => $comic->getId(),
            'message' => 'This needs the 18+ flag.',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($owner);
        $waiting = $this->getJson('/api/me/warnings')['warnings'][0];

        self::assertSame(UserWarning::SUBJECT_COMIC, $waiting['subject']);
        self::assertSame('Questionable Comic', $waiting['subjectLabel']);
    }

    /**
     * The usual reason to warn somebody about a comic is that the comic is
     * about to be removed. A notice that then reads as a complaint about
     * nothing in particular is no use to the person receiving it.
     */
    public function testTheComicNameSurvivesTheComicBeingDeleted(): void
    {
        $owner = UserFactory::createOne(['email' => 'about-to-lose-it@example.com'])->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'title' => 'Doomed Comic'])->object();

        $this->createAndLoginAdmin();
        $this->postJson('/api/admin/warnings', [
            'comicId' => $comic->getId(),
            'message' => 'Removing this.',
        ]);

        $this->deleteJson('/api/comics/' . $comic->getId());
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $waiting = $this->getJson('/api/me/warnings')['warnings'][0];

        self::assertSame('Doomed Comic', $waiting['subjectLabel']);
    }

    public function testWarningAboutAShareWarnsWhoeverMadeIt(): void
    {
        $owner = UserFactory::createOne(['email' => 'sharer@example.com'])->object();
        $recipient = UserFactory::createOne(['email' => 'holder@example.com'])->object();
        $comic = ComicFactory::createOne(['owner' => $owner, 'title' => 'Shared Comic'])->object();

        $share = new ComicShare($comic, $owner, (string) $recipient->getEmail());
        $share->markAccepted($recipient);
        $this->entityManager()->persist($share);
        $this->entityManager()->flush();

        $this->createAndLoginAdmin();
        $this->postJson('/api/admin/warnings', [
            'shareId' => $share->getId(),
            'message' => 'Do not share this one.',
        ]);
        self::assertResponseStatusCodeSame(201);

        // The sharer, not the person they shared with.
        $this->loginAs($recipient);
        self::assertSame([], $this->getJson('/api/me/warnings')['warnings']);

        $this->loginAs($owner);
        $waiting = $this->getJson('/api/me/warnings')['warnings'][0];
        self::assertSame(UserWarning::SUBJECT_SHARE, $waiting['subject']);
        self::assertSame('Shared Comic', $waiting['subjectLabel']);
    }

    /* ---------------------------------------------------------------------- */
    /* What the endpoint refuses                                               */
    /* ---------------------------------------------------------------------- */

    public function testAMessageIsRequired(): void
    {
        $reader = UserFactory::createOne(['email' => 'no-message@example.com'])->object();

        $this->createAndLoginAdmin();

        $payload = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => "   \n  ",
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('A message is required.', $payload['message']);
    }

    public function testAnOverlongMessageIsRefusedRatherThanTruncated(): void
    {
        $reader = UserFactory::createOne(['email' => 'verbose@example.com'])->object();

        $this->createAndLoginAdmin();
        $payload = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => str_repeat('a', UserWarning::MAX_MESSAGE_LENGTH + 1),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('at most', $payload['message']);
    }

    /**
     * A body carrying two targets must not be resolved in whichever order the
     * implementation happens to check them.
     */
    public function testExactlyOneTargetIsRequired(): void
    {
        $reader = UserFactory::createOne(['email' => 'ambiguous@example.com'])->object();
        $comic = ComicFactory::createOne(['owner' => $reader])->object();

        $this->createAndLoginAdmin();

        $both = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'comicId' => $comic->getId(),
            'message' => 'Which one?',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('exactly one', $both['message']);

        $this->postJson('/api/admin/warnings', ['message' => 'Nobody in particular.']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnAdministratorCannotWarnThemselves(): void
    {
        $admin = $this->createAndLoginAdmin();

        $payload = $this->postJson('/api/admin/warnings', [
            'userId' => $admin->getId(),
            'message' => 'Note to self.',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('You cannot warn yourself.', $payload['message']);
    }

    public function testOnlyAdministratorsMayIssueWarnings(): void
    {
        $reader = UserFactory::createOne(['email' => 'not-yours-to-warn@example.com'])->object();

        $this->createAndLoginUser(['email' => 'ordinary-user@example.com']);
        $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'You are not an administrator.',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /* ---------------------------------------------------------------------- */
    /* The emailed copy                                                        */
    /* ---------------------------------------------------------------------- */

    public function testNoEmailIsSentUnlessOneIsAskedFor(): void
    {
        $reader = UserFactory::createOne(['email' => 'no-mail@example.com'])->object();

        $this->createAndLoginAdmin();
        $created = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'In-app only.',
        ]);

        self::assertEmailCount(0);
        self::assertSame(UserWarning::EMAIL_NOT_REQUESTED, $created['warning']['emailState']);
    }

    /** A missing key is the absence of a request to email anybody. */
    public function testATruthyStringIsNotARequestToEmail(): void
    {
        $reader = UserFactory::createOne(['email' => 'not-truthy@example.com'])->object();

        $this->createAndLoginAdmin();
        $created = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'In-app only.',
            'sendEmail' => 'yes',
        ]);

        self::assertEmailCount(0);
        self::assertSame(UserWarning::EMAIL_NOT_REQUESTED, $created['warning']['emailState']);
    }

    public function testTheEmailedCopyCarriesTheSameMessage(): void
    {
        $reader = UserFactory::createOne(['email' => 'mailed@example.com'])->object();

        $this->createAndLoginAdmin();
        $created = $this->postJson('/api/admin/warnings', [
            'userId' => $reader->getId(),
            'message' => 'Tag your adult material.',
            'sendEmail' => true,
        ]);

        self::assertSame(UserWarning::EMAIL_SENT, $created['warning']['emailState']);
        self::assertEmailCount(1);

        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertStringContainsString('Tag your adult material.', (string) $message->getHtmlBody());
        self::assertSame('mailed@example.com', $message->getTo()[0]->getAddress());
    }
}
