<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http\Rest;

use OpenScene\Engine\Auth\Roles;
use OpenScene\Engine\Infrastructure\Repository\ModerationRepository;
use OpenScene\Engine\Infrastructure\Repository\PostRepository;
use OpenScene\Engine\Infrastructure\RateLimit\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

final class ModerationController extends BaseController
{
    public function __construct(
        RateLimiter $rateLimiter,
        private readonly ModerationRepository $moderation,
        private readonly PostRepository $posts
    ) {
        parent::__construct($rateLimiter);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        if (! $this->can(Roles::CAP_MODERATE)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $view = sanitize_key((string) $request->get_param('view'));
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $perPage = min(50, max(1, (int) $request->get_param('per_page') ?: 20));
        $rows = $this->posts->moderationList($view, $page, $perPage);

        return new WP_REST_Response([
            'data' => $rows,
            'meta' => [
                'view' => in_array($view, ['reported', 'all', 'locked', 'removed'], true) ? $view : 'all',
                'page' => $page,
                'per_page' => $perPage,
            ],
        ], 200);
    }

    public function ban(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        if (! $this->can(Roles::CAP_MODERATE) || ! $this->can(Roles::CAP_BAN_USER)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $targetUserId = (int) $request->get_param('user_id');
        $actorId = get_current_user_id();
        $ruleError = $this->validateBanTarget($actorId, $targetUserId);
        if ($ruleError instanceof WP_REST_Response) {
            return $ruleError;
        }

        $ok = $this->moderation->banUser(
            $targetUserId,
            $request->get_param('community_id') !== null ? (int) $request->get_param('community_id') : null,
            sanitize_textarea_field((string) $request->get_param('reason')),
            $actorId
        );

        return $ok
            ? new WP_REST_Response(['data' => ['banned' => true]], 200)
            : new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to ban user']]], 500);
    }

    public function unban(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        $nonce = $this->verifyNonce();
        if ($nonce !== true) {
            return $this->errorResponse($nonce);
        }

        if (! $this->can(Roles::CAP_MODERATE) || ! $this->can(Roles::CAP_BAN_USER)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $ok = $this->moderation->unban((int) $request['id'], get_current_user_id());

        return $ok
            ? new WP_REST_Response(['data' => ['unbanned' => true]], 200)
            : new WP_REST_Response(['errors' => [['code' => 'openscene_conflict', 'message' => 'Unable to unban']]], 500);
    }

    public function logs(WP_REST_Request $request): WP_REST_Response
    {
        $ban = $this->requireNotBanned();
        if ($ban !== true) {
            return $this->errorResponse($ban);
        }

        if (! $this->can(Roles::CAP_MODERATE)) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Not allowed']]], 403);
        }

        $rows = $this->moderation->logs(min(100, max(1, (int) $request->get_param('per_page') ?: 50)), max(0, (int) $request->get_param('offset')));

        return new WP_REST_Response(['data' => $rows], 200);
    }

    private function validateBanTarget(int $actorId, int $targetUserId): ?WP_REST_Response
    {
        if ($targetUserId <= 0) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_invalid_request', 'message' => 'Invalid target user']]], 422);
        }

        if ($actorId === $targetUserId) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'You cannot ban yourself']]], 403);
        }

        $targetIsAdmin = user_can($targetUserId, 'manage_options');
        $actorIsAdmin = user_can($actorId, 'manage_options');
        if ($targetIsAdmin && ! $actorIsAdmin) {
            return new WP_REST_Response(['errors' => [['code' => 'openscene_forbidden', 'message' => 'Only administrators can ban administrators']]], 403);
        }

        return null;
    }
}
