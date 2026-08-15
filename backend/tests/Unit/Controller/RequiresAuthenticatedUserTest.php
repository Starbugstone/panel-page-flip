<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\RequiresAuthenticatedUser;
use App\Entity\User;
use App\Security\UnauthenticatedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class RequiresAuthenticatedUserTest extends TestCase
{
    public function testRequireUserReturnsTheSignedInUser(): void
    {
        $user = new User();

        self::assertSame($user, $this->controller($user)->callRequireUser());
    }

    public function testRequireUserThrowsWhenNobodyIsSignedIn(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->expectExceptionMessage('User not authenticated');

        $this->controller(null)->callRequireUser();
    }

    /**
     * A token holding some other UserInterface is not something this
     * application can act on, and is refused rather than passed along to
     * services that expect the entity.
     */
    public function testRequireUserThrowsForAUserThatIsNotThisApplicationsEntity(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->controller($this->createMock(UserInterface::class))->callRequireUser();
    }

    public function testCurrentUserReturnsNullInsteadOfThrowing(): void
    {
        self::assertNull($this->controller(null)->callCurrentUser());
    }

    private function controller(?UserInterface $user): object
    {
        return new class($user) {
            use RequiresAuthenticatedUser;

            public function __construct(private readonly ?UserInterface $user)
            {
            }

            public function callRequireUser(): User
            {
                return $this->requireUser();
            }

            public function callCurrentUser(): ?User
            {
                return $this->currentUser();
            }

            /** Stands in for AbstractController::getUser(). */
            public function getUser(): ?UserInterface
            {
                return $this->user;
            }
        };
    }
}
