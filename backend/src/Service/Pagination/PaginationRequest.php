<?php

namespace App\Service\Pagination;

use Symfony\Component\HttpFoundation\Request;

/**
 * The paging, search and sort parameters shared by every admin list endpoint.
 *
 * Built from the query string, but never trusting it: the page size is clamped
 * so one request cannot ask for the whole table, and the sort field is checked
 * against an allow-list supplied by the caller so it can be interpolated into
 * DQL safely.
 */
final class PaginationRequest
{
    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT = 100;

    /**
     * @param string $sortField A field name the caller declared sortable.
     * @param string $direction Either "ASC" or "DESC".
     */
    private function __construct(
        public readonly int $page,
        public readonly int $limit,
        public readonly ?string $search,
        public readonly string $sortField,
        public readonly string $direction,
    ) {
    }

    /**
     * @param array<string, string> $sortableFields Query alias => DQL expression,
     *                                              e.g. ['createdAt' => 'u.createdAt'].
     * @param string $defaultSort A key of $sortableFields.
     * @param string $defaultDirection Newest-first lists want DESC; alphabetical ones ASC.
     */
    public static function fromRequest(
        Request $request,
        array $sortableFields,
        string $defaultSort,
        string $defaultDirection = 'DESC',
    ): self {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $search = trim((string) $request->query->get('search', ''));

        // An unknown sort field falls back to the default rather than erroring:
        // a stale bookmark should still render a list.
        $sort = (string) $request->query->get('sort', $defaultSort);
        if (!array_key_exists($sort, $sortableFields)) {
            $sort = $defaultSort;
        }

        $direction = strtoupper((string) $request->query->get('direction', $defaultDirection));
        if ($direction !== 'ASC') {
            $direction = 'DESC';
        }

        return new self($page, $limit, $search === '' ? null : $search, $sort, $direction);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /** The `LIKE` argument for the search term, or null when nothing was searched for. */
    public function searchPattern(): ?string
    {
        return $this->search === null ? null : '%' . mb_strtolower($this->search) . '%';
    }
}
