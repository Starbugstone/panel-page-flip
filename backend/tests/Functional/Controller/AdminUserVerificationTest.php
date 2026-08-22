<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\EmailVerificationToken;
use App\Repository\EmailVerificationTokenRepository;
use App\Service\EmailVerificationService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifying an account by hand, which is what an administrator does for
 * somebody whose verification mail never arrived.
 *
 * The endpoint answered 500 for every call once the single verification token
 * on the user became a collection: the controller went on calling the entity
 * setters that had gone with it, and nothing covered the route. These tests
 * exist so that the next such refactor fails here rather than in production.
 */
final class AdminUserVerificationTest extends AbstractApiTestCase
{
    public function testAnAdministratorCanVerifyAnUnverifiedAccount(): void
    {
        $target = UserFactory::createOne(['isEmailVerified' => false])->object();
        $this->createAndLoginAdmin();

        $body = $this->postJson('/api/users/' . $target->getId() . '/verify');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertTrue($body['user']['isEmailVerified']);
        self::assertSame($target->getId(), $body['user']['id']);

        self::assertTrue(
            $this->reloadUser($target->getId())->isEmailVerified(),
            'The account must still be verified after the request ends.'
        );
    }

    /**
     * An outstanding token is a live capability: anybody holding that link can
     * still verify the address. Once an administrator has vouched for the
     * account by hand, the link has nothing left to prove and must stop working.
     */
    public function testVerifyingByHandRetiresTheOutstandingTokens(): void
    {
        $target = UserFactory::createOne(['isEmailVerified' => false])->object();

        /** @var EmailVerificationService $service */
        $service = self::getContainer()->get(EmailVerificationService::class);
        $service->issue($target);

        /** @var EmailVerificationTokenRepository $tokens */
        $tokens = self::getContainer()->get(EmailVerificationTokenRepository::class);
        self::assertNotEmpty($tokens->findBy(['user' => $target]), 'The fixture must start with a live token.');

        $this->createAndLoginAdmin();
        $this->postJson('/api/users/' . $target->getId() . '/verify');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(
            [],
            $tokens->findBy(['user' => $target]),
            'A hand-verified account must have no verification links left outstanding.'
        );
    }

    public function testVerifyingAnAlreadyVerifiedAccountIsHarmless(): void
    {
        $target = UserFactory::createOne(['isEmailVerified' => true])->object();
        $this->createAndLoginAdmin();

        $body = $this->postJson('/api/users/' . $target->getId() . '/verify');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertTrue($body['user']['isEmailVerified']);
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        $this->createAndLoginAdmin();

        $this->postJson('/api/users/99999999/verify');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPlainUserCannotVerifyAnybody(): void
    {
        $target = UserFactory::createOne(['isEmailVerified' => false])->object();
        $this->createAndLoginUser();

        $this->postJson('/api/users/' . $target->getId() . '/verify');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse(
            $this->reloadUser($target->getId())->isEmailVerified(),
            'A refused request must not have verified the account anyway.'
        );
    }

    private function reloadUser(int $id): \App\Entity\User
    {
        $repository = self::getContainer()->get('doctrine')->getRepository(\App\Entity\User::class);
        $repository->getEntityManager()->clear(EmailVerificationToken::class);

        $user = $repository->find($id);
        self::assertNotNull($user);

        return $user;
    }
}
