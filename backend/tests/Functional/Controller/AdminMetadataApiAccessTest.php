<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An administrator can withdraw external metadata lookups from one account
 * without disabling the provider for everybody.
 *
 * Local sources are deliberately unaffected: ComicInfo.xml and the filename
 * parser never leave the server, so there is nothing to withdraw.
 */
final class AdminMetadataApiAccessTest extends AbstractApiTestCase
{
    public function testAnAdministratorCanWithdrawApiAccessFromAUser(): void
    {
        $target = UserFactory::createOne()->object();
        $this->createAndLoginAdmin();

        $this->patchJson(sprintf('/api/users/%d', $target->getId()), ['metadataApiEnabled' => false]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload($target)->isMetadataApiEnabled());
    }

    public function testAccessIsOnByDefault(): void
    {
        $target = UserFactory::createOne()->object();

        self::assertTrue($this->reload($target)->isMetadataApiEnabled());
    }

    /** Otherwise a user could simply grant it back to themselves. */
    public function testAUserCannotGrantThemselvesApiAccess(): void
    {
        $user = UserFactory::createOne(['metadataApiEnabled' => false])->object();
        $this->loginAs($user);

        $this->patchJson(sprintf('/api/users/%d', $user->getId()), ['metadataApiEnabled' => true]);

        self::assertFalse($this->reload($user)->isMetadataApiEnabled());
    }

    public function testARejectedValueIsNotABoolean(): void
    {
        $target = UserFactory::createOne()->object();
        $this->createAndLoginAdmin();

        $this->patchJson(sprintf('/api/users/%d', $target->getId()), ['metadataApiEnabled' => 'no']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTheAdminListReportsAccessWithoutRevealingAnyToken(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createOne(['metadataApiEnabled' => false]);

        $users = $this->getJson('/api/users')['items'];

        self::assertResponseIsSuccessful();
        foreach ($users as $user) {
            self::assertArrayHasKey('metadataApiEnabled', $user);
            self::assertArrayHasKey('hasPersonalMetadataCredential', $user);
        }
        self::assertContains(false, array_column($users, 'metadataApiEnabled'));
    }

    /**
     * The point of the switch: local metadata keeps working. A withdrawn
     * account still gets filename suggestions, which never touch the network.
     */
    public function testLocalSuggestionsStillWorkWithoutApiAccess(): void
    {
        $owner = UserFactory::createOne(['metadataApiEnabled' => false])->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'series' => null,
            'originalFilename' => 'Batman - 007 (2011) (Digital).cbz',
        ])->object();

        $this->loginAs($owner);
        $response = $this->getJson(sprintf('/api/comics/%d/metadata-suggestions', $comic->getId()));

        self::assertResponseIsSuccessful();
        self::assertContains('series', array_column($response['suggestions'], 'field'));
    }

    private function reload(User $user): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $user->getId());
    }
}
