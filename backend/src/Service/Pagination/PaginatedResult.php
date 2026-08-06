<?php

namespace App\Service\Pagination;

/**
 * One page of results plus the totals the client needs to render a pager.
 *
 * @template T
 */
final class PaginatedResult
{
    /** @param list<T> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $totalItems,
        public readonly int $page,
        public readonly int $limit,
    ) {
    }

    /** @param list<T> $items */
    public static function fromRequest(array $items, int $totalItems, PaginationRequest $request): self
    {
        return new self($items, $totalItems, $request->page, $request->limit);
    }

    public function totalPages(): int
    {
        return (int) max(1, ceil($this->totalItems / $this->limit));
    }

    /**
     * The `pagination` block every admin list response carries.
     *
     * @return array{page: int, limit: int, totalItems: int, totalPages: int}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'limit' => $this->limit,
            'totalItems' => $this->totalItems,
            'totalPages' => $this->totalPages(),
        ];
    }
}
