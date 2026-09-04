<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Pagination\LikePattern;
use Doctrine\DBAL\Connection;

/** Database-wide suggestions for the free-text filters on paginated admin tables. */
final class AdminTableFilterSuggestions
{
    public const MIN_QUERY_LENGTH = 3;
    private const LIMIT = 6;

    /**
     * Each select returns one value exactly as its table cell displays it. The
     * source and expressions are an internal allow-list; route input is never
     * interpolated into SQL.
     *
     * @var array<string, list<array{expression: string, from: string, searchExpression?: string, condition?: string}>>
     */
    private const SOURCES = [
        'users/identity' => [
            ['expression' => 'u.name', 'from' => '`user` u'],
            ['expression' => 'u.email', 'from' => '`user` u'],
        ],
        'comics/title-author' => [
            ['expression' => 'c.title', 'from' => 'comic c'],
            ['expression' => 'c.author', 'from' => 'comic c'],
        ],
        'comics/owner' => [
            ['expression' => 'u.name', 'from' => 'comic c INNER JOIN `user` u ON u.id = c.owner_id'],
            ['expression' => 'u.email', 'from' => 'comic c INNER JOIN `user` u ON u.id = c.owner_id'],
        ],
        'comics/tags' => [
            ['expression' => 't.name', 'from' => 'comic_tag ct INNER JOIN tag t ON t.id = ct.tag_id'],
        ],
        'tags/name' => [
            ['expression' => 't.name', 'from' => 'tag t'],
        ],
        'tags/creator' => [
            ['expression' => 'u.name', 'from' => 'tag t INNER JOIN `user` u ON u.id = t.creator_id'],
            ['expression' => 'u.email', 'from' => 'tag t INNER JOIN `user` u ON u.id = t.creator_id'],
            ['expression' => "'System'", 'from' => 'tag t', 'condition' => 't.creator_id IS NULL'],
        ],
        'shares/comic' => [
            ['expression' => 'c.title', 'from' => 'comic_share s INNER JOIN comic c ON c.id = s.comic_id'],
        ],
        'shares/owner' => [
            ['expression' => 'u.name', 'from' => 'comic_share s INNER JOIN `user` u ON u.id = s.owner_id'],
            ['expression' => 'u.email', 'from' => 'comic_share s INNER JOIN `user` u ON u.id = s.owner_id'],
        ],
        'shares/recipient' => [
            ['expression' => 'u.name', 'from' => 'comic_share s INNER JOIN `user` u ON u.id = s.recipient_user_id'],
            ['expression' => "CONCAT('@', u.username)", 'from' => 'comic_share s INNER JOIN `user` u ON u.id = s.recipient_user_id'],
            ['expression' => 's.recipient_email_normalized', 'from' => 'comic_share s'],
        ],
        'sharing-codes/owner' => [
            ['expression' => 'u.name', 'from' => 'share_claim_code sc INNER JOIN `user` u ON u.id = sc.owner_id'],
            ['expression' => 'u.email', 'from' => 'share_claim_code sc INNER JOIN `user` u ON u.id = sc.owner_id'],
        ],
        'sharing-codes/comics' => [
            ['expression' => 'c.title', 'from' => 'share_claim_code_comic scc INNER JOIN comic c ON c.id = scc.comic_id'],
        ],
        'audit-logs/admin' => [
            ['expression' => 'u.name', 'from' => 'admin_audit_log l INNER JOIN `user` u ON u.id = l.admin_user_id'],
            ['expression' => 'u.email', 'from' => 'admin_audit_log l INNER JOIN `user` u ON u.id = l.admin_user_id'],
        ],
        'audit-logs/details' => [
            [
                'expression' => 'CAST(l.payload AS CHAR)',
                'searchExpression' => 'l.payload_search',
                'from' => 'admin_audit_log l',
            ],
        ],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function supports(string $source): bool
    {
        return isset(self::SOURCES[$source]);
    }

    /** @return list<string> */
    public function search(string $source, string $query): array
    {
        if (!$this->supports($source)) {
            throw new \InvalidArgumentException('Unknown admin table suggestion source.');
        }

        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $selects = array_map(static function (array $candidate): string {
            $searchExpression = $candidate['searchExpression'] ?? $candidate['expression'];

            return sprintf(
                'SELECT %1$s AS value FROM %2$s WHERE %3$s IS NOT NULL AND LOWER(%3$s) LIKE :pattern%4$s',
                $candidate['expression'],
                $candidate['from'],
                $searchExpression,
                isset($candidate['condition']) ? ' AND ' . $candidate['condition'] : '',
            );
        }, self::SOURCES[$source]);

        $sql = sprintf(
            'SELECT value FROM (%s) candidates GROUP BY value '
            . 'ORDER BY CASE WHEN LOWER(value) LIKE :prefix THEN 0 ELSE 1 END, CHAR_LENGTH(value), value LIMIT %d',
            implode(' UNION ALL ', $selects),
            self::LIMIT,
        );

        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $this->connection->executeQuery($sql, [
                'pattern' => LikePattern::contains($query),
                'prefix' => self::prefixPattern($query),
            ])->fetchFirstColumn(),
        ));
    }

    private static function prefixPattern(string $query): string
    {
        return ltrim(LikePattern::contains($query), '%');
    }
}
