<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\LoginRateLimitSubscriber;
use App\Service\ApiRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LoginRateLimitSubscriberTest extends TestCase
{
    /**
     * The switch exists for a developer signing the same account in all day
     * from one address. Nothing else may turn it off.
     */
    public function testLoginLimitingIsDisabledOnlyWhenSwitchedOff(): void
    {
        $rateLimiter = $this->createMock(ApiRateLimiter::class);
        $rateLimiter->expects(self::never())->method('limit');
        $event = $this->requestEvent();

        (new LoginRateLimitSubscriber($rateLimiter, false))->limitLogin($event);

        self::assertNull($event->getResponse());
    }

    public function testLoginLimitingIsActiveWhenEnabled(): void
    {
        $response = new JsonResponse(['message' => 'Too many requests.'], 429);
        $rateLimiter = $this->createMock(ApiRateLimiter::class);
        $rateLimiter
            ->expects(self::once())
            ->method('limit')
            ->with(self::isInstanceOf(Request::class), 'login')
            ->willReturn($response);
        $event = $this->requestEvent();

        (new LoginRateLimitSubscriber($rateLimiter, true))->limitLogin($event);

        self::assertSame($response, $event->getResponse());
    }

    private function requestEvent(): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/login', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
