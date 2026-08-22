<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A body that is not JSON is a bad request, on every endpoint that reads one.
 *
 * {@see \App\Http\JsonRequestDecoder} throws for these, and each of the routes
 * below used to carry a second hand-written guard against a null it could never
 * return — dead since the decoder stopped returning one. The guards are gone;
 * these tests are what say the 400 they were aiming at is still there, rather
 * than a 500 from something further down trying to index a string.
 */
final class MalformedJsonPayloadTest extends AbstractApiTestCase
{
    public function testCreatingAUserRejectsATruncatedBody(): void
    {
        $this->createAndLoginAdmin();

        $this->postRaw('/api/users', '{"email": "someone@example.test"');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUpdatingAUserRejectsATruncatedBody(): void
    {
        $target = UserFactory::createOne()->object();
        $this->createAndLoginAdmin();

        $this->requestRaw('PUT', '/api/users/' . $target->getId(), '{"name": ');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUpdatingAUserRejectsAJsonScalar(): void
    {
        $target = UserFactory::createOne()->object();
        $this->createAndLoginAdmin();

        $this->requestRaw('PUT', '/api/users/' . $target->getId(), '"not an object"');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUpdatingAComicRejectsATruncatedBody(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();

        $this->requestRaw('PUT', '/api/comics/' . $comic->getId(), '{"title": ');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRecordingReadingProgressRejectsATruncatedBody(): void
    {
        $owner = $this->createAndLoginUser();
        $comic = ComicFactory::createOne(['owner' => $owner])->object();

        $this->postRaw('/api/comics/' . $comic->getId() . '/progress', '{"page":');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /**
     * The empty body is the one shape that is *not* an error: it decodes to an
     * empty array, and the endpoint's own validation decides from there.
     */
    public function testAnEmptyBodyIsNotTreatedAsMalformed(): void
    {
        $this->createAndLoginAdmin();

        $this->postRaw('/api/users', '');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString(
            'Missing required fields',
            (string) $this->client->getResponse()->getContent(),
            'An empty body must fall through to field validation, not to the JSON parser.'
        );
    }

    private function postRaw(string $url, string $body): void
    {
        $this->requestRaw('POST', $url, $body);
    }

    private function requestRaw(string $method, string $url, string $body): void
    {
        $this->client->request(
            $method,
            $url,
            [],
            [],
            array_merge(
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                $this->csrfHeader()
            ),
            $body
        );
    }
}
