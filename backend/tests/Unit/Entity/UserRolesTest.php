<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * The one place the application asks whether an account is administrative.
 *
 * Worth pinning on its own because it replaced two dozen open-coded checks,
 * seven of which compared loosely. Loose comparison is what lets something
 * that is not a role name match one.
 */
final class UserRolesTest extends TestCase
{
    public function testAFreshAccountIsNotAnAdministrator(): void
    {
        self::assertFalse((new User())->isAdmin());
    }

    public function testAnAccountHoldingTheRoleIsAnAdministrator(): void
    {
        $user = (new User())->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        self::assertTrue($user->isAdmin());
    }

    public function testEveryAccountStillCarriesTheUserRole(): void
    {
        self::assertContains('ROLE_USER', (new User())->getRoles());
    }

    /**
     * Nothing but the exact role name counts.
     *
     * The roles column is JSON, so it can hold values that are not strings at
     * all, and the checks this replaced were split between strict and loose
     * comparison. PHP 8's rules happen to make the two agree on every value
     * here — which is precisely why it is worth a test rather than a shrug:
     * the guarantee should rest on the comparison being strict, not on the
     * language's coercion table staying the shape it is now.
     *
     * @dataProvider valuesThatAreNotTheAdminRole
     */
    public function testOnlyTheRoleNameItselfGrantsAdministration(mixed $value): void
    {
        $user = (new User())->setRoles(['ROLE_USER', $value]);

        self::assertFalse($user->isAdmin());
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function valuesThatAreNotTheAdminRole(): iterable
    {
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'lowercase' => ['role_admin'];
        yield 'a longer role that contains it' => ['ROLE_ADMIN_READONLY'];
    }
}
