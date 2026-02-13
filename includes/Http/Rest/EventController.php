<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use DateTimeImmutable;
use DateTimeZone;
use OpenScene\Engine\Auth\Roles;
use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Repository\EventRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class EventController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly EventRepository $events,
        private readonly CacheManager $cache
    ) {
        parent::__construct($rateLimiter);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $scope = (string) $request->get_param('scope') === 'past' ? 'past' : 'upcoming';
        $limit = min(50, max(1, (int) $request->get_param('limit') ?: 20));
        $cursorToken = sanitize_text_field((string) $request->get_param('cursor'));
        $cursor = $this->decodeCursor($cursorToken, $scope);
        $cacheKey = sprintf('events:%s:%d:%s', $scope, $limit, $cursorToken !== '' ? $cursorToken : 'first');

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $cached['meta']['cached'] = true;
            return new WP_REST_Response($cached, 200);
        }

        $rows = $this->events->listByScope($scope, $limit, $cursor);
        $payload = [
            'data' => $rows,
            'meta' => [
                'scope' => $scope,
                'limit' => $limit,
                'next_cursor' => $this->buildNextCursor($rows, $scope),
                'cached' => false,
            ],
        ];
        $this->cache->set($cacheKey, $payload, 30);

        return new WP_REST_Response($payload, 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $event = $this->events->find((int) $request['id']);
        if (! $event) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Event not found']]], 404);
        }

        return new WP_REST_Response(['data' => $event], 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        if (! $this->can(Roles::CAP_LOCK_THREAD)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $payload = $this->sanitizeEventPayload($request);
        if (is_string($payload)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => $payload]]], 422);
        }

        $eventId = $this->events->create($payload);
        if ($eventId <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to create event']]], 500);
        }

        $this->cache->bumpVersion();
        return new WP_REST_Response(['data' => ['id' => $eventId]], 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        if (! $this->can(Roles::CAP_LOCK_THREAD)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $payload = $this->sanitizeEventPayload($request, false);
        if (is_string($payload)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => $payload]]], 422);
        }

        $ok = $this->events->update((int) $request['id'], $payload);
        if (! $ok) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to update event']]], 500);
        }

        $this->cache->bumpVersion();
        return new WP_REST_Response(['data' => ['updated' => true]], 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        if (! $this->can(Roles::CAP_LOCK_THREAD)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $ok = $this->events->delete((int) $request['id']);
        if (! $ok) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to delete event']]], 500);
        }

        $this->cache->bumpVersion();
        return new WP_REST_Response(['data' => ['deleted' => true]], 200);
    }

    private function sanitizeEventPayload(WP_REST_Request $request, bool $requirePostId = true): array|string
    {
        $eventDate = $this->normalizeToUtcMySql(sanitize_text_field((string) $request->get_param('event_date')));
        $eventEndDateRaw = sanitize_text_field((string) $request->get_param('event_end_date'));
        $eventEndDate = $eventEndDateRaw !== '' ? $this->normalizeToUtcMySql($eventEndDateRaw) : '';

        $payload = [
            'post_id' => (int) $request->get_param('post_id'),
            'event_date' => $eventDate ?? '',
            'event_end_date' => $eventEndDate ?: '',
            'venue_name' => sanitize_text_field((string) $request->get_param('venue_name')),
            'venue_address' => sanitize_textarea_field((string) $request->get_param('venue_address')),
            'ticket_url' => esc_url_raw((string) $request->get_param('ticket_url')),
            'metadata' => $request->get_param('metadata'),
        ];

        if ($requirePostId && $payload['post_id'] <= 0) {
            return 'post_id is required';
        }

        if ($payload['event_date'] === '') {
            return 'event_date is required';
        }

        if ($eventDate === null) {
            return 'event_date must be valid datetime';
        }

        if ($eventEndDateRaw !== '' && $eventEndDate === null) {
            return 'event_end_date must be valid datetime';
        }

        if ($payload['venue_name'] === '') {
            return 'venue_name is required';
        }

        return $payload;
    }

    private function normalizeToUtcMySql(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d\\TH:i:s',
            'Y-m-d\\TH:i',
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

    private function decodeCursor(string $token, string $scope): ?array
    {
        if ($token === '') {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (! is_string($decoded)) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (! is_array($data) || ($data['scope'] ?? '') !== $scope) {
            return null;
        }

        if (! isset($data['event_date'], $data['id'])) {
            return null;
        }

        return ['event_date' => (string) $data['event_date'], 'id' => (int) $data['id']];
    }

    private function buildNextCursor(array $rows, string $scope): ?string
    {
        if ($rows === []) {
            return null;
        }

        $last = $rows[count($rows) - 1];
        $cursor = [
            'scope' => $scope,
            'event_date' => (string) ($last['event_date'] ?? ''),
            'id' => (int) ($last['id'] ?? 0),
        ];

        return rtrim(strtr(base64_encode((string) wp_json_encode($cursor)), '+/', '-_'), '=');
    }
}
