<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Comic;
use App\Entity\User;
use App\Repository\ComicRepository;
use App\Security\ComicAccess;
use App\Security\ComicForbiddenException;
use App\Security\ComicNotAccessibleException;
use App\Security\Voter\ComicVoter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The choice between "no such comic" and "you may not", made in isolation.
 *
 * Covered here as well as through the endpoints because this is one decision
 * that every comic endpoint delegates: testing it directly is what makes the
 * rule readable as a rule, rather than as a property re-derived from a dozen
 * HTTP responses.
 */
final class ComicAccessTest extends TestCase
{
    public function testAGrantedAttributeReturnsTheComic(): void
    {
        $comic = new Comic();
        $access = $this->accessFor($comic, granted: [ComicVoter::EDIT]);

        self::assertSame($comic, $access->requireComic(1, ComicVoter::EDIT));
    }

    public function testAMissingComicIsNotFound(): void
    {
        $access = $this->accessFor(null, granted: []);

        $this->expectException(ComicNotAccessibleException::class);
        $access->requireComic(1, ComicVoter::VIEW);
    }

    /**
     * The leak this class exists to close: a stranger must not be able to tell
     * this case apart from the one above.
     */
    public function testAStrangerIsToldTheComicDoesNotExist(): void
    {
        $access = $this->accessFor(new Comic(), granted: []);

        $this->expectException(ComicNotAccessibleException::class);
        $access->requireComic(1, ComicVoter::VIEW);
    }

    public function testTheTwoRefusalsCarryTheSameMessage(): void
    {
        $missing = null;
        $stranger = null;

        try {
            $this->accessFor(null, granted: [])->requireComic(1, ComicVoter::VIEW);
        } catch (ComicNotAccessibleException $exception) {
            $missing = $exception->getMessage();
        }

        try {
            $this->accessFor(new Comic(), granted: [])->requireComic(1, ComicVoter::VIEW);
        } catch (ComicNotAccessibleException $exception) {
            $stranger = $exception->getMessage();
        }

        self::assertNotNull($missing);
        self::assertSame($missing, $stranger);
    }

    /**
     * Somebody who may see the comic but not do this to it gets the honest
     * refusal — a recipient trying to edit what was shared with them.
     */
    public function testSomebodyWhoKnowsTheComicIsRefusedRatherThanMisled(): void
    {
        $access = $this->accessFor(new Comic(), granted: [ComicVoter::KNOW, ComicVoter::VIEW]);

        $this->expectException(ComicForbiddenException::class);
        $access->requireComic(1, ComicVoter::EDIT);
    }

    /**
     * KNOW is what draws the line, not VIEW. An owner whose comic has been
     * quarantined keeps EDIT and DELETE so they can clear it up, and loses
     * VIEW — so asking for view rights first would have locked them out.
     */
    public function testAQuarantinedOwnerCanStillReachTheirComic(): void
    {
        $comic = new Comic();
        $access = $this->accessFor($comic, granted: [ComicVoter::KNOW, ComicVoter::EDIT, ComicVoter::DELETE]);

        self::assertSame($comic, $access->requireComic(1, ComicVoter::DELETE));
    }

    /**
     * And is refused plainly for what quarantine does withdraw, rather than
     * being told their own comic is missing.
     */
    public function testAQuarantinedOwnerIsRefusedReadingInPlainTerms(): void
    {
        $access = $this->accessFor(new Comic(), granted: [ComicVoter::KNOW, ComicVoter::EDIT, ComicVoter::DELETE]);

        $this->expectException(ComicForbiddenException::class);
        $access->requireComic(1, ComicVoter::VIEW);
    }

    /** @param list<string> $granted */
    private function accessFor(?Comic $comic, array $granted): ComicAccess
    {
        $comics = $this->createMock(ComicRepository::class);
        $comics->method('find')->willReturn($comic);

        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')
            ->willReturnCallback(static fn (mixed $attribute): bool => in_array($attribute, $granted, true));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new User());

        return new ComicAccess($comics, $authorization, new NullLogger(), $security);
    }
}
