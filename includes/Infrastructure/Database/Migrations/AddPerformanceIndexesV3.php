<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Database\Migrations;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class AddPerformanceIndexesV3
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function migrate(): void
    {
        // posts
        $this->ensureIndex($this->tables->posts(), 'idx_status_created', ['status', 'created_at']);
        $this->ensureIndex($this->tables->posts(), 'idx_status_score', ['status', 'score']);
        $this->ensureIndex($this->tables->posts(), 'idx_status_last_commented', ['status', 'last_commented_at']);
        $this->ensureIndex($this->tables->posts(), 'idx_community_status', ['community_id', 'status']);
        $this->ensureIndex($this->tables->posts(), 'idx_created_at', ['created_at']);
        $this->ensureIndex($this->tables->posts(), 'idx_community_visibility', ['community_id']);

        // comments
        $this->ensureIndex($this->tables->comments(), 'idx_post_status', ['post_id', 'status']);
        $this->ensureIndex($this->tables->comments(), 'idx_parent_id', ['parent_id']);
        $this->ensureIndex($this->tables->comments(), 'idx_post_depth', ['post_id', 'depth']);
        $this->ensureIndex($this->tables->comments(), 'idx_created_at', ['created_at']);

        // votes
        $this->ensureIndex($this->tables->votes(), 'uniq_user_target', ['user_id', 'target_type', 'target_id'], true);
        $this->ensureIndex($this->tables->votes(), 'idx_target', ['target_type', 'target_id']);

        // vote events
        $this->ensureIndex($this->tables->voteEvents(), 'idx_target', ['target_type', 'target_id']);
        $this->ensureIndex($this->tables->voteEvents(), 'idx_user', ['user_id']);

        // post reports
        $this->ensureIndex($this->tables->postReports(), 'idx_post', ['post_id']);
        $this->ensureIndex($this->tables->postReports(), 'idx_user_post', ['user_id', 'post_id']);

        // moderation logs
        $this->ensureIndex($this->tables->moderationLogs(), 'idx_target', ['target_type', 'target_id']);
        $this->ensureIndex($this->tables->moderationLogs(), 'idx_created_at', ['created_at']);

        // bans
        $this->ensureIndex($this->tables->bans(), 'uniq_user', ['user_id'], true);
        $this->ensureIndex($this->tables->bans(), 'idx_expires_at', ['expires_at']);

        // communities
        $this->ensureIndex($this->tables->communities(), 'uniq_slug', ['slug'], true);
        $this->ensureIndex($this->tables->communities(), 'idx_visibility', ['visibility']);
    }

    /**
     * Add index when not already satisfied by existing index name or left-prefix column coverage.
     *
     * @param list<string> $columns
     */
    private function ensureIndex(string $table, string $indexName, array $columns, bool $unique = false): void
    {
        if ($this->indexCovered($table, $indexName, $columns, $unique)) {
            return;
        }

        $tableSql = $this->escapeIdentifier($table);
        $indexSql = $this->escapeIdentifier($indexName);
        $columnsSql = implode(', ', array_map([$this, 'escapeIdentifier'], $columns));
        $uniqueSql = $unique ? 'UNIQUE ' : '';

        $this->wpdb->query("ALTER TABLE {$tableSql} ADD {$uniqueSql}INDEX {$indexSql} ({$columnsSql})"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Match by exact index name OR existing index with same left-prefix columns and uniqueness.
     *
     * @param list<string> $columns
     */
    private function indexCovered(string $table, string $indexName, array $columns, bool $unique): bool
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                 ORDER BY INDEX_NAME ASC, SEQ_IN_INDEX ASC",
                $table
            ),
            ARRAY_A
        );

        if (! is_array($rows) || $rows === []) {
            return false;
        }

        $byName = [];
        foreach ($rows as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            if ($name === '') {
                continue;
            }

            if (! isset($byName[$name])) {
                $byName[$name] = [
                    'non_unique' => (int) ($row['NON_UNIQUE'] ?? 1),
                    'columns' => [],
                ];
            }
            $byName[$name]['columns'][] = (string) ($row['COLUMN_NAME'] ?? '');
        }

        foreach ($byName as $existingName => $definition) {
            $existingUnique = ((int) ($definition['non_unique'] ?? 1)) === 0;
            $existingColumns = is_array($definition['columns'] ?? null) ? $definition['columns'] : [];
            $prefix = array_slice($existingColumns, 0, count($columns));
            $coversColumns = $prefix === $columns;
            $coversUnique = $unique ? $existingUnique : true;

            if ($existingName === $indexName && $coversColumns && $coversUnique) {
                return true;
            }

            if ($coversColumns && $coversUnique) {
                return true;
            }
        }

        return false;
    }

    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

