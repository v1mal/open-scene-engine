<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class UserRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function findByUsername(string $username): ?array
    {
        $user = get_user_by('login', $username);
        if (! $user) {
            return null;
        }

        return [
            'id' => (int) $user->ID,
            'username' => $user->user_login,
            'bio' => (string) get_user_meta((int) $user->ID, 'description', true),
            'avatar' => get_avatar_url((int) $user->ID),
            'join_date' => $user->user_registered,
        ];
    }

    public function postsByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $table = $this->tables->posts();
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND status IN ('published','locked') ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function commentsByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $table = $this->tables->comments();
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND status = 'published' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }
}
