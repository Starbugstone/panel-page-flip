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
 *   - A clean rate-limiter and application cache per test.
 */
abstract class AbstractApiTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    protected ?KernelBrowser $client = null;

    /** Whoever {@see loginAs()} last signed in, for helpers that need their id. */
    protected ?User $currentUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        // The limiters are cache-backed and keyed by user id, while the database
        // is rolled back between tests — so ids come round again and a test can
        // inherit an allowance an earlier one already spent. That made failures
        // depend on execution order and on how many users unrelated tests
        // happened to create. Clearing the pool makes every limiter test see the
        // full allowance, whichever order they run in.
        static::getContainer()->get('cache.rate_limiter')->clear();

        // The same hazard one pool over. Endpoints that cache a computed answer
        // — the admin statistics are cached for a minute — keep it in the
        // application pool, which outlives the rolled-back database. A test
        // that read the figures with one user in the table left them there for
        // whichever test asked next, so `/api/admin/stats` reported the count
        // from a different test's fixtures and the failure depended on the
        // order and the clock.
        static::getContainer()->get('cache.app')->clear();

        // A test that made the mail server fail must not leave it failing for
        // the next one; the switch is static, because the service it stands in
        // for cannot be replaced once the container has built it.
        \App\Tests\Support\SwitchableMailer::reset();
        \App\Tests\Support\SwitchableMessageBus::reset();
    }

    protected function browser(): KernelBrowser
    {
        return $this->client;
    }

    /**
     * Spend the signed-in account's whole identifier-lookup allowance.
     *
     * In process rather than over HTTP. The allowance is deliberately loose —
     * it is the ceiling that keeps an exhausted caller away from the database,
     * not the control that makes guessing hopeless — so spending it through the
     * API would mean hundreds of requests to set up a single assertion.
     * Consuming the same limiter the guard consumes leaves the state under test
     * identical.
     */
    protected function exhaustIdentifierLookups(?User $caller = null): void
    {
        $user = $caller ?? $this->currentUser;
        self::assertNotNull($user, 'Sign somebody in before spending their allowance.');

        $limiter = static::getContainer()
            ->get('test.limiter.identifier_lookup')
            ->create((string) $user->getId());

        // One past the limit, so the next real request is refused rather than
        // being the one that happens to exhaust it.
        while ($limiter->consume()->isAccepted()) {
        }
    }

    protected function loginAs(User $user): void
    {
        $this->currentUser = $user;
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
        $user = UserFactory::createOne($attributes);
        $this->loginAs($user);

        return $user;
    }

    protected function createAndLoginAdmin(array $attributes = []): User
    {
        $user = UserFactory::new()->admin()->create($attributes);
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
