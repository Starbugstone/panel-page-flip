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
     * The reclassification and the share are one decision, so a refused
     * reclassification must not leave an ordinary share behind.
     */
    public function testAComicYouCannotClassifyBlocksTheWholeShare(): void
    {
        $stranger = UserFactory::createOne(['email' => 'not-yours@example.com'])->object();
        $theirs = ComicFactory::new()->ownedBy($stranger)->create()->object();

        $owner = $this->createAndLoginUser(['email' => 'overreaching@example.com']);
        $mine = ComicFactory::new()->ownedBy($owner)->create()->object();

        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$mine->getId(), $theirs->getId()],
            'email' => 'reader@example.com',
            'senderResponsibilityAccepted' => true,
            'markExplicit' => true,
        ]);

        // Somebody else's comic never reaches the promoter — it is filtered out
        // as unshareable first — so this succeeds for the one that is the
        // caller's and refuses the other, exactly as it would without the flag.
        self::assertSame(1, $payload['created']);
        self::assertTrue($this->reload((int) $mine->getId())->isExplicitContent());
        self::assertFalse($this->reload((int) $theirs->getId())->isExplicitContent());
    }
}
