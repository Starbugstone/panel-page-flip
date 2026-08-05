<?php

namespace App\Tests\Functional\Security;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

class ApiCsrfTest extends AbstractApiTestCase
{
    public function testAuthenticatedUnsafeRequestWithoutTokenIsRejected(): void
    {
        $this->browser()->loginUser(UserFactory::createOne()->object());

        $this->browser()->request('POST', '/api/me', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Invalid CSRF token.', $this->json()['message']);
    }

    public function testAuthenticatedSafeRequestSetsReadableTokenCookie(): void
    {
        $this->browser()->loginUser(UserFactory::createOne()->object());

        $this->getJson('/api/me');

        self::assertResponseIsSuccessful();
        $cookie = $this->browser()->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($cookie);
        self::assertFalse($cookie->isHttpOnly());
        self::assertSame('/', $cookie->getPath());
    }

    public function testValidTokenAllowsUnsafeRequest(): void
    {
        $this->loginAs(UserFactory::createOne()->object());

        $payload = $this->postJson('/api/me');

        self::assertResponseIsSuccessful();
        self::assertTrue($payload['sessionRefreshed']);
    }

    public function testAnonymousRegistrationIsExemptFromCsrfValidation(): void
    {
        $this->browser()->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode([
            'email' => 'csrf-registration@test.local',
            'password' => 'Valid!Password123',
            'name' => 'CSRF Test',
            'agreeTerms' => true,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
    }
}
