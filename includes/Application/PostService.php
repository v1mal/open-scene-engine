<?php

declare(strict_types=1);

namespace OpenScene\Engine\Application;

use DateTimeImmutable;
use DateTimeZone;
use OpenScene\Engine\Infrastructure\Repository\EventRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use RuntimeException;
use wpdb;

final class PostService
{
    public function __construct(
        private readonly wpdb $wpdb,
        private readonly PostRepository $posts,
        private readonly EventRepository $events
    ) {
    }

    public function createPostWithOptionalEvent(array $payload): int
    {
        $type = $this->normalizeType((string) ($payload['type'] ?? 'text'));
        $eventPayload = null;

        if ($type === 'event') {
            $eventPayload = $this->validatedAndNormalizedEventPayload($payload);
        }

        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            $postId = $this->posts->create([
                'community_id' => (int) $payload['community_id'],
                'user_id' => (int) $payload['user_id'],
                'title' => (string) $payload['title'],
                'body' => (string) ($payload['body'] ?? ''),
                'type' => $type,
            ]);

            if ($postId <= 0) {
                throw new RuntimeException('Unable to create post');
            }

            if ($type === 'event' && is_array($eventPayload)) {
                $eventId = $this->events->create(array_merge($eventPayload, ['post_id' => $postId]));
                if ($eventId <= 0) {
                    throw new RuntimeException('Unable to create event');
                }
            }

            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return $postId;
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            throw new RuntimeException($e->getMessage());
        }
    }

    public function updatePostTypeAndEvent(int $postId, string $newType, array $eventPayload = []): bool
    {
        $type = $this->normalizeType($newType);

        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        try {
            $updated = $this->posts->updateType($postId, $type);
            if (! $updated) {
                throw new RuntimeException('Unable to update post type');
            }

            if ($type === 'event') {
                $normalized = $this->validatedAndNormalizedEventPayload($eventPayload);
                $existing = $this->events->findByPostId($postId);
                if ($existing) {
                    $ok = $this->events->update((int) $existing['id'], $normalized);
                    if (! $ok) {
                        throw new RuntimeException('Unable to update linked event');
                    }
                } else {
                    $created = $this->events->create(array_merge($normalized, ['post_id' => $postId]));
                    if ($created <= 0) {
                        throw new RuntimeException('Unable to create linked event');
                    }
                }
            } else {
                $this->events->deleteByPostId($postId);
            }

            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            throw new RuntimeException($e->getMessage());
        }
    }

    public function deletePostAndLinkedEvent(int $postId): bool
    {
        $result = $this->posts->softDelete($postId);
        $state = (string) ($result['state'] ?? 'error');
        if ($state === 'removed' || $state === 'already_removed') {
            $this->events->deleteByPostId($postId);
            return true;
        }

        throw new RuntimeException('Unable to delete post');
    }

    private function normalizeType(string $type): string
    {
        if (! in_array($type, ['text', 'link', 'media', 'event'], true)) {
            throw new RuntimeException('Invalid post type');
        }

        return $type;
    }

    private function validatedAndNormalizedEventPayload(array $payload): array
    {
        $eventDateInput = (string) ($payload['event_date'] ?? '');
        $venueName = trim((string) ($payload['venue_name'] ?? ''));

        if ($eventDateInput === '') {
            throw new RuntimeException('event_date is required for event posts');
        }

        if ($venueName === '') {
            throw new RuntimeException('venue_name is required for event posts');
        }

        $eventDate = $this->normalizeToUtcMySql($eventDateInput);
        if ($eventDate === null) {
            throw new RuntimeException('event_date must be valid datetime');
        }

        $endDateInput = (string) ($payload['event_end_date'] ?? '');
        $eventEndDate = null;
        if ($endDateInput !== '') {
            $eventEndDate = $this->normalizeToUtcMySql($endDateInput);
            if ($eventEndDate === null) {
                throw new RuntimeException('event_end_date must be valid datetime');
            }
        }

        return [
            'event_date' => $eventDate,
            'event_end_date' => $eventEndDate,
            'venue_name' => $venueName,
            'venue_address' => (string) ($payload['venue_address'] ?? ''),
            'ticket_url' => (string) ($payload['ticket_url'] ?? ''),
            'metadata' => $payload['metadata'] ?? null,
        ];
    }

    private function normalizeToUtcMySql(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
        ];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($dt instanceof DateTimeImmutable) {
                return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        try {
            $dt = new DateTimeImmutable($value);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
