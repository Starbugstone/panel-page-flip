<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Comic;
use App\Enum\ComicPageType;
use App\Enum\ReadingDirection;
use PHPUnit\Framework\TestCase;

/**
 * The page-info contract the spread and derivative work reads. Stored rows are
 * normalised on the way out, so data written by an older version can never
 * reach a consumer in a shape it does not expect.
 */
final class ComicPageInfoTest extends TestCase
{
    public function testExposesStoredPageFactsKeyedByPageNumber(): void
    {
        $comic = (new Comic())->setPageMetadata([
            ['page' => 1, 'type' => 'FrontCover', 'width' => 1200, 'height' => 1800],
            ['page' => 2, 'doublePage' => true],
        ]);

        $info = $comic->getPageInfo();

        self::assertSame([1, 2], array_keys($info));
        self::assertSame(ComicPageType::FrontCover, $info[1]->type);
        self::assertSame(1200, $info[1]->width);
        self::assertTrue($info[2]->doublePage);
    }

    public function testDropsRowsItCannotTrust(): void
    {
        $comic = (new Comic())->setPageMetadata([
            ['page' => 0],
            ['page' => 'two'],
            ['type' => 'Story'],
            ['page' => 3, 'type' => 'NotARealType', 'width' => -5],
            'not even an array',
        ]);

        $info = $comic->getPageInfo();

        self::assertSame([3], array_keys($info));
        self::assertNull($info[3]->type);
        self::assertNull($info[3]->width);
    }

    public function testHasNoPageInfoUntilSomethingSuppliesIt(): void
    {
        self::assertSame([], (new Comic())->getPageInfo());
        self::assertSame([], (new Comic())->getPageMetadata());
        self::assertSame([], (new Comic())->getCreators());
    }

    public function testDefaultsToLeftToRight(): void
    {
        self::assertSame(ReadingDirection::LeftToRight, (new Comic())->getReadingDirection());
    }

    /** Empty collections are stored as NULL so an absent value reads as absent. */
    public function testStoresEmptyCollectionsAsNothing(): void
    {
        $comic = (new Comic())->setCreators([])->setPageMetadata([]);

        self::assertSame([], $comic->getCreators());
        self::assertSame([], $comic->getPageMetadata());
    }
}
