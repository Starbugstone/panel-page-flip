<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Service\SecurityAuditLogger;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marking comics 18+ without leaving the share.
 *
 * The moment somebody decides a comic is adult is almost always the moment they
 * are about to hand it to somebody else. Making them cancel, find the comic,
 * edit it and come back is how a comic goes out unmarked — so the classification
 * is reachable from the share dialog.
 *
 * The interesting rule is the asymmetry. Sharing may promote a comic to 18+ and
 * may never demote one: an unticked box is the absence of a claim, not a claim
 * that the comic is fine.
 */
final class ShareExplicitPromotionTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    private function reload(int $comicId): Comic
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Comic::class)->find($comicId);
    }

    public function testTickingTheBoxMarksEveryComicInTheShare(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'classifier@example.com']);
        $first = ComicFactory::new()->ownedBy($owner)->create(['title' => 'One'])->object();
        $second = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Two'])->object();

        self::assertFalse($first->isExplicitContent());

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$first->getId(), $second->getId()],
            'email' => 'grown-up@example.com',
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        // On the owner's actual comics, not merely on the share.
        self::assertTrue($this->reload((int) $first->getId())->isExplicitContent());
        self::assertTrue($this->reload((int) $second->getId())->isExplicitContent());
    }

    public function testAComicMarkedDuringSharingIsGatedForTheRecipient(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'gate-setter@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Very Identifiable'])->object();
        $recipient = UserFactory::createOne(['email' => 'reader@example.com'])->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => (string) $recipient->getEmail(),
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($recipient);
        $received = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];

        self::assertTrue($received['requiresAdultConfirmation']);
        // The title is the identifying detail the gate withholds, so the
        // classification is effective from the first response the recipient
        // ever sees.
        self::assertNull($received['comicTitle']);
        self::assertStringNotContainsString(
            'Very Identifiable',
            (string) $this->browser()->getResponse()->getContent()
        );
    }

    /**
     * A recipient who accepted this comic agreed to read something that was not
     * classified 18+. Their old silence is not a declaration about the comic as
     * it is now, so access stops until they say so again.
     */
    public function testCorrectingAComicWhileSharingItReGatesTheRecipientsWhoAlreadyHaveIt(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'late-corrector@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $existing = UserFactory::createOne(['email' => 'already-reading@example.com'])->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => (string) $existing->getEmail(),
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($existing);
        $shareId = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0]['id'];
        $this->postJson('/api/shares/' . $shareId . '/accept', []);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0]['canRead']);

        // The owner shares the same comic with somebody else and corrects the
        // classification on the way past.
        $this->loginAs($owner);
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'somebody-new@example.com',
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($existing);
        $share = $this->getJson('/api/shares/shared-with-me')['sharedWithMe'][0];

        self::assertSame(ComicShare::STATUS_ACCEPTED, $share['status'], 'The share itself is not undone.');
        self::assertTrue($share['requiresAdultConfirmation']);
        self::assertFalse($share['canRead'], 'Reading stops until they declare their age again.');

        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
        self::assertSame('share_dialog', $record->context['via']);
        self::assertFalse($record->context['explicit_before']);
        self::assertTrue($record->context['explicit_after']);
        // Nothing needed *resetting*: this recipient never made an age
        // declaration, because the comic was not classified when they accepted
        // it. The gate closes on them all the same — it is derived from the
        // comic's classification and the absence of a declaration, not from a
        // flag somebody has to remember to clear.
        self::assertSame(0, $record->context['shares_regated']);
    }

    /**
     * The one-way rule. Leaving the box unticked is not a statement that the
     * comic is fine, so it cannot undo a classification somebody made
     * deliberately.
     */
    public function testAnUntickedBoxNeverClearsAnExistingClassification(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'careless@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)
            ->create(['title' => 'Stays Explicit', 'explicitContent' => true])
            ->object();

        foreach ([[], ['markExplicit' => false]] as $index => $extra) {
            $this->postJson('/api/shares/invitations/bulk', array_merge([
                'comicIds' => [$comic->getId()],
                'email' => sprintf('reader%d@example.com', $index),
                'senderResponsibilityAccepted' => true,
            ], $extra));
            self::assertResponseStatusCodeSame(201);

            self::assertTrue(
                $this->reload((int) $comic->getId())->isExplicitContent(),
                'Sharing must never demote a comic.'
            );
        }
    }

    /** An already-explicit comic is not re-marked, so nothing is re-gated. */
    public function testMarkingAnAlreadyExplicitComicChangesNothing(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'redundant@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)
            ->create(['explicitContent' => true])
            ->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->assertNoAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
        self::assertTrue($this->reload((int) $comic->getId())->isExplicitContent());
    }

    public function testAContentCodeCanMarkItsComicsTooAndTheyStayHidden(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'code-classifier@example.com']);
        $first = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Arc One'])->object();
        $second = ComicFactory::new()->ownedBy($owner)->create(['title' => 'Arc Two'])->object();

        $created = $this->postJson('/api/shares/group-codes', [
            'comicIds' => [$first->getId(), $second->getId()],
            'maxUses' => 1,
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        self::assertTrue($this->reload((int) $first->getId())->isExplicitContent());

        // The owner's own list redacts the titles too, because the payload it
        // returns is the one shown next to a code that may be pasted anywhere.
        $listed = $this->getJson('/api/shares/content-codes')['codes'][0];
        self::assertSame(
            ['A comic marked as explicit content (18+)', 'A comic marked as explicit content (18+)'],
            $listed['comicTitles']
        );

        // And redeeming leaves both behind the gate rather than in a collection.
        $this->createAndLoginUser(['email' => 'young-redeemer@example.com']);
        $payload = $this->postJson('/api/shares/content-codes/redeem', ['code' => $created['code']]);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['awaiting_age_confirmation', 'awaiting_age_confirmation'],
            array_column($payload['results'], 'status')
        );
    }

    /**
     * A selection is shared whole or not at all, and the 18+ flag rides on the
     * same decision: a batch containing a comic the caller cannot share leaves
     * behind neither a share nor a reclassification.
     */
    public function testAComicYouCannotClassifyBlocksTheWholeShare(): void
    {
        $stranger = UserFactory::createOne(['email' => 'not-yours@example.com'])->object();
        $theirs = ComicFactory::new()->ownedBy($stranger)->create()->object();

        $owner = $this->createAndLoginUser(['email' => 'overreaching@example.com']);
        $mine = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$mine->getId(), $theirs->getId()],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);

        // One refusal for the whole batch, and the caller's own comic is
        // untouched: a sender told "1 shared" when they asked for 2 has been
        // told the wrong thing, and a comic marked 18+ by a share that never
        // happened is a reclassification nobody asked for.
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload((int) $mine->getId())->isExplicitContent());
        self::assertFalse($this->reload((int) $theirs->getId())->isExplicitContent());

        // And no record claiming otherwise. An audit entry is a statement that
        // something happened; one written for a reclassification that was
        // rolled back is a false statement in the place people go to find out
        // what was true.
        $this->assertNoAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
    }

    /**
     * A refused code leaves no reclassification and no record of one.
     *
     * The promotion used to run before the allowance was claimed, so a request
     * that ran out of allowance had already emitted the audit event for a
     * change that was never flushed.
     */
    public function testARefusedCodeLeavesNeitherTheMarkNorTheRecord(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'over-limit@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        // maxUses outside the permitted range, so the request is refused after
        // the comics have been resolved and authorised — the window the
        // promotion used to sit in.
        $this->postJson('/api/shares/comic-codes', [
            'comicIds' => [$comic->getId()],
            'maxUses' => 999,
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->reload((int) $comic->getId())->isExplicitContent());
        self::assertNoAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
    }

    /**
     * Marking 18+ sticks even when the share itself is entirely duplicate.
     *
     * The reclassification is a change to the owner's own library and does not
     * depend on a relationship being created from it. `inviteMany` returns
     * without committing when there is nothing new to make, so a promotion that
     * rode on that flush was silently dropped for exactly the batch where the
     * owner was correcting comics they had already shared — which is when
     * getting the classification right matters most.
     */
    public function testMarkingExplicitPersistsWhenEveryShareIsADuplicate(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'corrector@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload((int) $comic->getId())->isExplicitContent());

        // The same comic to the same person, now marked 18+. Nothing new to
        // create, and the classification is the whole point of the request.
        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);

        self::assertSame(0, $payload['created']);
        self::assertTrue($this->reload((int) $comic->getId())->isExplicitContent());
        $this->assertLoggedAuditEvent(SecurityAuditLogger::COMIC_EXPLICIT_CLASSIFICATION_CHANGED);
    }
}
