<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\LoadDemoFixturesCommand;
use App\DataFixtures\AppFixtures;
use App\Entity\Comic;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\AppDataEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LoadDemoFixturesCommandTest extends KernelTestCase
{
    use ResetDatabase;

    public function testAnArchiveFailureDoesNotLeaveAnApparentlyCompleteFixtureSet(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $fixtures = new AppFixtures(
            $container->get(UserPasswordHasherInterface::class),
            $container->get(AppDataEncryptionService::class),
            (string) $container->getParameter('comics_directory'),
            '/missing-demo-source',
        );
        $tester = new CommandTester(new LoadDemoFixturesCommand($fixtures, $entityManager, self::$kernel));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('/missing-demo-source/public/comic.png', $tester->getDisplay());
        self::assertSame(0, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM user'));
    }

    public function testCommandPreservesExistingDataAndIsIdempotent(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $existingAdmin = (new User())
            ->setEmail('existing-admin@example.test')
            ->setName('Existing Local Administrator')
            ->setUsername('existing_admin')
            ->assignUserCode('EX15T1NG0001')
            ->setPassword('existing-password-hash')
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setIsEmailVerified(true);
        $entityManager->persist($existingAdmin);
        $existingTag = (new Tag())->setName('manga')->setIsGlobal(true)->setHideFromLibrary(true);
        $entityManager->persist($existingTag);
        $entityManager->flush();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:load-demo-fixtures'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('18 demo comics', $tester->getDisplay());
        self::assertCount(7, $entityManager->getRepository(User::class)->findAll());
        self::assertCount(18, $entityManager->getRepository(Comic::class)->findAll());
        self::assertCount(8, $entityManager->getRepository(Tag::class)->findAll());
        self::assertSame($existingTag, $entityManager->getRepository(Tag::class)->findOneBy(['name' => 'Manga', 'isGlobal' => true]));
        self::assertTrue($existingTag->hidesFromLibrary());
        $preservedAdmin = $entityManager->getRepository(User::class)->findOneBy([
            'email' => 'existing-admin@example.test',
        ]);
        self::assertSame($existingAdmin, $preservedAdmin);
        self::assertSame('existing-password-hash', $preservedAdmin?->getPassword());
        self::assertContains('ROLE_ADMIN', $preservedAdmin?->getRoles() ?? []);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('already loaded', $tester->getDisplay());
        self::assertCount(7, $entityManager->getRepository(User::class)->findAll());
        self::assertCount(18, $entityManager->getRepository(Comic::class)->findAll());
        self::assertSame(AppFixtures::PASSWORD, 'DemoPassword123!');
    }
}
