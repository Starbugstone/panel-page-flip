<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ContentReport;
use App\Entity\User;
use App\Service\ComicShareService;
use App\Service\ContentRestrictionService;
use App\Service\SecurityAuditLogger;
use PHPUnit\Framework\TestCase;

/**
 * The published action table against the dispatch that enforces it.
 *
 * The admin screen renders its options from `ACTIONS`, so an entry the service
 * cannot actually perform is an option that always fails, and an action missing
 * from the table is one no administrator can reach. Neither shows up anywhere
 * else.
 */
final class ContentRestrictionServiceActionsTest extends TestCase
{
    /** @dataProvider actionProvider */
    public function testEveryPublishedActionIsDispatched(string $action, ?string $requires): void
    {
        try {
            $this->service()->apply($action, $this->report(), new User());
            self::assertNull($requires, sprintf('"%s" needs no target and was applied.', $action));
        } catch (\DomainException $exception) {
            self::assertNotSame(
                'Invalid administrative content action.',
                $exception->getMessage(),
                sprintf('"%s" is offered to administrators but the service does not handle it.', $action),
            );
            // With nothing linked, an action that needs a target must say which.
            self::assertSame(
                $requires === 'comic' ? 'Link a comic before applying this action.' : 'Link a user before applying this action.',
                $exception->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function actionProvider(): iterable
    {
        foreach (ContentRestrictionService::ACTIONS as $action) {
            yield $action['value'] => [$action['value'], $action['requires']];
        }
    }

    public function testAnActionOutsideTheTableIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invalid administrative content action.');

        $this->service()->apply('delete_everything', $this->report(), new User());
    }

    private function report(): ContentReport
    {
        return new ContentReport(
            'Reporter',
            'reporter@example.com',
            ContentReport::CATEGORY_COPYRIGHT,
            'Reference 900',
            'An allegation long enough to be accepted by the submission rules and to describe the work.'
        );
    }

    private function service(): ContentRestrictionService
    {
        return new ContentRestrictionService(
            $this->createMock(ComicShareService::class),
            $this->createMock(SecurityAuditLogger::class),
        );
    }
}
