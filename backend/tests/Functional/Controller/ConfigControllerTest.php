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
        self::assertArrayHasKey('maxConcurrentUploads', $payload['upload']);
        self::assertArrayHasKey('maxParallelFileUploads', $payload['upload']);
        self::assertContains('cbz', $payload['upload']['comicFormats']);
        self::assertIsArray($payload['metadataProviders']);
        foreach ($payload['metadataProviders'] as $provider) {
            self::assertArrayHasKey('key', $provider);
            self::assertArrayHasKey('label', $provider);
            self::assertArrayNotHasKey('credentials', $provider);
            self::assertArrayNotHasKey('apiKey', $provider);
        }
    }

    /**
     * The two upload limits answer different questions — how many comics move
     * at once, and how many requests that is allowed to cost — so the payload
     * has to keep them apart. Reporting the request budget as the file count is
     * exactly the confusion this setting exists to end.
     */
    public function testParallelFileUploadsIsReportedSeparatelyFromTheRequestBudget(): void
    {
        $this->createAndLoginUser();

        $payload = $this->getJson('/api/config');

        self::assertResponseIsSuccessful();
        self::assertSame(
            (int)static::getContainer()->getParameter('max_parallel_file_uploads'),
            $payload['upload']['maxParallelFileUploads']
        );
        self::assertSame(
            (int)static::getContainer()->getParameter('max_concurrent_uploads'),
            $payload['upload']['maxConcurrentUploads']
        );
        self::assertNotSame(
            $payload['upload']['maxConcurrentUploads'],
            $payload['upload']['maxParallelFileUploads'],
            'The test environment sets these to different values so a controller reading the wrong parameter fails here.'
        );
    }
}
