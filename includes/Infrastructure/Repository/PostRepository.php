<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class PostRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function feedByCursor(string $sort, int $limit, ?array $cursor = null, ?int $communityId = null): array
    {
        $table = $this->tables->posts();
        $communities = $this->tables->communities();
        $events = $this->tables->events();
        $sortMode = in_array($sort, ['hot', 'new', 'top'], true)
            ? $sort
            : ($sort === 'score' ? 'top' : ($sort === 'created_at' ? 'new' : 'hot'));
        $whereParts = ["p.status IN ('published','removed')", "c.visibility = 'public'"];
        $params = [];
        $isFirstPage = ! is_array($cursor);
        $pinnedPageOne = $isFirstPage && ($sortMode === 'hot' || $sortMode === 'new');
        $removedExpr = "(p.status = 'removed')";
        $hotExpr = "((p.comment_count * 2) + CASE
            WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR) THEN 3
            WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 6 HOUR) THEN 2
            WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1
            ELSE 0
        END)";
        $lastCommentedExpr = "COALESCE(p.last_commented_at, '1970-01-01 00:00:00')";

        if ($communityId !== null) {
            $whereParts[] = 'p.community_id = %d';
            $params[] = $communityId;
        }

        if (is_array($cursor) && isset($cursor['id'], $cursor['created_at'], $cursor['removed'])) {
            $id = (int) $cursor['id'];
            $createdAt = (string) $cursor['created_at'];
            $removed = (int) $cursor['removed'];
            $cursorPageOnePinned = (int) ($cursor['page1_pinned'] ?? 0) === 1;

            if (($sortMode === 'hot' || $sortMode === 'new') && $cursorPageOnePinned) {
                $whereParts[] = 'p.is_sticky = 0';
            }

            if ($sortMode === 'top') {
                $score = (int) ($cursor['score'] ?? 0);
                $whereParts[] = "({$removedExpr} > %d OR ({$removedExpr} = %d AND (p.score < %d OR (p.score = %d AND (p.created_at < %s OR (p.created_at = %s AND p.id < %d))))) )";
                $params[] = $removed;
                $params[] = $removed;
                $params[] = $score;
                $params[] = $score;
                $params[] = $createdAt;
                $params[] = $createdAt;
                $params[] = $id;
            } elseif ($sortMode === 'new') {
                $whereParts[] = "({$removedExpr} > %d OR ({$removedExpr} = %d AND (p.created_at < %s OR (p.created_at = %s AND p.id < %d))))";
                $params[] = $removed;
                $params[] = $removed;
                $params[] = $createdAt;
                $params[] = $createdAt;
                $params[] = $id;
            } else {
                $hotScore = (int) ($cursor['hot_score'] ?? 0);
                $lastCommentedAt = (string) ($cursor['last_commented_at'] ?? '1970-01-01 00:00:00');
                $whereParts[] = "({$removedExpr} > %d OR ({$removedExpr} = %d AND ({$hotExpr} < %d OR ({$hotExpr} = %d AND ({$lastCommentedExpr} < %s OR ({$lastCommentedExpr} = %s AND (p.created_at < %s OR (p.created_at = %s AND p.id < %d))))))))";
                $params[] = $removed;
                $params[] = $removed;
                $params[] = $hotScore;
                $params[] = $hotScore;
                $params[] = $lastCommentedAt;
                $params[] = $lastCommentedAt;
                $params[] = $createdAt;
                $params[] = $createdAt;
                $params[] = $id;
            }
        }

        $orderParts = [];
        $orderParts[] = "{$removedExpr} ASC";
        if ($pinnedPageOne) {
            $orderParts[] = 'p.is_sticky DESC';
        }
        if ($sortMode === 'top') {
            $orderParts[] = 'p.score DESC';
            $orderParts[] = 'p.created_at DESC';
        } elseif ($sortMode === 'new') {
            $orderParts[] = 'p.created_at DESC';
        } else {
            $orderParts[] = "{$hotExpr} DESC";
            $orderParts[] = "{$lastCommentedExpr} DESC";
            $orderParts[] = 'p.created_at DESC';
        }
        $orderParts[] = 'p.id DESC';
        $order = 'ORDER BY ' . implode(', ', $orderParts);

        $limit = min(50, max(1, $limit));
        $where = implode(' AND ', $whereParts);
        $sql = "SELECT p.*, e.event_date AS openscene_event_date, e.venue_name AS openscene_event_venue_name, {$hotExpr} AS openscene_hot_score
                FROM {$table} p
                INNER JOIN {$communities} c ON c.id = p.community_id
                LEFT JOIN {$events} e ON e.post_id = p.id
                WHERE {$where} {$order} LIMIT %d";
        $params[] = $limit;
        $prepared = $this->wpdb->prepare($sql, ...$params);

        return $this->wpdb->get_results($prepared, ARRAY_A) ?: [];
    }

    /**
     * Global feed query with explicit sort modes for API tabs:
     * - hot: weighted activity score from comment_count + last_commented_at recency boost
     * - new: created_at DESC
     * - top: score DESC, created_at DESC
     *
     * Pinned ordering is applied only for page 1 of hot/new:
     * ORDER BY is_sticky DESC, <sort criteria>.
     */
    public function feedByPage(string $sort, int $page, int $perPage): array
    {
        $table = $this->tables->posts();
        $communities = $this->tables->communities();
        $events = $this->tables->events();
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $sortMode = in_array($sort, ['hot', 'new', 'top'], true) ? $sort : 'hot';

        $orderParts = [];
        $orderParts[] = "(p.status = 'removed') ASC";
        if (($sortMode === 'hot' || $sortMode === 'new') && $page === 1) {
            $orderParts[] = 'p.is_sticky DESC';
        }

        if ($sortMode === 'top') {
            $orderParts[] = 'p.score DESC';
            $orderParts[] = 'p.created_at DESC';
        } elseif ($sortMode === 'new') {
            $orderParts[] = 'p.created_at DESC';
        } else {
            $orderParts[] = "((p.comment_count * 2) + CASE
                WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR) THEN 3
                WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 6 HOUR) THEN 2
                WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1
                ELSE 0
            END) DESC";
            $orderParts[] = 'p.last_commented_at DESC';
            $orderParts[] = 'p.created_at DESC';
        }
        $orderParts[] = 'p.id DESC';
        $order = 'ORDER BY ' . implode(', ', $orderParts);

        $sql = $this->wpdb->prepare(
            "SELECT p.*, e.event_date AS openscene_event_date, e.venue_name AS openscene_event_venue_name
             FROM {$table} p
             INNER JOIN {$communities} c ON c.id = p.community_id
             LEFT JOIN {$events} e ON e.post_id = p.id
             WHERE p.status IN ('published','removed')
               AND c.visibility = 'public'
             {$order}
             LIMIT %d OFFSET %d",
            $perPage,
            $offset
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function searchByPage(string $query, string $sort, int $page, int $perPage): array
    {
        $table = $this->tables->posts();
        $events = $this->tables->events();
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $sortMode = in_array($sort, ['hot', 'new', 'top'], true) ? $sort : 'hot';

        $orderParts = [];
        $orderParts[] = "(p.status = 'removed') ASC";
        if (($sortMode === 'hot' || $sortMode === 'new') && $page === 1) {
            $orderParts[] = 'p.is_sticky DESC';
        }

        if ($sortMode === 'top') {
            $orderParts[] = 'p.score DESC';
            $orderParts[] = 'p.created_at DESC';
        } elseif ($sortMode === 'new') {
            $orderParts[] = 'p.created_at DESC';
        } else {
            $orderParts[] = "((p.comment_count * 2) + CASE
                WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR) THEN 3
                WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 6 HOUR) THEN 2
                WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1
                ELSE 0
            END) DESC";
            $orderParts[] = 'p.last_commented_at DESC';
            $orderParts[] = 'p.created_at DESC';
        }
        $orderParts[] = 'p.id DESC';
        $order = 'ORDER BY ' . implode(', ', $orderParts);

        $like = '%' . $this->wpdb->esc_like($query) . '%';
        $sql = $this->wpdb->prepare(
            "SELECT p.*, e.event_date AS openscene_event_date, e.venue_name AS openscene_event_venue_name
             FROM {$table} p
             LEFT JOIN {$events} e ON e.post_id = p.id
             WHERE p.status IN ('published','removed')
               AND (p.title LIKE %s OR p.body LIKE %s)
             {$order}
             LIMIT %d OFFSET %d",
            $like,
            $like,
            $perPage,
            $offset
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function find(int $id): ?array
    {
        $table = $this->tables->posts();
        $events = $this->tables->events();
        $sql = $this->wpdb->prepare(
            "SELECT p.*, e.event_date AS openscene_event_date, e.venue_name AS openscene_event_venue_name
             FROM {$table} p
             LEFT JOIN {$events} e ON e.post_id = p.id
             WHERE p.id = %d
             LIMIT 1",
            $id
        );
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $table = $this->tables->posts();
        $now = current_time('mysql', true);

        $ok = $this->wpdb->insert($table, [
            'community_id' => (int) $data['community_id'],
            'user_id' => (int) $data['user_id'],
            'title' => (string) $data['title'],
            'body' => (string) ($data['body'] ?? ''),
            'type' => in_array((string) ($data['type'] ?? 'text'), ['text', 'link', 'media', 'event'], true)
                ? (string) $data['type']
                : 'text',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']);

        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function updateStatus(int $postId, string $status): bool
    {
        $table = $this->tables->posts();
        $updated = $this->wpdb->update($table, ['status' => $status, 'updated_at' => current_time('mysql', true)], ['id' => $postId], ['%s', '%s'], ['%d']);

        return $updated !== false;
    }

    public function updateType(int $postId, string $type): bool
    {
        if (! in_array($type, ['text', 'link', 'media', 'event'], true)) {
            return false;
        }

        $table = $this->tables->posts();
        $updated = $this->wpdb->update($table, ['type' => $type, 'updated_at' => current_time('mysql', true)], ['id' => $postId], ['%s', '%s'], ['%d']);
        return $updated !== false;
    }

    public function toggleSticky(int $postId, bool $sticky): bool
    {
        $table = $this->tables->posts();
        $updated = $this->wpdb->update($table, ['is_sticky' => $sticky ? 1 : 0, 'updated_at' => current_time('mysql', true)], ['id' => $postId], ['%d', '%s'], ['%d']);

        return $updated !== false;
    }

    public function adjustScore(int $postId, int $delta): bool
    {
        $table = $this->tables->posts();
        $sql = $this->wpdb->prepare("UPDATE {$table} SET score = score + %d WHERE id = %d", $delta, $postId);
        return $this->wpdb->query($sql) !== false;
    }

    /** @return array{reported:bool,reports_count:int,ok:bool} */
    public function reportPost(int $postId, int $userId): array
    {
        $postsTable = $this->tables->posts();
        $reportsTable = $this->tables->postReports();
        $now = current_time('mysql', true);

        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$reportsTable} WHERE post_id = %d AND user_id = %d LIMIT 1 FOR UPDATE",
                $postId,
                $userId
            ));

            if (! $exists) {
                $inserted = $this->wpdb->insert($reportsTable, [
                    'post_id' => $postId,
                    'user_id' => $userId,
                    'created_at' => $now,
                ], ['%d', '%d', '%s']);
                if (! $inserted) {
                    throw new \RuntimeException('Unable to report post');
                }

                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$postsTable} SET reports_count = reports_count + 1 WHERE id = %d",
                    $postId
                ));
            }

            $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT reports_count FROM {$postsTable} WHERE id = %d",
                $postId
            ));
            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

            return ['reported' => true, 'reports_count' => $count, 'ok' => true];
        } catch (\Throwable) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return ['reported' => false, 'reports_count' => 0, 'ok' => false];
        }
    }

    public function clearReports(int $postId): bool
    {
        $postsTable = $this->tables->posts();
        $reportsTable = $this->tables->postReports();

        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$reportsTable} WHERE post_id = %d", $postId));
            $this->wpdb->query($this->wpdb->prepare("UPDATE {$postsTable} SET reports_count = 0 WHERE id = %d", $postId));
            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return true;
        } catch (\Throwable) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return false;
        }
    }

    public function hasUserReported(int $postId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $reportsTable = $this->tables->postReports();
        $found = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$reportsTable} WHERE post_id = %d AND user_id = %d LIMIT 1",
            $postId,
            $userId
        ));

        return (bool) $found;
    }

    /** @param list<int> $postIds @return array<int,bool> */
    public function userReportedMap(array $postIds, int $userId): array
    {
        if ($userId <= 0 || $postIds === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $postIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $reportsTable = $this->tables->postReports();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge([$userId], $ids);
        $sql = $this->wpdb->prepare(
            "SELECT post_id FROM {$reportsTable} WHERE user_id = %d AND post_id IN ({$placeholders})",
            ...$params
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) ($row['post_id'] ?? 0)] = true;
        }

        return $map;
    }

    public function moderationList(string $view, int $page, int $perPage): array
    {
        $table = $this->tables->posts();
        global $wpdb;
        $usersTable = $wpdb->users;

        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $viewMode = in_array($view, ['reported', 'all', 'locked', 'removed'], true) ? $view : 'all';

        $whereParts = ["p.status IN ('published','locked','removed')"];
        if ($viewMode === 'reported') {
            $whereParts[] = 'p.reports_count > 0';
        } elseif ($viewMode === 'locked') {
            $whereParts[] = "p.status = 'locked'";
        } elseif ($viewMode === 'removed') {
            $whereParts[] = "p.status = 'removed'";
        }

        $order = "ORDER BY
            (p.status = 'removed') ASC,
            " . ($viewMode === 'reported'
                ? "p.reports_count DESC, COALESCE(p.last_commented_at, '1970-01-01 00:00:00') DESC, p.created_at DESC, p.id DESC"
                : "((p.comment_count * 2) + CASE
                    WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR) THEN 3
                    WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 6 HOUR) THEN 2
                    WHEN p.last_commented_at > (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1
                    ELSE 0
                  END) DESC, COALESCE(p.last_commented_at, '1970-01-01 00:00:00') DESC, p.created_at DESC, p.id DESC");

        $where = implode(' AND ', $whereParts);
        $sql = $this->wpdb->prepare(
            "SELECT
                p.id,
                p.title,
                p.body,
                p.reports_count,
                p.comment_count,
                p.created_at,
                p.last_commented_at,
                p.status,
                p.is_sticky,
                CASE WHEN p.status = 'locked' THEN 1 ELSE 0 END AS locked,
                p.is_sticky AS pinned,
                COALESCE(u.user_login, CONCAT('user_', p.user_id)) AS username
             FROM {$table} p
             LEFT JOIN {$usersTable} u ON u.ID = p.user_id
             WHERE {$where}
             {$order}
             LIMIT %d OFFSET %d",
            $perPage,
            $offset
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['excerpt'] = wp_html_excerpt((string) ($row['body'] ?? ''), 220, '...');
            unset($row['body']);
        }

        return $rows;
    }

    /** @return array{state:string,post:?array} */
    public function softDelete(int $postId): array
    {
        $table = $this->tables->posts();
        $reportsTable = $this->tables->postReports();
        $now = current_time('mysql', true);

        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare("SELECT id, status FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE", $postId),
                ARRAY_A
            );

            if (! is_array($row)) {
                $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                return ['state' => 'not_found', 'post' => null];
            }

            $status = (string) ($row['status'] ?? '');
            if ($status === 'removed') {
                $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                return ['state' => 'already_removed', 'post' => $this->find($postId)];
            }

            $updated = $this->wpdb->update(
                $table,
                [
                    'status' => 'removed',
                    'title' => '[removed]',
                    'body' => '',
                    'reports_count' => 0,
                    'updated_at' => $now,
                ],
                ['id' => $postId],
                ['%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );

            if ($updated === false) {
                throw new \RuntimeException('Unable to remove post');
            }

            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$reportsTable} WHERE post_id = %d", $postId));

            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return ['state' => 'removed', 'post' => $this->find($postId)];
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return ['state' => 'error', 'post' => null];
        }
    }
}
