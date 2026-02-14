<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Application\PostService;
use OpenScene\Engine\Auth\Roles;
use OpenScene\Engine\Infrastructure\Cache\CacheManager;
use OpenScene\Engine\Infrastructure\Repository\ModerationRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\VoteRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class PostController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly PostService $postService,
        private readonly PostRepository $posts,
        private readonly VoteRepository $votes,
        private readonly ModerationRepository $moderation,
        private readonly CacheManager $cache
    ) {
        parent::__construct($rateLimiter);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $post = $this->posts->findPublicById((int) $request['id']);

        if (! $post) {
            return new WP_REST_Response(['errors' => [['code' => 'rest_not_found', 'message' => 'Not found']]], 404);
        }

        $eventDate = (string) ($post['openscene_event_date'] ?? '');
        $venueName = (string) ($post['openscene_event_venue_name'] ?? '');
        $post['event_summary'] = $eventDate !== '' ? ['event_date' => $eventDate, 'venue_name' => $venueName] : null;
        $post['user_vote'] = $this->votes->findUserVoteValue(get_current_user_id(), 'post', (int) ($post['id'] ?? 0));
        $post['user_reported'] = $this->posts->hasUserReported((int) ($post['id'] ?? 0), get_current_user_id());
        unset($post['openscene_event_date'], $post['openscene_event_venue_name']);

        return new WP_REST_Response(['data' => $post], 200);
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

        $limit = $this->requireRateLimit('create_post', 5, 10 * MINUTE_IN_SECONDS);
        if ($limit !== true) {
            return new WP_REST_Response(['errors' => [['code' => $limit->get_error_code(), 'message' => $limit->get_error_message()]]], 429);
        }

        $communityId = (int) $request->get_param('community_id');
        $userId = get_current_user_id();

        if (! $this->can('openscene_create_post') || $userId <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        if ($this->moderation->isBanned($userId, $communityId)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'User is banned']]], 403);
        }

        $payload = [
            'community_id' => $communityId,
            'user_id' => $userId,
            'title' => sanitize_text_field((string) $request->get_param('title')),
            'body' => wp_kses_post((string) $request->get_param('body')),
            'type' => sanitize_key((string) $request->get_param('type') ?: 'text'),
            'event_date' => sanitize_text_field((string) $request->get_param('event_date')),
            'event_end_date' => sanitize_text_field((string) $request->get_param('event_end_date')),
            'venue_name' => sanitize_text_field((string) $request->get_param('venue_name')),
            'venue_address' => sanitize_textarea_field((string) $request->get_param('venue_address')),
            'ticket_url' => esc_url_raw((string) $request->get_param('ticket_url')),
            'metadata' => $request->get_param('metadata'),
        ];

        try {
            $postId = $this->postService->createPostWithOptionalEvent($payload);
        } catch (\RuntimeException $e) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => $e->getMessage()]]], 422);
        }

        $this->cache->bumpVersion();

        return new WP_REST_Response(['data' => ['id' => $postId]], 201);
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

        if (! $this->can('openscene_delete_post')) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $postId = (int) $request['id'];
        $existing = $this->posts->find($postId);
        if (! is_array($existing)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }
        if ((string) ($existing['status'] ?? '') === 'removed') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Removed posts are not editable']]], 403);
        }

        $type = sanitize_key((string) $request->get_param('type'));
        if ($type === '') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => 'type is required for update']]], 422);
        }

        try {
            $ok = $this->postService->updatePostTypeAndEvent($postId, $type, [
                'event_date' => sanitize_text_field((string) $request->get_param('event_date')),
                'event_end_date' => sanitize_text_field((string) $request->get_param('event_end_date')),
                'venue_name' => sanitize_text_field((string) $request->get_param('venue_name')),
                'venue_address' => sanitize_textarea_field((string) $request->get_param('venue_address')),
                'ticket_url' => esc_url_raw((string) $request->get_param('ticket_url')),
                'metadata' => $request->get_param('metadata'),
            ]);
        } catch (\RuntimeException $e) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => $e->getMessage()]]], 422);
        }

        if (! $ok) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to update post']]], 500);
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

        if (! is_user_logged_in() || get_current_user_id() <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Authentication required']]], 403);
        }

        $feature = $this->requireFeatureEnabled('delete', 'Deletion is disabled', 'manage_options');
        if ($feature !== true) {
            return $this->errorResponse($feature);
        }

        if (! $this->can(Roles::CAP_DELETE_ANY_POST)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $postId = (int) $request['id'];
        $result = $this->posts->softDelete($postId);
        $state = (string) ($result['state'] ?? 'error');

        if ($state === 'not_found') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }

        if ($state === 'already_removed') {
            return new WP_REST_Response(['data' => ['status' => 'removed', 'already_removed' => true]], 200);
        }

        if ($state !== 'removed') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to remove post']]], 500);
        }

        $this->moderation->log(get_current_user_id(), 'post', $postId, 'delete', null, []);
        $this->cache->bumpVersion();
        return new WP_REST_Response(['data' => ['status' => 'removed']], 200);
    }

    public function lock(WP_REST_Request $request): WP_REST_Response
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

        $postId = (int) $request['id'];
        $post = $this->posts->find($postId);
        if (! is_array($post)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }
        if ((string) ($post['status'] ?? '') === 'removed') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Removed post cannot be locked']]], 403);
        }

        $locked = $request->get_param('locked');
        $nextLocked = $locked === null ? true : (bool) $locked;
        $nextStatus = $nextLocked ? 'locked' : 'published';
        $ok = $this->posts->updateStatus($postId, $nextStatus);
        if ($ok) {
            $this->moderation->log(get_current_user_id(), 'post', $postId, $nextLocked ? 'lock' : 'unlock', null, []);
            $this->cache->bumpVersion();
            return new WP_REST_Response(['data' => ['id' => $postId, 'status' => $nextStatus]], 200);
        }

        return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to lock post']]], 500);
    }

    public function sticky(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        if (! $this->can(Roles::CAP_PIN_THREAD)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $postId = (int) $request['id'];
        $sticky = (bool) $request->get_param('sticky');
        $ok = $this->posts->toggleSticky($postId, $sticky);
        if ($ok) {
            $this->moderation->log(get_current_user_id(), 'post', $postId, $sticky ? 'sticky' : 'unsticky', null, []);
            $this->cache->bumpVersion();
            return new WP_REST_Response(['data' => ['id' => $postId, 'is_sticky' => $sticky]], 200);
        }

        return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to update sticky state']]], 500);
    }

    public function vote(WP_REST_Request $request): WP_REST_Response
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

        $feature = $this->requireFeatureEnabled('voting', 'Voting is disabled');
        if ($feature !== true) {
            return $this->errorResponse($feature);
        }

        if (! $this->can('openscene_vote')) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $limit = $this->requireRateLimit('vote', 120, MINUTE_IN_SECONDS);
        if ($limit !== true) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_rate_limited', 'message' => 'Vote rate limited']]], 429);
        }

        $postId = (int) $request['id'];
        $post = $this->posts->find($postId);
        if (! is_array($post) || ! in_array((string) ($post['status'] ?? ''), ['published', 'locked'], true)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }

        $rawValue = $request->get_param('value');
        if (! is_numeric($rawValue)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => 'value must be -1, 0, or 1']]], 422);
        }
        $requestedValue = (int) $rawValue;
        if ((string) $requestedValue !== trim((string) $rawValue) || ! in_array($requestedValue, [-1, 0, 1], true)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => 'value must be -1, 0, or 1']]], 422);
        }

        $userId = get_current_user_id();
        $existingValue = $this->votes->findUserVoteValue($userId, 'post', $postId);
        $effectiveValue = ($requestedValue !== 0 && $existingValue === $requestedValue) ? 0 : $requestedValue;
        $newValue = $effectiveValue === 0 ? null : $effectiveValue;

        $result = $this->votes->mutate($userId, 'post', $postId, $newValue);
        if (! ($result['ok'] ?? false)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Vote transaction failed']]], 500);
        }

        $fresh = $this->posts->find($postId);
        $score = (int) ($fresh['score'] ?? 0);
        return new WP_REST_Response(['data' => ['score' => $score, 'user_vote' => $effectiveValue]], 200);
    }

    public function report(WP_REST_Request $request): WP_REST_Response
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

        $feature = $this->requireFeatureEnabled('reporting', 'Reporting is disabled');
        if ($feature !== true) {
            return $this->errorResponse($feature);
        }

        $postId = (int) $request['id'];
        $post = $this->posts->find($postId);
        if (! is_array($post) || (string) ($post['status'] ?? '') !== 'published') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }

        $result = $this->posts->reportPost($postId, get_current_user_id());
        if (! ($result['ok'] ?? false)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to report post']]], 500);
        }

        return new WP_REST_Response([
            'data' => [
                'reports_count' => (int) ($result['reports_count'] ?? 0),
                'reported' => true,
            ],
        ], 200);
    }

    public function clearReports(WP_REST_Request $request): WP_REST_Response
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
        if (! $this->can(Roles::CAP_MODERATE)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $postId = (int) $request['id'];
        $post = $this->posts->find($postId);
        if (! is_array($post)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }

        $ok = $this->posts->clearReports($postId);
        if (! $ok) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to clear reports']]], 500);
        }
        $this->moderation->log(get_current_user_id(), 'post', $postId, 'clear_reports', null, []);
        $this->cache->bumpVersion();

        return new WP_REST_Response(['data' => ['reports_count' => 0, 'cleared' => true]], 200);
    }
}
