<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class EmailVerificationPrivacyTest extends AbstractApiTestCase
{
    public function testResendDoesNotRevealAccountOrVerificationState(): void
    {
        UserFactory::createOne(['email' => 'verified@example.test', 'isEmailVerified' => true]);
        UserFactory::createOne(['email' => 'pending@example.test', 'isEmailVerified' => false]);

        $responses = [];
        foreach (['missing@example.test', 'verified@example.test', 'pending@example.test'] as $email) {
            $responses[] = $this->postJson('/api/email-verification/resend', ['email' => $email]);
            self::assertResponseIsSuccessful();
        }

        self::assertSame($responses[0], $responses[1]);
        self::assertSame($responses[1], $responses[2]);
    }
}
