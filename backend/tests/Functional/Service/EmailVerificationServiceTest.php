<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Repository\EmailVerificationTokenRepository;
use App\Service\EmailVerificationResult;
use App\Service\EmailVerificationService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class EmailVerificationServiceTest extends AbstractApiTestCase
{
    public function testIssueReplacesEarlierTokensForTheSameUser(): void
    {
        $user = UserFactory::new()->unverified()->create()->object();
        $service = self::getContainer()->get(EmailVerificationService::class);
        $first = $service->issue($user);
        $second = $service->issue($user);

        $tokens = self::getContainer()->get(EmailVerificationTokenRepository::class);
        self::assertNull($tokens->findValidToken($first));
        self::assertNotNull($tokens->findValidToken($second));
    }

    public function testVerifyRejectsAnUnknownToken(): void
    {
        $result = self::getContainer()->get(EmailVerificationService::class)
            ->verify(str_repeat('cd', 32));

        self::assertSame(EmailVerificationResult::INVALID, $result->status);
        self::assertNull($result->user);
    }
}
