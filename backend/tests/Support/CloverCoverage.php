<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class CloverCoverage
{
    private function __construct(
        private readonly int $coveredLines,
        private readonly int $lines,
        private readonly int $coveredMethods,
        private readonly int $methods,
        /** @var list<string> */
        private readonly array $uncoveredFiles,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $xml = @file_get_contents($path);
        if (false === $xml) {
            throw new \InvalidArgumentException(sprintf('Coverage report "%s" could not be read.', $path));
        }

        return self::fromXml($xml);
    }

    public static function fromXml(string $xml): self
    {
        $report = @simplexml_load_string($xml);
        $metrics = false === $report ? null : $report->project->metrics;
        if (null === $metrics || 0 === $metrics->count()) {
            throw new \InvalidArgumentException('Coverage report has no project metrics.');
        }

        $lines = (int) $metrics['statements'];
        $methods = (int) $metrics['methods'];
        if ($lines < 1 || $methods < 1) {
            throw new \InvalidArgumentException('Coverage report project metrics are empty.');
        }

        $uncoveredFiles = [];
        foreach ($report->project->file as $file) {
            $fileMetrics = $file->metrics;
            if ((int) $fileMetrics['statements'] > 0 && (int) $fileMetrics['coveredstatements'] === 0) {
                $uncoveredFiles[] = (string) $file['name'];
            }
        }
        sort($uncoveredFiles);

        return new self(
            (int) $metrics['coveredstatements'],
            $lines,
            (int) $metrics['coveredmethods'],
            $methods,
            $uncoveredFiles,
        );
    }

    public function linePercentage(): float
    {
        return 100 * $this->coveredLines / $this->lines;
    }

    public function methodPercentage(): float
    {
        return 100 * $this->coveredMethods / $this->methods;
    }

    /** @return list<string> */
    public function failures(float $minimumLines, float $minimumMethods): array
    {
        $failures = [];
        if ($this->linePercentage() < $minimumLines) {
            $failures[] = sprintf(
                'Line coverage %.2f%% is below %.2f%%.',
                $this->linePercentage(),
                $minimumLines,
            );
        }
        if ($this->methodPercentage() < $minimumMethods) {
            $failures[] = sprintf(
                'Method coverage %.2f%% is below %.2f%%.',
                $this->methodPercentage(),
                $minimumMethods,
            );
        }
        if ($this->uncoveredFiles !== []) {
            $failures[] = sprintf(
                'Production files with no executed lines: %s.',
                implode(', ', $this->uncoveredFiles),
            );
        }

        return $failures;
    }
}
