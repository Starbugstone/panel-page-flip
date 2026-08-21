<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Service\ComicShareService;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A signed-in stranger must not be able to tell somebody else's comic from one
 * that was never uploaded.
 *
 * Comic ids are small sequential integers, so anybody with an account can walk
 * the whole space in a few thousand requests. What that walk returns is
 * therefore the whole question: as long as every endpoint answers a stranger
 * identically whether the id is real or invented, the walk yields a list of
 * numbers and nothing else. The moment one endpoint distinguishes them — a 403
 * where its neighbour says 404, a different message, a different body — it has
 * become a directory of who owns how much.
 *
 * Asserted endpoint by endpoint rather than once, because the leak that
 * prompted this was not a missing check anywhere. Every route was authorised
 * correctly; they simply did not agree on how to say no.
 */
final class ComicEnumerationTest extends AbstractApiTestCase
{
    /** An id far beyond anything the factories create in a test. */
    private const UNUSED_COMIC_ID = 999_666;

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function comicEndpoints(): iterable
    {
        yield 'metadata' => ['GET', '/api/comics/%d', []];
        yield 'page manifest' => ['GET', '/api/comics/%d/pages', []];
        yield 'page' => ['GET', '/api/comics/%d/pages/1', []];
        yield 'download' => ['GET', '/api/comics/%d/download', []];
        yield 'metadata suggestions' => ['GET', '/api/comics/%d/metadata-suggestions', []];
        yield 'update' => ['PATCH', '/api/comics/%d', ['title' => 'Taken']];
        yield 'delete' => ['DELETE', '/api/comics/%d', []];
        yield 'progress' => ['POST', '/api/comics/%d/progress', ['currentPage' => 2]];
        yield 'reset progress' => ['POST', '/api/comics/%d/reading-progress/reset', []];
        yield 'metadata candidates' => ['POST', '/api/comics/%d/metadata-candidates', []];
        yield 'metadata record' => [
            'POST',
            '/api/comics/%d/metadata-record',
            ['provider' => 'metron', 'externalId' => '1'],
        ];
        yield 'metadata refresh' => ['POST', '/api/comics/%d/metadata-refresh', []];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @dataProvider comicEndpoints
     */
    public function testAStrangerCannotTellARealComicFromAnInventedOne(
        string $method,
        string $template,
        array $payload
    ): void {
        $comic = ComicFactory::createOne(['owner' => UserFactory::createOne()->object()])->object();
        $this->loginAs(UserFactory::createOne()->object());

        $real = $this->call($method, sprintf($template, $comic->getId()), $payload);
        $invented = $this->call($method, sprintf($template, self::UNUSED_COMIC_ID), $payload);

        self::assertSame(404, $real['status'], 'A stranger should be told a real comic does not exist.');
        self::assertSame($invented, $real, 'The two answers must be indistinguishable.');
    }

    /**
     * The cover route names the owner as well as the comic, so it can leak the
     * pairing even when the comic id is one the caller already knows.
     */
    public function testACoverRevealsNeitherTheComicNorItsOwner(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::createOne([
            'owner' => $owner,
            'coverImagePath' => 'covers/missing/cover.png',
        ])->object();
        $this->loginAs(UserFactory::createOne()->object());

        $real = $this->call('GET', sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), $comic->getId()));
        $invented = $this->call(
            'GET',
            sprintf('/api/comics/cover/%d/%d/cover.png', $owner->getId(), self::UNUSED_COMIC_ID)
        );

        self::assertSame(404, $real['status']);
        self::assertSame($invented, $real);
    }

    /**
     * The other half of the rule. Somebody who has been offered the comic is
     * owed the real answer — hiding it from them protects nobody and only makes
     * a refusal they can act on look like a broken link.
     */
    public function testAnInvitedRecipientIsRefusedRatherThanToldItIsMissing(): void
    {
        $owner = UserFactory::createOne()->object();
        $comic = ComicFactory::new()->ownedBy($owner)->create()->object();
        $recipient = UserFactory::createOne(['email' => 'invited@test.local'])->object();
        $this->persistPendingShare($comic, $owner, $recipient);

        $this->loginAs($recipient);

        // Not accepted yet, so there is nothing to read — but they know
        // perfectly well that there is something to accept.
        self::assertSame(403, $this->call('GET', '/api/comics/'.$comic->getId())['status']);
        self::assertSame(403, $this->call('GET', '/api/comics/'.$comic->getId().'/pages/1')['status']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: string}
     */
    private function call(string $method, string $url, array $payload = []): array
    {
        $headers = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $this->csrfHeader());

        $this->browser()->request($method, $url, [], [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $this->browser()->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getContent(),
        ];
    }

    private function persistPendingShare(Comic $comic, User $owner, User $recipient): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $share = new ComicShare(
            $entityManager->getReference(Comic::class, (int) $comic->getId()),
            $entityManager->getReference(User::class, (int) $owner->getId()),
            (string) $recipient->getEmail()
        );
        $share->markPending(new \DateTimeImmutable(ComicShareService::INVITATION_TTL));

        $entityManager->persist($share);
        $entityManager->flush();
    }
}
