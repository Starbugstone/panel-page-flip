<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pagination;

use App\Service\Pagination\ColumnFilter;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class ColumnFilterTest extends TestCase
{
    public function testItReadsExactAndOpenCalendarRanges(): void
    {
        $exact = new \DateTimeImmutable('2026-08-14');
        self::assertEquals([$exact, $exact], ColumnFilter::dateRange('2026-08-14'));
        self::assertEquals(
            [new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-31')],
            ColumnFilter::dateRange('2026-08-01..2026-08-31'),
        );
        self::assertEquals(
            [null, new \DateTimeImmutable('2026-08-31')],
            ColumnFilter::dateRange('..2026-08-31'),
        );
        self::assertEquals(
            [new \DateTimeImmutable('2026-08-01'), null],
            ColumnFilter::dateRange('2026-08-01..'),
        );
    }

    public function testItRejectsLooseInvalidAndBackwardsDates(): void
    {
        self::assertNull(ColumnFilter::dateRange('tomorrow'));
        self::assertNull(ColumnFilter::dateRange('2026-02-30'));
        self::assertNull(ColumnFilter::dateRange('2026-08-31..2026-08-01'));
        self::assertNull(ColumnFilter::dateRange('..'));
        self::assertSame(
            'UTC',
            ColumnFilter::day('2026-08-01', 'not/a-real-zone')?->getTimezone()->getName(),
        );
    }

    public function testItTurnsBrowserCalendarEdgesIntoUtcAcrossDaylightSavingTime(): void
    {
        $parameters = [];
        $qb = $this->createMock(QueryBuilder::class);
        $qb->expects(self::exactly(2))->method('andWhere')->willReturnSelf();
        $qb->expects(self::exactly(2))->method('setParameter')
            ->willReturnCallback(function (string $name, mixed $value) use (&$parameters, $qb): QueryBuilder {
                $parameters[$name] = $value;

                return $qb;
            });

        ColumnFilter::applyDay($qb, 'u.createdAt', 'created', '2026-03-29', 'Europe/Paris');

        self::assertSame('2026-03-28T23:00:00+00:00', $parameters['createdFrom']->format('c'));
        // Paris changes from UTC+1 to UTC+2 during this selected day, so its
        // two local midnights are only 23 hours apart.
        self::assertSame('2026-03-29T22:00:00+00:00', $parameters['createdTo']->format('c'));
    }
}
