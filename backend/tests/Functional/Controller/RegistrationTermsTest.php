<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;

final class RegistrationTermsTest extends AbstractApiTestCase
{
    public function testRegistrationReportsAMissingPasswordUnderThePasswordKey(): void
    {
        $payload = $this->postJson('/api/register', [
            'email' => 'missing-password@test.local',
            'agreeTerms' => true,
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['password' => 'Password is required'], $payload['errors']);
    }

    public function testRegistrationRequiresTermsAcceptance(): void
    {
        $payload = $this->postJson('/api/register', [
            'email' => 'terms@test.local',
            'password' => 'Valid!Password123',
            'name' => 'Terms Test',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Validation failed', $payload['message']);
        self::assertArrayHasKey('[agreeTerms]', $payload['errors']);
    }

    public function testRegistrationAcceptsPlainPasswordFallback(): void
    {
        $payload = $this->postJson('/api/register', [
            'email' => 'plain-password@test.local',
            'plainPassword' => 'Valid!Password123',
            'name' => 'Plain Password Test',
            'agreeTerms' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($payload['requiresVerification']);
    }

    public function testVerificationEmailUsesTheConfiguredSiteName(): void
    {
        $this->postJson('/api/register', [
            'email' => 'verification-brand@test.local',
            'password' => 'Valid!Password123',
            'agreeTerms' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);

        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertSame('Test Sender', $message->getFrom()[0]->getName());
        self::assertSame('Verify your Test Sender email address', $message->getSubject());
        self::assertStringContainsString('Test Sender', (string) $message->getHtmlBody());
        self::assertStringNotContainsString('Comic Reader', (string) $message->getHtmlBody());
    }
}
