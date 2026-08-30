<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Service\EmailVerificationService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class EmailVerificationControllerTest extends AbstractApiTestCase
{
    public function testVerifyRedirectsToFailureWhenTheTokenIsUnknown(): void
    {
        $this->browser()->request('GET', '/api/email-verification/verify/' . str_repeat('ab', 32));

        self::assertResponseRedirects();
        $location = (string) $this->browser()->getResponse()->headers->get('Location');
        self::assertStringContainsString('/email-verification', $location);
        self::assertStringContainsString('verification-failed', $location);
    }

    public function testVerifyMarksAnUnverifiedAccountAndRedirectsToSuccess(): void
    {
        $user = UserFactory::new()->unverified()->create();
        $plainToken = self::getContainer()->get(EmailVerificationService::class)->issue($user);

        $this->browser()->request('GET', '/api/email-verification/verify/' . $plainToken);

        self::assertResponseRedirects();
        $location = (string) $this->browser()->getResponse()->headers->get('Location');
        self::assertStringContainsString('verification-success', $location);

        self::getContainer()->get('doctrine')->getManager()->refresh($user);
        self::assertTrue($user->isEmailVerified());
    }

    public function testVerifySaysAlreadyVerifiedWhenTheAccountAlreadyIs(): void
    {
        $user = UserFactory::new()->unverified()->create();
        $service = self::getContainer()->get(EmailVerificationService::class);
        $plainToken = $service->issue($user);
        $service->verify($plainToken);
        $secondToken = $service->issue($user);

        $this->browser()->request('GET', '/api/email-verification/verify/' . $secondToken);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            'already+been+verified',
            (string) $this->browser()->getResponse()->headers->get('Location')
        );
    }

    public function testResendRequiresAnEmail(): void
    {
        $payload = $this->postJson('/api/email-verification/resend', []);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Email is required', $payload['message']);
    }
}
