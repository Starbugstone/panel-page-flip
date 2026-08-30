<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\ResetPasswordToken;
use App\Service\ResetPasswordService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class PasswordSessionInvalidationTest extends AbstractApiTestCase
{
    public function testSessionCreatedBeforePasswordResetIsRejectedAfterward(): void
    {
        $user = UserFactory::createOne();
        $this->loginAs($user);
        $cookies = $this->browser()->getCookieJar()->all();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $plainToken = bin2hex(random_bytes(32));
        $token = new ResetPasswordToken();
        $token->setUser($user);
        $token->setToken(hash('sha256', $plainToken));
        $token->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $token->setIsUsed(false);
        $entityManager->persist($token);
        $entityManager->flush();

        static::getContainer()->get(ResetPasswordService::class)->resetPassword($plainToken, 'ChangedPassword!123456');

        static::ensureKernelShutdown();
        $freshClient = static::createClient();
        foreach ($cookies as $cookie) {
            $freshClient->getCookieJar()->set($cookie);
        }
        $freshClient->request('GET', '/api/me', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['user' => null, 'sessionRefreshed' => false],
            json_decode((string) $freshClient->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)
        );
    }
}
