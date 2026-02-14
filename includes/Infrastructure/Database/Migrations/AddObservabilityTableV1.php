<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Database\Migrations;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class AddObservabilityTableV1
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function migrate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->tables->observabilityLogs();
        $charset = $this->wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(50) NOT NULL,
            context VARCHAR(100) NOT NULL,
            duration_ms INT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_type_created (type, created_at),
            KEY idx_created_at (created_at)
        ) {$charset};");
    }
}

