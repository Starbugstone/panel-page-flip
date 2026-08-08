<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;

/**
 * The sharing trail, and the two acknowledgements the 18+ gate turns on.
 *
 * The acknowledgements are already stored on the share itself — that is the
 * canonical evidence, and it is better evidence than any log line because it is
 * attached to the record it is about. What these events add is a trail that can
 * be read in order, which is why they carry identifiers and timestamps and
 * nothing else.
 *
 * "Nothing else" is the part worth testing. An explicit comic's title, its
 * cover, and the invitation token are precisely what the gate exists to
 * withhold; writing them into an audit file would hand them to anybody who can
 * read logs, which is a different set of people from the ones the share was
 * meant for.
 */
final class ShareAuditLoggingTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    private const EXPLICIT_TITLE = 'A Very Identifiable Explicit Title';

    /** The link returned by the most recent {@see invite()}. */
    private string $lastInvitationUrl = '';

    public function testCreatingAShareRecordsTheSendersAcknowledgement(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'sender@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'An Ordinary Comic'])->object();
        $recipient = UserFactory::createOne(['email' => 'invitee@test.local'])->object();

        $this->invite($comic, (string) $recipient->getEmail());

        $created = $this->assertLoggedAuditEvent(SecurityAuditLogger::SHARE_CREATED);
        self::assertSame($owner->getId(), $created->context['actor_user_id']);
        self::assertSame($comic->getId(), $created->context['comic_id']);

        $acknowledged = $this->assertLoggedAuditEvent(SecurityAuditLogger::SHARE_SENDER_RESPONSIBILITY_ACCEPTED);
        self::assertSame($owner->getId(), $acknowledged->context['actor_user_id']);
        self::assertSame($comic->getId(), $acknowledged->context['comic_id']);
        self::assertNotNull($acknowledged->context['target_id']);
        // Server-generated, and matching the timestamp on the share, which is
        // what would actually be produced if anybody asked for evidence.
        self::assertNotNull($acknowledged->context['accepted_at']);
    }

    public function testTheAcknowledgementRecordCarriesNoTokenTitleOrAddress(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'careful-sender@test.local']);
        $comic = $this->explicitComic($owner);
        $recipient = UserFactory::createOne(['email' => 'careful-invitee@test.local'])->object();

        $response = $this->invite($comic, (string) $recipient->getEmail());
        $invitationUrl = $response['invitationUrl'];
        $token = substr($invitationUrl, strrpos($invitationUrl, '/') + 1);

        $this->assertNothingLogged($token, 'An invitation token');
        $this->assertNothingLogged($invitationUrl, 'A full invitation URL');
        $this->assertNothingLogged(self::EXPLICIT_TITLE, "An explicit comic's title");
        $this->assertNothingLogged((string) $recipient->getEmail(), "A recipient's email address");
    }

    public function testAnAdultConfirmationIsAuditedAndDoesNotEmailAdministrators(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'explicit-owner@test.local']);
        $comic = $this->explicitComic($owner);
        $recipient = UserFactory::createOne(['email' => 'confirming@test.local'])->object();
        $this->invite($comic, (string) $recipient->getEmail());

        $shareId = $this->shareIdFor($comic);
        $this->loginAs($recipient);
        $this->postJson('/api/shares/' . $shareId . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::SHARE_ADULT_CONFIRMED);
        self::assertSame($recipient->getId(), $record->context['actor_user_id']);
        self::assertSame($shareId, $record->context['target_id']);
        self::assertNotNull($record->context['confirmed_at']);

        // A recipient declaring their age is the feature working as designed.
        // Mailing an administrator about it would be surveillance dressed as
        // security, and would drown the alerts that matter.
        self::assertSame([], $this->alertsAbout(SecurityAuditLogger::SHARE_ADULT_CONFIRMED));
        $this->assertNothingLogged(self::EXPLICIT_TITLE, "An explicit comic's title");
    }

    /**
     * The UI shows the declaration before it shows the accept button, so
     * reaching this is not a user making a mistake — it is somebody calling the
     * API directly to see whether the gate is real.
     */
    public function testAcceptingBeforeConfirmingAdulthoodIsRecordedAsABypassAttempt(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'gated-owner@test.local']);
        $comic = $this->explicitComic($owner);
        $recipient = UserFactory::createOne(['email' => 'impatient@test.local'])->object();
        $this->invite($comic, (string) $recipient->getEmail());

        $shareId = $this->shareIdFor($comic);
        $this->loginAs($recipient);
        $this->postJson('/api/shares/' . $shareId . '/accept');
        self::assertResponseStatusCodeSame(403);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT);
        self::assertSame($recipient->getId(), $record->context['actor_user_id']);
        self::assertSame('accept_without_adult_confirmation', $record->context['reason']);
        self::assertSame(SecurityAuditLogger::RESULT_DENIED, $record->context['result']);
    }

    public function testRepeatedBypassAttemptsProduceOneThrottledAlert(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'gated-owner-2@test.local']);
        $comic = $this->explicitComic($owner);
        $recipient = UserFactory::createOne(['email' => 'persistent@test.local'])->object();
        $this->invite($comic, (string) $recipient->getEmail());

        $shareId = $this->shareIdFor($comic);
        $this->loginAs($recipient);

        // Five is the threshold this event uses — tighter than the general
        // authorization one, because there is no innocent way to arrive here.
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->postJson('/api/shares/' . $shareId . '/accept');
            self::assertResponseStatusCodeSame(403);
        }

        self::assertCount(5, $this->securityRecords(SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT));
        self::assertCount(1, $this->alertsAbout(SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT));

        // And the sixth is logged in silence.
        $this->postJson('/api/shares/' . $shareId . '/accept');
        self::assertCount(6, $this->securityRecords(SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT));
        self::assertSame([], $this->alertsAbout(SecurityAuditLogger::ADULT_GATE_BYPASS_ATTEMPT));
    }

    /**
     * An age declaration is made by one person about themselves, so making it
     * on somebody else's invitation is not a mistake the UI can produce.
     */
    public function testConfirmingSomebodyElsesShareIsARecordedSecurityEvent(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'third-party-owner@test.local']);
        $comic = $this->explicitComic($owner);
        $recipient = UserFactory::createOne(['email' => 'intended@test.local'])->object();
        $this->invite($comic, (string) $recipient->getEmail());

        $invitationUrl = $this->lastInvitationUrl;
        $token = substr($invitationUrl, strrpos($invitationUrl, '/') + 1);

        // Somebody who was forwarded the link, signed in as themselves.
        $interloper = UserFactory::createOne(['email' => 'interloper@test.local'])->object();
        $this->loginAs($interloper);
        $this->postJson('/api/shares/invitations/' . $token . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseStatusCodeSame(403);

        $record = $this->assertLoggedSecurityEvent(SecurityAuditLogger::SHARE_WRONG_RECIPIENT);
        self::assertSame($interloper->getId(), $record->context['actor_user_id']);
        self::assertTrue($record->context['explicit_content']);
    }

    public function testReclassifyingAComicIsAuditedInBothDirections(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'reclassifier@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => self::EXPLICIT_TITLE])->object();

        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();

        $marked = $this->assertLoggedAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
        self::assertFalse($marked->context['explicit_before']);
        self::assertTrue($marked->context['explicit_after']);
        self::assertSame($comic->getId(), $marked->context['comic_id']);
        self::assertSame(0, $marked->context['shares_regated']);

        $this->clearSecurityLog();

        // Turning it off is the direction that opens something up, and it is
        // the change nobody would go looking for.
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => false]);
        self::assertResponseIsSuccessful();

        $unmarked = $this->assertLoggedAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
        self::assertTrue($unmarked->context['explicit_before']);
        self::assertFalse($unmarked->context['explicit_after']);

        $this->assertNothingLogged(self::EXPLICIT_TITLE, "A comic's title");
    }

    public function testReclassificationRecordsHowManyRecipientsWereReGated(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'regater@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Innocuous For Now'])->object();
        $recipient = UserFactory::createOne(['email' => 'already-reading@test.local'])->object();

        $this->invite($comic, (string) $recipient->getEmail());
        $shareId = $this->shareIdFor($comic);

        $this->loginAs($recipient);
        $this->postJson('/api/shares/' . $shareId . '/accept');
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        $this->clearSecurityLog();
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
        // Nobody had declared an age for a comic that was not explicit, so
        // there is nothing to reset — but the field has to be there, because
        // "how many people lost access" is the question this event answers.
        self::assertSame(0, $record->context['shares_regated']);
    }

    /**
     * The case the count exists for: somebody was reading an explicit comic
     * under a declaration they had already made, the owner unmarked it and
     * marked it again, and that declaration no longer covers the comic as it is
     * now. They lose access until they say so again, and the record says how
     * many people that was.
     */
    public function testReGatingAConfirmedRecipientIsCounted(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'regater2@test.local']);
        $comic = $this->explicitComic($owner);
        $recipient = UserFactory::createOne(['email' => 'confirmed-reader@test.local'])->object();

        $this->invite($comic, (string) $recipient->getEmail());
        $shareId = $this->shareIdFor($comic);

        $this->loginAs($recipient);
        $this->postJson('/api/shares/' . $shareId . '/confirm-adult', ['adultConfirmed' => true]);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/shares/' . $shareId . '/accept');
        self::assertResponseIsSuccessful();

        $this->loginAs($owner);
        // Off, then on again. Only the false-to-true edge re-gates.
        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => false]);
        self::assertResponseIsSuccessful();
        $this->clearSecurityLog();

        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
        self::assertTrue($record->context['explicit_after']);
        self::assertSame(1, $record->context['shares_regated']);
    }

    public function testDeletingAComicRecordsHowManyRecipientsLostAccess(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'deleter@test.local']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Doomed Comic'])->object();
        $comicId = $comic->getId();
        $recipient = UserFactory::createOne(['email' => 'loses-access@test.local'])->object();
        $this->invite($comic, (string) $recipient->getEmail());

        $this->clearSecurityLog();
        $this->deleteJson('/api/comics/' . $comicId);
        self::assertResponseIsSuccessful();

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::COMIC_DELETED);
        self::assertSame($owner->getId(), $record->context['actor_user_id']);
        self::assertSame($comicId, $record->context['comic_id']);
        self::assertSame(1, $record->context['shares_tombstoned']);

        // A deletion is a mutation worth auditing. Reading the comic is not,
        // and no read event exists to have been logged.
        $this->assertNothingLogged('Doomed Comic', "A comic's title");
    }

    /* ---------------------------------------------------------------------- */

    /** @return array<string, mixed> */
    private function invite(Comic $comic, string $email): array
    {
        $response = $this->postJson(
            '/api/shares/comics/' . $comic->getId() . '/invitations',
            ['email' => $email, 'senderResponsibilityAccepted' => true]
        );
        self::assertResponseStatusCodeSame(201);
        $this->lastInvitationUrl = $response['invitationUrl'];

        return $response;
    }

    private function explicitComic(User $owner): Comic
    {
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => self::EXPLICIT_TITLE])->object();

        $this->patchJson('/api/comics/' . $comic->getId(), ['explicitContent' => true]);
        self::assertResponseIsSuccessful();
        $this->clearSecurityLog();

        return $comic;
    }

    private function shareIdFor(Comic $comic): int
    {
        $shares = static::getContainer()->get(ComicShareRepository::class)
            ->findBy(['comic' => $comic->getId()]);

        self::assertNotEmpty($shares, 'Expected a share for this comic.');
        /** @var ComicShare $share */
        $share = $shares[0];

        return (int) $share->getId();
    }
}
