<?php

declare(strict_types=1);

namespace App\Tests\Unit\Quality;

use App\Tests\Support\CloverCoverage;
use PHPUnit\Framework\TestCase;

final class CloverCoverageTest extends TestCase
{
    public function testItReadsProjectLineAndMethodCoverage(): void
    {
        $coverage = CloverCoverage::fromXml(<<<'XML'
            <?xml version="1.0"?>
            <coverage><project><metrics methods="10" coveredmethods="7" statements="100" coveredstatements="83" /></project></coverage>
            XML);

        self::assertSame(83.0, $coverage->linePercentage());
        self::assertSame(70.0, $coverage->methodPercentage());
        self::assertSame([], $coverage->failures(80.0, 70.0));
    }

    public function testItNamesEveryThresholdThatRegressed(): void
    {
        $coverage = CloverCoverage::fromXml(<<<'XML'
            <?xml version="1.0"?>
            <coverage><project><metrics methods="10" coveredmethods="6" statements="100" coveredstatements="79" /></project></coverage>
            XML);

        self::assertSame([
            'Line coverage 79.00% is below 80.00%.',
            'Method coverage 60.00% is below 70.00%.',
        ], $coverage->failures(80.0, 70.0));
    }

    public function testItRejectsCoverableProductionFilesThatNoTestExecuted(): void
    {
        $coverage = CloverCoverage::fromXml(<<<'XML'
            <?xml version="1.0"?>
            <coverage>
              <project>
                <file name="/app/src/Covered.php"><metrics statements="3" coveredstatements="1" /></file>
                <file name="/app/src/Untested.php"><metrics statements="2" coveredstatements="0" /></file>
                <file name="/app/src/Constants.php"><metrics statements="0" coveredstatements="0" /></file>
                <metrics methods="10" coveredmethods="7" statements="100" coveredstatements="83" />
              </project>
            </coverage>
            XML);

        self::assertSame([
            'Production files with no executed lines: /app/src/Untested.php.',
        ], $coverage->failures(80.0, 70.0));
    }

    public function testItRejectsAReportWithoutProjectMetrics(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('project metrics');

        CloverCoverage::fromXml('<coverage />');
    }
}
