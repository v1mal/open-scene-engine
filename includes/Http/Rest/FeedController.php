<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\VoteRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class FeedController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly PostRepository $posts,
        private readonly VoteRepository $votes,
        private readonly CacheManager $cache
    ) {
        parent::__construct($rateLimiter);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $sortParam = sanitize_key((string) $request->get_param('sort'));
        $sort = in_array($sortParam, ['hot', 'new', 'top'], true) ? $sortParam : 'hot';
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $perPage = min(50, max(1, (int) $request->get_param('per_page') ?: ((int) $request->get_param('limit') ?: 20)));

        $cacheKey = sprintf('feed:global:%s:%d:%d', $sort, $page, $perPage);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $cached['meta']['cached'] = true;
            return new WP_REST_Response($cached, 200);
        }

        $rows = $this->normalizePostList($this->posts->feedByPage($sort, $page, $perPage));
        $payload = [
            'data' => $rows,
            'meta' => [
                'sort' => $sort,
                'page' => $page,
                'per_page' => $perPage,
                'next_cursor' => null,
                'cached' => false,
            ],
        ];
        $this->cache->set($cacheKey, $payload, 30);

        return new WP_REST_Response($payload, 200);
    }

    public function search(WP_REST_Request $request): WP_REST_Response
    {
        $q = trim((string) $request->get_param('q'));
        if (strlen($q) < 2) {
            return new WP_REST_Response([
                'errors' => [[
                    'code' => 'openscene_invalid_search_query',
                    'message' => 'Search query must be at least 2 characters.',
                ]],
            ], 422);
        }

        $sortParam = sanitize_key((string) $request->get_param('sort'));
        $sort = in_array($sortParam, ['hot', 'new', 'top'], true) ? $sortParam : 'hot';
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $perPage = min(50, max(1, (int) $request->get_param('per_page') ?: ((int) $request->get_param('limit') ?: 20)));

        $cacheKey = sprintf('feed:search:%s:%s:%d:%d', md5(strtolower($q)), $sort, $page, $perPage);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $cached['meta']['cached'] = true;
            return new WP_REST_Response($cached, 200);
        }

        $rows = $this->normalizePostList($this->posts->searchByPage($q, $sort, $page, $perPage));
        $payload = [
            'data' => $rows,
            'meta' => [
                'sort' => $sort,
                'page' => $page,
                'per_page' => $perPage,
                'next_cursor' => null,
                'cached' => false,
            ],
        ];
        $this->cache->set($cacheKey, $payload, 30);

        return new WP_REST_Response($payload, 200);
    }

    private function normalizePostList(array $rows): array
    {
        $userId = get_current_user_id();
        $postIds = [];
        foreach ($rows as $row) {
            $postIds[] = (int) ($row['id'] ?? 0);
        }
        $userVotes = $this->votes->findUserVoteValuesForTargets($userId, 'post', $postIds);
        $userReports = $this->posts->userReportedMap($postIds, $userId);

        foreach ($rows as &$row) {
            $eventDate = (string) ($row['openscene_event_date'] ?? '');
            $venueName = (string) ($row['openscene_event_venue_name'] ?? '');
            $row['event_summary'] = $eventDate !== '' ? [
                'event_date' => $eventDate,
                'venue_name' => $venueName,
            ] : null;
            $row['user_vote'] = (int) ($userVotes[(int) ($row['id'] ?? 0)] ?? 0);
            $row['user_reported'] = (bool) ($userReports[(int) ($row['id'] ?? 0)] ?? false);
            unset($row['openscene_event_date'], $row['openscene_event_venue_name']);
        }

        return $rows;
    }
}
