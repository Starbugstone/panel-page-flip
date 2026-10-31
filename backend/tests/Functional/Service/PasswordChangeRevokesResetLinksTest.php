<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\ResetPasswordToken;
use App\Entity\User;
use App\Repository\ResetPasswordTokenRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A reset link is somebody's way into an account until it dies. Changing the
 * password is what a person does when they think that access has got out, so
 * every way of changing it has to end every link already sent.
 */
final class PasswordChangeRevokesResetLinksTest extends AbstractApiTestCase
{
    private const NEW_PASSWORD = 'N3w!Passw0rd-Strong#1';

    public function testAnAdministratorChangingAPasswordKillsTheLinksAlreadySent(): void
    {
        $target = UserFactory::createOne(['email' => 'target@test.local']);
        $link = $this->issueResetLink($target);
        $otherLink = $this->issueResetLink($target);
        $this->assertLinkWorks($link);
        $this->createAndLoginAdmin();

        $this->putJson('/api/users/'.$target->getId(), ['password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        $this->assertLinkIsDead($link);
        $this->assertLinkIsDead($otherLink);
        $this->assertPasswordIs($target, self::NEW_PASSWORD);
    }

    /**
     * The only way an account changes its own password while signed in: every
     * other account is refused /api/users/{id} by the firewall, and uses the
     * reset flow below.
     */
    public function testAnAdministratorChangingTheirOwnPasswordKillsTheirLinks(): void
    {
        $admin = $this->createAndLoginAdmin(['email' => 'own-password@test.local']);
        $link = $this->issueResetLink($admin);

        $this->putJson('/api/users/'.$admin->getId(), ['password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        $this->assertLinkIsDead($link);
    }

    public function testAnotherAccountsLinksSurviveAPasswordChange(): void
    {
        $target = UserFactory::createOne();
        $bystander = UserFactory::createOne();
        $bystandersLink = $this->issueResetLink($bystander);
        $this->createAndLoginAdmin();

        $this->putJson('/api/users/'.$target->getId(), ['password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        $this->assertLinkWorks($bystandersLink);
    }

    public function testARefusedPasswordChangeLeavesTheLinksAlone(): void
    {
        $target = UserFactory::createOne();
        $link = $this->issueResetLink($target);
        $this->createAndLoginAdmin();

        $this->putJson('/api/users/'.$target->getId(), ['password' => 'short']);
        self::assertResponseStatusCodeSame(400);

        $this->assertLinkWorks($link);
    }

    public function testAnUpdateThatDoesNotTouchThePasswordLeavesTheLinksAlone(): void
    {
        $target = UserFactory::createOne();
        $link = $this->issueResetLink($target);
        $this->createAndLoginAdmin();

        $this->putJson('/api/users/'.$target->getId(), ['name' => 'A New Name']);
        self::assertResponseIsSuccessful();

        $this->assertLinkWorks($link);
    }

    public function testTheConsoleCommandKillsTheLinksAlreadySent(): void
    {
        $target = UserFactory::createOne(['email' => 'console@test.local']);
        $link = $this->issueResetLink($target);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:reset-user-password'));
        self::assertSame(Command::SUCCESS, $tester->execute([
            'email' => 'console@test.local',
            'password' => self::NEW_PASSWORD,
        ]));

        $this->assertLinkIsDead($link);
        $this->assertPasswordIs($target, self::NEW_PASSWORD);
    }

    public function testTheConsoleCommandRefusingAPasswordLeavesTheLinksAlone(): void
    {
        $target = UserFactory::createOne(['email' => 'console-refused@test.local']);
        $link = $this->issueResetLink($target);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:reset-user-password'));
        self::assertSame(Command::FAILURE, $tester->execute([
            'email' => 'console-refused@test.local',
            'password' => 'short',
        ]));

        $this->assertLinkWorks($link);
    }

    public function testCreatingAnAccountFromTheConsoleStillWorks(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:create-user'));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'email' => 'brand-new@test.local',
            'password' => self::NEW_PASSWORD,
        ]));
    }

    public function testUsingOneLinkKillsEveryOtherLinkForTheAccount(): void
    {
        $target = UserFactory::createOne();
        $used = $this->issueResetLink($target);
        $other = $this->issueResetLink($target);

        $this->postJson('/api/reset-password/reset/'.$used, [
            'password' => self::NEW_PASSWORD,
            'confirmPassword' => self::NEW_PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $this->assertLinkIsDead($used);
        $this->assertLinkIsDead($other);
        $this->assertPasswordIs($target, self::NEW_PASSWORD);
    }

    /** The conditional write is what stops a token read as valid being used twice. */
    public function testAResetTokenCanOnlyBeClaimedOnce(): void
    {
        $this->issueResetLink(UserFactory::createOne());
        $repository = self::getContainer()->get(ResetPasswordTokenRepository::class);
        $token = $repository->findOneBy(['isUsed' => false]);
        self::assertNotNull($token);

        self::assertTrue($repository->claimToken($token));
        self::assertFalse($repository->claimToken($token));
    }

    private function issueResetLink(User $user): string
    {
        $plain = bin2hex(random_bytes(32));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(
            (new ResetPasswordToken())
                ->setUser($entityManager->getReference(User::class, $user->getId()))
                ->setToken(hash('sha256', $plain))
                ->setExpiresAt(new \DateTimeImmutable('+1 hour'))
                ->setIsUsed(false)
        );
        $entityManager->flush();

        return $plain;
    }

    private function assertLinkWorks(string $link): void
    {
        $this->getJson('/api/reset-password/validate/'.$link);
        self::assertResponseIsSuccessful();
    }

    private function assertLinkIsDead(string $link): void
    {
        $this->getJson('/api/reset-password/validate/'.$link);
        self::assertResponseStatusCodeSame(400);

        $this->postJson('/api/reset-password/reset/'.$link, [
            'password' => 'An0ther!Passw0rd-Strong',
            'confirmPassword' => 'An0ther!Passw0rd-Strong',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    private function assertPasswordIs(User $user, string $password): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(User::class, $user->getId());

        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($reloaded, $password));
    }
}
