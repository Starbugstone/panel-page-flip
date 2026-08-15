<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\UserMetadataCredential;
use App\Service\AppDataEncryptionService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class MetadataCredentialPersistenceIntegrityTest extends AbstractApiTestCase
{
    public function testUnrelatedPersonalCredentialChangeCannotOverwriteANewerToken(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $user = UserFactory::createOne()->object();

        $credential = (new UserMetadataCredential())
            ->setUser($user)
            ->setMetronToken('old-metron-token')
            ->setComicVineApiKey('old-vine-key');
        $entityManager->persist($credential);
        $entityManager->flush();
        $id = $credential->getId();
        self::assertNotNull($id);

        $entityManager->clear();
        $stale = $entityManager->find(UserMetadataCredential::class, $id);
        self::assertInstanceOf(UserMetadataCredential::class, $stale);
        self::assertSame('old-metron-token', $stale->getMetronToken());

        // Simulate another process rotating Metron after this EntityManager has
        // loaded its copy. The following flush changes only Comic Vine and must
        // not put the stale Metron token back into the database.
        $entityManager->getConnection()->executeStatement(
            'UPDATE user_metadata_credential SET metron_token = :token WHERE id = :id',
            ['token' => $encryption->encrypt('new-metron-token'), 'id' => $id]
        );

        $stale->setComicVineApiKey('new-vine-key');
        $entityManager->flush();

        $row = $entityManager->getConnection()->fetchAssociative(
            'SELECT metron_token, comic_vine_api_key FROM user_metadata_credential WHERE id = :id',
            ['id' => $id]
        );
        self::assertIsArray($row);
        self::assertSame('new-metron-token', $encryption->decrypt((string) $row['metron_token']));
        self::assertSame('new-vine-key', $encryption->decrypt((string) $row['comic_vine_api_key']));
    }

    public function testIntentionalPersonalCredentialChangeIsEncryptedAndReadable(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $user = UserFactory::createOne()->object();

        $credential = (new UserMetadataCredential())
            ->setUser($user)
            ->setMetronToken('initial-token');
        $entityManager->persist($credential);
        $entityManager->flush();
        $id = $credential->getId();
        self::assertNotNull($id);

        $credential->setMetronToken('rotated-token');
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchOne(
            'SELECT metron_token FROM user_metadata_credential WHERE id = :id',
            ['id' => $id]
        );
        self::assertIsString($stored);
        self::assertTrue($encryption->isEncrypted($stored));
        self::assertSame('rotated-token', $encryption->decrypt($stored));
        self::assertSame('rotated-token', $credential->getMetronToken());
    }
}
