<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Application\SavedPostService;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\SavedPostRepository;
use OpenScene\Engine\Infrastructure\Repository\UserRepository;
use OpenScene\Engine\Infrastructure\Repository\VoteRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class SavedPostController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly SavedPostService $service,
        private readonly SavedPostRepository $savedPosts,
        private readonly UserRepository $users,
        private readonly VoteRepository $votes,
        private readonly PostRepository $posts
    ) {
        parent::__construct($rateLimiter);
    }

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }
        if (! is_user_logged_in() || get_current_user_id() <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Authentication required']]], 403);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        $feature = $this->requireFeatureEnabled('saved_posts', 'Saved posts are disabled');
        if ($feature !== true) {
            return $this->errorResponse($feature);
        }

        $limit = $this->requireRateLimit('saved_posts_mutation', 120, MINUTE_IN_SECONDS);
        if ($limit !== true) {
            return new WP_REST_Response(['errors' => [['code' => $limit->get_error_code(), 'message' => $limit->get_error_message()]]], 429);
        }

        $result = $this->service->save(get_current_user_id(), (int) $request['id']);
        if (! ($result['ok'] ?? false)) {
            if (($result['error'] ?? '') === 'not_found') {
                return new WP_REST_Response(['errors' => [['code' => 'rest_not_found', 'message' => 'Not found']]], 404);
            }
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to save post']]], 500);
        }

        return new WP_REST_Response(['data' => [
            'saved' => true,
            'already_saved' => (bool) ($result['already_saved'] ?? false),
        ]], 200);
    }

    public function unsave(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }
        if (! is_user_logged_in() || get_current_user_id() <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Authentication required']]], 403);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        $feature = $this->requireFeatureEnabled('saved_posts', 'Saved posts are disabled');
        if ($feature !== true) {
            return $this->errorResponse($feature);
        }

        $limit = $this->requireRateLimit('saved_posts_mutation', 120, MINUTE_IN_SECONDS);
        if ($limit !== true) {
            return new WP_REST_Response(['errors' => [['code' => $limit->get_error_code(), 'message' => $limit->get_error_message()]]], 429);
        }

        $result = $this->service->unsave(get_current_user_id(), (int) $request['id']);
        if (! ($result['ok'] ?? false)) {
            if (($result['error'] ?? '') === 'not_found') {
                return new WP_REST_Response(['errors' => [['code' => 'rest_not_found', 'message' => 'Not found']]], 404);
            }
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to unsave post']]], 500);
        }

        return new WP_REST_Response(['data' => [
            'saved' => false,
            'already_unsaved' => (bool) ($result['already_unsaved'] ?? false),
        ]], 200);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        if (! is_user_logged_in() || get_current_user_id() <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Authentication required']]], 403);
        }

        $feature = $this->requireFeatureEnabled('saved_posts', 'Saved posts are disabled');
        if ($feature !== true) {
            return $this->errorResponse($feature);
        }

        $username = sanitize_user((string) $request['username'], true);
        $user = $this->users->findByUsername($username);
        if (! is_array($user)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'User not found']]], 404);
        }

        $currentUserId = get_current_user_id();
        $targetUserId = (int) ($user['id'] ?? 0);
        if ($targetUserId !== $currentUserId && ! current_user_can('manage_options')) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $limit = min(50, max(1, (int) $request->get_param('limit') ?: 20));
        $cursorToken = sanitize_text_field((string) $request->get_param('cursor'));
        $cursor = $this->decodeCursor($cursorToken);

        $rows = $this->savedPosts->listVisibleByCursor($targetUserId, $limit, $cursor);
        $rows = $this->normalizePostList($rows, $currentUserId);

        return new WP_REST_Response([
            'data' => $rows,
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $this->buildNextCursor($rows),
            ],
        ], 200);
    }

    private function decodeCursor(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (! is_string($decoded)) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (! is_array($data) || ! isset($data['saved_at'], $data['saved_id'])) {
            return null;
        }

        return [
            'saved_at' => (string) $data['saved_at'],
            'saved_id' => (int) $data['saved_id'],
        ];
    }

    private function buildNextCursor(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $last = $rows[count($rows) - 1];
        $cursor = [
            'saved_at' => (string) ($last['saved_at'] ?? ''),
            'saved_id' => (int) ($last['saved_id'] ?? 0),
        ];

        return rtrim(strtr(base64_encode((string) wp_json_encode($cursor)), '+/', '-_'), '=');
    }

    private function normalizePostList(array $rows, int $viewerUserId): array
    {
        $postIds = [];
        foreach ($rows as $row) {
            $postIds[] = (int) ($row['id'] ?? 0);
        }

        $userVotes = $this->votes->findUserVoteValuesForTargets($viewerUserId, 'post', $postIds);
        $userReports = $this->posts->userReportedMap($postIds, $viewerUserId);
        $savedMap = $this->savedPosts->savedMapForPosts($viewerUserId, $postIds);

        foreach ($rows as &$row) {
            $eventDate = (string) ($row['openscene_event_date'] ?? '');
            $venueName = (string) ($row['openscene_event_venue_name'] ?? '');
            $row['event_summary'] = $eventDate !== '' ? [
                'event_date' => $eventDate,
                'venue_name' => $venueName,
            ] : null;
            $row['user_vote'] = (int) ($userVotes[(int) ($row['id'] ?? 0)] ?? 0);
            $row['user_reported'] = (bool) ($userReports[(int) ($row['id'] ?? 0)] ?? false);
            $row['saved'] = (bool) ($savedMap[(int) ($row['id'] ?? 0)] ?? false);
            unset($row['openscene_event_date'], $row['openscene_event_venue_name']);
        }

        return $rows;
    }
}
