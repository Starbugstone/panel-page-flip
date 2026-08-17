<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * No administrative endpoint answers an ordinary account.
 *
 * Derived from the router rather than from a list written out here, because a
 * list is exactly what stops being true. An administrative route added next
 * month is covered by this test the day it is added; a hand-maintained
 * enumeration would quietly not cover it, and would look just as green.
 *
 * The refusal itself comes from `security.yaml`'s access control, one line
 * ahead of any check inside a controller. That is the layer worth pinning: the
 * in-controller `denyAccessUnlessGranted` calls are a second lock on the same
 * door, and several admin actions have only ever had the first.
 */
final class AdminSurfaceIsClosedTest extends AbstractApiTestCase
{
    /**
     * `/api/users` contains a small number of deliberately non-administrative
     * routes. Keep the exceptions explicit here rather than deriving them from
     * `security.yaml`: this test must remain capable of catching an accidental
     * relaxation of that configuration.
     *
     * @var array<string, true>
     */
    private const NON_ADMIN_USER_ROUTES = [
        'GET /api/users/username-suggestion' => true,
        'GET /api/users/username-available' => true,
        'POST /api/users/resolve-username' => true,
        'PUT /api/users/username' => true,
    ];

    /**
     * Routes an ordinary user must never get past.
     *
     * `/api/users` is included alongside `/api/admin`: managing accounts is an
     * administrative surface that simply does not live under that prefix. The
     * handful of registration/account-holder routes under the same prefix are
     * excluded explicitly above.
     *
     * @return list<array{0: string, 1: string}> method and path
     */
    private function administrativeRoutes(): array
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);

        $routes = [];
        /** @var Route $route */
        foreach ($router->getRouteCollection() as $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/admin') && !str_starts_with($path, '/api/users')) {
                continue;
            }

            // A concrete id: the point is to be refused before anything looks
            // for the record, so it does not matter that nothing has this id.
            $url = (string) preg_replace('/\{[^}]+\}/', '4242', $path);

            foreach ($route->getMethods() ?: ['GET'] as $method) {
                if (isset(self::NON_ADMIN_USER_ROUTES[$method.' '.$path])) {
                    continue;
                }

                $routes[] = [$method, $url];
            }
        }

        self::assertNotEmpty($routes, 'The router should expose administrative routes to check.');

        return $routes;
    }

    public function testEveryAdministrativeRouteRefusesAnOrdinaryUser(): void
    {
        $this->createAndLoginUser(['email' => 'ordinary@test.local']);

        foreach ($this->administrativeRoutes() as [$method, $url]) {
            $status = $this->statusOf($method, $url);

            self::assertSame(
                403,
                $status,
                sprintf('%s %s let an ordinary user through with %d.', $method, $url, $status)
            );
        }
    }

    /**
     * The same sweep unauthenticated, so a route is not merely refusing the
     * ordinary account while answering nobody at all.
     */
    public function testEveryAdministrativeRouteRefusesAnonymousVisitors(): void
    {
        foreach ($this->administrativeRoutes() as [$method, $url]) {
            $status = $this->statusOf($method, $url);

            self::assertSame(
                401,
                $status,
                sprintf('%s %s answered an anonymous visitor with %d.', $method, $url, $status)
            );
        }
    }

    /**
     * And the control: an administrator is not refused by the same rule, so a
     * green sweep above cannot be the result of everything being broken.
     */
    public function testAnAdministratorIsNotRefusedByTheFirewall(): void
    {
        $this->createAndLoginAdmin(['email' => 'admin@test.local']);

        self::assertNotSame(403, $this->statusOf('GET', '/api/admin/stats'));
        self::assertNotSame(403, $this->statusOf('GET', '/api/users'));
    }

    private function statusOf(string $method, string $url): int
    {
        $headers = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $this->csrfHeader());

        $this->browser()->request($method, $url, [], [], $headers, '{}');

        return $this->browser()->getResponse()->getStatusCode();
    }
}
