<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class SavedPostRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    /** @return array{ok:bool,already_saved:bool} */
    public function save(int $userId, int $postId): array
    {
        $table = $this->tables->savedPosts();
        $now = current_time('mysql', true);
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$table} (user_id, post_id, created_at)
             VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE created_at = created_at",
            $userId,
            $postId,
            $now
        );
        $result = $this->wpdb->query($sql);
        if ($result === false) {
            return ['ok' => false, 'already_saved' => false];
        }

        return ['ok' => true, 'already_saved' => ((int) $result === 0)];
    }

    /** @return array{ok:bool,already_unsaved:bool} */
    public function unsave(int $userId, int $postId): array
    {
        $table = $this->tables->savedPosts();
        $deleted = $this->wpdb->delete(
            $table,
            ['user_id' => $userId, 'post_id' => $postId],
            ['%d', '%d']
        );

        if ($deleted === false) {
            return ['ok' => false, 'already_unsaved' => false];
        }

        return ['ok' => true, 'already_unsaved' => ((int) $deleted === 0)];
    }

    public function hasSaved(int $userId, int $postId): bool
    {
        if ($userId <= 0 || $postId <= 0) {
            return false;
        }

        $table = $this->tables->savedPosts();
        $sql = $this->wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND post_id = %d LIMIT 1",
            $userId,
            $postId
        );

        return (int) $this->wpdb->get_var($sql) === 1;
    }

    /** @param list<int> $postIds */
    public function savedMapForPosts(int $userId, array $postIds): array
    {
        if ($userId <= 0 || $postIds === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $postIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $table = $this->tables->savedPosts();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge([$userId], $ids);
        $sql = $this->wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE user_id = %d AND post_id IN ({$placeholders})",
            ...$params
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            if ($postId > 0) {
                $map[$postId] = true;
            }
        }

        return $map;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listVisibleByCursor(int $userId, int $limit, ?array $cursor = null): array
    {
        $saved = $this->tables->savedPosts();
        $posts = $this->tables->posts();
        $communities = $this->tables->communities();
        $events = $this->tables->events();

        $whereParts = [
            'sp.user_id = %d',
            "p.status IN ('published','removed')",
            "c.visibility = 'public'",
        ];
        $params = [$userId];

        if (is_array($cursor) && isset($cursor['saved_at'], $cursor['saved_id'])) {
            $whereParts[] = '(sp.created_at < %s OR (sp.created_at = %s AND sp.id < %d))';
            $params[] = (string) $cursor['saved_at'];
            $params[] = (string) $cursor['saved_at'];
            $params[] = (int) $cursor['saved_id'];
        }

        $limit = min(50, max(1, $limit));
        $params[] = $limit;

        $where = implode(' AND ', $whereParts);
        $sql = "SELECT p.*, e.event_date AS openscene_event_date, e.venue_name AS openscene_event_venue_name,
                       sp.created_at AS saved_at, sp.id AS saved_id
                FROM {$saved} sp
                INNER JOIN {$posts} p ON p.id = sp.post_id
                INNER JOIN {$communities} c ON c.id = p.community_id
                LEFT JOIN {$events} e ON e.post_id = p.id
                WHERE {$where}
                ORDER BY sp.created_at DESC, sp.id DESC
                LIMIT %d";

        $prepared = $this->wpdb->prepare($sql, ...$params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }
}
