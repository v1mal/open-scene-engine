<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class CommentRepository
{
    public const MAX_DEPTH = 6;

    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function topLevelForPost(int $postId, int $limit = 20, int $offset = 0, string $sort = 'created_at'): array
    {
        $table = $this->tables->comments();
        $order = $sort === 'score'
            ? 'ORDER BY score DESC, created_at ASC, id ASC'
            : 'ORDER BY created_at ASC, id ASC';

        $sql = $this->wpdb->prepare(
            "SELECT id, post_id, user_id, parent_id, body, score, depth, child_count, created_at
             FROM {$table}
             WHERE post_id = %d AND parent_id IS NULL AND status IN ('published','removed')
             {$order}
             LIMIT %d OFFSET %d",
            $postId,
            min($limit, 50),
            $offset
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function childrenForParent(int $postId, int $parentId, int $limit = 20, int $offset = 0, string $sort = 'created_at'): array
    {
        $table = $this->tables->comments();
        $order = $sort === 'score'
            ? 'ORDER BY score DESC, created_at ASC, id ASC'
            : 'ORDER BY created_at ASC, id ASC';

        $sql = $this->wpdb->prepare(
            "SELECT id, post_id, user_id, parent_id, body, score, depth, child_count, created_at
             FROM {$table}
             WHERE post_id = %d AND parent_id = %d AND status = 'published'
             {$order}
             LIMIT %d OFFSET %d",
            $postId,
            $parentId,
            min($limit, 50),
            $offset
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function find(int $commentId): ?array
    {
        $table = $this->tables->comments();
        $sql = $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $commentId);
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function create(int $postId, int $userId, string $body, ?int $parentId): int
    {
        $table = $this->tables->comments();
        $posts = $this->tables->posts();
        $now = current_time('mysql', true);

        $depth = 0;
        $path = null;
        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            if ($parentId !== null) {
                $parentSql = $this->wpdb->prepare(
                    "SELECT id, post_id, depth, path FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
                    $parentId
                );
                $parent = $this->wpdb->get_row($parentSql, ARRAY_A);
                if (! is_array($parent) || (int) ($parent['post_id'] ?? 0) !== $postId) {
                    $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    return 0;
                }

                $parentDepth = (int) ($parent['depth'] ?? 0);
                if ($parentDepth >= self::MAX_DEPTH - 1) {
                    $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    return -1;
                }

                $depth = $parentDepth + 1;
                $path = trim((string) ($parent['path'] ?? ''), '.') . '.' . $parentId;

                $parentUpdated = $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$table} SET child_count = child_count + 1 WHERE id = %d",
                    $parentId
                ));
                if ((int) $parentUpdated !== 1) {
                    throw new \RuntimeException('Unable to update parent child count');
                }
            }

            $inserted = $this->wpdb->insert($table, [
                'post_id' => $postId,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'body' => $body,
                'depth' => $depth,
                'path' => $path,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s']);
            if ($inserted !== 1) {
                throw new \RuntimeException('Unable to create comment');
            }

            $postUpdated = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$posts} SET comment_count = comment_count + 1, last_commented_at = %s WHERE id = %d",
                $now,
                $postId
            ));
            if ((int) $postUpdated !== 1) {
                throw new \RuntimeException('Unable to update post comment count');
            }

            $commentId = (int) $this->wpdb->insert_id;
            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return $commentId;
        } catch (\Throwable) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return 0;
        }
    }

    public function adjustScore(int $commentId, int $delta): bool
    {
        $table = $this->tables->comments();
        $sql = $this->wpdb->prepare("UPDATE {$table} SET score = score + %d WHERE id = %d", $delta, $commentId);

        return (int) $this->wpdb->query($sql) > 0;
    }

    /** @return array{ok:bool,changed:bool} */
    public function moderateDelete(int $commentId): array
    {
        $table = $this->tables->comments();
        $posts = $this->tables->posts();
        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            $comment = $this->wpdb->get_row(
                $this->wpdb->prepare("SELECT id, post_id, status FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE", $commentId),
                ARRAY_A
            );
            if (! is_array($comment)) {
                $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                return ['ok' => false, 'changed' => false];
            }

            if ((string) ($comment['status'] ?? '') !== 'published') {
                $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                return ['ok' => true, 'changed' => false];
            }

            $updated = $this->wpdb->update(
                $table,
                [
                    'status' => 'removed',
                    'body' => '[removed]',
                    'updated_at' => current_time('mysql', true),
                ],
                ['id' => $commentId, 'status' => 'published'],
                ['%s', '%s', '%s'],
                ['%d', '%s']
            );

            if ((int) $updated !== 1) {
                throw new \RuntimeException('Unable to update comment state');
            }

            $postUpdated = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$posts} SET comment_count = GREATEST(comment_count - 1, 0) WHERE id = %d",
                (int) ($comment['post_id'] ?? 0)
            ));
            if ((int) $postUpdated !== 1) {
                throw new \RuntimeException('Unable to update post comment count');
            }

            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return ['ok' => true, 'changed' => true];
        } catch (\Throwable) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return ['ok' => false, 'changed' => false];
        }
    }
}
