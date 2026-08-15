<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Base class for HTTP / functional tests.
 *
 * Provides:
 *   - DAMA transactional rollback per test (via the bundle and listener).
 *   - A pre-booted KernelBrowser via {@see browser()}.
 *   - Helpers to authenticate users and post JSON.
 */
abstract class AbstractApiTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    protected ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        // The limiters are filesystem-cached, so an allowance outlives both the
        // per-test rollback and the run itself: ids come round again and
        // inherit what an earlier test — or yesterday's run — already spent.
        // Left alone, the suite passes from cold and then fails on the next
        // run against the same var/cache. Tests that assert a limit spend it
        // themselves, so starting every test from a full allowance is what
        // they already assume.
        $limiterCache = static::getContainer()->get('cache.rate_limiter');
        $limiterCache->clear();
    }

    protected function browser(): KernelBrowser
    {
        return $this->client;
    }

    protected function loginAs(User $user): void
    {
        $this->client->loginUser($user);
        // Trigger a /api/me GET to seed the XSRF-TOKEN cookie used by the CSRF subscriber.
        $this->client->request('GET', '/api/me');
    }

    protected function csrfHeader(): array
    {
        $cookieJar = $this->client->getCookieJar();
        $cookie = $cookieJar->get('XSRF-TOKEN');
        if (!$cookie) {
            return [];
        }

        return ['HTTP_X_XSRF_TOKEN' => $cookie->getValue()];
    }

    protected function createAndLoginUser(array $attributes = []): User
    {
        $user = UserFactory::createOne($attributes)->object();
        $this->loginAs($user);

        return $user;
    }

    protected function createAndLoginAdmin(array $attributes = []): User
    {
        $user = UserFactory::new()->admin()->create($attributes)->object();
        $this->loginAs($user);

        return $user;
    }

    protected function postJson(string $url, array $payload = [], array $headers = []): array
    {
        $defaultHeaders = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $this->csrfHeader());

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            array_merge($defaultHeaders, $headers),
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $this->json();
    }

    protected function putJson(string $url, array $payload = []): array
    {
        $headers = array_merge(
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $this->csrfHeader()
        );

        $this->client->request(
            'PUT',
            $url,
            [],
            [],
            $headers,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $this->json();
    }

    protected function patchJson(string $url, array $payload = []): array
    {
        $headers = array_merge(
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $this->csrfHeader()
        );

        $this->client->request(
            'PATCH',
            $url,
            [],
            [],
            $headers,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $this->json();
    }

    protected function getJson(string $url): array
    {
        $this->client->request('GET', $url, [], [], ['HTTP_ACCEPT' => 'application/json']);

        return $this->json();
    }

    protected function deleteJson(string $url, array $payload = []): array
    {
        $headers = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $this->csrfHeader());
        $this->client->request(
            'DELETE',
            $url,
            [],
            [],
            $headers,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        return $this->json();
    }

    protected function json(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR) ?? [];
    }
}
