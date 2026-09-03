<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Service\SecurityAuditLogger;
use App\Service\UsernamePolicy;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use App\Tests\Functional\SecurityLogAssertions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The public identity every account has.
 *
 * A username is what a sender reads before handing a comic over. That makes it
 * load-bearing in a way a display name is not: two people can both be called
 * Matthew, so "Sharing with: Matthew" confirms nothing, while
 * "@SilverOtter4821" names exactly one account.
 *
 * Most of what is asserted here is what the identity must *not* do — collide
 * with itself under a different capitalisation, impersonate the service, be
 * derivable from the account behind it, or turn into a directory of who exists.
 */
final class UsernameIdentityTest extends AbstractApiTestCase
{
    use SecurityLogAssertions;

    private function register(array $overrides = []): array
    {
        return $this->postJson('/api/register', $overrides + [
            'email' => 'newcomer@example.com',
            'password' => UserFactory::PASSWORD,
            'name' => 'Newcomer',
            'agreeTerms' => true,
        ]);
    }

    /* ---------------------------------------------------------------------- */
    /* Every account has one                                                   */
    /* ---------------------------------------------------------------------- */

    public function testAnAccountIsBornWithAUsernameAndAUserCode(): void
    {
        $this->register();
        self::assertResponseStatusCodeSame(201);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'newcomer@example.com']);

        self::assertNotNull($user);
        self::assertNull(UsernamePolicy::validate($user->getUsername()));
        self::assertSame(UsernamePolicy::canonicalise($user->getUsername()), $user->getUsernameCanonical());
        // Issued at creation rather than on first use: a code is how other
        // people reach this account, and one that only appears when its owner
        // next visits the Sharing page cannot be shared with until then.
        self::assertNotSame('', $user->getUserCode());
    }

    /**
     * The invariant is structural, not something each of the several ways to
     * create a user has to remember.
     */
    public function testAnAccountCreatedByAnAdministratorAlsoGetsOne(): void
    {
        $this->createAndLoginAdmin(['email' => 'operator@example.com']);

        $this->postJson('/api/users', [
            'email' => 'made-by-admin@example.com',
            'name' => 'Made By Admin',
            'password' => UserFactory::PASSWORD,
            'roles' => ['ROLE_USER'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'made-by-admin@example.com']);

        self::assertNotNull($user);
        self::assertNull(UsernamePolicy::validate($user->getUsername()));
        self::assertNotSame('', $user->getUserCode());
    }

    public function testTheRegistrationFormCanBeOfferedANameNobodyHolds(): void
    {
        $suggested = $this->getJson('/api/users/username-suggestion')['username'];

        self::assertResponseIsSuccessful();
        self::assertNull(UsernamePolicy::validate($suggested));
        self::assertTrue($this->getJson('/api/users/username-available?username=' . $suggested)['available']);

        // Offering another gives another; the field has a "generate another"
        // button behind it.
        self::assertNotSame($suggested, $this->getJson('/api/users/username-suggestion')['username']);
    }

    public function testSomebodyCanRegisterWithTheNameTheyChose(): void
    {
        $this->register(['username' => 'ChosenByMe']);

        self::assertResponseStatusCodeSame(201);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'newcomer@example.com']);
        self::assertSame('ChosenByMe', $user->getUsername());
    }

    public function testRegistrationRefusesATakenNameAndOffersAnotherOne(): void
    {
        UserFactory::createOne(['email' => 'first@example.com']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $taken = $entityManager->getRepository(User::class)->findOneBy(['email' => 'first@example.com'])->getUsername();

        // Shouted, because uniqueness is judged without regard to case: an
        // account differing only in capitalisation would make every sharing
        // confirmation ambiguous.
        $payload = $this->register(['username' => strtoupper($taken)]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('already taken', $payload['errors']['username']);
        // A dead end is not an answer — the form needs something to put in the
        // field.
        self::assertNull(UsernamePolicy::validate($payload['suggestion']));

        self::assertNull($entityManager->getRepository(User::class)->findOneBy(['email' => 'newcomer@example.com']));
    }

    public function testRegistrationRefusesAReservedName(): void
    {
        $payload = $this->register(['username' => 'support']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('reserved', $payload['errors']['username']);
    }

    public function testRegistrationRefusesAMalformedName(): void
    {
        $payload = $this->register(['username' => 'not a username']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('letters, numbers', $payload['errors']['username']);
    }

    public function testAvailabilityExplainsWhyANameCannotBeUsed(): void
    {
        self::assertFalse($this->getJson('/api/users/username-available?username=ab')['available']);
        self::assertStringContainsString(
            'between 3 and 32',
            $this->getJson('/api/users/username-available?username=ab')['message']
        );

        self::assertFalse($this->getJson('/api/users/username-available?username=root')['available']);
        self::assertStringContainsString(
            'reserved',
            $this->getJson('/api/users/username-available?username=root')['message']
        );
    }

    /* ---------------------------------------------------------------------- */
    /* Resolution                                                              */
    /* ---------------------------------------------------------------------- */

    public function testResolvingAUsernameNamesThePersonAndNothingElse(): void
    {
        $recipient = UserFactory::createOne([
            'email' => 'not-for-sharing@example.com',
            'name' => 'Named Reader',
        ]);

        $this->createAndLoginUser(['email' => 'looker@example.com']);
        $payload = $this->postJson('/api/users/resolve-username', [
            'username' => $recipient->getUsername(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($recipient->getUsername(), $payload['recipient']['username']);
        self::assertSame('Named Reader', $payload['recipient']['name']);
        self::assertSame(
            sprintf('Named Reader (@%s)', $recipient->getUsername()),
            $payload['recipient']['label']
        );
        self::assertStringNotContainsString(
            'not-for-sharing@example.com',
            (string) $this->browser()->getResponse()->getContent()
        );
        self::assertArrayNotHasKey('id', $payload['recipient']);
        self::assertArrayNotHasKey('email', $payload['recipient']);
    }

    /**
     * The @ is how a username is written, not part of it, so pasting one out of
     * a chat window works.
     */
    public function testTheAtSignIsAccepted(): void
    {
        $recipient = UserFactory::createOne(['email' => 'at-sign@example.com']);

        $this->createAndLoginUser(['email' => 'paster@example.com']);
        $this->postJson('/api/users/resolve-username', [
            'username' => '@' . $recipient->getUsername(),
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testResolutionIsCaseInsensitive(): void
    {
        $recipient = UserFactory::createOne(['email' => 'shouted@example.com']);

        $this->createAndLoginUser(['email' => 'shouter@example.com']);
        $payload = $this->postJson('/api/users/resolve-username', [
            'username' => strtoupper($recipient->getUsername()),
        ]);

        self::assertResponseIsSuccessful();
        // Answered with the name as its owner writes it, not as it was typed.
        self::assertSame($recipient->getUsername(), $payload['recipient']['username']);
    }

    /**
     * Two different refusals, deliberately answered differently.
     *
     * Looking your own name up is a conflict in the lookup: nothing was asked
     * for yet, and 409 with "your own username" is what tells the box which
     * name it just resolved. Actually offering yourself a comic is the sharing
     * rule, and ComicShareService owns that one for every recipient form —
     * address, username or user code — so it answers 400 and one sentence.
     * A copy of it lived in the controller and gave a username a 409 while the
     * same refusal for an address was a 400.
     */
    public function testYouCannotShareWithYourself(): void
    {
        $me = $this->createAndLoginUser(['email' => 'solo@example.com']);

        $this->postJson('/api/users/resolve-username', ['username' => $me->getUsername()]);
        self::assertResponseStatusCodeSame(409);

        $comic = ComicFactory::new()->ownedBy($me)->create();
        $payload = $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'username' => $me->getUsername(),
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('You cannot share a comic with yourself.', $payload['message']);
    }

    /**
     * There is no directory. Resolution is exact, one name per request, and a
     * near miss is a miss — nothing here can be turned into a list of accounts.
     */
    public function testThereIsNoWayToSearchForAccounts(): void
    {
        UserFactory::createOne(['email' => 'findable@example.com', 'name' => 'Findable']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $target = $entityManager->getRepository(User::class)->findOneBy(['email' => 'findable@example.com']);

        $this->createAndLoginUser(['email' => 'hunter@example.com']);

        foreach ([
            substr($target->getUsername(), 0, 5),
            substr($target->getUsername(), 0, -1),
            'Findable',
            'findable@example.com',
        ] as $guess) {
            $this->postJson('/api/users/resolve-username', ['username' => $guess]);
            self::assertResponseStatusCodeSame(
                404,
                sprintf('"%s" is not the username and must not resolve.', $guess)
            );
        }
    }

    /**
     * Guessing at handles is the one thing an exact lookup with no directory
     * behind it exists to be bad at, and a username is short and memorable
     * where a code is sixty random bits — so the limit is the real control.
     */
    public function testGuessingAtUsernamesRunsOutOfAllowance(): void
    {
        $this->createAndLoginUser(['email' => 'guesser@example.com']);

        $refused = null;
        for ($attempt = 0; $attempt < 40 && $refused === null; ++$attempt) {
            $payload = $this->postJson('/api/users/resolve-username', [
                'username' => sprintf('NobodyCalledThis%d', $attempt),
            ]);

            if ($this->browser()->getResponse()->getStatusCode() === 429) {
                $refused = $payload;
            }
        }

        self::assertNotNull($refused, 'Username lookups should run out of allowance.');
        self::assertStringContainsString('Too many usernames', $refused['message']);
        $this->assertLoggedSecurityEvent(SecurityAuditLogger::USERNAME_ENUMERATION_ATTEMPT);
    }

    /* ---------------------------------------------------------------------- */
    /* Sharing by username                                                     */
    /* ---------------------------------------------------------------------- */

    public function testSharingByUsernameNeverShowsTheSenderTheAddress(): void
    {
        $recipient = UserFactory::createOne([
            'email' => 'by-handle@example.com',
            'name' => 'Handle Holder',
        ]);

        $owner = $this->createAndLoginUser(['email' => 'handle-sender@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'username' => $recipient->getUsername(),
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $entry = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0];

        self::assertStringNotContainsString(
            'by-handle@example.com',
            (string) $this->browser()->getResponse()->getContent()
        );
        self::assertNull($entry['recipientEmail']);
        self::assertSame($recipient->getUsername(), $entry['recipientUsername']);

        // And the recipient has an ordinary invitation waiting.
        $this->loginAs($recipient);
        self::assertCount(1, $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);
    }

    public function testSharingWithAUsernameNobodyHoldsRefusesTheWholeRequest(): void
    {
        $owner = $this->createAndLoginUser(['email' => 'misaddressed@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();

        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'username' => 'NobodyAtAll1234',
            'senderResponsibilityAccepted' => true,
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->getJson('/api/shares/shared-by-me')['sharedByMe']);
    }

    /* ---------------------------------------------------------------------- */
    /* Changing it                                                             */
    /* ---------------------------------------------------------------------- */

    public function testAUsernameCanBeChangedAndIsRecorded(): void
    {
        $user = $this->createAndLoginUser(['email' => 'renaming@example.com']);
        $before = $user->getUsername();

        $payload = $this->putJson('/api/users/username', ['username' => 'BrandNewHandle']);

        self::assertResponseIsSuccessful();
        self::assertSame('BrandNewHandle', $payload['username']);
        self::assertSame('BrandNewHandle', $this->getJson('/api/me')['user']['username']);

        // Both names go in the record, because the question it exists to answer
        // is "who used to be called that?" after somebody reports an
        // impersonation — and neither name is a secret.
        $record = $this->assertLoggedAuditEvent(SecurityAuditLogger::USERNAME_CHANGED);
        self::assertSame($before, $record->context['previous_username']);
        self::assertSame('BrandNewHandle', $record->context['username']);
    }

    /**
     * Shares are relationships and not addresses, so nothing a rename touches
     * can take a comic away from anybody.
     */
    public function testRenamingLeavesExistingSharesUntouched(): void
    {
        $recipient = UserFactory::createOne(['email' => 'keeps-them@example.com']);

        $owner = $this->createAndLoginUser(['email' => 'gave-them@example.com']);
        $comic = ComicFactory::new()->ownedBy($owner)->create();
        $this->postJson('/api/shares/invitations/bulk', [
            'comicIds' => [$comic->getId()],
            'username' => $recipient->getUsername(),
            'senderResponsibilityAccepted' => true,
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($recipient);
        $this->putJson('/api/users/username', ['username' => 'CompletelyDifferent']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->getJson('/api/shares/shared-with-me')['sharedWithMe']);

        // And the owner reads the new handle, not the retired one.
        $this->loginAs($owner);
        $entry = $this->getJson('/api/shares/shared-by-me')['sharedByMe'][0];
        self::assertSame('CompletelyDifferent', $entry['recipientUsername']);
    }

    public function testAUsernameSomebodyElseHoldsCannotBeTaken(): void
    {
        $held = UserFactory::createOne(['email' => 'incumbent@example.com']);

        $this->createAndLoginUser(['email' => 'impostor@example.com']);
        $payload = $this->putJson('/api/users/username', [
            'username' => strtoupper($held->getUsername()),
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('already taken', $payload['message']);
    }

    public function testAReservedUsernameCannotBeTaken(): void
    {
        $this->createAndLoginUser(['email' => 'would-be-staff@example.com']);

        $payload = $this->putJson('/api/users/username', ['username' => 'Moderator']);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('reserved', $payload['message']);
    }

    /**
     * Taking a name somebody has just vacated is the cheapest impersonation
     * there is, so churn is slow on purpose.
     */
    public function testRenamingIsRateLimited(): void
    {
        $this->createAndLoginUser(['email' => 'churner@example.com']);

        $refused = null;
        for ($attempt = 0; $attempt < 6 && $refused === null; ++$attempt) {
            $payload = $this->putJson('/api/users/username', [
                'username' => sprintf('Restless%d', $attempt),
            ]);

            if ($this->browser()->getResponse()->getStatusCode() === 429) {
                $refused = $payload;
            }
        }

        self::assertNotNull($refused, 'Renaming should run out of allowance.');
        self::assertStringContainsString('too many times', $refused['message']);
    }

    /**
     * An exhausted allowance must refuse a real username too.
     *
     * This is the whole reason the preflight guard exists. Charging only for a
     * miss leaves the two answers different once the allowance is gone — a name
     * that exists still resolves, one that does not is refused — and that
     * difference is the fact the allowance was protecting. Whether a lookup
     * would have succeeded has to stop being observable, which means the
     * repository has to become unreachable rather than merely uncharged.
     */
    public function testAnExhaustedAllowanceStopsTellingRealUsernamesFromImaginaryOnes(): void
    {
        $known = UserFactory::createOne([
            'email' => 'findable@example.com',
            'username' => 'FindableOtter4821',
        ]);

        $this->createAndLoginUser(['email' => 'oracle@example.com']);

        // Sanity: this name resolves while there is allowance left, so the
        // refusals below are the allowance and not a broken fixture.
        $found = $this->postJson('/api/users/resolve-username', ['username' => $known->getUsername()]);
        self::assertResponseIsSuccessful();
        self::assertSame('FindableOtter4821', $found['recipient']['username']);

        $this->exhaustIdentifierLookups();

        $real = $this->postJson('/api/users/resolve-username', ['username' => $known->getUsername()]);
        $realStatus = $this->browser()->getResponse()->getStatusCode();

        $imaginary = $this->postJson('/api/users/resolve-username', ['username' => 'NobodyHoldsThisOne']);

        self::assertSame(429, $realStatus);
        self::assertSame($realStatus, $this->browser()->getResponse()->getStatusCode());
        // Same status and same sentence, so nothing distinguishes the name that
        // exists from the name that does not.
        self::assertSame($real['message'], $imaginary['message']);
    }
}
