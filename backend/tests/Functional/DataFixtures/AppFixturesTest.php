<?php

declare(strict_types=1);

namespace App\Tests\Functional\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Entity\AdminAuditLog;
use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\LibraryFolder;
use App\Entity\ShareClaimCode;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\UserWarning;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AppFixturesTest extends KernelTestCase
{
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private string $comicsDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->comicsDirectory = (string) static::getContainer()->getParameter('comics_directory');
    }

    public function testLoadBuildsACompleteMultiUserDemoLibrary(): void
    {
        $fixture = static::getContainer()->get(AppFixtures::class);
        $fixture->load($this->entityManager);

        self::assertCount(6, $this->all(User::class));
        self::assertCount(18, $this->all(Comic::class));
        self::assertCount(8, $this->all(Tag::class));
        self::assertCount(12, $this->all(ComicShare::class));
        self::assertCount(8, $this->all(ComicReadingProgress::class));
        self::assertCount(8, $this->all(LibraryFolder::class));
        self::assertCount(3, $this->all(ShareClaimCode::class));
        self::assertCount(3, $this->all(ContentReport::class));
        self::assertCount(3, $this->all(UserWarning::class));
        self::assertCount(5, $this->all(AdminAuditLog::class));

        $alex = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'alex@example.test']);
        self::assertInstanceOf(User::class, $alex);
        self::assertTrue($alex->isEmailVerified());
        self::assertContains('ROLE_USER', $alex->getRoles());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid(
            $alex,
            AppFixtures::PASSWORD,
        ));

        $acceptedShare = $this->entityManager->getRepository(ComicShare::class)->findOneBy([
            'recipientUser' => $alex,
            'status' => ComicShare::STATUS_ACCEPTED,
        ]);
        self::assertInstanceOf(ComicShare::class, $acceptedShare);
        self::assertNotSame($alex, $acceptedShare->getOwner());
        self::assertTrue($acceptedShare->grantsReadAccess());

        $pendingShare = $this->entityManager->getRepository(ComicShare::class)->findOneBy([
            'recipientUser' => $alex,
            'status' => ComicShare::STATUS_PENDING,
        ]);
        self::assertInstanceOf(ComicShare::class, $pendingShare);
        self::assertTrue($pendingShare->isPending());

        foreach ($this->all(Comic::class) as $comic) {
            self::assertInstanceOf(Comic::class, $comic);
            $ownerId = $comic->getOwner()?->getId();
            self::assertNotNull($ownerId);
            self::assertFileExists($this->comicsDirectory.'/'.$ownerId.'/'.basename((string) $comic->getFilePath()));
            self::assertSame(1, $comic->getPageCount());
        }
    }

    /** @param class-string $entityClass */
    private function all(string $entityClass): array
    {
        return $this->entityManager->getRepository($entityClass)->findAll();
    }
}
