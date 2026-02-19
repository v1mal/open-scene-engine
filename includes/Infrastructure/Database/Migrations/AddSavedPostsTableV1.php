<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Database\Migrations;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class AddSavedPostsTableV1
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function migrate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->tables->savedPosts();
        $charset = $this->wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_post (user_id, post_id),
            KEY idx_user_created (user_id, created_at),
            KEY idx_post (post_id)
        ) {$charset};");
    }
}
