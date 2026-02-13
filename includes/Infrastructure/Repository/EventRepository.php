<?php

declare(strict_types=1);

namespace OpenScene\Engine\Infrastructure\Repository;

use OpenScene\Engine\Infrastructure\Database\TableNames;
use wpdb;

final class EventRepository
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly TableNames $tables
    ) {
    }

    public function create(array $data): int
    {
        $table = $this->tables->events();
        $now = current_time('mysql', true);

        $ok = $this->wpdb->insert($table, [
            'post_id' => (int) $data['post_id'],
            'event_date' => (string) $data['event_date'],
            'event_end_date' => $data['event_end_date'] ?: null,
            'venue_name' => (string) $data['venue_name'],
            'venue_address' => (string) ($data['venue_address'] ?? ''),
            'ticket_url' => (string) ($data['ticket_url'] ?? ''),
            'metadata' => isset($data['metadata']) ? wp_json_encode($data['metadata']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function update(int $id, array $data): bool
    {
        $table = $this->tables->events();
        $payload = [];
        $format = [];

        if (array_key_exists('event_date', $data)) {
            $payload['event_date'] = (string) $data['event_date'];
            $format[] = '%s';
        }
        if (array_key_exists('event_end_date', $data)) {
            $payload['event_end_date'] = $data['event_end_date'] ?: null;
            $format[] = '%s';
        }
        if (array_key_exists('venue_name', $data)) {
            $payload['venue_name'] = (string) $data['venue_name'];
            $format[] = '%s';
        }
        if (array_key_exists('venue_address', $data)) {
            $payload['venue_address'] = (string) $data['venue_address'];
            $format[] = '%s';
        }
        if (array_key_exists('ticket_url', $data)) {
            $payload['ticket_url'] = (string) $data['ticket_url'];
            $format[] = '%s';
        }
        if (array_key_exists('metadata', $data)) {
            $payload['metadata'] = isset($data['metadata']) ? wp_json_encode($data['metadata']) : null;
            $format[] = '%s';
        }

        if ($payload === []) {
            return true;
        }

        $payload['updated_at'] = current_time('mysql', true);
        $format[] = '%s';

        $updated = $this->wpdb->update($table, $payload, ['id' => $id], $format, ['%d']);
        return $updated !== false;
    }

    public function delete(int $id): bool
    {
        $deleted = $this->wpdb->delete($this->tables->events(), ['id' => $id], ['%d']);
        return $deleted !== false;
    }

    public function deleteByPostId(int $postId): bool
    {
        $deleted = $this->wpdb->delete($this->tables->events(), ['post_id' => $postId], ['%d']);
        return $deleted !== false;
    }

    public function find(int $id): ?array
    {
        $events = $this->tables->events();
        $posts = $this->tables->posts();

        $sql = $this->wpdb->prepare(
            "SELECT e.*, p.title, p.body, p.type, p.status, p.community_id, p.user_id, p.score, p.comment_count
             FROM {$events} e
             INNER JOIN {$posts} p ON p.id = e.post_id
             WHERE e.id = %d
             LIMIT 1",
            $id
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);
        if (! is_array($row)) {
            return null;
        }

        $row['metadata'] = $this->decodeMetadata($row['metadata'] ?? null);
        return $row;
    }

    public function findByPostId(int $postId): ?array
    {
        $sql = $this->wpdb->prepare("SELECT * FROM {$this->tables->events()} WHERE post_id = %d LIMIT 1", $postId);
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        if (! is_array($row)) {
            return null;
        }

        $row['metadata'] = $this->decodeMetadata($row['metadata'] ?? null);
        return $row;
    }

    public function listByScope(string $scope, int $limit, ?array $cursor = null): array
    {
        $events = $this->tables->events();
        $posts = $this->tables->posts();

        $scope = $scope === 'past' ? 'past' : 'upcoming';
        $limit = min(50, max(1, $limit));
        $now = current_time('mysql', true);

        $whereParts = ["p.status IN ('published','locked')"];
        $params = [];

        if ($scope === 'upcoming') {
            $whereParts[] = 'e.event_date >= %s';
            $params[] = $now;
        } else {
            $whereParts[] = 'e.event_date < %s';
            $params[] = $now;
        }

        if (is_array($cursor) && isset($cursor['event_date'], $cursor['id'])) {
            if ($scope === 'upcoming') {
                $whereParts[] = '(e.event_date > %s OR (e.event_date = %s AND e.id > %d))';
            } else {
                $whereParts[] = '(e.event_date < %s OR (e.event_date = %s AND e.id < %d))';
            }

            $params[] = (string) $cursor['event_date'];
            $params[] = (string) $cursor['event_date'];
            $params[] = (int) $cursor['id'];
        }

        $order = $scope === 'upcoming'
            ? 'ORDER BY e.event_date ASC, e.id ASC'
            : 'ORDER BY e.event_date DESC, e.id DESC';

        $where = implode(' AND ', $whereParts);
        $sql = "SELECT e.*, p.title, p.community_id, p.user_id, p.status
                FROM {$events} e
                INNER JOIN {$posts} p ON p.id = e.post_id
                WHERE {$where}
                {$order}
                LIMIT %d";

        $params[] = $limit;
        $prepared = $this->wpdb->prepare($sql, ...$params);

        $rows = $this->wpdb->get_results($prepared, ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['metadata'] = $this->decodeMetadata($row['metadata'] ?? null);
        }

        return $rows;
    }

    public function findByPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
        $sql = $this->wpdb->prepare(
            "SELECT post_id, event_date, venue_name FROM {$this->tables->events()} WHERE post_id IN ({$placeholders})",
            ...$postIds
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['post_id']] = [
                'event_date' => (string) $row['event_date'],
                'venue_name' => (string) $row['venue_name'],
            ];
        }

        return $map;
    }

    private function decodeMetadata(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
