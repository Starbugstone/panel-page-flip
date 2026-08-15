<?php

namespace App\Service;

/**
 * The shape of a source page, as the reader needs to know it.
 *
 * Deliberately only numbers: geometry is derived from a comic whose access is
 * checked on every request, but archive entry names and filesystem paths are
 * not something a client ever needs and are not carried here.
 */
final class PageGeometry
{
    public function __construct(
        public readonly int $page,
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($page < 1 || $width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Page geometry must be positive.');
        }
    }

    public function aspectRatio(): float
    {
        return round($this->width / $this->height, 4);
    }

    /** @return array{page: int, width: int, height: int, aspectRatio: float} */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'width' => $this->width,
            'height' => $this->height,
            'aspectRatio' => $this->aspectRatio(),
        ];
    }
}
