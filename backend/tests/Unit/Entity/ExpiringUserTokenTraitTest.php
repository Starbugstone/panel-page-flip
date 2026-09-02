<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EmailVerificationToken;
use App\Entity\ResetPasswordToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ExpiringUserTokenTraitTest extends TestCase
{
    /** @dataProvider tokens */
    public function testCommonTokenState(EmailVerificationToken|ResetPasswordToken $token): void
    {
        $user = new User();
        $future = new \DateTimeImmutable('+1 hour');

        self::assertSame($token, $token->setToken('hashed-token'));
        self::assertSame($token, $token->setUser($user));
        self::assertSame($token, $token->setExpiresAt($future));
        self::assertNull($token->getId());
        self::assertSame('hashed-token', $token->getToken());
        self::assertSame($user, $token->getUser());
        self::assertSame($future, $token->getExpiresAt());
        self::assertFalse($token->isExpired());

        $token->setExpiresAt(new \DateTimeImmutable('-1 second'));
        self::assertTrue($token->isExpired());
    }

    /** @return iterable<string, array{0: EmailVerificationToken|ResetPasswordToken}> */
    public static function tokens(): iterable
    {
        yield 'email verification' => [new EmailVerificationToken(new User())];
        yield 'password reset' => [new ResetPasswordToken()];
    }
}
