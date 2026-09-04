<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\AdminAuditLog;
use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ShareClaimCode;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\ShareCodeType;
use App\Service\SharingCodeFormat;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class AdminTableFilterSuggestionControllerTest extends AbstractApiTestCase
{
    public function testSuggestionsSearchTheWholeDatabaseAndRankPrefixesFirst(): void
    {
        $this->createAndLoginAdmin(['name' => 'Unmatched Administrator']);
        UserFactory::createSequence(array_map(static fn (int $number): array => [
            'name' => sprintf('Unmatched Account %02d', $number),
            'email' => sprintf('unmatched%02d@example.test', $number),
        ], range(1, 30)));
        UserFactory::createOne(['name' => 'Alphabet Owner', 'email' => 'middle@example.test']);
        UserFactory::createOne(['name' => 'Phantom Owner', 'email' => 'prefix@example.test']);

        $payload = $this->getJson('/api/admin/table-filter-suggestions/users/identity?query=pha');

        self::assertResponseIsSuccessful();
        self::assertSame(['Phantom Owner', 'Alphabet Owner'], $payload['suggestions']);
    }

    public function testQueriesShorterThanThreeCharactersDoNotSearch(): void
    {
        $this->createAndLoginAdmin();
        UserFactory::createOne(['name' => 'Selina Kyle']);

        $payload = $this->getJson('/api/admin/table-filter-suggestions/users/identity?query=se');

        self::assertResponseIsSuccessful();
        self::assertSame([], $payload['suggestions']);
    }

    public function testEveryPagedAdminTextColumnUsesItsDatabaseRows(): void
    {
        $admin = $this->createAndLoginAdmin(['name' => 'Needle Administrator']);
        $owner = UserFactory::createOne(['name' => 'Needle Owner', 'email' => 'needle-owner@example.test']);
        $recipient = UserFactory::createOne(['name' => 'Needle Recipient', 'email' => 'needle-recipient@example.test']);
        $comic = ComicFactory::new()->ownedBy($owner)->create([
            'title' => 'Needle Comic',
            'author' => 'Needle Author',
        ]);
        $tag = TagFactory::new()->createdBy($owner)->create(['name' => 'Needle Tag']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $managedComic = $entityManager->find(Comic::class, $comic->getId());
        $managedTag = $entityManager->find(Tag::class, $tag->getId());
        $managedOwner = $entityManager->find(User::class, $owner->getId());
        $managedRecipient = $entityManager->find(User::class, $recipient->getId());
        $managedAdmin = $entityManager->find(User::class, $admin->getId());
        self::assertNotNull($managedComic);
        self::assertNotNull($managedTag);
        self::assertNotNull($managedOwner);
        self::assertNotNull($managedRecipient);
        self::assertNotNull($managedAdmin);

        $managedComic->addTag($managedTag);
        $share = new ComicShare($managedComic, $managedOwner, (string) $managedRecipient->getEmail());
        $share->linkRecipientUser($managedRecipient);
        $entityManager->persist($share);
        $entityManager->persist(new ShareClaimCode(
            $managedOwner,
            ShareCodeType::COMIC,
            SharingCodeFormat::hash(ShareCodeType::COMIC, 'ABCDEF123456'),
            [$managedComic],
            1,
            new \DateTimeImmutable('+7 days'),
        ));
        $entityManager->persist((new AdminAuditLog())
            ->setAdminUser($managedAdmin)
            ->setAction('needle_action')
            ->setTargetType('comic')
            ->setTargetId($managedComic->getId())
            ->setPayload(['reason' => 'Needle detail']));
        $entityManager->flush();

        $expectations = [
            'users/identity' => 'Needle Owner',
            'comics/title-author' => 'Needle Comic',
            'comics/owner' => 'Needle Owner',
            'comics/tags' => 'Needle Tag',
            'tags/name' => 'Needle Tag',
            'tags/creator' => 'Needle Owner',
            'shares/comic' => 'Needle Comic',
            'shares/owner' => 'Needle Owner',
            'shares/recipient' => 'Needle Recipient',
            'sharing-codes/owner' => 'Needle Owner',
            'sharing-codes/comics' => 'Needle Comic',
            'audit-logs/admin' => 'Needle Administrator',
        ];

        foreach ($expectations as $source => $expected) {
            $payload = $this->getJson('/api/admin/table-filter-suggestions/' . $source . '?query=needle');
            self::assertResponseIsSuccessful();
            self::assertContains($expected, $payload['suggestions'], $source);
        }

        $details = $this->getJson('/api/admin/table-filter-suggestions/audit-logs/details?query=needle');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Needle detail', $details['suggestions'][0]);
    }

    public function testUnknownSuggestionSourcesAreRejected(): void
    {
        $this->createAndLoginAdmin();

        $this->getJson('/api/admin/table-filter-suggestions/users/password?query=secret');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOrdinaryUsersCannotSearchAdministrativeSuggestions(): void
    {
        $this->createAndLoginUser();

        $this->getJson('/api/admin/table-filter-suggestions/users/identity?query=sel');

        self::assertResponseStatusCodeSame(403);
    }
}
