<?php

namespace App\ComicSource\Pdf;

use App\ComicSource\MemoryBudget;

/**
 * Stops the native reader while stopping is still something it can recover from.
 *
 * A PDF chooses how much work it causes: a small file can reach the same object
 * under thousands of numbers, or point every object at a compression bomb. Both
 * limits it can hit are fatal errors that skip the renderer fallback and upload
 * cleanup, so the reader gives up with a PdfException well before either.
 */
final class PdfWorkBudget
{
    /** One operation on one document; nothing a real comic needs comes close. */
    private const MAX_SECONDS = 20.0;

    /**
     * An upload makes several operations in one request and still has to fall
     * back to the renderer afterwards, so one may only spend a share of the limit.
     */
    private const TIME_LIMIT_SHARE = 4;

    private float $deadline;

    public function __construct()
    {
        $this->restart();
    }

    public function restart(): void
    {
        $this->deadline = microtime(true) + self::allowance();
    }

    /** @throws PdfException when the next $bytes or any more time cannot be spared */
    public function check(int|float $bytes = 0): void
    {
        if (!MemoryBudget::fits($bytes)) throw new PdfException('PDF needs more memory than the native reader has available.');
        if (microtime(true) > $this->deadline) throw new PdfException('PDF needs more time than the native reader has available.');
    }

    private static function allowance(): float
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) return self::MAX_SECONDS;

        return min(self::MAX_SECONDS, max(1.0, $limit / self::TIME_LIMIT_SHARE));
    }
}
