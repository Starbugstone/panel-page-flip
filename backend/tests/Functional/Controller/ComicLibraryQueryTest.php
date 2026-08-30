<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ComicReadingProgress;
use App\Entity\User;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The library endpoint feeds every card the dashboard draws, so the number of
 * queries it runs has to stay independent of how many comics a user owns.
 */
final class ComicLibraryQueryTest extends AbstractApiTestCase
{
    public function testTheLibraryEndpointReturnsTagsAndReadingProgress(): void
    {
        $user = UserFactory::createOne();
        $this->seedLibrary($user, 1);
        $this->loginAs($user);

        $payload = $this->getJson('/api/comics');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $payload['comics']);
        $comic = $payload['comics'][0];
        self::assertSame(['Serialized'], array_column($comic['tags'], 'name'));
        self::assertSame(3, $comic['readingProgress']['currentPage']);
        self::assertNotNull($comic['coverImagePath']);
    }

    /**
     * A library three times the size must not cost three times the queries. The
     * two users are compared against each other rather than against a hard
     * number so the assertion survives unrelated changes to the endpoint.
     */
    public function testTheLibraryEndpointDoesNotQueryPerComic(): void
    {
        $smallLibraryUser = UserFactory::createOne();
        $this->seedLibrary($smallLibraryUser, 2);
        $largeLibraryUser = UserFactory::createOne();
        $this->seedLibrary($largeLibraryUser, 6);

        $this->loginAs($smallLibraryUser);
        $this->getJson('/api/comics');
        self::assertResponseIsSuccessful();
        $smallLibraryQueries = $this->executedQueryCount();

        $this->loginAs($largeLibraryUser);
        $this->getJson('/api/comics');
        self::assertResponseIsSuccessful();
        $largeLibraryQueries = $this->executedQueryCount();

        self::assertSame(
            $smallLibraryQueries,
            $largeLibraryQueries,
            'Listing the library runs one query per comic; the tag, owner or reading-progress lookup has been un-batched.'
        );
    }

    /**
     * The admin view is the only one that serialises an owner, so it is the only
     * place the owner preload can be shown to work. Comics are spread one per
     * owner and the library then grown, so an un-batched owner lookup shows up
     * as queries that scale with the number of owners on screen.
     */
    public function testTheAdminLibraryDoesNotQueryPerOwner(): void
    {
        $this->seedLibraryAcrossNewOwners(2);
        $this->loginAs(UserFactory::new()->admin()->create());

        $this->getJson('/api/comics?adminContext=true');
        self::assertResponseIsSuccessful();
        $fewOwnersQueries = $this->executedQueryCount();

        $this->seedLibraryAcrossNewOwners(4);
        $payload = $this->getJson('/api/comics?adminContext=true');
        self::assertResponseIsSuccessful();
        $manyOwnersQueries = $this->executedQueryCount();

        self::assertCount(6, $payload['comics']);
        self::assertNotNull($payload['comics'][0]['owner']['email']);
        self::assertSame(
            $fewOwnersQueries,
            $manyOwnersQueries,
            'Listing the admin library runs one query per owner; Comic::owner is no longer preloaded.'
        );
    }

    /**
     * Doctrine's debug data holder records every statement the profiler would
     * display, and is reset for each request the test client sends. Reading it
     * straight after a request therefore counts that request alone.
     */
    private function executedQueryCount(): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');

        return array_sum(array_map('count', $holder->getData()));
    }

    private function seedLibraryAcrossNewOwners(int $ownerCount): void
    {
        for ($index = 0; $index < $ownerCount; $index++) {
            $this->seedLibrary(UserFactory::createOne(), 1);
        }
    }

    private function seedLibrary(User $user, int $comicCount): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tag = TagFactory::createOne(['name' => 'Serialized', 'creator' => $user]);

        for ($index = 0; $index < $comicCount; $index++) {
            $comic = ComicFactory::createOne([
                'owner' => $user,
                'coverImagePath' => 'covers/1/cover-' . $index . '.png',
            ]);
            $comic->addTag($tag);

            $progress = (new ComicReadingProgress())
                ->setUser($user)
                ->setComic($comic)
                ->setCurrentPage(3);
            $entityManager->persist($progress);
        }

        $entityManager->flush();
        // Serializing must go back to the database for the associations, exactly
        // as it does on a real request.
        $entityManager->clear();
    }
}
