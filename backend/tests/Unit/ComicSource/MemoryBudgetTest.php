<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\MemoryBudget;
use PHPUnit\Framework\TestCase;

final class MemoryBudgetTest extends TestCase
{
    private string|false $configured = false;

    protected function setUp(): void
    {
        $this->configured = ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        if ($this->configured !== false) ini_set('memory_limit', $this->configured);
    }

    public function testAnUnlimitedProcessFitsAnything(): void
    {
        ini_set('memory_limit', '-1');

        self::assertSame(PHP_INT_MAX, MemoryBudget::available());
        self::assertTrue(MemoryBudget::fits(PHP_INT_MAX));
    }

    public function testReadsTheShorthandAndCountsWhatIsAlreadyInUse(): void
    {
        ini_set('memory_limit', '2G');
        $used = memory_get_usage(true);

        self::assertTrue(MemoryBudget::fits(1_000_000_000));
        self::assertFalse(MemoryBudget::fits(2_147_483_648 - $used + 1));
    }

    public function testKeepsSomethingBackRatherThanOfferingEveryLastByte(): void
    {
        ini_set('memory_limit', '2G');

        self::assertLessThan(2_147_483_648 - memory_get_usage(true), MemoryBudget::available());
    }
}
