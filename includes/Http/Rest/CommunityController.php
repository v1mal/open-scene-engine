<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Repository\CommunityRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class CommunityController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly CommunityRepository $communities,
        private readonly PostRepository $posts,
        private readonly CacheManager $cache
    ) {
        parent::__construct($rateLimiter);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $limit = min(100, max(1, (int) $request->get_param('limit') ?: 50));
        $cacheKey = sprintf('community:list:%d', $limit);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return new WP_REST_Response(['data' => $cached, 'meta' => ['cached' => true]], 200);
        }

        $rows = $this->communities->listVisible($limit);
        $this->cache->set($cacheKey, $rows, 60);

        return new WP_REST_Response(['data' => $rows, 'meta' => ['cached' => false]], 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $slug = sanitize_title((string) $request['slug']);
        $community = $this->communities->findBySlug($slug);

        if (! $community) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Community not found']]], 404);
        }
        if ((string) ($community['visibility'] ?? '') !== 'public') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Community not found']]], 404);
        }

        return new WP_REST_Response(['data' => $community], 200);
    }

    public function posts(WP_REST_Request $request): WP_REST_Response
    {
        $communityId = (int) $request['id'];
        $community = $this->communities->findById($communityId);
        if (! is_array($community) || (string) ($community['visibility'] ?? '') !== 'public') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Community not found']]], 404);
        }
        $sortParam = sanitize_key((string) $request->get_param('sort'));
        $sort = in_array($sortParam, ['hot', 'new', 'top'], true)
            ? $sortParam
            : ($sortParam === 'created_at' ? 'new' : ($sortParam === 'score' ? 'top' : 'hot'));
        $limit = min(50, max(1, (int) $request->get_param('limit') ?: 20));
        $cursorToken = sanitize_text_field((string) $request->get_param('cursor'));
        $cursor = $this->decodeCursor($cursorToken, $sort);

        $cacheKey = sprintf('feed:community:%d:%s:%d:%s', $communityId, $sort, $limit, $cursorToken !== '' ? $cursorToken : 'first');
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $cached['meta']['cached'] = true;
            return new WP_REST_Response($cached, 200);
        }

        $rows = $this->normalizeEventSummary($this->posts->feedByCursor($sort, $limit, $cursor, $communityId));
        $userId = get_current_user_id();
        $postIds = [];
        foreach ($rows as $row) {
            $postIds[] = (int) ($row['id'] ?? 0);
        }
        $reportedMap = $this->posts->userReportedMap($postIds, $userId);
        foreach ($rows as &$row) {
            $row['user_reported'] = (bool) ($reportedMap[(int) ($row['id'] ?? 0)] ?? false);
        }
        $payload = [
            'data' => $rows,
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $this->buildNextCursor($rows, $sort, $cursorToken === ''),
                'cached' => false,
            ],
        ];
        $this->cache->set($cacheKey, $payload, 30);

        return new WP_REST_Response($payload, 200);
    }

    private function decodeCursor(string $token, string $sort): ?array
    {
        if ($token === '') {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (! is_string($decoded)) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            return null;
        }

        if (($data['sort'] ?? '') !== $sort) {
            return null;
        }

        if (! isset($data['id'], $data['created_at'], $data['removed'])) {
            return null;
        }

        $cursor = [
            'id' => (int) $data['id'],
            'created_at' => (string) $data['created_at'],
            'removed' => (int) $data['removed'],
            'page1_pinned' => (int) ($data['page1_pinned'] ?? 0),
        ];

        if (isset($data['score'])) {
            $cursor['score'] = (int) $data['score'];
        }
        if (isset($data['last_commented_at'])) {
            $cursor['last_commented_at'] = (string) $data['last_commented_at'];
        }
        if (isset($data['hot_score'])) {
            $cursor['hot_score'] = (int) $data['hot_score'];
        }

        return $cursor;
    }

    private function buildNextCursor(array $rows, string $sort, bool $firstPage): ?string
    {
        if ($rows === []) {
            return null;
        }

        $last = $rows[count($rows) - 1];
        $cursor = [
            'sort' => $sort,
            'id' => (int) ($last['id'] ?? 0),
            'created_at' => (string) ($last['created_at'] ?? ''),
            'removed' => (int) ((string) ($last['status'] ?? '') === 'removed' ? 1 : 0),
            'page1_pinned' => ($firstPage && in_array($sort, ['hot', 'new'], true)) ? 1 : 0,
        ];

        if ($sort === 'top') {
            $cursor['score'] = (int) ($last['score'] ?? 0);
        } elseif ($sort === 'hot') {
            $cursor['last_commented_at'] = (string) ($last['last_commented_at'] ?? '1970-01-01 00:00:00');
            $cursor['hot_score'] = (int) ($last['openscene_hot_score'] ?? 0);
        }

        return rtrim(strtr(base64_encode((string) wp_json_encode($cursor)), '+/', '-_'), '=');
    }

    private function normalizeEventSummary(array $rows): array
    {
        foreach ($rows as &$row) {
            $eventDate = (string) ($row['openscene_event_date'] ?? '');
            $venueName = (string) ($row['openscene_event_venue_name'] ?? '');
            $row['event_summary'] = $eventDate !== '' ? [
                'event_date' => $eventDate,
                'venue_name' => $venueName,
            ] : null;
            unset($row['openscene_event_date'], $row['openscene_event_venue_name'], $row['openscene_hot_score']);
        }

        return $rows;
    }
}
