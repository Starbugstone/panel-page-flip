<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ApiExceptionSubscriber;
use App\Security\UnauthenticatedException;
use App\Service\ShareException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The single place the API answers "you are not signed in", so the wording and
 * the status live here rather than at every action that needs a user.
 */
final class ApiExceptionSubscriberTest extends TestCase
{
    public function testAnUnauthenticatedExceptionBecomesA401(): void
    {
        $event = $this->exceptionEvent('/api/comics', new UnauthenticatedException());

        (new ApiExceptionSubscriber())->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ['message' => 'User not authenticated'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testABadRequestStillBecomesItsOwnStatus(): void
    {
        $event = $this->exceptionEvent('/api/comics', new BadRequestHttpException('Malformed JSON.'));

        (new ApiExceptionSubscriber())->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['message' => 'Malformed JSON.'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testAShareFailureKeepsItsOwnStatusAndBody(): void
    {
        $event = $this->exceptionEvent(
            '/api/shares/comics/1/invitations',
            new ShareException('You have already invited them.', 429)
        );

        (new ApiExceptionSubscriber())->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame(
            ['message' => 'You have already invited them.'],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    /**
     * The code is what a client branches on — the age gate opens on this and
     * not on the wording — so it has to survive being rendered here.
     */
    public function testAShareFailureCarriesItsCodeWhenItHasOne(): void
    {
        $event = $this->exceptionEvent('/api/shares/comics/1/invitations', new ShareException(
            'The recipient has not confirmed they are 18 or older.',
            403,
            ShareException::CODE_ADULT_CONFIRMATION_REQUIRED
        ));

        (new ApiExceptionSubscriber())->onException($event);

        $payload = json_decode((string) $event->getResponse()?->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ShareException::CODE_ADULT_CONFIRMATION_REQUIRED, $payload['code']);
    }

    /**
     * Anything outside /api belongs to whatever renders the app's own error
     * pages; answering it with JSON here would hand a browser a blank screen.
     */
    public function testRequestsOutsideTheApiAreLeftAlone(): void
    {
        $event = $this->exceptionEvent('/login', new UnauthenticatedException());

        (new ApiExceptionSubscriber())->onException($event);

        self::assertNull($event->getResponse());
    }

    public function testUnrelatedExceptionsAreLeftForTheDefaultHandler(): void
    {
        $event = $this->exceptionEvent('/api/comics', new \RuntimeException('something else'));

        (new ApiExceptionSubscriber())->onException($event);

        self::assertNull($event->getResponse());
    }

    private function exceptionEvent(string $path, \Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );
    }
}
