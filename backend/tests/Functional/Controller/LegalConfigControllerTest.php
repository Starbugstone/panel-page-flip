<?php

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;

final class LegalConfigControllerTest extends AbstractApiTestCase
{
    public function testLegalContactIsPubliclyAvailable(): void
    {
        $payload = $this->getJson('/api/legal-config');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($payload['operator']);
        self::assertNotEmpty($payload['privacyEmail']);
        self::assertStringContainsString('@', $payload['privacyEmail']);
    }
}
