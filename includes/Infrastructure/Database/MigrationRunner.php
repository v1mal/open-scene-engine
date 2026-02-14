<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Database;

use OpenScene\Engine\Infrastructure\Database\Migrations\AddPerformanceIndexesV4;

final class MigrationRunner
{
    public const DB_VERSION = '1.4.0';

    public function migrate(): void
    {
        $current = (string) get_option('openscene_db_version', '0');
        $tables = new TableNames();
        if (version_compare($current, self::DB_VERSION, '>=')) {
            $this->ensurePostTypeEnumHasEvent($tables->posts());
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$tables->communities()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            slug VARCHAR(140) NOT NULL,
            description TEXT NULL,
            icon VARCHAR(255) NULL,
            rules LONGTEXT NULL,
            visibility ENUM('public','restricted','private') NOT NULL DEFAULT 'public',
            created_by_user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY visibility_created (visibility, created_at),
            KEY creator_created (created_by_user_id, created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->posts()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            community_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(300) NOT NULL,
            body LONGTEXT NULL,
            type ENUM('text','link','media','event') NOT NULL DEFAULT 'text',
            status ENUM('published','locked','removed','deleted') NOT NULL DEFAULT 'published',
            is_sticky TINYINT(1) NOT NULL DEFAULT 0,
            score INT NOT NULL DEFAULT 0,
            comment_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_commented_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY community_feed (community_id, status, is_sticky, created_at, id),
            KEY global_feed (status, is_sticky, created_at, id),
            KEY user_posts (user_id, status, created_at, id),
            KEY top_feed (status, score, created_at, id),
            KEY community_top (community_id, status, score, created_at, id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->comments()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            body LONGTEXT NOT NULL,
            status ENUM('published','removed','deleted') NOT NULL DEFAULT 'published',
            score INT NOT NULL DEFAULT 0,
            depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            path VARCHAR(1024) NULL,
            child_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY top_level (post_id, parent_id, status, created_at, id),
            KEY children_sort (post_id, parent_id, status, created_at, id),
            KEY user_comments (user_id, status, created_at, id),
            KEY top_comments (post_id, status, score, created_at, id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->votes()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            target_type ENUM('post','comment') NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            value TINYINT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_target (user_id, target_type, target_id),
            KEY target_lookup (target_type, target_id),
            KEY user_recent (user_id, created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->events()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            event_date DATETIME NOT NULL,
            event_end_date DATETIME NULL,
            venue_name VARCHAR(255) NOT NULL,
            venue_address TEXT NULL,
            ticket_url VARCHAR(255) NULL,
            metadata JSON NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY post_unique (post_id),
            KEY event_date_idx (event_date),
            KEY event_date_post_idx (event_date, post_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->bans()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            community_id BIGINT UNSIGNED NULL,
            reason TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_active (user_id, is_active),
            KEY community_active (community_id, is_active)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->moderationLogs()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            target_type VARCHAR(30) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            reason TEXT NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY target_action_time (target_type, target_id, action, created_at),
            KEY actor_time (actor_user_id, created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->voteEvents()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            target_type ENUM('post','comment') NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            old_value TINYINT NULL,
            new_value TINYINT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY target_time (target_type, target_id, created_at),
            KEY user_time (user_id, created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$tables->postReports()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_user (post_id, user_id),
            KEY post_created (post_id, created_at),
            KEY user_created (user_id, created_at)
        ) {$charset};");

        $this->ensureReportsCountColumn($tables->posts());

        $this->ensurePostTypeEnumHasEvent($tables->posts());

        (new AddPerformanceIndexesV4($wpdb, $tables))->migrate();

        update_option('openscene_db_version', self::DB_VERSION);
        add_option('openscene_cache_version', '1', '', false);
    }

    private function ensurePostTypeEnumHasEvent(string $postsTable): void
    {
        global $wpdb;

        $column = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'type' LIMIT 1",
                $postsTable
            ),
            ARRAY_A
        );

        $columnType = is_array($column) ? (string) ($column['COLUMN_TYPE'] ?? '') : '';
        if ($columnType === '') {
            return;
        }

        if (str_contains($columnType, "'event'")) {
            return;
        }

        $wpdb->query("ALTER TABLE {$postsTable} MODIFY COLUMN type ENUM('text','link','media','event') NOT NULL DEFAULT 'text'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
    }

    private function ensureReportsCountColumn(string $postsTable): void
    {
        global $wpdb;
        $column = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'reports_count' LIMIT 1",
                $postsTable
            ),
            ARRAY_A
        );

        if (! is_array($column)) {
            $wpdb->query("ALTER TABLE {$postsTable} ADD COLUMN reports_count INT UNSIGNED NOT NULL DEFAULT 0"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        }
    }
}
