<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Reader\ReaderPreferences;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ReaderPreferencesControllerTest extends AbstractApiTestCase
{
    public function testDefaultsRequireAuthentication(): void
    {
        $this->getJson('/api/reader/preferences');

        self::assertResponseStatusCodeSame(401);
    }

    public function testReturnsDefaultsWhenNothingHasBeenSaved(): void
    {
        $this->createAndLoginUser();

        $payload = $this->getJson('/api/reader/preferences');

        self::assertResponseIsSuccessful();
        self::assertSame('contain', $payload['preferences']['settings']['fit']);
        self::assertSame(ReaderPreferences::SCHEMA_VERSION, $payload['preferences']['schemaVersion']);
    }

    public function testUnsafePreferenceWriteRequiresCsrfProtection(): void
    {
        $user = UserFactory::createOne()->object();
        $this->browser()->loginUser($user);
        $preferences = static::getContainer()->get(ReaderPreferences::class)->defaults();

        $this->browser()->request(
            'PUT',
            '/api/reader/preferences',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['preferences' => $preferences], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertNull($user->getReaderPreferences());
    }

    public function testPersistsAValidatedReplacementForTheAuthenticatedUser(): void
    {
        $user = $this->createAndLoginUser();
        $preferences = static::getContainer()->get(ReaderPreferences::class)->defaults();
        $preferences['settings']['fit'] = 'width';
        $preferences['settings']['autoHideControls'] = false;

        $payload = $this->putJson('/api/reader/preferences', ['preferences' => $preferences]);

        self::assertResponseIsSuccessful();
        self::assertSame($preferences, $payload['preferences']);

        $storedUser = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertEquals($preferences, $storedUser?->getReaderPreferences());

        self::assertSame($preferences, $this->getJson('/api/reader/preferences')['preferences']);
    }

    public function testRejectsUnsupportedAndUnknownValuesWithoutChangingStoredData(): void
    {
        $user = UserFactory::createOne()->object();
        $defaults = static::getContainer()->get(ReaderPreferences::class)->defaults();
        $user->setReaderPreferences($defaults);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->loginAs($user);

        $invalid = $defaults;
        $invalid['settings']['fit'] = 'stretch';
        $invalid['settings']['script'] = '<script>alert(1)</script>';

        $this->putJson('/api/reader/preferences', ['preferences' => $invalid]);

        self::assertResponseStatusCodeSame(422);
        $storedUser = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertEquals($defaults, $storedUser?->getReaderPreferences());
    }

    public function testResetRemovesStoredPreferencesAndReturnsDefaults(): void
    {
        $user = $this->createAndLoginUser();
        $preferences = static::getContainer()->get(ReaderPreferences::class)->defaults();
        $preferences['settings']['fit'] = 'height';
        $user->setReaderPreferences($preferences);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $payload = $this->deleteJson('/api/reader/preferences');

        self::assertResponseIsSuccessful();
        self::assertSame('contain', $payload['preferences']['settings']['fit']);
        $storedUser = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId());
        self::assertNull($storedUser?->getReaderPreferences());
    }

    public function testPreferencesAreIsolatedBetweenUsers(): void
    {
        $firstUser = UserFactory::createOne()->object();
        $preferences = static::getContainer()->get(ReaderPreferences::class)->defaults();
        $preferences['settings']['fit'] = 'original';
        $firstUser->setReaderPreferences($preferences);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $secondUser = $this->createAndLoginUser();
        $payload = $this->getJson('/api/reader/preferences');

        self::assertResponseIsSuccessful();
        self::assertSame('contain', $payload['preferences']['settings']['fit']);
        self::assertNotSame($firstUser->getId(), $secondUser->getId());
    }
}
