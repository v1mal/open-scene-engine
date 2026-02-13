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
        $sql = $this->wpdb->prepare(
            "SELECT id, name, slug, description, icon, visibility FROM {$table} WHERE visibility = 'public' AND slug <> 'all-scenes' ORDER BY created_at DESC LIMIT %d",
            $limit
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
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
