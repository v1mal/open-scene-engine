<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class ModerationRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function banUser(int $userId, ?int $communityId, string $reason, int $actorId): bool
    {
        $table = $this->tables->bans();
        $ok = $this->wpdb->insert($table, [
            'user_id' => $userId,
            'community_id' => $communityId,
            'reason' => $reason,
            'is_active' => 1,
            'created_by' => $actorId,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%d', '%s', '%d', '%d', '%s']);

        if ($ok) {
            $this->log($actorId, 'user', $userId, 'ban', $reason, ['community_id' => $communityId]);
        }

        return (bool) $ok;
    }

    public function unban(int $banId, int $actorId): bool
    {
        $table = $this->tables->bans();
        $updated = $this->wpdb->update($table, ['is_active' => 0], ['id' => $banId], ['%d'], ['%d']);
        if ($updated !== false) {
            $this->log($actorId, 'ban', $banId, 'unban', null, []);
        }

        return $updated !== false;
    }

    public function isBanned(int $userId, ?int $communityId = null): bool
    {
        $table = $this->tables->bans();
        $sql = $communityId
            ? $this->wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d AND is_active = 1 AND (community_id IS NULL OR community_id = %d) LIMIT 1", $userId, $communityId)
            : $this->wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d AND is_active = 1 LIMIT 1", $userId);

        return (bool) $this->wpdb->get_var($sql);
    }

    public function logs(int $limit = 50, int $offset = 0): array
    {
        $table = $this->tables->moderationLogs();
        $sql = $this->wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset);

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function log(int $actorId, string $targetType, int $targetId, string $action, ?string $reason, array $metadata): void
    {
        $this->wpdb->insert($this->tables->moderationLogs(), [
            'actor_user_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'reason' => $reason,
            'metadata' => wp_json_encode($metadata),
            'created_at' => current_time('mysql', true),
        ], ['%d', '%s', '%d', '%s', '%s', '%s', '%s']);
    }
}
