<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\AbstractApiTestCase;

final class ConfigControllerTest extends AbstractApiTestCase
{
    /**
     * The firewall refuses this before the controller's own `getUser()` check
     * runs, so the status is the contract here — the body is Symfony's, not
     * ours.
     */
    public function testAnonymousCallersAreRefused(): void
    {
        $this->getJson('/api/config');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSignedInUsersReceiveUploadAndProviderConfigWithoutCredentials(): void
    {
        $this->createAndLoginUser();

        $payload = $this->getJson('/api/config');

        self::assertResponseIsSuccessful();
        self::assertSame((int) static::getContainer()->getParameter('max_concurrent_uploads'), $payload['upload']['maxConcurrentUploads']);
        self::assertSame((int) static::getContainer()->getParameter('max_parallel_file_uploads'), $payload['upload']['maxParallelFileUploads']);
        self::assertNotSame($payload['upload']['maxConcurrentUploads'], $payload['upload']['maxParallelFileUploads']);
        self::assertSame((int) static::getContainer()->getParameter('upload_max_chunk_bytes'), $payload['upload']['maxChunkBytes']);
        self::assertContains('cbz', $payload['upload']['comicFormats']);
        self::assertIsArray($payload['metadataProviders']);
        foreach ($payload['metadataProviders'] as $provider) {
            self::assertArrayHasKey('key', $provider);
            self::assertArrayHasKey('label', $provider);
            self::assertArrayNotHasKey('credentials', $provider);
            self::assertArrayNotHasKey('apiKey', $provider);
        }
    }

}
