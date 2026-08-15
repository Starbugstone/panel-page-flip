<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;

final class RegistrationTermsTest extends AbstractApiTestCase
{
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
}
