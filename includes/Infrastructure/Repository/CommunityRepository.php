<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class CommunityRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function listVisible(int $limit = 50): array
    {
        $table = $this->tables->communities();
        $posts = $this->tables->posts();
        $sql = "SELECT c.id, c.name, c.slug, c.description, c.icon, c.visibility, c.created_at,
                       MAX(p.created_at) AS latest_post_at,
                       MAX(p.last_commented_at) AS latest_comment_at
                FROM {$table} c
                LEFT JOIN {$posts} p
                  ON p.community_id = c.id
                 AND p.status <> 'deleted'
                WHERE c.visibility = 'public'
                  AND c.slug <> 'all-scenes'
                GROUP BY c.id, c.name, c.slug, c.description, c.icon, c.visibility, c.created_at";

        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];
        if (empty($rows)) {
            return [];
        }

        $sortDesc = static function (array $items, string $key, string $fallbackKey = 'created_at'): array {
            usort($items, static function (array $a, array $b) use ($key, $fallbackKey): int {
                $av = (string) ($a[$key] ?? '');
                $bv = (string) ($b[$key] ?? '');
                $aNull = $av === '' || $av === '0000-00-00 00:00:00';
                $bNull = $bv === '' || $bv === '0000-00-00 00:00:00';
                if ($aNull !== $bNull) {
                    return $aNull ? 1 : -1;
                }
                if (! $aNull && $av !== $bv) {
                    return strcmp($bv, $av);
                }
                $af = (string) ($a[$fallbackKey] ?? '');
                $bf = (string) ($b[$fallbackKey] ?? '');
                if ($af !== $bf) {
                    return strcmp($bf, $af);
                }
                return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
            });
            return $items;
        };

        $postSorted = $sortDesc($rows, 'latest_post_at');
        $topPost = array_slice($postSorted, 0, 3);
        $picked = [];
        foreach ($topPost as $row) {
            $picked[(int) $row['id']] = true;
        }

        $commentPool = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $hasCommentActivity = (string) ($row['latest_comment_at'] ?? '') !== '';
            if (! isset($picked[$id]) && $hasCommentActivity) {
                $commentPool[] = $row;
            }
        }

        $commentSorted = $sortDesc($commentPool, 'latest_comment_at');
        $topComment = array_slice($commentSorted, 0, 2);
        foreach ($topComment as $row) {
            $picked[(int) $row['id']] = true;
        }

        $remaining = [];
        foreach ($postSorted as $row) {
            if (! isset($picked[(int) ($row['id'] ?? 0)])) {
                $remaining[] = $row;
            }
        }

        $ordered = array_merge($topPost, $topComment, $remaining);
        if ($limit > 0) {
            $ordered = array_slice($ordered, 0, $limit);
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'description' => isset($row['description']) ? (string) $row['description'] : '',
                'icon' => isset($row['icon']) ? (string) $row['icon'] : '',
                'visibility' => (string) ($row['visibility'] ?? 'public'),
            ];
        }, $ordered);
    }

    public function findBySlug(string $slug): ?array
    {
        $table = $this->tables->communities();
        $sql = $this->wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug);
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function countAll(): int
    {
        $table = $this->tables->communities();
        $sql = "SELECT COUNT(*) FROM {$table}";
        return (int) $this->wpdb->get_var($sql);
    }

    public function countEnabled(): int
    {
        $table = $this->tables->communities();
        $sql = "SELECT COUNT(*) FROM {$table} WHERE visibility = 'public'";
        return (int) $this->wpdb->get_var($sql);
    }

    public function findById(int $id): ?array
    {
        $table = $this->tables->communities();
        $sql = $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id);
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function listAllWithPostCounts(): array
    {
        $communities = $this->tables->communities();
        $posts = $this->tables->posts();
        $sql = "SELECT c.id, c.name, c.slug, c.description, c.visibility,
                       COUNT(p.id) AS post_count
                FROM {$communities} c
                LEFT JOIN {$posts} p ON p.community_id = c.id AND p.status <> 'deleted'
                GROUP BY c.id, c.name, c.slug, c.description, c.visibility
                ORDER BY c.created_at DESC, c.id DESC";

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function updateById(int $id, array $payload): bool
    {
        $table = $this->tables->communities();
        $data = [
            'name' => (string) ($payload['name'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'description' => isset($payload['description']) ? (string) $payload['description'] : null,
            'icon' => isset($payload['icon']) ? (string) $payload['icon'] : null,
            'rules' => isset($payload['rules']) ? (string) $payload['rules'] : null,
            'visibility' => (string) ($payload['visibility'] ?? 'public'),
            'updated_at' => current_time('mysql', true),
        ];

        $updated = $this->wpdb->update(
            $table,
            $data,
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        $table = $this->tables->communities();
        $updated = $this->wpdb->update(
            $table,
            [
                'visibility' => $enabled ? 'public' : 'private',
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    public function postCountForCommunity(int $id): int
    {
        $posts = $this->tables->posts();
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$posts} WHERE community_id = %d AND status <> 'deleted'",
            $id
        );
        return (int) $this->wpdb->get_var($sql);
    }

    public function deleteById(int $id): bool
    {
        $table = $this->tables->communities();
        $deleted = $this->wpdb->delete($table, ['id' => $id], ['%d']);
        return $deleted !== false;
    }

    /** @param array{name:string,slug:string,description:?string,icon:?string,rules:?string,visibility:string,created_by_user_id:int,created_at:string,updated_at:string} $payload */
    public function create(array $payload): int
    {
        $table = $this->tables->communities();

        $ok = $this->wpdb->insert(
            $table,
            [
                'name' => $payload['name'],
                'slug' => $payload['slug'],
                'description' => $payload['description'],
                'icon' => $payload['icon'],
                'rules' => $payload['rules'],
                'visibility' => $payload['visibility'],
                'created_by_user_id' => $payload['created_by_user_id'],
                'created_at' => $payload['created_at'],
                'updated_at' => $payload['updated_at'],
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
            ]
        );

        if ($ok === false) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }
}
