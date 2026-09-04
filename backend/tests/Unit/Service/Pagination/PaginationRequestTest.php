<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pagination;

use App\Service\Pagination\PaginationRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PaginationRequestTest extends TestCase
{
    public function testBoundsPageBeforeMultiplyingTheOffset(): void
    {
        $pagination = PaginationRequest::fromRequest(
            new Request(['page' => (string) PHP_INT_MAX, 'limit' => '100']),
            ['title' => 'c.title'],
            'title',
        );

        self::assertLessThanOrEqual(2_147_483_647, $pagination->offset());
        self::assertGreaterThan(0, $pagination->offset());
        self::assertSame(($pagination->page - 1) * $pagination->limit, $pagination->offset());
    }

    public function testKeepsAnOrdinaryPageAndItsSortUnchanged(): void
    {
        $pagination = PaginationRequest::fromRequest(
            new Request(['page' => '3', 'limit' => '25', 'sort' => 'title', 'direction' => 'asc']),
            ['title' => 'c.title'],
            'title',
        );

        self::assertSame(3, $pagination->page);
        self::assertSame(50, $pagination->offset());
        self::assertSame('ASC', $pagination->direction);
    }
}
