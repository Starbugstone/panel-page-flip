<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\MetadataProviderConfiguration;
use App\Entity\User;
use App\Service\AppDataEncryptionService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class SecretPersistenceIntegrityTest extends AbstractApiTestCase
{
    public function testStaleUnrelatedUserFlushCannotOverwriteNewerDropboxCredential(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $user = UserFactory::createOne()->object();
        $user->setDropboxRefreshToken('old-refresh');
        $entityManager->flush();
        $id = $user->getId();

        $entityManager->clear();
        $stale = $entityManager->find(User::class, $id);
        self::assertInstanceOf(User::class, $stale);
        self::assertSame('old-refresh', $stale->getDropboxRefreshToken());

        $entityManager->getConnection()->executeStatement(
            'UPDATE `user` SET dropbox_refresh_token = :token WHERE id = :id',
            ['token' => $encryption->encrypt('new-refresh'), 'id' => $id]
        );
        $stale->setName('Unrelated profile edit');
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchOne('SELECT dropbox_refresh_token FROM `user` WHERE id = :id', ['id' => $id]);
        self::assertSame('new-refresh', $encryption->decrypt(is_string($stored) ? $stored : null));
    }

    public function testStaleUnrelatedProviderFlushCannotOverwriteNewerCredential(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $configuration = new MetadataProviderConfiguration();
        $configuration->setMetronToken('old-token');
        $entityManager->persist($configuration);
        $entityManager->flush();

        $entityManager->clear();
        $stale = $entityManager->find(MetadataProviderConfiguration::class, 1);
        self::assertInstanceOf(MetadataProviderConfiguration::class, $stale);
        self::assertSame('old-token', $stale->getMetronToken());

        $entityManager->getConnection()->executeStatement(
            'UPDATE metadata_provider_configuration SET metron_token = :token WHERE id = 1',
            ['token' => $encryption->encrypt('new-token')]
        );
        // Change a non-secret setting on the stale entity. The encrypted token
        // that was rotated directly in storage must survive this unrelated flush.
        $stale->setMetronSharedEnabled(true);
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchOne('SELECT metron_token FROM metadata_provider_configuration WHERE id = 1');
        self::assertSame('new-token', $encryption->decrypt(is_string($stored) ? $stored : null));
    }

    public function testIntentionalCredentialChangeIsEncryptedAndReadable(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $encryption = static::getContainer()->get(AppDataEncryptionService::class);
        $user = UserFactory::createOne()->object();
        $id = $user->getId();
        $entityManager->clear();

        $managed = $entityManager->find(User::class, $id);
        self::assertInstanceOf(User::class, $managed);
        $managed->setDropboxRefreshToken('rotated-refresh');
        $entityManager->flush();

        $stored = $entityManager->getConnection()->fetchOne('SELECT dropbox_refresh_token FROM `user` WHERE id = :id', ['id' => $id]);
        self::assertIsString($stored);
        self::assertTrue($encryption->isEncrypted($stored));
        self::assertSame('rotated-refresh', $encryption->decrypt($stored));
    }
}
