<?php

declare(strict_types=1);

namespace App\Metadata;

/** What a filename implies. Every field is a guess, including the series. */
final class FilenameGuess
{
    public function __construct(
        public readonly string $series,
        public readonly ?string $issueNumber = null,
        public readonly ?int $volume = null,
        public readonly ?int $year = null,
    ) {
    }
}
