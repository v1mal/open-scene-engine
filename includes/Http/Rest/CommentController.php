<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Auth\Roles;
use OpenScene\Engine\Infrastructure\Repository\CommentRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\Repository\ModerationRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class CommentController extends BaseController
{
    private const MAX_COMMENTS_PER_POST_VIEW = 500;

    public function __construct(
        RateLimiter $rateLimiter,
        private readonly CommentRepository $comments,
        private readonly PostRepository $posts,
        private readonly ModerationRepository $moderation
    ) {
        parent::__construct($rateLimiter);
    }

    public function listTopLevel(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request['id'];
        $page = max(1, (int) $request->get_param('page'));
        $limit = min(50, max(1, (int) $request->get_param('per_page') ?: 20));
        $offset = ($page - 1) * $limit;
        $sort = $request->get_param('sort') === 'score' ? 'score' : 'created_at';

        if (($offset + $limit) > self::MAX_COMMENTS_PER_POST_VIEW) {
            return new WP_REST_Response([
                'errors' => [[
                    'code' => 'openscene_invalid_request',
                    'message' => 'Comment view limit reached for this post request window',
                ]],
                'meta' => ['max_comments' => self::MAX_COMMENTS_PER_POST_VIEW, 'has_more' => true],
            ], 422);
        }

        $post = $this->posts->find($postId);
        if (! $post) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }

        $rows = $this->comments->topLevelForPost($postId, $limit, $offset, $sort);

        return new WP_REST_Response([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $limit,
                'max_depth' => CommentRepository::MAX_DEPTH,
                'mode' => 'parent-first',
            ],
        ], 200);
    }

    public function listChildren(WP_REST_Request $request): WP_REST_Response
    {
        $postId = (int) $request['post_id'];
        $parentId = (int) $request['id'];
        $page = max(1, (int) $request->get_param('page'));
        $limit = min(50, max(1, (int) $request->get_param('per_page') ?: 20));
        $offset = ($page - 1) * $limit;
        $sort = $request->get_param('sort') === 'score' ? 'score' : 'created_at';

        if (($offset + $limit) > self::MAX_COMMENTS_PER_POST_VIEW) {
            return new WP_REST_Response([
                'errors' => [[
                    'code' => 'openscene_invalid_request',
                    'message' => 'Child comment limit reached for this request window',
                ]],
                'meta' => ['max_comments' => self::MAX_COMMENTS_PER_POST_VIEW, 'has_more' => true],
            ], 422);
        }

        $parent = $this->comments->find($parentId);
        if (
            ! is_array($parent)
            || (int) ($parent['post_id'] ?? 0) !== $postId
            || ! in_array((string) ($parent['status'] ?? ''), ['published', 'removed'], true)
        ) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Parent comment not found']]], 404);
        }

        $rows = $this->comments->childrenForParent($postId, $parentId, $limit, $offset, $sort);
        return new WP_REST_Response(['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $limit]], 200);
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

        $limit = $this->requireRateLimit('create_comment', 20, 10 * MINUTE_IN_SECONDS);
        if ($limit !== true) {
            return new WP_REST_Response(['errors' => [['code' => $limit->get_error_code(), 'message' => $limit->get_error_message()]]], 429);
        }

        if (! $this->can('openscene_comment')) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $postId = (int) $request['id'];
        $post = $this->posts->find($postId);
        if (! $post) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Post not found']]], 404);
        }

        if ($post['status'] === 'locked') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Post locked']]], 403);
        }
        if ($post['status'] === 'removed') {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Post removed']]], 403);
        }

        $userId = get_current_user_id();
        if ($this->moderation->isBanned($userId, (int) $post['community_id'])) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'User is banned']]], 403);
        }

        $parentId = $request->get_param('parent_id') !== null ? (int) $request->get_param('parent_id') : null;
        $commentId = $this->comments->create($postId, $userId, wp_kses_post((string) $request->get_param('body')), $parentId);

        if ($commentId === -1) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => 'Maximum depth reached (6)']]], 422);
        }

        if ($commentId <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to create comment']]], 500);
        }

        return new WP_REST_Response(['data' => ['id' => $commentId]], 201);
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

        if (! $this->can(Roles::CAP_DELETE_ANY_COMMENT)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $commentId = (int) $request['id'];
        $comment = $this->comments->find($commentId);
        if (! is_array($comment)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_not_found', 'message' => 'Comment not found']]], 404);
        }

        if ((string) ($comment['status'] ?? '') !== 'published') {
            return new WP_REST_Response(['data' => ['deleted' => true, 'already_deleted' => true]], 200);
        }

        $ok = $this->comments->moderateDelete($commentId);
        if (! $ok) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to delete comment']]], 500);
        }

        $this->moderation->log(get_current_user_id(), 'comment', $commentId, 'delete', null, [
            'post_id' => (int) ($comment['post_id'] ?? 0),
        ]);

        return new WP_REST_Response(['data' => ['deleted' => true]], 200);
    }
}
