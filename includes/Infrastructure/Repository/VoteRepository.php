<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use OpenScene\Engine\Infrastructure\Observability\ObservabilityLogger;
use wpdb;

final class VoteRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables,
        private readonly PostRepository $posts,
        private readonly CommentRepository $comments,
        private readonly ?ObservabilityLogger $observability = null
    ) {
    }

    public function mutate(int $userId, string $targetType, int $targetId, ?int $newValue): array
    {
        $observe = $this->observability?->isBasicEnabled() === true;
        $start = $observe ? microtime(true) : 0.0;
        $votesTable = $this->tables->votes();
        $eventsTable = $this->tables->voteEvents();
        $now = current_time('mysql', true);

        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            $existingSql = $this->wpdb->prepare(
                "SELECT id, value FROM {$votesTable} WHERE user_id = %d AND target_type = %s AND target_id = %d LIMIT 1 FOR UPDATE",
                $userId,
                $targetType,
                $targetId
            );
            $existing = $this->wpdb->get_row($existingSql, ARRAY_A);
            $oldValue = $existing ? (int) $existing['value'] : 0;

            $delta = 0;
            if ($newValue === null) {
                if ($existing) {
                    $deleted = $this->wpdb->delete($votesTable, ['id' => (int) $existing['id']], ['%d']);
                    if ($deleted !== 1) {
                        throw new \RuntimeException('Unable to remove existing vote');
                    }
                    $delta = -$oldValue;
                }
            } elseif (! in_array($newValue, [-1, 1], true)) {
                throw new \RuntimeException('Invalid vote value');
            } elseif ($existing) {
                if ($oldValue !== $newValue) {
                    $updated = $this->wpdb->update($votesTable, ['value' => $newValue, 'updated_at' => $now], ['id' => (int) $existing['id']], ['%d', '%s'], ['%d']);
                    if ($updated !== 1) {
                        throw new \RuntimeException('Unable to update existing vote');
                    }
                    $delta = $newValue - $oldValue;
                }
            } else {
                $inserted = $this->wpdb->insert($votesTable, [
                    'user_id' => $userId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'value' => $newValue,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['%d', '%s', '%d', '%d', '%s', '%s']);
                if ($inserted !== 1) {
                    throw new \RuntimeException('Unable to create vote');
                }
                $delta = (int) $newValue;
            }

            if ($delta !== 0) {
                $scoreUpdated = false;
                if ($targetType === 'post') {
                    $scoreUpdated = $this->posts->adjustScore($targetId, $delta);
                } else {
                    $scoreUpdated = $this->comments->adjustScore($targetId, $delta);
                }

                if (! $scoreUpdated) {
                    throw new \RuntimeException('Unable to update target score');
                }
            }

            $eventInserted = $this->wpdb->insert($eventsTable, [
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'old_value' => $oldValue ?: null,
                'new_value' => $newValue,
                'created_at' => $now,
            ], ['%d', '%s', '%d', '%d', '%d', '%s']);
            if ($eventInserted !== 1) {
                throw new \RuntimeException('Unable to record vote event');
            }

            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            if ($observe) {
                $durationMs = (int) round((microtime(true) - $start) * 1000);
                if ($durationMs > ObservabilityLogger::SLOW_QUERY_THRESHOLD_MS) {
                    $this->observability?->logSlowQuery('vote_mutate', $durationMs);
                }
            }

            return ['ok' => true, 'delta' => $delta];
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            if ($observe) {
                $this->observability?->logMutationFailure('vote');
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function findUserVoteValue(int $userId, string $targetType, int $targetId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $votesTable = $this->tables->votes();
        $sql = $this->wpdb->prepare(
            "SELECT value FROM {$votesTable} WHERE user_id = %d AND target_type = %s AND target_id = %d LIMIT 1",
            $userId,
            $targetType,
            $targetId
        );
        $value = $this->wpdb->get_var($sql);

        return in_array((int) $value, [-1, 1], true) ? (int) $value : 0;
    }

    /** @param list<int> $targetIds */
    public function findUserVoteValuesForTargets(int $userId, string $targetType, array $targetIds): array
    {
        if ($userId <= 0 || $targetIds === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $targetIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $votesTable = $this->tables->votes();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge([$userId, $targetType], $ids);
        $sql = $this->wpdb->prepare(
            "SELECT target_id, value FROM {$votesTable} WHERE user_id = %d AND target_type = %s AND target_id IN ({$placeholders})",
            ...$params
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $targetId = (int) ($row['target_id'] ?? 0);
            $value = (int) ($row['value'] ?? 0);
            if ($targetId > 0 && in_array($value, [-1, 1], true)) {
                $map[$targetId] = $value;
            }
        }

        return $map;
    }
}
