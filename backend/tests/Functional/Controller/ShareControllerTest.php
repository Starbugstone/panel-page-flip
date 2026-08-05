<?php

namespace App\Tests\Functional\Controller;

use App\Entity\ShareToken;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ShareControllerTest extends AbstractApiTestCase
{
    public function testRefusingAShareDeletesTheInvitationAndItsCoverFile(): void
    {
        $recipient = $this->createAndLoginUser(['email' => 'recipient@test.local']);
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();

        $publicSharesDirectory = static::getContainer()->getParameter('public_shares_directory');
        if (!is_dir($publicSharesDirectory)) {
            mkdir($publicSharesDirectory, 0775, true);
        }
        $coverFilename = 'share-refuse-test-' . bin2hex(random_bytes(6)) . '.jpg';
        $coverPath = rtrim((string) $publicSharesDirectory, '/\\') . '/' . $coverFilename;
        file_put_contents($coverPath, 'cover bytes');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $share = (new ShareToken($comic, $owner, $recipient->getEmail()))
            ->setExpiresAt(new \DateTimeImmutable('+1 day'))
            ->setPublicCoverPath($coverFilename);
        $entityManager->persist($share);
        $entityManager->flush();
        $token = $share->getToken();
        $shareId = $share->getId();

        try {
            $payload = $this->postJson('/api/share/refuse/' . $token);

            self::assertResponseIsSuccessful();
            self::assertSame('Share refused successfully', $payload['message']);
            self::assertFileDoesNotExist($coverPath);

            $entityManager->clear();
            self::assertNull($entityManager->find(ShareToken::class, $shareId));
        } finally {
            if (is_file($coverPath)) {
                unlink($coverPath);
            }
        }
    }
}
